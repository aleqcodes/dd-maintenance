<?php
/**
 * Leitura e autorização de requests administrativos.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Admin_Request {
	/** Valida a capacidade e o nonce do endpoint antes da aplicação. */
	public static function authorize( string $nonce_action = '' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		if ( '' !== $nonce_action ) {
			check_admin_referer( $nonce_action );
		}
	}

	/** @return string */
	public static function post_text( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/** @return string */
	public static function post_key( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/** @return string */
	public static function get_text( string $key, string $default = '' ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
	}

	/** @return string */
	public static function get_key( string $key, string $default = '' ): string {
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : $default;
	}

	/** @return bool */
	public static function post_flag( string $key ): bool {
		return ! empty( $_POST[ $key ] );
	}
}
