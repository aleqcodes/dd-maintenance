<?php
/** Serviço de limpeza de artefatos temporários do restore. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Cleanup_Service {
	private $restore;
	public function __construct( DD_Maintenance_Restore_Implementation $restore ) { $this->restore = $restore; }
	/** @return array */
	public function failed( string $session_id ): array {
		if ( '' === $session_id ) { return array( 'cleaned' => false, 'errors' => array( 'session_id_missing' ) ); }
		return $this->restore->cleanup_failed_restore( $session_id );
	}
}
