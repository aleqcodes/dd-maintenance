<?php
/**
 * Registra as ações administrativas de restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-dd-maintenance-legacy-compatibility.php';

class DD_Maintenance_Restore_Action_Controller {

	private $settings;

	/**
	 * @param DD_Maintenance_Settings $settings Fachada administrativa.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra ações novas e aliases legados de restauração e downloads locais.
	 */
	public function register(): void {
		$actions = array(
			'admin_post_dd_maintenance_restore_upload'   => 'handle_restore_upload',
			'admin_post_dd_maintenance_restore_local'    => 'handle_restore_local',
			'admin_post_dd_maintenance_delete_backup'    => 'handle_delete_backup',
			'admin_post_dd_maintenance_download_backup'  => 'handle_download_backup',
			'wp_ajax_dd_maintenance_ajax_restore'         => 'ajax_handle_restore',
			'wp_ajax_nopriv_dd_maintenance_ajax_restore' => 'ajax_handle_restore',
			'admin_post_backuper_download_backup'         => 'handle_download_backup',
		);

		foreach ( $actions as $hook => $method ) {
			$callback = function () use ( $method ) {
				return call_user_func_array( array( $this->settings, $method ), func_get_args() );
			};
			if ( 0 === strpos( $hook, 'admin_post_backuper_' ) ) {
				DD_Maintenance_Legacy_Compatibility::register_legacy_hook( $hook, $callback );
				continue;
			}
			add_action( $hook, $callback );
		}
	}
}
