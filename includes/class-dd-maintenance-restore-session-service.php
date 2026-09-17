<?php
/**
 * Serviço de ciclo de vida e persistência das sessões de restore.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Restore_Session_Service {
	/** @var DD_Maintenance_Restore_Implementation */
	private $restore;

	/** @param DD_Maintenance_Restore_Implementation|null $restore Implementação compatível. */
	public function __construct( $restore = null ) {
		$this->restore = $restore instanceof DD_Maintenance_Restore_Implementation ? $restore : new DD_Maintenance_Restore_Implementation();
	}

	/** @return array|WP_Error */
	public function initialize( array $zip_paths, string $temp_upload_dir = '', bool $apply_elementor_compatibility = false, string $correlation_id = '' ) {
		return $this->restore->init_restore_session( $zip_paths, $temp_upload_dir, $apply_elementor_compatibility, $correlation_id );
	}

	/** @return array|WP_Error */
	public function get( string $session_id ) {
		return $this->restore->get_restore_session_data( $session_id );
	}

	/** @return bool */
	public function verify_token( string $session_id, string $token ): bool {
		return $this->restore->verify_restore_token( $session_id, $token );
	}

	/** @return bool */
	public function save( string $extract_dir, array $session ): bool {
		return $this->restore->save_restore_session_data( $extract_dir, $session );
	}

	/** @return DD_Maintenance_Restore_Implementation */
	public function implementation(): DD_Maintenance_Restore_Implementation {
		return $this->restore;
	}
}
