<?php
/**
 * Regras comuns de finalização e validação do banco restaurado.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-restore-database-adapter.php';
require_once __DIR__ . '/class-dd-maintenance-restore-query-executor.php';

final class DD_Maintenance_Restore_Database_Finalizer {
	/**
	 * Executa as instruções de restauração específicas do driver.
	 *
	 * @return true|WP_Error
	 */
	public function restore_connection( DD_Maintenance_Restore_Query_Executor $executor ) {
		$database = $executor->database();
		$queries  = $database->uses_mysqli()
			? array( 'COMMIT;', 'SET AUTOCOMMIT = 1;', 'SET FOREIGN_KEY_CHECKS = 1;', 'SET UNIQUE_CHECKS = 1;' )
			: array( 'SET FOREIGN_KEY_CHECKS = 1;' );
		foreach ( $queries as $query ) {
			$result = $executor->execute_required( $query, 'restore_db_finalize_failed', __( 'Não foi possível finalizar a conexão do banco restaurado.', 'dd-maintenance' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	public static function validate_prefix( string $prefix ): bool {
		return '' !== $prefix && (bool) preg_match( '/^[A-Za-z0-9_]+$/', $prefix );
	}

	/** @return array|WP_Error */
	public function discover_options_table( DD_Maintenance_Restore_Database_Adapter $database, string $fallback_prefix ) {
		if ( ! self::validate_prefix( $fallback_prefix ) ) {
			return new WP_Error( 'restore_prefix_invalid', __( 'O prefixo atual do banco contém caracteres inválidos.', 'dd-maintenance' ) );
		}
		$tables = $database->get_col( "SHOW TABLES LIKE '%options'" );
		if ( ! is_array( $tables ) ) {
			return array( 'prefix' => '', 'table' => '' );
		}
		foreach ( $tables as $table ) {
			if ( is_string( $table ) && preg_match( '/^([A-Za-z0-9_]+)options$/', $table, $matches ) && self::validate_prefix( $matches[1] ) ) {
				return array( 'prefix' => $matches[1], 'table' => $table );
			}
		}
		return array( 'prefix' => '', 'table' => '' );
	}
}
