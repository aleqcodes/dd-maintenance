<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/dd-maintenance-phpunit-' . getmypid() . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! isset( $GLOBALS['dd_phpunit_transients'] ) ) {
	$GLOBALS['dd_phpunit_transients'] = array();
}

$GLOBALS['dd_phpunit_options'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
$GLOBALS['dd_phpunit_hooks'] = array();

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0755, true );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['dd_phpunit_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, bool $autoload = true ): bool {
		if ( ! empty( $GLOBALS['dd_phpunit_fail_update_option'] ) ) {
			return false;
		}
		$GLOBALS['dd_phpunit_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $name ) {
		return $GLOBALS['dd_phpunit_transients'][ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['dd_phpunit_transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['dd_phpunit_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['dd_phpunit_hooks'][ $hook ][] = $callback;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ): string {
		return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $name );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return $GLOBALS['dd_phpunit_logged_in'] ?? true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return ( $GLOBALS['dd_phpunit_can_manage'] ?? true ) && 'manage_options' === $capability;
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action, string $query_arg, bool $stop = true ): bool {
		return $GLOBALS['dd_phpunit_valid_nonce'] ?? true;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra_special = false ): string {
		return substr( md5( uniqid( '', true ) ), 0, $length );
	}
}
if ( ! function_exists( 'ignore_user_abort' ) ) {
	function ignore_user_abort( bool $enable = true ): int {
		return 0;
	}
}
if ( ! class_exists( 'DD_Maintenance_Config' ) ) {
	class DD_Maintenance_Config {
		public static function has_password(): bool {
			return false;
		}
	}
}
if ( ! class_exists( 'DD_Maintenance_Test_Json_Response' ) ) {
	class DD_Maintenance_Test_Json_Response extends RuntimeException {
		public $success;
		public $data;

		public function __construct( bool $success, $data ) {
			$this->success = $success;
			$this->data    = $data;
			parent::__construct( $success ? 'success' : 'error' );
		}
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null ): void {
		throw new DD_Maintenance_Test_Json_Response( true, $data );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null ): void {
		throw new DD_Maintenance_Test_Json_Response( false, $data );
	}
}
