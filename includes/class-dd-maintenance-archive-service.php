<?php
/**
 * Serviço de validação, união e extração de arquivos de restore.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Restore_Implementation' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
}

class DD_Maintenance_Archive_Service {
	/** @var DD_Maintenance_Restore_Implementation */
	private $restore;

	/** @param DD_Maintenance_Restore_Implementation|null $restore Implementação compatível. */
	public function __construct( $restore = null ) {
		$this->restore = $restore instanceof DD_Maintenance_Restore_Implementation ? $restore : new DD_Maintenance_Restore_Implementation();
	}

	/** @return array|WP_Error */
	public function from_upload( array $file_input, bool $apply_elementor_compatibility = false ) {
		return $this->restore->restore_from_upload( $file_input, $apply_elementor_compatibility );
	}

	/** @return array|WP_Error */
	public function from_temp_directory( string $temp_dir, bool $apply_elementor_compatibility = false ) {
		return $this->restore->restore_from_temp_directory( $temp_dir, $apply_elementor_compatibility );
	}

	/** @return array|WP_Error */
	public function from_local( string $identifier, bool $apply_elementor_compatibility = false ) {
		return $this->restore->restore_from_local_file( $identifier, $apply_elementor_compatibility );
	}

	/** @return bool|WP_Error */
	public function join( array $part_files, string $output_file ) {
		return $this->restore->join_part_files( $part_files, $output_file );
	}

	/** @return array|WP_Error */
	public function restore_archive( string $zip_path, bool $apply_elementor_compatibility = false ) {
		return $this->restore->restore_archive( $zip_path, $apply_elementor_compatibility );
	}

	/** @return array */
	public static function local_backups(): array {
		return DD_Maintenance_Restore_Implementation::get_local_backups();
	}

	/** @return bool */
	public static function delete_local_backup( string $identifier ): bool {
		return DD_Maintenance_Restore_Implementation::delete_local_backup( $identifier );
	}
}
