<?php
/**
 * Regressões de integridade para backup e restauração progressivos.
 */

declare(strict_types=1);

final class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $value ); }
function sanitize_title( $value ) { return 'integrity-test'; }
function get_bloginfo( $field ) { return 'Integrity Test'; }
function current_time( $format ) { return date( $format, 1770000000 ); }
function home_url() { return 'https://example.test'; }
function __( $value ) { return $value; }
function size_format( $bytes ) { return $bytes . ' B'; }
function number_format_i18n( $number ) { return (string) $number; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function maybe_unserialize( $value ) { return $value; }
function get_date_from_gmt( $date, $format ) { return date( $format, strtotime( $date ) ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function is_user_logged_in() { return false; }
function current_user_can( $capability ) { return false; }
function check_ajax_referer( $action, $query_arg = false, $stop = true ) { return false; }
function add_action() {}

final class Json_Response extends Exception {
	public $success;
	public $data;

	public function __construct( $success, $data ) {
		parent::__construct();
		$this->success = $success;
		$this->data    = $data;
	}
}

function wp_send_json_error( $data ) { throw new Json_Response( false, $data ); }
function wp_send_json_success( $data ) { throw new Json_Response( true, $data ); }

final class DD_Maintenance_Config {
	public static function has_password() { return false; }
	public static function verify_password( $password ) { return false; }
}

$GLOBALS['password_counter'] = 0;
function wp_generate_password( $length = 12, $special = false, $extra = false ) {
	$GLOBALS['password_counter']++;
	return substr( hash( 'sha256', 'password-' . $GLOBALS['password_counter'] ), 0, $length );
}

$GLOBALS['test_options'] = array(
	'dd_maintenance_settings' => array(
		'include_db'        => 1,
		'include_wpcontent' => 0,
		'include_wpconfig'  => 0,
		'include_entire'    => 0,
		'keep_local'        => 0,
		'split_size_mb'     => 25,
	),
);
function get_option( $name, $default = array() ) { return $GLOBALS['test_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['test_options'][ $name ] = $value; return true; }

final class FakeWpdb {
	public $dbh = null;
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		return true;
	}

	public function get_col( $sql ) {
		if ( 'SHOW TABLES' === $sql ) {
			return array( 'wp_data' );
		}
		return array();
	}

	public function get_row( $sql, $format ) {
		return array( 'wp_data', 'CREATE TABLE `wp_data` (`id` bigint NOT NULL, `value` text, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
	}

	public function get_results( $sql, $format = null ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/LIMIT (\d+),\s*(\d+)/', $sql, $matches ) ) {
			return array();
		}
		$offset = (int) $matches[1];
		$limit  = (int) $matches[2];
		$rows   = array();
		for ( $id = $offset + 1; $id <= min( 1200, $offset + $limit ); $id++ ) {
			$rows[] = array( 'id' => $id, 'value' => 'row-' . $id );
		}
		return $rows;
	}

	public function get_var( $sql ) { return false; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function _real_escape( $value ) { return addslashes( $value ); }
	public function set_prefix( $prefix ) { $this->prefix = $prefix; }
}

$wpdb = new FakeWpdb();
$root = sys_get_temp_dir() . '/dd-integrity-regressions-' . getmypid();
define( 'ABSPATH', $root . '/site/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'ARRAY_A', 'ARRAY_A' );

final class DD_Maintenance {
	public static function backup_dir() {
		$dir = WP_CONTENT_DIR . '/uploads/dd-maintenance';
		wp_mkdir_p( $dir );
		return $dir;
	}
}

require dirname( __DIR__ ) . '/includes/class-dd-maintenance-backup.php';
require dirname( __DIR__ ) . '/includes/class-dd-maintenance-restore.php';
require dirname( __DIR__ ) . '/includes/class-dd-maintenance-settings.php';

function remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

try {
	$backup = new DD_Maintenance_Backup();
	$first  = $backup->init_session();
	$second = $backup->init_session();
	assert( ! is_wp_error( $first ) && ! is_wp_error( $second ) );
	assert( $first['base_name'] !== $second['base_name'], 'Backups iniciados no mesmo segundo devem ter nomes distintos.' );

	do {
		$dump = $backup->dump_database_step( $first['session_id'] );
		assert( ! is_wp_error( $dump ) );
	} while ( ! $dump['completed'] );
	assert( $dump['completed'] === true, 'O dump deve concluir com sucesso.' );
	$select_queries = array_values( array_filter( $wpdb->queries, static function( $sql ) { return 0 === strpos( $sql, 'SELECT * FROM' ); } ) );
	assert( ! empty( $select_queries ) && false !== strpos( $select_queries[0], 'ORDER BY `id`' ), 'A paginação deve usar a chave primária.' );

	$restore_dir = DD_Maintenance::backup_dir() . '/restore_exec_rst_progress';
	wp_mkdir_p( $restore_dir );
	$sql_file = $restore_dir . '/database.sql';
	$sql_body = '';
	for ( $i = 0; $i < 600; $i++ ) {
		$sql_body .= "SET @dd_value = {$i};\n";
	}
	file_put_contents( $sql_file, $sql_body );
	$token = 'restore-token-value';
	$state = array(
		'session_id' => 'rst_progress', 'extract_dir' => $restore_dir, 'temp_upload_dir' => '', 'zip_paths' => array(),
		'db_file' => $sql_file, 'db_file_size' => filesize( $sql_file ), 'db_offset' => 0, 'db_queries' => 0,
		'db_tables' => 0, 'db_errors' => 0, 'db_error_samples' => array(), 'db_current_siteurl' => '',
		'db_current_home' => '', 'db_query_buffer' => '', 'db_in_string' => false, 'db_string_char' => '',
		'target_siteurl' => '', 'target_home' => '', 'log' => array(), 'auth_token_hash' => hash( 'sha256', $token ),
		'auth_expires_at' => time() + 300, 'files_copied' => 0, 'db_stats' => null,
	);
	file_put_contents( $restore_dir . '/state.json', json_encode( $state ) );

	$restore = new DD_Maintenance_Restore();
	$batch1  = $restore->restore_database_step( 'rst_progress', 0.0 );
	$saved1  = json_decode( file_get_contents( $restore_dir . '/state.json' ), true );
	assert( ! is_wp_error( $batch1 ) && $batch1['completed'] === false, 'O primeiro lote SQL deve deixar trabalho pendente.' );
	assert( $saved1['db_offset'] > 0 && $batch1['percent'] > 0, 'O lote SQL deve persistir offset e progresso reais.' );
	$batch2 = $restore->restore_database_step( 'rst_progress', 0.0 );
	assert( ! is_wp_error( $batch2 ) && $batch2['completed'] === true && $batch2['queries'] === 600, 'O segundo lote deve retomar e concluir sem repetir consultas.' );

	$settings_reflection = new ReflectionClass( 'DD_Maintenance_Settings' );
	$settings_handler    = $settings_reflection->newInstanceWithoutConstructor();
	$capture_response    = static function( array $post ) use ( $settings_handler ) {
		$_POST = $post;
		try {
			$settings_handler->ajax_handle_restore();
		} catch ( Json_Response $response ) {
			return $response;
		}
		throw new RuntimeException( 'O handler AJAX não emitiu resposta JSON.' );
	};

	$upload_bypass = $capture_response(
		array(
			'mode'              => 'upload_chunk',
			'upload_session_id' => 'upload_restore_stolen',
			'restore_token'     => $token,
		)
	);
	assert( $upload_bypass->success === false, 'Um diretório de upload não deve autorizar acesso público ao handler.' );

	$wrong_token = $capture_response(
		array(
			'mode'               => 'restore_db',
			'restore_session_id' => 'rst_progress',
			'restore_token'      => 'wrong-token',
		)
	);
	assert( $wrong_token->success === false, 'A continuação pública deve rejeitar um token incorreto.' );

	$valid_token = $capture_response(
		array(
			'mode'               => 'restore_db',
			'restore_session_id' => 'rst_progress',
			'restore_token'      => $token,
		)
	);
	assert( $valid_token->success === true, 'A continuação pública deve aceitar somente o token efêmero correto.' );
	assert( $restore->verify_restore_token( 'rst_progress', $token ) === true, 'O token correto deve autorizar a continuação.' );
	assert( $restore->verify_restore_token( 'rst_progress', 'wrong-token' ) === false, 'Um token incorreto deve ser rejeitado.' );

	$failure_dir = DD_Maintenance::backup_dir() . '/restore_exec_rst_copy_failure';
	wp_mkdir_p( $failure_dir );
	file_put_contents( $failure_dir . '/restore_queue.jsonl', json_encode( array( 'src' => $failure_dir . '/missing.txt', 'dst' => ABSPATH . 'missing.txt' ) ) . "\n" );
	$failure_state = array(
		'session_id' => 'rst_copy_failure', 'extract_dir' => $failure_dir, 'temp_upload_dir' => '', 'files_done' => false,
		'files_queue_created' => true, 'files_total' => 1, 'files_copied' => 0, 'files_queue_offset' => 0, 'log' => array(),
	);
	file_put_contents( $failure_dir . '/state.json', json_encode( $failure_state ) );
	$copy_result = $restore->restore_files_step( 'rst_copy_failure' );
	assert( is_wp_error( $copy_result ) && 'restore_source_missing' === $copy_result->get_error_code(), 'Uma cópia incompleta deve falhar explicitamente.' );

	$sql_only = DD_Maintenance::backup_dir() . '/sql-only.sql';
	file_put_contents( $sql_only, 'SELECT 1;' );
	assert( DD_Maintenance_Restore::delete_local_backup( 'sql-only' ) === true, 'Excluir um dump SQL avulso deve retornar sucesso.' );
	assert( ! file_exists( $sql_only ) );

	$backup->cleanup_session_step( $first['session_id'] );
	$backup->cleanup_session_step( $second['session_id'] );
	echo "Regressões de integridade de backup e restauração passaram.\n";
} finally {
	remove_tree( $root );
}
