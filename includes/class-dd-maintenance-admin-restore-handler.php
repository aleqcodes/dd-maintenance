<?php
/** Handler das ações administrativas de restauração. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}


class DD_Maintenance_Admin_Restore_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	private $downloads;

	public function __construct( $settings = null, $downloads = null ) {
		$this->settings  = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation();
		$this->downloads = $downloads instanceof DD_Maintenance_Admin_Download_Handler ? $downloads : new DD_Maintenance_Admin_Download_Handler( $this->settings );
	}
	public function handle_restore_upload() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_restore_upload' );
		return $this->settings->handle_restore_upload( DD_Maintenance_Restore_Request::from_globals() );
	}
	public function handle_restore_local() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_restore_local' );
		return $this->settings->handle_restore_local( DD_Maintenance_Restore_Request::from_globals() );
	}
	public function handle_delete_backup() { return $this->downloads->handle_delete_backup(); }
	public function handle_download_backup() { return $this->downloads->handle_download_backup(); }
	public function ajax_handle_restore() { return $this->settings->ajax_handle_restore( DD_Maintenance_Restore_Request::from_globals() ); }
}
