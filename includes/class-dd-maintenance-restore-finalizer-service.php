<?php
/** Serviço de pós-processamento e finalização do restore. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Finalizer_Service {
	private $restore;
	public function __construct( DD_Maintenance_Restore_Implementation $restore ) { $this->restore = $restore; }
	/** @return array|WP_Error */
	public function run( string $session_id ) {
		if ( '' === $session_id ) { return new WP_Error( 'session_id_missing', __( 'Sessão de restore ausente.', 'dd-maintenance' ) ); }
		return $this->restore->finalize_restore_step( $session_id );
	}
}
