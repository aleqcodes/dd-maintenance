<?php
/**
 * Registra as ações administrativas de backup.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-dd-maintenance-legacy-compatibility.php';

class DD_Maintenance_Backup_Action_Controller {

	private $settings;

	/**
	 * @param DD_Maintenance_Settings $settings Fachada administrativa.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra ações novas e aliases legados de backup.
	 */
	public function register(): void {
		$actions = array(
			'admin_post_dd_maintenance_run_backup' => 'handle_backup',
			'admin_post_backuper_run_backup'       => 'handle_backup',
			'wp_ajax_dd_maintenance_ajax_action'  => 'ajax_handle_action',
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
