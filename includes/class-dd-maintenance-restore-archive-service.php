<?php
/** Orquestra o restore completo por volumes, banco, arquivos e finalização. @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Archive_Service {
	private $restore;
	public function __construct( DD_Maintenance_Restore_Implementation $restore ) { $this->restore = $restore; }

	/** @return array|WP_Error */
	public function run( array $zip_paths, bool $apply_elementor_compatibility = false ) {
		$session = $this->restore->init_restore_session( $zip_paths, '', $apply_elementor_compatibility );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$session_id = $session['session_id'];
		$extract = $this->repeat( function () use ( $session_id ) { return $this->restore->extract_volume_step( $session_id, 10 ); } );
		if ( is_wp_error( $extract ) ) { return $this->failed( $session_id, $extract ); }
		$database = $this->repeat( function () use ( $session_id ) { return $this->restore->restore_database_step( $session_id ); } );
		if ( is_wp_error( $database ) ) { return $this->failed( $session_id, $database ); }
		$files = $this->repeat( function () use ( $session_id ) { return $this->restore->restore_files_step( $session_id ); } );
		if ( is_wp_error( $files ) ) { return $this->failed( $session_id, $files ); }
		$final = $this->restore->finalize_restore_step( $session_id );
		$correlation_id = $session['correlation_id'] ?? '';
		if ( is_wp_error( $final ) ) {
			DD_Maintenance::record_event( 'restore', 'operation_failed', array( 'step' => 'restore_finalize', 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $final->get_error_code(), 'error_count' => 1 ) );
		} else {
			$warnings = is_array( $final['warnings'] ?? null ) ? $final['warnings'] : array();
			DD_Maintenance::record_event( 'restore', 'operation_finished', array( 'step' => 'restore_finalize', 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => empty( $warnings ) ? 'success' : 'warning', 'progress' => 100, 'error_count' => count( $warnings ), 'warning_count' => count( $warnings ), 'failure_code' => empty( $warnings ) ? '' : 'restore_warnings' ) );
		}
		return $final;
	}

	/** @param callable $step @return array|WP_Error */
	private function repeat( callable $step ) {
		do { $result = call_user_func( $step ); } while ( ! is_wp_error( $result ) && empty( $result['completed'] ) );
		return $result;
	}

	/** @return array|WP_Error */
	private function failed( string $session_id, WP_Error $error ) {
		$cleanup = $this->restore->cleanup_failed_restore( $session_id );
		if ( empty( $cleanup['errors'] ) ) { return $error; }
		return new WP_Error( $error->get_error_code(), $error->get_error_message() . ' [cleanup: ' . implode( ', ', $cleanup['errors'] ) . ']' );
	}
}
