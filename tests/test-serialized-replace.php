<?php
/**
 * Teste unitário para Search & Replace em dados serializados e JSON (Elementor).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd_test_sr_' . uniqid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function wp_json_encode( $val, $flags = 0 ) { return json_encode( $val, $flags ); }
function size_format( $b ) { return $b . ' B'; }
function __( $t ) { return $t; }
function is_serialized( $data ) {
	if ( ! is_string( $data ) ) return false;
	$data = trim( $data );
	if ( 'N;' === $data ) return true;
	if ( strlen( $data ) < 4 ) return false;
	if ( ':' !== $data[1] ) return false;
	$last = substr( $data, -1 );
	if ( ';' !== $last && '}' !== $last ) return false;
	$token = $data[0];
	return in_array( $token, array( 's', 'a', 'O', 'b', 'i', 'd' ), true );
}

require_once __DIR__ . '/../includes/class-dd-maintenance-restore.php';

// 1. Testa array serializado com URLs de tamanhos diferentes
$old_url = 'http://localhost/site1';
$new_url = 'https://meunovositeproducao.com.br';

$original_data = array(
	'settings' => array(
		'url'   => $old_url,
		'image' => $old_url . '/wp-content/uploads/2026/08/foto.jpg',
	),
	'active' => true,
	'count'  => 42,
);
$serialized_input = serialize( $original_data );

$replaced = DD_Maintenance_Restore::recursive_search_replace( $old_url, $new_url, $serialized_input );
assert( is_string( $replaced ), 'Deve retornar string serializada.' );

$unserialized = unserialize( $replaced );
assert( is_array( $unserialized ), 'unserialize() NÃO PODE falhar após search & replace.' );
assert( $unserialized['settings']['url'] === $new_url, 'A URL foi substituída corretamente.' );
assert( $unserialized['settings']['image'] === $new_url . '/wp-content/uploads/2026/08/foto.jpg', 'O link da imagem foi atualizado.' );
assert( $unserialized['active'] === true, 'Tipos booleanos preservados.' );

// 2. Testa JSON do Elementor (_elementor_data)
$elementor_json = json_encode( array(
	array(
		'id' => 'abc123',
		'elType' => 'widget',
		'widgetType' => 'image',
		'settings' => array(
			'image' => array(
				'url' => $old_url . '/wp-content/uploads/logo.png',
			),
			'link' => array(
				'url' => $old_url . '/contato',
			),
		),
	)
) );

$replaced_json = DD_Maintenance_Restore::recursive_search_replace( $old_url, $new_url, $elementor_json );
$decoded_json  = json_decode( $replaced_json, true );
assert( is_array( $decoded_json ), 'JSON do Elementor decodificado com sucesso.' );
assert( $decoded_json[0]['settings']['image']['url'] === $new_url . '/wp-content/uploads/logo.png', 'URL da imagem no Elementor atualizada.' );
assert( $decoded_json[0]['settings']['link']['url'] === $new_url . '/contato', 'Link no Elementor atualizado.' );

echo "Todos os testes de Search & Replace serializado e Elementor passaram com 100% de sucesso!\n";
