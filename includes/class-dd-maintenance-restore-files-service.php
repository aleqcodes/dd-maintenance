<?php
/**
 * Serviço de restauração de arquivos e progresso de cópia.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Restore_Files_Service {
	/** @var DD_Maintenance_Restore_Implementation */
	private $restore;

	/** @param DD_Maintenance_Restore_Implementation|null $restore Implementação compatível. */
	public function __construct( $restore = null ) {
		$this->restore = $restore instanceof DD_Maintenance_Restore_Implementation ? $restore : new DD_Maintenance_Restore_Implementation();
	}

	/** @return array|WP_Error */
	public function extract_volume_step( string $session_id, int $batch_limit = 10 ) {
		return $this->restore->extract_volume_step( $session_id, $batch_limit );
	}

	/** @return array|WP_Error */
	public function step( string $session_id ) {
		return $this->restore->restore_files_step( $session_id );
	}

	/** @return array|WP_Error */
	public function restore( string $extract_dir ) {
		return $this->restore->restore_files( $extract_dir );
	}

	/** @return int|WP_Error */
	public function copy_directory( string $source_dir, string $dest_dir, array $ignore_paths = array() ) {
		return $this->restore->copy_directory( $source_dir, $dest_dir, $ignore_paths );
	}
}
