<?php
/**
 * Teste unitário para exclusão de backups remotos no S3 / Spaces.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd_test_s3_del_' . uniqid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name ); }
function sanitize_title( $text ) { return 'site-test'; }
function get_bloginfo( $field ) { return 'Site Test'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_remote_request( $url, $args = array() ) {
	$method = $args['method'] ?? 'GET';
	$GLOBALS['s3_mock_requests'][] = array( 'method' => $method, 'url' => $url );
	return array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	);
}
function wp_remote_get( $url, $args = array() ) {
	$xml = '<?xml version="1.0" encoding="UTF-8"?>
	<ListBucketResult>
		<Name>test-bucket</Name>
		<Contents>
			<Key>site-test/2026-08-24/backup-abc-2026-08-24.part001.zip</Key>
			<Size>26214400</Size>
			<LastModified>2026-08-24T15:00:00Z</LastModified>
		</Contents>
		<Contents>
			<Key>site-test/2026-08-24/backup-abc-2026-08-24.part002.zip</Key>
			<Size>10485760</Size>
			<LastModified>2026-08-24T15:00:00Z</LastModified>
		</Contents>
		<Contents>
			<Key>site-test/2026-08-24/backup-abc-2026-08-24.sql</Key>
			<Size>5242880</Size>
			<LastModified>2026-08-24T15:00:00Z</LastModified>
		</Contents>
		<Contents>
			<Key>site-test/2026-08-24/other-backup.zip</Key>
			<Size>12345</Size>
			<LastModified>2026-08-24T14:00:00Z</LastModified>
		</Contents>
	</ListBucketResult>';
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => $xml,
	);
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 200; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
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

function get_option( $name, $default = array() ) {
	return array(
		's3_access_key' => 'DO00TESTKEY',
		's3_secret_key' => 'TESTSECRET',
		's3_bucket'     => 'test-bucket',
		's3_region'     => 'nyc3',
	);
}

require_once __DIR__ . '/../includes/class-dd-maintenance-s3.php';

$s3 = new DD_Maintenance_S3();
assert( $s3->is_configured() === true, 'S3 deve estar configurado.' );

// 1. Testa list_objects
$objects = $s3->list_objects( 'site-test' );
assert( is_array( $objects ), 'list_objects deve retornar array.' );
assert( count( $objects ) === 4, 'Deve listar 4 objetos do XML mock.' );
assert( $objects[0]['key'] === 'site-test/2026-08-24/backup-abc-2026-08-24.part001.zip', 'Chave 1 correta.' );

// 2. Testa delete_backup_remote
$GLOBALS['s3_mock_requests'] = array();
$result = $s3->delete_backup_remote( 'backup-abc-2026-08-24' );
assert( $result['deleted'] === 3, 'Deve encontrar e excluir exatamente as 3 partes do backup-abc (2 zips + 1 sql).' );
assert( count( $GLOBALS['s3_mock_requests'] ) === 3, 'Deve disparar 3 requisições DELETE.' );

echo "Testes de exclusão remota no S3 passaram com sucesso!\n";
