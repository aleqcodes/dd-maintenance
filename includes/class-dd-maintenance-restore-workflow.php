<?php
/**
 * Caso de uso compartilhado para restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore.php';
}
if ( ! class_exists( 'DD_Maintenance_Restore_Result' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-result.php';
}

class DD_Maintenance_Restore_Workflow {

	private $restore;

	/**
	 * @param DD_Maintenance_Restore|null $restore Serviço de restauração.
	 */
	public function __construct( $restore = null ) {
		$this->restore = $restore instanceof DD_Maintenance_Restore ? $restore : new DD_Maintenance_Restore();
	}

	/**
	 * Restaura um upload e normaliza o resultado final.
	 */
	public function from_upload( array $file_input, bool $apply_elementor_compatibility = false ) {
		$result = $this->restore->restore_from_upload( $file_input, $apply_elementor_compatibility );
		return is_wp_error( $result ) ? $result : DD_Maintenance_Restore_Result::from_array( $result );
	}

	/**
	 * Restaura um backup local e normaliza o resultado final.
	 */
	public function from_local( string $identifier, bool $apply_elementor_compatibility = false ) {
		$result = $this->restore->restore_from_local_file( $identifier, $apply_elementor_compatibility );
		return is_wp_error( $result ) ? $result : DD_Maintenance_Restore_Result::from_array( $result );
	}

	/**
	 * Inicializa uma sessão incremental.
	 *
	 * @param array  $zip_paths       Volumes.
	 * @param string $temp_upload_dir Diretório temporário.
	 * @return array|WP_Error
	 */
	public function initialize( array $zip_paths, string $temp_upload_dir = '', bool $apply_elementor_compatibility = false ) {
		return $this->restore->init_restore_session( $zip_paths, $temp_upload_dir, $apply_elementor_compatibility );
	}

	/**
	 * @param string $session_id Sessão.
	 * @param int    $batch_limit Limite de volumes.
	 * @return array|WP_Error
	 */
	public function extract( string $session_id, int $batch_limit = 10 ) {
		return $this->restore->extract_volume_step( $session_id, $batch_limit );
	}

	/**
	 * @param string $session_id Sessão.
	 * @return array|WP_Error
	 */
	public function database( string $session_id ) {
		return $this->restore->restore_database_step( $session_id );
	}

	/**
	 * @param string $session_id Sessão.
	 * @return array|WP_Error
	 */
	public function files( string $session_id ) {
		return $this->restore->restore_files_step( $session_id );
	}

	/**
	 * @param string $session_id Sessão.
	 * @return DD_Maintenance_Restore_Result|WP_Error
	 */
	public function finalize( string $session_id ) {
		$result = $this->restore->finalize_restore_step( $session_id );
		return is_wp_error( $result ) ? $result : DD_Maintenance_Restore_Result::from_array( $result );
	}

	/**
	 * @param string $session_id Sessão.
	 * @param string $token Token efêmero.
	 * @return bool
	 */
	public function verify_token( string $session_id, string $token ): bool {
		return $this->restore->verify_restore_token( $session_id, $token );
	}

	/**
	 * @param string $session_id Sessão.
	 * @return void
	 */
	public function cleanup_failed( string $session_id ): void {
		$this->restore->cleanup_failed_restore( $session_id );
	}

	/**
	 * @return DD_Maintenance_Restore
	 */
	public function restore_service(): DD_Maintenance_Restore {
		return $this->restore;
	}
}
