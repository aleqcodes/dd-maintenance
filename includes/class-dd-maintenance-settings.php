<?php
/**
 * Fachada administrativa compatível e composição dos handlers.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
require_once __DIR__ . '/class-dd-maintenance-admin-request.php';
require_once __DIR__ . '/class-dd-maintenance-settings-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-general-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-backup-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-download-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-restore-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-log-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-s3-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-config-handler.php';
require_once __DIR__ . '/class-dd-maintenance-admin-page-service.php';

class DD_Maintenance_Settings {
	/** @var DD_Maintenance_Settings_Implementation */
	private $implementation;
	/** @var DD_Maintenance_Admin_Page_Service */
	private $page_service;
	/** @var DD_Maintenance_Settings_Handler */
	private $settings_handler;
	/** @var DD_Maintenance_Admin_Backup_Handler */
	private $backup_handler;
	/** @var DD_Maintenance_Admin_Restore_Handler */
	private $restore_handler;
	/** @var DD_Maintenance_Admin_Download_Handler */
	private $download_handler;
	/** @var DD_Maintenance_Admin_Log_Handler */
	private $log_handler;
	/** @var DD_Maintenance_Admin_S3_Handler */
	private $s3_handler;
	/** @var DD_Maintenance_Admin_Config_Handler */
	private $config_handler;
	/** @var DD_Maintenance_Admin_General_Handler */
	private $general_handler;

	/** @param DD_Maintenance_Settings_Implementation|null $implementation Implementação administrativa. */
	public function __construct( $implementation = null ) {
		$this->implementation  = $implementation instanceof DD_Maintenance_Settings_Implementation ? $implementation : new DD_Maintenance_Settings_Implementation();
		$this->page_service    = new DD_Maintenance_Admin_Page_Service( $this->implementation );
		$this->settings_handler = new DD_Maintenance_Settings_Handler( $this->implementation );
		$this->general_handler   = new DD_Maintenance_Admin_General_Handler( $this->implementation );
		$this->backup_handler    = new DD_Maintenance_Admin_Backup_Handler( $this->implementation );
		$this->download_handler  = new DD_Maintenance_Admin_Download_Handler( $this->implementation );
		$this->log_handler       = new DD_Maintenance_Admin_Log_Handler( $this->implementation );
		$this->s3_handler        = new DD_Maintenance_Admin_S3_Handler( $this->implementation );
		$this->config_handler    = new DD_Maintenance_Admin_Config_Handler( $this->implementation );
		$this->restore_handler   = new DD_Maintenance_Admin_Restore_Handler( $this->implementation, $this->download_handler );

		( new DD_Maintenance_Admin_Action_Controller( $this ) )->register();
		( new DD_Maintenance_Backup_Action_Controller( $this->backup_handler ) )->register();
		( new DD_Maintenance_Restore_Action_Controller( $this->restore_handler ) )->register();
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_redirects' ) );
		add_action( 'admin_notices', array( $this, 'show_notice' ) );
	}
	public function handle_legacy_redirects() { return $this->page_service->legacy_redirects(); }
	public function page_url( $tab = '' ) { return $this->page_service->page_url( $tab ); }
	public function register_menu() { return $this->page_service->register_menu(); }
	public function render_page() { return $this->page_service->render(); }
	public function show_notice() { return $this->page_service->show_notice(); }
	public function save_settings() { return $this->settings_handler->save_settings(); }
	public function handle_plugins() { return $this->general_handler->handle_plugins(); }
	public function handle_core() { return $this->general_handler->handle_core(); }
	public function handle_full() { return $this->general_handler->handle_full(); }
	public function handle_config_action() { return $this->config_handler->handle_config_action(); }
	public function handle_backup() { return $this->backup_handler->handle_backup(); }
	public static function get_download_url( string $filename ): string { return DD_Maintenance_Settings_Implementation::get_download_url( $filename ); }
	public function ajax_handle_action() { return $this->backup_handler->ajax_handle_action(); }
	public function handle_restore_upload() { return $this->restore_handler->handle_restore_upload(); }
	public function handle_restore_local() { return $this->restore_handler->handle_restore_local(); }
	public function handle_delete_backup() { return $this->download_handler->handle_delete_backup(); }
	public function handle_download_backup() { return $this->download_handler->handle_download_backup(); }
	public function handle_clear_log() { return $this->log_handler->handle_clear_log(); }
	public function handle_delete_log() { return $this->log_handler->handle_delete_log(); }
	public function handle_download_log() { return $this->log_handler->handle_download_log(); }
	public function handle_delete_s3_object() { return $this->s3_handler->handle_delete_s3_object(); }
	public function handle_delete_s3_backup() { return $this->s3_handler->handle_delete_s3_backup(); }
	public function ajax_handle_restore() { return $this->restore_handler->ajax_handle_restore(); }
}
