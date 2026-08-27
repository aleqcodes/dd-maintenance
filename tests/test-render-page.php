<?php
/**
 * Teste unitário para execução e renderização de todas as abas no DD Maintenance.
 */

declare(strict_types=1);
if ( ! defined( 'DD_MAINTENANCE_VERSION' ) ) {
	define( 'DD_MAINTENANCE_VERSION', '2.1.1' );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd_test_render_' . uniqid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code = $code;
			$this->message = $message;
		}
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
function is_wp_error( $val ) { return $val instanceof WP_Error; }

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}
function wp_mkdir_p( $path ) {
	return is_dir( $path ) || mkdir( $path, 0777, true );
}
function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
}
function wp_unslash( $val ) {
	return $val;
}
function wp_create_nonce( $action ) {
	return 'nonce_' . md5( (string) $action );
}
function wp_verify_nonce( $nonce, $action ) {
	return $nonce === 'nonce_' . md5( (string) $action );
}
function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( $action ) . '">';
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function add_query_arg( ...$args ) {
	if ( count( $args ) === 1 ) {
		return $args[0];
	}
	if ( count( $args ) === 2 && is_array( $args[0] ) ) {
		$url   = $args[1];
		$query = http_build_query( $args[0] );
		return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $query;
	}
	if ( count( $args ) === 3 && is_string( $args[0] ) ) {
		$key   = $args[0];
		$val   = $args[1];
		$url   = $args[2];
		$query = http_build_query( array( $key => $val ) );
		return $url . ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $query;
	}
	return $args[ count( $args ) - 1 ];
}
function get_date_from_gmt( $date, $format ) {
	return date( $format, strtotime( $date ) );
}
function size_format( $bytes, $decimals = 0 ) {
	$bytes = (int) $bytes;
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, 1 ) . ' MB';
	} elseif ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}
	return $bytes . ' B';
}
function current_time( $format ) {
	return date( $format );
}
function wp_max_upload_size() {
	return 256 * 1024 * 1024;
}
function current_user_can( $cap ) {
	return true;
}
function __( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function checked( $checked, $current = true, $echo = true ) {
	$res = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
	if ( $echo ) echo $res;
	return $res;
}
function selected( $selected, $current = true, $echo = true ) {
	$res = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $echo ) echo $res;
	return $res;
}
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_attr__( $text, $domain = 'default' ) { return $text; }
function esc_html_e( $text, $domain = 'default' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $text, $domain = 'default' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }
function esc_js( $text ) { return addslashes( (string) $text ); }
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
	echo '<input type="submit" name="' . esc_attr( $name ) . '" value="' . esc_attr( $text ?? 'Salvar' ) . '">';
}
function wp_list_pluck( $list, $field ) {
	return array_column( $list, $field );
}
function wp_json_encode( $val ) {
	return json_encode( $val );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function get_option( $key, $default = array() ) { return $default; }
function get_transient( $key ) { return false; }
function wp_next_scheduled( $hook ) { return false; }
function add_action() {}
function add_options_page() {}
function add_menu_page() {}

require_once __DIR__ . '/../includes/class-dd-maintenance.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-config.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-s3.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-restore.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-settings.php';
require_once __DIR__ . '/../includes/class-dd-maintenance-backup.php';

$settings_obj = new DD_Maintenance_Settings();

$tabs = array( 'general', 'config', 's3', 'cron', 'restore', 'logs', 'backups' );

foreach ( $tabs as $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	$settings_obj->render_page();
	$output = ob_get_clean();

	assert( ! empty( $output ), "A aba '{$tab}' deve renderizar conteúdo HTML." );
	assert( strpos( $output, 'DD Maintenance' ) !== false, "A aba '{$tab}' deve conter o título principal." );
	if ( 'restore' === $tab || 'backups' === $tab ) {
		assert( strpos( $output, 'Backups Locais Armazenados no Servidor' ) !== false, "A aba restore deve renderizar a área de backups locais." );
		assert( strpos( $output, 'restore_token' ) !== false, 'O cliente AJAX deve enviar o token efêmero nas etapas de restauração.' );
	}
}

echo "Teste de renderização de todas as abas concluído com 100% de sucesso!\n";
