<?php
/** Serviço de escrita incremental dos volumes ZIP. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Backup_Archive_Writer {
	private $backup;
	public function __construct( DD_Maintenance_Backup $backup ) { $this->backup = $backup; }
	/** @return array|WP_Error */
	public function run( string $session_id ) {
		if ( '' === $session_id ) {
			return new WP_Error( 'session_id_missing', __( 'Sessão de backup ausente.', 'dd-maintenance' ) );
		}
		return $this->backup->legacy_zip_batch_step( $session_id );
	}
}
