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

// 3. Testa normalização de Dynamic Tags do Elementor para compatibilidade com PHP 8.2
$tag_missing = '[elementor-tag id="a5a44c3" name="site-url"]';
$tag_empty   = '[elementor-tag id="a5a44c3" name="site-url" settings=""]';
$tag_null    = '[elementor-tag id="a5a44c3" name="site-url" settings="null"]';
$tag_valid   = '[elementor-tag id="a5a44c3" name="site-url" settings="%7B%22fallback%22%3A%22%22%7D"]';

assert( DD_Maintenance_Restore::fix_elementor_dynamic_tags( $tag_missing ) === '[elementor-tag id="a5a44c3" name="site-url" settings="%7B%7D"]', 'Tag sem settings deve receber settings=%7B%7D.' );
assert( DD_Maintenance_Restore::fix_elementor_dynamic_tags( $tag_empty ) === '[elementor-tag id="a5a44c3" name="site-url" settings="%7B%7D"]', 'Tag com settings vazia deve receber settings=%7B%7D.' );
assert( DD_Maintenance_Restore::fix_elementor_dynamic_tags( $tag_null ) === '[elementor-tag id="a5a44c3" name="site-url" settings="%7B%7D"]', 'Tag com settings null deve receber settings=%7B%7D.' );
assert( DD_Maintenance_Restore::fix_elementor_dynamic_tags( $tag_valid ) === $tag_valid, 'Tag com settings válida deve permanecer inalterada.' );

// 4. Testa normalização de tags dentro de JSON cru (_elementor_data)
$raw_elementor_data = json_encode( array(
	array(
		'id' => 'widget_image_1',
		'elType' => 'widget',
		'widgetType' => 'image',
		'settings' => array(
			'link' => array(
				'url' => '[elementor-tag id="a5a44c3" name="site-url"]',
			),
		),
	),
), JSON_UNESCAPED_SLASHES );

$fixed_elementor_data = DD_Maintenance_Restore::fix_elementor_dynamic_tags( $raw_elementor_data );
$decoded_fixed = json_decode( $fixed_elementor_data, true );
assert( is_array( $decoded_fixed ), 'O JSON resultante deve ser 100% válido.' );
assert( $decoded_fixed[0]['settings']['link']['url'] === '[elementor-tag id="a5a44c3" name="site-url" settings="%7B%7D"]', 'Tag dentro do JSON deve receber settings=%7B%7D.' );

echo "Todos os testes de Search & Replace serializado e Elementor passaram com 100% de sucesso!\n";
