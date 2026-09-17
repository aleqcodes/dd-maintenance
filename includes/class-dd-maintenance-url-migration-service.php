<?php
/**
 * Serviço de migração de URLs em estruturas WordPress e Elementor.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-restore-url-migrator.php';

class DD_Maintenance_Url_Migration_Service {
	/** @return array */
	public static function replacement_map( string $from_url, string $to_url ): array {
		return ( new DD_Maintenance_Restore_Url_Migrator() )->replacement_map( $from_url, $to_url );
	}

	/** @return mixed */
	public static function replace( $from, $to, $data, bool $was_serialized = false ) {
		return ( new DD_Maintenance_Restore_Url_Migrator() )->replace( $from, $to, $data, $was_serialized );
	}
}
