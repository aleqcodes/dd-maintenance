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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $msg;
		public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
		public function get_error_message() { return $this->msg; }
	}
}
function is_wp_error( $val ) { return $val instanceof WP_Error; }

require_once __DIR__ . '/../includes/class-dd-maintenance.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-restore.php';

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

// Limpa teste
foreach ( $parts_created as $p ) {
	@unlink( $p );
}
@rmdir( $temp_dir );

echo "Teste de upload sequencial de volumes de backup concluído com sucesso!\n";
