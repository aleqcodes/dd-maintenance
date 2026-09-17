<?php
/**
 * Serviço de importação incremental e finalização do banco restaurado.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Restore_Database_Service {
	/** @var DD_Maintenance_Restore_Implementation */
	private $restore;

	/** @param DD_Maintenance_Restore_Implementation|null $restore Implementação compatível. */
	public function __construct( $restore = null ) {
		$this->restore = $restore instanceof DD_Maintenance_Restore_Implementation ? $restore : new DD_Maintenance_Restore_Implementation();
	}

	/** @return array|WP_Error */
	public function step( string $session_id, float $time_limit_seconds = 7.0 ) {
		return $this->restore->restore_database_step( $session_id, $time_limit_seconds );
	}

	/** @return array|WP_Error */
	public function restore( string $sql_file ) {
		return $this->restore->restore_database( $sql_file );
	}
}
