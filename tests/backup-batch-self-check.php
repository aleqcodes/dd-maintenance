<?php

declare(strict_types=1);

final class WP_Error {
	private $code;
	private $message;

	public function __construct($code, $message) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function wp_generate_password($length = 12) { return substr('selfchecksession', 0, $length); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, $args); }
function sanitize_title($value) { return 'self-check'; }
function get_bloginfo($field) { return 'Self Check'; }
function current_time($format) { return date($format); }
$GLOBALS['test_options']    = array();
$GLOBALS['test_events']     = array();
$GLOBALS['test_transients'] = array();

function get_option($name, $default = array()) {
	if ('dd_maintenance_settings' === $name || 'backuper_settings' === $name) {
		return array(
			'include_db'        => 1,
			'include_wpcontent' => 0,
			'include_wpconfig'  => 0,
			'include_entire'    => 1,
			'keep_local'        => 0,
			'retention_local'   => 5,
		);
	}
	return $GLOBALS['test_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['test_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['test_options'][$name]); return true; }
function set_transient($name, $value, $expiration) { $GLOBALS['test_transients'][$name] = $value; return true; }
function add_action() {}
function add_filter() {}
function wp_clear_scheduled_hook($hook) {
	$GLOBALS['test_events'] = array_values(array_filter($GLOBALS['test_events'], function($event) use ($hook) {
		return $event['hook'] !== $hook;
	}));
}
function wp_next_scheduled($hook, $args = array()) {
	foreach ($GLOBALS['test_events'] as $event) {
		if ($event['hook'] === $hook && $event['args'] === $args) {
			return $event['time'];
		}
	}
	return false;
}
function wp_schedule_single_event($time, $hook, $args = array()) {
	$GLOBALS['test_events'][] = compact('time', 'hook', 'args');
	return true;
}
function wp_schedule_event() { return true; }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
function sanitize_file_name($value) { return preg_replace('/[^A-Za-z0-9_.-]/', '', $value); }
function __($value) { return $value; }
function size_format($bytes) { return $bytes . ' B'; }
function home_url() { return 'https://example.test'; }
function get_date_from_gmt($date, $format) { return date($format, strtotime($date)); }

class DD_Maintenance_Settings {}
class DD_Maintenance_Config {}
class DD_Maintenance_Updater {
	public function update_plugins() { return array('updated' => 0, 'logs' => array()); }
	public function update_core() { return array('updated' => false, 'message' => 'Core já atualizado.'); }
}
class DD_Maintenance_S3 {
	public static $uploads = array();
	public function is_configured() { return true; }
	public function put_object($key, $file) {
		assert(is_file($file));
		self::$uploads[] = $key;
		return array('key' => $key);
	}
}

final class FakeWpdb {
	public $last_error = '';

	public function get_col($query) {
		return array('wp_options', 'wp_posts');
	}

	public function get_row($query, $format) {
		preg_match('/`([^`]+)`/', $query, $match);
		$table = $match[1];
		return array($table, "CREATE TABLE `{$table}` (`id` bigint PRIMARY KEY, `value` text)");
	}

	public function get_results($query, $format) {
		preg_match('/FROM `([^`]+)` LIMIT (\\d+), (\\d+)/', $query, $match);
		$offset = (int) $match[2];
		$limit  = (int) $match[3];
		$rows   = array();
		for ($id = $offset + 1; $id <= min(300, $offset + $limit); $id++) {
			$rows[] = array('id' => $id, 'value' => 'row-' . $id);
		}
		return $rows;
	}

	public function _real_escape($value) {
		return addslashes($value);
	}

	public function query($sql) {
		$this->last_error = '';
		return true;
	}
}

$wpdb = new FakeWpdb();

