<?php
/** Serviço de limpeza dos artefatos de backup. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Backup_Cleanup_Service {
	private $backup;
	public function __construct( DD_Maintenance_Backup $backup ) { $this->backup = $backup; }
	/** @return true|WP_Error */
	public function completed( string $session_id ) {
		if ( '' === $session_id ) {
			return new WP_Error( 'session_id_missing', __( 'Sessão de backup ausente.', 'dd-maintenance' ) );
		}
		return $this->backup->legacy_cleanup_session_step( $session_id );
	}
	/** @return array */
	public function failed( string $session_id, string $message = '', array $log = array() ): array {
		if ( '' === $session_id ) {
			return array( 'cleaned' => false, 'log' => $log, 'errors' => array( 'session_id_missing' ) );
		}
		return $this->backup->legacy_cleanup_failed_session( $session_id, $message, $log );
	}
}
