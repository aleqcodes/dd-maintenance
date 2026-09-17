<?php
/** Handler de limpeza, remoção e download de logs. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_Log_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation();
	}
	public function handle_clear_log() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_clear_log' );
		return $this->settings->handle_clear_log();
	}
	public function handle_delete_log() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_delete_log' );
		return $this->settings->handle_delete_log( DD_Maintenance_Artifact_Request::from_globals() );
	}
	public function handle_download_log() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_download_log' );
		return $this->settings->handle_download_log( DD_Maintenance_Artifact_Request::from_globals() );
	}
}
