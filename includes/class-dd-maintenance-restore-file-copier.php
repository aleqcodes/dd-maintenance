<?php
/** Serviço de cópia segura e progresso de arquivos restaurados. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_File_Copier {
	private $restore;
	public function __construct( DD_Maintenance_Restore_Implementation $restore ) { $this->restore = $restore; }
	/** @return array|WP_Error */
	public function step( string $session_id ) {
		if ( '' === $session_id ) { return new WP_Error( 'session_id_missing', __( 'Sessão de restore ausente.', 'dd-maintenance' ) ); }
		return $this->restore->restore_files_step( $session_id );
	}
	/** @return int|WP_Error */
	public function copy( string $source_dir, string $dest_dir, array $ignore_paths = array() ) {
		return $this->restore->copy_directory( $source_dir, $dest_dir, $ignore_paths );
	}
}
