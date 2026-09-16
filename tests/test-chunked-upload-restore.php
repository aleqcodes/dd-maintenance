<?php
/**
 * Teste unitário para upload sequencial de múltiplos volumes de backup para restauração.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd_test_upload_' . uniqid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) ); }
function wp_generate_password( $len = 12, $special = false ) { return substr( md5( (string) microtime() ), 0, $len ); }
function size_format( $bytes ) { return $bytes . ' B'; }
function __( $t ) { return $t; }
function wp_unslash( $value ) { return $value; }
function is_user_logged_in() { return true; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function check_ajax_referer( $action, $query_arg, $stop = true ) { return true; }
function wp_send_json_success( $data = null ) { throw new DD_Maintenance_Test_Response( true, $data ); }
function wp_send_json_error( $data = null ) { throw new DD_Maintenance_Test_Response( false, $data ); }
if ( ! class_exists( 'DD_Maintenance_Test_Response' ) ) {
	class DD_Maintenance_Test_Response extends RuntimeException {
		public $success;
		public $data;
		public function __construct( $success, $data ) {
			$this->success = $success;
			$this->data    = $data;
			parent::__construct( $success ? 'success' : 'error' );
		}
	}
}
if ( ! class_exists( 'DD_Maintenance_Config' ) ) {
	class DD_Maintenance_Config {
		public static function has_password() { return false; }
	}
}

require_once __DIR__ . '/../includes/class-dd-maintenance.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-settings.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-restore.php';
$production_settings = ( new ReflectionClass( 'DD_Maintenance_Settings' ) )->newInstanceWithoutConstructor();
$_POST               = array( 'mode' => 'upload_init' );
try {
	$production_settings->ajax_handle_restore();
	assert( false, 'O handler de produção deveria responder por JSON.' );
} catch ( DD_Maintenance_Test_Response $response ) {
	assert( true === $response->success, 'O handler de produção deve iniciar a sessão de upload.' );
	assert( is_array( $response->data ) && isset( $response->data['upload_session_id'] ), 'O handler deve devolver o identificador da sessão.' );
	$production_session_dir = DD_Maintenance::backup_dir() . '/' . $response->data['upload_session_id'];
	assert( is_dir( $production_session_dir ), 'O handler deve criar o diretório da sessão.' );
	rmdir( $production_session_dir );
}
$_POST      = array();
$backup_dir = DD_Maintenance::backup_dir();
$upload_session_id = 'upload_restore_' . time() . '_' . wp_generate_password( 10, false );
$temp_dir = $backup_dir . '/' . $upload_session_id;

assert( wp_mkdir_p( $temp_dir ) === true, 'Pasta temporária de upload deve ser criada.' );

// Simula upload de 5 partes sequenciais
$parts_created = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$part_name = sprintf( 'site-test.part%03d.zip', $i );
	$part_path = $temp_dir . '/' . $part_name;
	file_put_contents( $part_path, 'mock zip data ' . $i );
	$parts_created[] = $part_path;
}

$restore = new DD_Maintenance_Restore();
$files   = glob( $temp_dir . '/*.zip' );
assert( count( $files ) === 5, 'Devem existir 5 partes salvas na pasta temporária.' );

// Verifica a montagem por trechos e retry idempotente no mesmo offset.
$payload       = 'chunk-0000|chunk-1111|chunk-2222';
$source_path   = $temp_dir . '/source.bin';
$staging_path  = $temp_dir . '/.source.bin.uploading';
$final_path    = $temp_dir . '/assembled.bin';
$chunk_size    = 12;
file_put_contents( $source_path, $payload );

foreach ( array( 0, 1, 1, 2 ) as $chunk_index ) {
	$offset = $chunk_index * $chunk_size;
	$input  = fopen( $source_path, 'rb' );
	$output = fopen( $staging_path, 'c+b' );
	fseek( $input, $offset, SEEK_SET );
	fseek( $output, $offset, SEEK_SET );
	stream_copy_to_stream( $input, $output, $chunk_size );
	fclose( $input );
	fclose( $output );
}

assert( rename( $staging_path, $final_path ) === true, 'O arquivo montado deve ser finalizado.' );
assert( hash_file( 'sha256', $final_path ) === hash( 'sha256', $payload ), 'Os trechos devem preservar a integridade do arquivo original.' );
@unlink( $source_path );
@unlink( $final_path );

// Limpa teste
foreach ( $parts_created as $p ) {
	@unlink( $p );
}
@rmdir( $temp_dir );

echo "Teste de upload sequencial de volumes de backup concluído com sucesso!\n";
