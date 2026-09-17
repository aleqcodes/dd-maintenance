<?php
/** Handler das ações administrativas de backup. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_Backup_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation();
	}
	public function handle_backup() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_run_backup' );
		return $this->settings->handle_backup();
	}
	public function ajax_handle_action() { return $this->settings->ajax_handle_action(); }
}
