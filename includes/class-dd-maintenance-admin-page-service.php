<?php
/**
 * Serviço de composição da página administrativa.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Settings_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-settings-implementation.php';
}

class DD_Maintenance_Admin_Page_Service {
	/** @var DD_Maintenance_Settings_Implementation */
	private $settings;

	/** @param DD_Maintenance_Settings_Implementation|null $settings Implementação administrativa. */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof DD_Maintenance_Settings_Implementation ? $settings : new DD_Maintenance_Settings_Implementation();
	}

	public function page_url( $tab = '' ): string { return $this->settings->page_url( $tab ); }
	public function register_menu() { return $this->settings->register_menu(); }
	public function render() { return $this->settings->render_page(); }
	public function show_notice() { return $this->settings->show_notice(); }
	public function legacy_redirects() { return $this->settings->handle_legacy_redirects(); }
}
