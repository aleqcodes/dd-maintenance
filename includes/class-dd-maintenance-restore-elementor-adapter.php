<?php
/**
 * Adaptador isolado para pós-processamento Elementor durante restore.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Restore_Elementor_Adapter {
	/** @return string[] */
	public static function ensure_active_kit( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array {
		return DD_Maintenance_Restore_Implementation::ensure_elementor_active_kit( $dump_prefix, $database );
	}

	/** @return string[] */
	public static function rebuild_conditions( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array {
		return DD_Maintenance_Restore_Implementation::rebuild_elementor_theme_builder_conditions( $dump_prefix, $database );
	}

	/** @return string[] */
	public static function clear_cache( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null, array $event_context = array() ): array {
		return DD_Maintenance_Restore_Implementation::clear_elementor_cache( $dump_prefix, $database, $event_context );
	}

	/** @return mixed */
	public static function fix_dynamic_tags( $content ) {
		return DD_Maintenance_Restore_Implementation::fix_elementor_dynamic_tags( $content );
	}

	/** @return string */
	public static function fix_dynamic_tags_string( string $content ): string {
		return DD_Maintenance_Restore_Implementation::fix_elementor_dynamic_tags_string( $content );
	}
}
