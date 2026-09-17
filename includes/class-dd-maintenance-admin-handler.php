<?php
/**
 * Roteador de ações administrativas por responsabilidade.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Admin_Handler {
	private $settings;
	private $general;
	private $backup;
	private $config;
	private $logs;
	private $s3;
	private $downloads;

	public function __construct( $settings, $general, $backup, $config, $logs, $s3, $downloads ) {
		$this->settings  = $settings;
		$this->general   = $general;
		$this->backup    = $backup;
		$this->config    = $config;
		$this->logs      = $logs;
		$this->s3        = $s3;
		$this->downloads = $downloads;
	}

	public function save_settings() { return $this->settings->save_settings(); }
	public function handle_plugins() { return $this->general->handle_plugins(); }
	public function handle_core() { return $this->general->handle_core(); }
	public function handle_full() { return $this->general->handle_full(); }
	public function handle_config_action() { return $this->config->handle_config_action(); }
	public function handle_clear_log() { return $this->logs->handle_clear_log(); }
	public function handle_delete_log() { return $this->logs->handle_delete_log(); }
	public function handle_download_log() { return $this->logs->handle_download_log(); }
	public function handle_delete_s3_object() { return $this->s3->handle_delete_s3_object(); }
	public function handle_delete_s3_backup() { return $this->s3->handle_delete_s3_backup(); }
}
