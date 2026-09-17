<?php
/**
 * Fronteira explícita para migração de URLs durante o restore.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Url_Migrator {
	/** @return array */
	public function replacement_map( string $from_url, string $to_url ): array {
		return DD_Maintenance_Restore_Implementation::build_url_replacement_map( $from_url, $to_url );
	}

	/** @return mixed */
	public function replace( $from, $to, $data, bool $was_serialized = false ) {
		return DD_Maintenance_Restore_Implementation::recursive_search_replace( $from, $to, $data, $was_serialized );
	}

	/** @return string[] */
	public function warnings( DD_Maintenance_Restore_Implementation $restore, string $from_url, string $to_url, string $prefix, DD_Maintenance_Restore_Database_Adapter $database ): array {
		return $restore->migrate_urls( $from_url, $to_url, $prefix, $database );
	}
}
