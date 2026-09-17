<?php
/** Handler de downloads e exclusão de backups. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_Download_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation( false );
	}
	public function handle_delete_backup() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_delete_backup' );
		return $this->settings->handle_delete_backup();
	}
	public function handle_download_backup() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_download_backup' );
		return $this->settings->handle_download_backup();
	}
}
