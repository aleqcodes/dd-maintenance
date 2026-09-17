<?php
/** Serviço de importação incremental do banco restaurado. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Database_Importer {
	private $restore;
	public function __construct( DD_Maintenance_Restore_Implementation $restore ) { $this->restore = $restore; }
	/** @return array|WP_Error */
	public function step( string $session_id, float $time_limit_seconds = 7.0 ) {
		if ( '' === $session_id ) { return new WP_Error( 'session_id_missing', __( 'Sessão de restore ausente.', 'dd-maintenance' ) ); }
		return $this->restore->restore_database_step( $session_id, $time_limit_seconds );
	}
	/** @return array|WP_Error */
	public function import( string $sql_file ) { return $this->restore->restore_database( $sql_file ); }
}
