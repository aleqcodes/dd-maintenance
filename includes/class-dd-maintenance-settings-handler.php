<?php
/**
 * Handler de configurações administrativas.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Settings_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;

	/** @param DD_Maintenance_Settings_Implementation|null $settings Implementação administrativa. */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation( false );
	}

	public function save_settings() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_save_settings' );
		return $this->settings->save_settings();
	}
}
