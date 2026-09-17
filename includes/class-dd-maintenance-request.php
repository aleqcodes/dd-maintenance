<?php
/**
 * Requests normalizados na fronteira administrativa.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Request {
	/** @var array<string, mixed> */
	private $post;
	/** @var array<string, mixed> */
	private $get;
	/** @var array<string, mixed> */
	private $files;

	/**
	 * @param array $post  Dados POST capturados.
	 * @param array $get   Dados GET capturados.
	 * @param array $files Dados FILES capturados.
	 */
	protected function __construct( array $post, array $get, array $files ) {
		$this->post  = $post;
		$this->get   = $get;
		$this->files = $files;
	}

	/** @return self */
	public static function from_globals() {
		$data = self::captured_globals();
		return new self( $data[0], $data[1], $data[2] );
	}

	/** @return array<int,array<string,mixed>> */
	protected static function captured_globals(): array {
		return array(
			is_array( $_POST ) ? $_POST : array(),
			is_array( $_GET ) ? $_GET : array(),
			is_array( $_FILES ) ? $_FILES : array(),
		);
	}

	/** @return string */
	public function post_text( string $key, string $default = '' ): string {
		return isset( $this->post[ $key ] ) && is_scalar( $this->post[ $key ] ) ? self::sanitize_text( $this->post[ $key ] ) : $default;
	}

	/** @return string */
	public function post_key( string $key, string $default = '' ): string {
		return isset( $this->post[ $key ] ) && is_scalar( $this->post[ $key ] ) ? self::sanitize_key_value( $this->post[ $key ] ) : $default;
	}

	/** @return string */
	public function post_secret( string $key, string $default = '' ): string {
		return isset( $this->post[ $key ] ) && is_scalar( $this->post[ $key ] ) ? trim( self::sanitize_text( $this->post[ $key ] ) ) : $default;
	}

	/** @return bool */
	public function post_flag( string $key ): bool {
		return ! empty( $this->post[ $key ] );
	}

	/** @return int */
	public function post_int( string $key, int $default = 0 ): int {
		return isset( $this->post[ $key ] ) && is_scalar( $this->post[ $key ] ) ? (int) $this->post[ $key ] : $default;
	}

	/** @return string */
	public function get_key( string $key, string $default = '' ): string {
		return isset( $this->get[ $key ] ) && is_scalar( $this->get[ $key ] ) ? self::sanitize_key_value( $this->get[ $key ] ) : $default;
	}

	/** @return array<string, mixed> */
	public function file_input( string $key ): array {
		return isset( $this->files[ $key ] ) && is_array( $this->files[ $key ] ) ? $this->files[ $key ] : array();
	}
	public function get_text( string $key, string $default = '' ): string {
		return isset( $this->get[ $key ] ) && is_scalar( $this->get[ $key ] ) ? self::sanitize_text( $this->get[ $key ] ) : $default;
	}

	/** @return bool */
	public function has_post( string $key ): bool {
		return array_key_exists( $key, $this->post );
	}
	/** @return mixed */
	public function post_value( string $key, $default = null ) {
		return array_key_exists( $key, $this->post ) ? ( function_exists( 'wp_unslash' ) ? wp_unslash( $this->post[ $key ] ) : $this->post[ $key ] ) : $default;
	}
	private static function sanitize_text( $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $value ) : stripslashes( (string) $value );
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	private static function sanitize_key_value( $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $value ) : stripslashes( (string) $value );
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
	}
}

class DD_Maintenance_Settings_Request extends DD_Maintenance_Request {
	public static function from_globals(): DD_Maintenance_Settings_Request {
		$data = parent::captured_globals();
		return new self( $data[0], $data[1], $data[2] );
	}
}
class DD_Maintenance_Backup_Request extends DD_Maintenance_Request {
	public static function from_globals(): DD_Maintenance_Backup_Request {
		$data = parent::captured_globals();
		return new self( $data[0], $data[1], $data[2] );
	}
}
class DD_Maintenance_Restore_Request extends DD_Maintenance_Request {
	public static function from_globals(): DD_Maintenance_Restore_Request {
		$data = parent::captured_globals();
		return new self( $data[0], $data[1], $data[2] );
	}
}
class DD_Maintenance_Artifact_Request extends DD_Maintenance_Request {
	public static function from_globals(): DD_Maintenance_Artifact_Request {
		$data = parent::captured_globals();
		return new self( $data[0], $data[1], $data[2] );
	}
}