$root = sys_get_temp_dir() . '/dd-maintenance-self-check-' . getmypid();
define('ABSPATH', $root . '/site/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('ARRAY_N', 'ARRAY_N');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
wp_mkdir_p(WP_CONTENT_DIR . '/data');

require dirname(__DIR__) . '/includes/class-dd-maintenance-restore.php';
require dirname(__DIR__) . '/includes/class-dd-maintenance.php';

function remove_tree($path) {
	if (!is_dir($path)) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

try {
	$ten_mb = str_repeat('A', 10 * 1024 * 1024);
	for ($i = 1; $i <= 3; $i++) {
		file_put_contents(WP_CONTENT_DIR . '/data/file-' . $i . '.bin', $ten_mb);
	}
	unset($ten_mb);

	$large_file = WP_CONTENT_DIR . '/data/large.bin';
	file_put_contents($large_file, str_repeat('L', 30 * 1024 * 1024));
	$large_hash = hash_file('sha256', $large_file);
	wp_mkdir_p(WP_CONTENT_DIR . '/many');
	for ($i = 1; $i <= 3000; $i++) {
		file_put_contents(WP_CONTENT_DIR . '/many/file-' . $i . '.txt', 'x');
	}

	require dirname(__DIR__) . '/includes/class-dd-maintenance-backup.php';
	$backup  = new DD_Maintenance_Backup();
	$session = $backup->init_session();
	assert(!is_wp_error($session));

	do {
		$db = $backup->dump_database_step($session['session_id']);
		assert(!is_wp_error($db));
	} while (!$db['completed']);
	$sql       = file_get_contents($session['db_file']);
	$db_size   = filesize($session['db_file']);
	$db_repeat = $backup->dump_database_step($session['session_id']);
	assert(substr_count($sql, 'INSERT INTO') === 600);
	assert($db_repeat['completed'] === true && filesize($session['db_file']) === $db_size, 'O checkpoint do banco deve ser idempotente.');

	do {
		$index = $backup->index_files_step($session['session_id']);
		assert(!is_wp_error($index));
	} while (!$index['completed']);
	assert($index['total_files'] === 3005, 'Arquivos indexados: ' . $index['total_files']);

	$max_zip_time = 0;
	$zip_calls    = 0;
	do {
		$started      = microtime(true);
		$zip          = $backup->zip_batch_step($session['session_id']);
		$max_zip_time = max($max_zip_time, microtime(true) - $started);
		assert(!is_wp_error($zip), is_wp_error($zip) ? $zip->get_error_message() : '');
		$zip_calls++;
		assert($zip_calls < 30, 'A criação dos volumes não convergiu.');
	} while (!$zip['completed']);
	assert($max_zip_time < 15, 'Cada chamada de montagem deve permanecer curta.');
	assert($zip_calls >= 4, 'Milhares de arquivos devem ser processados em chamadas curtas.');

	$result = $backup->finalize_and_split_step($session['session_id']);
	assert(!is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
	assert($result['completed'] === true);
	assert(count($result['parts']) >= 4);

	$entry_names = array();
	foreach ($result['parts'] as $part) {
		assert($part['size'] <= DD_Maintenance_Backup::CHUNK_SIZE);
		$archive = new ZipArchive();
		assert($archive->open($part['file']) === true, 'Cada lote deve ser um ZIP independente.');
		for ($index = 0; $index < $archive->numFiles; $index++) {
			$stat = $archive->statIndex($index);
			$entry_names[] = $stat['name'];
			assert($stat['comp_method'] === ZipArchive::CM_STORE, 'Nenhuma entrada deve ser comprimida.');
		}
		$archive->close();
	}
	assert(in_array('site/wp-content/data/file-1.bin', $entry_names, true));
	assert(in_array('__dd_chunks__/manifest.json', $entry_names, true));

	$repeat = $backup->finalize_and_split_step($session['session_id']);
	assert($repeat['completed'] === true && count($repeat['parts']) === count($result['parts']), 'O checkpoint final deve ser retomável.');
	$backup->cleanup_session_step($session['session_id']);

	unlink($large_file);
	unlink(WP_CONTENT_DIR . '/many/file-1.txt');
	$restore = (new DD_Maintenance_Restore())->restore_from_local_file($result['base']);
	assert(!is_wp_error($restore), is_wp_error($restore) ? $restore->get_error_message() : '');
	assert($restore['success'] === true);
	assert(hash_file('sha256', $large_file) === $large_hash, 'O arquivo maior que 25MB deve ser reconstruído sem alteração.');
	assert(file_get_contents(WP_CONTENT_DIR . '/many/file-1.txt') === 'x');

	delete_option('dd_maintenance_background_job');
	$maintenance = DD_Maintenance::instance();
	$maintenance->cron_full_maintenance();
	$iterations = 0;
	while (!empty($GLOBALS['test_events'])) {
		$event = array_shift($GLOBALS['test_events']);
		assert($event['hook'] === 'dd_maintenance_backup_continue');
		$maintenance->cron_backup_continue($event['args'][0]);
		$job = get_option('dd_maintenance_background_job');
		$iterations++;
		assert($iterations < 150, 'O worker do WP-Cron não convergiu.');
	}
	$job = get_option('dd_maintenance_background_job');
	assert($job['status'] === 'completed' && $job['phase'] === 'done');
	assert(count(DD_Maintenance_S3::$uploads) === count($job['parts']), 'O WP-Cron deve enviar um volume independente por evento.');

	// Teste de simulação de falha e autolimpeza
	$fail_session = $backup->init_session();
	assert(!is_wp_error($fail_session));
	$fail_dir = $fail_session['session_dir'];
	assert(is_dir($fail_dir));

	$cleanup = $backup->cleanup_failed_session($fail_session['session_id'], 'Erro de teste simulado');
	assert(!is_dir($fail_dir), 'A pasta da sessão que falhou deve ser removida da pasta de uploads.');

	$logs = DD_Maintenance::get_saved_logs();
	assert(!empty($logs), 'Os logs salvos fisicamente devem ser encontrados na pasta de uploads.');
	$has_failure_log = false;
	foreach ($logs as $log_item) {
		if ($log_item['status'] === 'failure') {
			$has_failure_log = true;
			$content = DD_Maintenance::get_log_content($log_item['filename']);
			assert(strpos($content, 'AUTOLIMPEZA') !== false, 'O log de falha deve conter o registro de autolimpeza.');
		}
	}
	assert($has_failure_log, 'Deve existir pelo menos um log registrado com status de falha.');

	echo "backup volume self-check: OK\n";
} finally {
	remove_tree($root);
}
