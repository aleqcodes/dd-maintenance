<?php
/** Handler das operações protegidas de wp-config.php. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_Config_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation();
	}
	public function handle_config_action() {
		DD_Maintenance_Admin_Request::authorize();
		return $this->settings->handle_config_action( DD_Maintenance_Settings_Request::from_globals() );
	}
}
