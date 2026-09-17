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
	if ( 'DELETE' !== $method || ! isset( $args['headers'], $args['timeout'] ) || 30 !== $args['timeout'] ) {
		throw new RuntimeException( 'wp_remote_request recebeu um contrato inesperado.' );
	}
	$GLOBALS['s3_mock_requests'][] = array( 'method' => $method, 'url' => $url );
	return array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	);
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['s3_mock_get_urls'][] = $url;
	if ( false !== strpos( $url, 'continuation-token=page-2' ) ) {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
		<ListBucketResult>
			<Contents>
				<Key>site-test/2026-08-24/backup-abc-2026-08-240.zip</Key>
				<Size>12345</Size>
				<LastModified>2026-08-24T16:00:00Z</LastModified>
			</Contents>
			<IsTruncated>false</IsTruncated>
		</ListBucketResult>';
	} else {
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
			<IsTruncated>true</IsTruncated>
			<NextContinuationToken>page-2</NextContinuationToken>
		</ListBucketResult>';
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => $xml,
	);
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 200; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }
function size_format( $bytes ) { return $bytes . ' B'; }
function get_date_from_gmt( $date, $format ) { return date( $format, strtotime( $date ) ); }
function __( $t ) { return $t; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $msg;
		public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
		public function get_error_code() { return $this->code; }
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
abstract class DD_Test_S3_Put_Transport {
	public $requests = array();
	private $responses;

	public function __construct( array $responses ) {
		$this->responses = $responses;
	}

	public function __invoke( $url, $headers, $file, $size ) {
		if ( ! is_string( $url ) || ! is_array( $headers ) || ! is_file( $file ) || (int) filesize( $file ) !== (int) $size ) {
			throw new RuntimeException( 'O transporte S3 recebeu argumentos inválidos.' );
		}
		foreach ( array( 'Host', 'Authorization', 'x-amz-content-sha256', 'x-amz-date', 'Content-Type', 'Content-Length' ) as $header ) {
			if ( ! isset( $headers[ $header ] ) || '' === (string) $headers[ $header ] ) {
				throw new RuntimeException( 'O transporte S3 recebeu cabeçalhos incompletos.' );
			}
		}
		$this->requests[] = array(
			'url'     => $url,
			'headers' => $headers,
			'file'    => $file,
			'size'    => (int) $size,
		);
		if ( empty( $this->responses ) ) {
			throw new RuntimeException( 'O transporte S3 recebeu uma tentativa não preparada.' );
		}
		$response = array_shift( $this->responses );
		if ( is_wp_error( $response ) || is_array( $response ) ) {
			return $response;
		}
		throw new RuntimeException( 'O transporte S3 retornou um contrato inesperado.' );
	}
}

final class DD_Test_S3_Curl_Transport extends DD_Test_S3_Put_Transport {}
final class DD_Test_S3_Wp_Remote_Request_Transport extends DD_Test_S3_Put_Transport {}

require_once __DIR__ . '/../includes/class-dd-maintenance-s3.php';

$s3 = new DD_Maintenance_S3();
assert( $s3->is_configured() === true, 'S3 deve estar configurado.' );

// 1. Testa list_objects
$GLOBALS['s3_mock_get_urls'] = array();
$objects = $s3->list_objects( 'site-test' );
$list_urls = array_values( array_filter( $GLOBALS['s3_mock_get_urls'], static function( $url ) { return false !== strpos( $url, 'list-type=2' ); } ) );
assert( is_array( $objects ), 'list_objects deve retornar array.' );
assert( count( $objects ) === 5, 'Deve reunir objetos de todas as páginas do XML mock.' );
assert( count( $list_urls ) === 2, 'Deve consultar a página seguinte com o continuation token.' );
assert( false !== strpos( $list_urls[1], 'continuation-token=page-2' ), 'A segunda consulta deve enviar o continuation token.' );
assert( $objects[0]['key'] === 'site-test/2026-08-24/backup-abc-2026-08-24.part001.zip', 'Chave 1 correta.' );

// 2. Agrupa volumes e dump SQL do mesmo backup em uma única entrada
$grouped = $s3->get_remote_backups( 'site-test' );
assert( is_array( $grouped ), 'get_remote_backups deve retornar uma lista.' );
assert( count( $grouped ) === 3, 'Deve retornar os três pacotes agrupados, inclusive o da segunda página.' );

$backup_abc = null;
foreach ( $grouped as $backup ) {
	if ( $backup['identifier'] === 'backup-abc-2026-08-24' ) {
		$backup_abc = $backup;
		break;
	}
}
assert( is_array( $backup_abc ), 'O pacote backup-abc deve existir.' );
assert( $backup_abc['is_multipart'] === true, 'O pacote deve ser identificado como multipart.' );
assert( $backup_abc['total_parts'] === 2, 'O pacote deve conter os dois volumes ZIP.' );
assert( $backup_abc['has_sql'] === true, 'O dump SQL deve pertencer ao mesmo pacote.' );
assert( $backup_abc['size'] === 40 * 1024 * 1024, 'O tamanho total deve somar os volumes e o SQL.' );

// 3. Testa delete_backup_remote
$GLOBALS['s3_mock_requests'] = array();
$result = $s3->delete_backup_remote( 'backup-abc-2026-08-24' );
assert( $result['deleted'] === 3, 'Deve encontrar e excluir exatamente as 3 partes do backup-abc (2 zips + 1 sql).' );
// 4. Testa o transporte cURL com cURL habilitado, sem substituir funções globais.
$curl_transport = new DD_Test_S3_Curl_Transport(
	array(
		array(
			'status'  => 204,
			'headers' => array( 'etag' => '"curl-etag"' ),
			'body'    => '',
		),
	)
);
$curl_s3 = new DD_Maintenance_S3( $curl_transport );
$dummy_file = sys_get_temp_dir() . '/dummy_part.zip';
file_put_contents( $dummy_file, 'dummy zip content' );
$put_res = $curl_s3->put_object( 'site-test/2026-08-24/test.zip', $dummy_file );
assert( ! is_wp_error( $put_res ), 'put_object deve ter sucesso via double cURL.' );
assert( '"curl-etag"' === $put_res['etag'], 'put_object deve preservar o ETag do transporte cURL.' );
assert( 1 === count( $curl_transport->requests ), 'O double cURL deve receber exatamente um PUT.' );

// 5. Double separado para o contrato wp_remote_request e retry idempotente.
$wp_transport = new DD_Test_S3_Wp_Remote_Request_Transport(
	array(
		array(
			'status'  => 503,
			'headers' => array( 'x-amz-request-id' => 'retry-request' ),
			'body'    => '<Error><Code>ServiceUnavailable</Code></Error>',
		),
		array(
			'status'  => 204,
			'headers' => array( 'etag' => '"retry-etag"' ),
			'body'    => '',
		),
	)
);
$wp_s3 = new DD_Maintenance_S3( $wp_transport );
$retry_res = $wp_s3->put_object( 'site-test/2026-08-24/retry.zip', $dummy_file );
assert( ! is_wp_error( $retry_res ), 'PUT deve ser concluído após uma falha transitória.' );
assert( 2 === count( $wp_transport->requests ), 'Retry deve repetir o PUT idempotente exatamente uma vez.' );
assert( $wp_transport->requests[0]['url'] === $wp_transport->requests[1]['url'], 'Retry deve reutilizar a mesma chave.' );

// 6. Resposta HTTP inválida deve expor status e request id, nunca credenciais.
$invalid_transport = new DD_Test_S3_Wp_Remote_Request_Transport(
	array(
		array(
			'status'  => 400,
			'headers' => array( 'x-amz-request-id' => 'invalid-request' ),
			'body'    => '<Error><Code>InvalidArgument</Code><Message>payload inválido</Message></Error>',
		),
	)
);
$invalid_s3 = new DD_Maintenance_S3( $invalid_transport );
$invalid_res = $invalid_s3->put_object( 'site-test/2026-08-24/invalid.zip', $dummy_file );
assert( is_wp_error( $invalid_res ), 'Resposta HTTP inválida deve retornar WP_Error.' );
assert( false !== strpos( $invalid_res->get_error_message(), 'HTTP 400' ), 'Erro inválido deve informar o status HTTP.' );
assert( false !== strpos( $invalid_res->get_error_message(), 'Request ID: invalid-request' ), 'Erro inválido deve informar o request id.' );
assert( false === strpos( $invalid_res->get_error_message(), 'TESTSECRET' ), 'Erro nunca deve incluir a Secret Key.' );

// O double deve falhar imediatamente quando o contrato do transporte muda.
$unexpected_contract = false;
try {
	$curl_transport( 'https://unexpected.invalid', array(), $dummy_file, 0 );
} catch ( RuntimeException $exception ) {
	$unexpected_contract = true;
}
assert( true === $unexpected_contract, 'O double deve rejeitar chamadas fora do contrato.' );

@unlink( $dummy_file );
echo "Testes de exclusão remota no S3 passaram com sucesso!\n";
