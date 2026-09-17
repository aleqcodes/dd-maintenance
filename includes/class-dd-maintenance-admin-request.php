<?php
/**
 * Leitura e autorização de requests administrativos.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Admin_Request {
	/**
	 * Política declarativa das entradas administrativas e AJAX.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const POLICIES = array(
		'dd_maintenance_save_settings'     => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_save_settings', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_update_plugins'   => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_update_plugins', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_update_core'      => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_update_core', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_run_full'         => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_run_full', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_config_action'    => array( 'capability' => 'manage_options', 'nonce' => '', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_run_backup'       => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_run_backup', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_restore_upload'   => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_restore_upload', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_restore_local'    => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_restore_local', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_delete_backup'    => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_delete_backup', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_download_backup'  => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_download_backup', 'method' => 'GET', 'public' => false, 'rollback_blocked' => false, 'response' => 'stream' ),
		'dd_maintenance_clear_log'        => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_clear_log', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_delete_log'       => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_delete_log', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_download_log'     => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_download_log', 'method' => 'GET', 'public' => false, 'rollback_blocked' => false, 'response' => 'stream' ),
		'dd_maintenance_delete_s3_object' => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_delete_s3_object', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_delete_s3_backup' => array( 'capability' => 'manage_options', 'nonce' => 'dd_maintenance_delete_s3_backup', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'redirect' ),
		'dd_maintenance_ajax_action'      => array( 'capability' => 'manage_options', 'nonce' => 'dd_maint_ajax_nonce', 'method' => 'POST', 'public' => false, 'rollback_blocked' => true, 'response' => 'json' ),
		'dd_maintenance_ajax_restore'     => array( 'capability' => 'manage_options', 'nonce' => 'dd_maint_ajax_nonce', 'method' => 'POST', 'public' => true, 'rollback_blocked' => true, 'response' => 'json' ),
	);

	/** Retorna a política declarada de uma entrada. */
	public static function policy( string $action ): array {
		return self::POLICIES[ $action ] ?? array(
			'capability'      => 'manage_options',
			'nonce'           => '',
			'method'          => '',
			'public'          => false,
			'rollback_blocked' => true,
			'response'        => 'unknown',
		);
	}

	/** Valida a capacidade, o método e o nonce do endpoint antes da aplicação. */
	public static function authorize( string $nonce_action = '' ): void {
		$policy = self::policy( $nonce_action );
		if ( ! current_user_can( $policy['capability'] ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( '' !== $policy['method'] && '' !== $method && $policy['method'] !== $method ) {
			wp_die( esc_html__( 'Método de requisição não permitido.', 'dd-maintenance' ) );
		}
		$required_nonce = '' !== $nonce_action ? (string) $policy['nonce'] : '';
		if ( '' !== $required_nonce ) {
			check_admin_referer( $required_nonce );
		}
	}
	/**
	 * Valida a sessão administrativa, método e nonce de uma entrada AJAX.
	 *
	 * @return bool
	 */
	public static function authorize_ajax( string $action ): bool {
		$policy = self::policy( $action );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( '' !== $policy['method'] && '' !== $method && $policy['method'] !== $method ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( $policy['capability'] ) ) {
			return false;
		}
		return '' !== $policy['nonce'] && check_ajax_referer( (string) $policy['nonce'], 'nonce', false );
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
