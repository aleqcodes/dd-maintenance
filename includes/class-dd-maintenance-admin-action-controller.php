<?php
/**
 * Registra as ações administrativas e delega sua execução.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Admin_Action_Controller {

	private $settings;

	/**
	 * @param DD_Maintenance_Settings $settings Fachada administrativa.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra ações novas, AJAX e aliases legados sem duplicar regras de negócio.
	 */
	public function register(): void {
		$actions = array(
			'admin_post_dd_maintenance_save_settings'     => 'save_settings',
			'admin_post_dd_maintenance_update_plugins'   => 'handle_plugins',
			'admin_post_dd_maintenance_update_core'      => 'handle_core',
			'admin_post_dd_maintenance_run_full'         => 'handle_full',
			'admin_post_dd_maintenance_config_action'    => 'handle_config_action',
			'admin_post_dd_maintenance_clear_log'        => 'handle_clear_log',
			'admin_post_dd_maintenance_delete_log'       => 'handle_delete_log',
			'admin_post_dd_maintenance_download_log'     => 'handle_download_log',
			'admin_post_dd_maintenance_delete_s3_object' => 'handle_delete_s3_object',
			'admin_post_dd_maintenance_delete_s3_backup'  => 'handle_delete_s3_backup',
			'admin_post_backuper_save_settings'           => 'save_settings',
			'admin_post_backuper_update_plugins'          => 'handle_plugins',
			'admin_post_backuper_update_core'             => 'handle_core',
			'admin_post_backuper_run_full'                => 'handle_full',
		);

		foreach ( $actions as $hook => $method ) {
			add_action(
				$hook,
				function () use ( $method ) {
					return call_user_func_array( array( $this->settings, $method ), func_get_args() );
				}
			);
		}
	}
}
