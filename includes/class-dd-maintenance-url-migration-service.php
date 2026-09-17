<?php
/**
 * Serviço de migração de URLs em estruturas WordPress e Elementor.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Url_Migration_Service {
	/** @return array */
	public static function replacement_map( string $from_url, string $to_url ): array {
		return DD_Maintenance_Restore_Implementation::build_url_replacement_map( $from_url, $to_url );
	}

	/** @return mixed */
	public static function replace( $from, $to, $data, bool $was_serialized = false ) {
		return DD_Maintenance_Restore_Implementation::recursive_search_replace( $from, $to, $data, $was_serialized );
	}
}
