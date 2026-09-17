<?php
/** Handler de ações administrativas gerais. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_General_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation( false );
	}
	public function handle_plugins() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_update_plugins' );
		return $this->settings->handle_plugins();
	}
	public function handle_core() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_update_core' );
		return $this->settings->handle_core();
	}
	public function handle_full() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_run_full' );
		return $this->settings->handle_full();
	}
}
