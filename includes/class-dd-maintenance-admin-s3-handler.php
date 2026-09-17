<?php
/** Handler de operações administrativas S3. */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_S3_Handler {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation( false );
	}
	public function handle_delete_s3_object() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_delete_s3_object' );
		return $this->settings->handle_delete_s3_object();
	}
	public function handle_delete_s3_backup() {
		DD_Maintenance_Admin_Request::authorize( 'dd_maintenance_delete_s3_backup' );
		return $this->settings->handle_delete_s3_backup();
	}
}
