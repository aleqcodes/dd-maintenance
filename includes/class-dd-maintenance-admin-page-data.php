<?php
/**
 * Dados calculados para renderização administrativa.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-settings-repository.php';
require_once __DIR__ . '/class-dd-maintenance-s3.php';

final class DD_Maintenance_Admin_Page_Data {
	private $settings_repository;
	private $s3;

	public function __construct( $settings_repository = null, $s3 = null ) {
		$this->settings_repository = $settings_repository instanceof DD_Maintenance_Settings_Repository ? $settings_repository : new DD_Maintenance_Settings_Repository();
		$this->s3                  = $s3 instanceof DD_Maintenance_S3 ? $s3 : new DD_Maintenance_S3();
	}

	/** @return array */
	public function for_tab( string $tab ): array {
		$settings = $this->settings_repository->get();
		$settings['s3_secret_key'] = '';
		$data = array( 'settings' => $settings );
		switch ( $tab ) {
			case 'config':
				$data['config_status'] = DD_Maintenance_Config::get_wp_config_status();
				$data['has_password']  = DD_Maintenance_Config::has_password();
				break;
			case 's3':
				$data['s3']              = $this->s3;
				$data['s3_configured']   = $this->s3->is_configured();
				$data['split_size_mb']   = $this->settings_repository->get_split_size_mb( $settings );
				$data['remote_backups']  = $this->remote_backups( $data['s3_configured'] );
				break;
			case 'cron':
				$data['next_cron']      = $this->next_cron();
				$data['split_size_mb']  = $this->settings_repository->get_split_size_mb( $settings );
				break;
			case 'restore':
				$data['local_backups']  = DD_Maintenance_Restore::get_local_backups();
				$data['max_upload']     = function_exists( 'wp_max_upload_size' ) ? size_format( wp_max_upload_size() ) : '';
				$data['s3']             = $this->s3;
				$data['s3_configured']  = $this->s3->is_configured();
				$data['has_password']  = DD_Maintenance_Config::has_password();
				break;
			case 'logs':
				$data['last_log']          = $this->last_log();
				$data['saved_logs']        = DD_Maintenance::get_saved_logs();
				$data['last_event']        = DD_Maintenance::get_last_event();
				$data['event_summary']     = ! empty( $data['last_event'] ) ? DD_Maintenance_Observability::summary( $data['last_event'] ) : array();
				break;
			case 'general':
			default:
				$data['s3']              = $this->s3;
				$data['s3_configured']   = $this->s3->is_configured();
				$data['config_status']   = DD_Maintenance_Config::get_wp_config_status();
				$data['last_log']        = $this->last_log();
				$data['local_backups']   = DD_Maintenance_Restore::get_local_backups();
				$data['next_cron']       = $this->next_cron();
				break;
		}
		return $data;
	}

	private function last_log() {
		$last_log = get_transient( 'dd_maintenance_last_log' );
		if ( empty( $last_log ) ) {
			$last_log = get_transient( 'backuper_last_log' );
			if ( ! empty( $last_log ) && class_exists( 'DD_Maintenance_Legacy_Compatibility' ) ) {
				DD_Maintenance_Legacy_Compatibility::record_usage( 'transient_backuper_last_log' );
			}
		}
		return $last_log;
	}

	private function next_cron() {
		$next = wp_next_scheduled( 'dd_maintenance_daily_maintenance' );
		return $next ? $next : wp_next_scheduled( 'backuper_daily_maintenance' );
	}

	/** @return array|WP_Error */
	private function remote_backups( bool $configured ) {
		if ( ! $configured ) {
			return array();
		}
		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		return $this->s3->get_remote_backups( $site_slug ? $site_slug : 'site' );
	}
}
