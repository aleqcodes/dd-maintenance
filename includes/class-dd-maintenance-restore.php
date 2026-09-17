<?php
/**
 * Fachada compatível para os serviços de restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-restore-implementation.php';
require_once __DIR__ . '/class-dd-maintenance-restore-session-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-files-service.php';
require_once __DIR__ . '/class-dd-maintenance-archive-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-database-service.php';
require_once __DIR__ . '/class-dd-maintenance-url-migration-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-elementor-adapter.php';
require_once __DIR__ . '/class-dd-maintenance-restore-finalizer-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-cleanup-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-file-copier.php';
require_once __DIR__ . '/class-dd-maintenance-restore-database-importer.php';

class DD_Maintenance_Restore {
	private $implementation;
	private $sessions;
	private $files;
	private $archives;
	private $database;
	private $database_importer;
	private $finalizer;
	private $cleanup;
	private $file_copier;

	/**
	 * @param DD_Maintenance_Restore_Implementation|null $implementation Implementação dos serviços.
	 */
	public function __construct( $implementation = null ) {
		$this->implementation = $implementation instanceof DD_Maintenance_Restore_Implementation ? $implementation : new DD_Maintenance_Restore_Implementation();
		$this->sessions       = new DD_Maintenance_Restore_Session_Service( $this->implementation );
		$this->files          = new DD_Maintenance_Restore_Files_Service( $this->implementation );
		$this->archives       = new DD_Maintenance_Archive_Service( $this->implementation );
		$this->database       = new DD_Maintenance_Restore_Database_Service( $this->implementation );
		$this->database_importer = new DD_Maintenance_Restore_Database_Importer( $this->implementation );
		$this->finalizer      = new DD_Maintenance_Restore_Finalizer_Service( $this->implementation );
		$this->cleanup        = new DD_Maintenance_Restore_Cleanup_Service( $this->implementation );
		$this->file_copier    = new DD_Maintenance_Restore_File_Copier( $this->implementation );
	}
 

	/** @return array|WP_Error */
	public function restore_from_upload( array $file_input, bool $apply_elementor_compatibility = false ) { return $this->archives->from_upload( $file_input, $apply_elementor_compatibility ); }
	/** @return array|WP_Error */
	public function restore_from_temp_directory( string $temp_dir, bool $apply_elementor_compatibility = false ) { return $this->archives->from_temp_directory( $temp_dir, $apply_elementor_compatibility ); }
	/** @return array|WP_Error */
	public function restore_from_local_file( string $identifier, bool $apply_elementor_compatibility = false ) { return $this->archives->from_local( $identifier, $apply_elementor_compatibility ); }
	/** @return bool|WP_Error */
	public function join_part_files( array $part_files, string $output_file ) { return $this->archives->join( $part_files, $output_file ); }
	/** @return array|WP_Error */
	public function restore_archive( string $zip_path, bool $apply_elementor_compatibility = false ) { return $this->archives->restore_archive( $zip_path, $apply_elementor_compatibility ); }
	/** @return array|WP_Error */
	public function init_restore_session( array $zip_paths, string $temp_upload_dir = '', bool $apply_elementor_compatibility = false, string $correlation_id = '' ) { return $this->sessions->initialize( $zip_paths, $temp_upload_dir, $apply_elementor_compatibility, $correlation_id ); }
	/** @return array|WP_Error */
	public function get_restore_session_data( string $session_id ) { return $this->sessions->get( $session_id ); }
	/** @return bool */
	public function verify_restore_token( string $session_id, string $token ): bool { return $this->sessions->verify_token( $session_id, $token ); }
	/** @return bool */
	public function save_restore_session_data( string $extract_dir, array $session ): bool { return $this->sessions->save( $extract_dir, $session ); }
	/** @return array|WP_Error */
	public function extract_volume_step( string $session_id, int $batch_limit = 10 ) { return $this->files->extract_volume_step( $session_id, $batch_limit ); }
	/** @return array|WP_Error */
	public function restore_database_step( string $session_id, float $time_limit_seconds = 7.0 ) { return $this->database_importer->step( $session_id, $time_limit_seconds ); }
	/** @return array|WP_Error */
	public function restore_files_step( string $session_id ) { return $this->file_copier->step( $session_id ); }
	/** @return array|WP_Error */
	public function finalize_restore_step( string $session_id ) { return $this->finalizer->run( $session_id ); }
	/** @return array */
	public function cleanup_failed_restore( string $session_id ): array { return $this->cleanup->failed( $session_id ); }
	/** @return array|WP_Error */
	public function restore_database( string $sql_file ) { return $this->database_importer->import( $sql_file ); }
	/** @return array|WP_Error */
	public function restore_files( string $extract_dir ) { return $this->files->restore( $extract_dir ); }
	/** @return int|WP_Error */
	public function copy_directory( string $source_dir, string $dest_dir, array $ignore_paths = array() ) { return $this->file_copier->copy( $source_dir, $dest_dir, $ignore_paths ); }

	/** @return array */
	public static function get_local_backups(): array { return DD_Maintenance_Archive_Service::local_backups(); }
	/** @return bool */
	public static function delete_local_backup( string $identifier ): bool { return DD_Maintenance_Archive_Service::delete_local_backup( $identifier ); }
	/** @return array */
	public static function build_url_replacement_map( string $from_url, string $to_url ): array { return DD_Maintenance_Url_Migration_Service::replacement_map( $from_url, $to_url ); }
	/** @return mixed */
	public static function recursive_search_replace( $from, $to, $data, bool $was_serialized = false ) { return DD_Maintenance_Url_Migration_Service::replace( $from, $to, $data, $was_serialized ); }
	/** @return mixed */
	public static function fix_elementor_dynamic_tags( $content ) { return DD_Maintenance_Restore_Elementor_Adapter::fix_dynamic_tags( $content ); }
	/** @return string */
	public static function fix_elementor_dynamic_tags_string( string $content ): string { return DD_Maintenance_Restore_Elementor_Adapter::fix_dynamic_tags_string( $content ); }
	/** @return string[] */
	public static function ensure_elementor_active_kit( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array { return DD_Maintenance_Restore_Elementor_Adapter::ensure_active_kit( $dump_prefix, $database ); }
	/** @return string[] */
	public static function rebuild_elementor_theme_builder_conditions( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array { return DD_Maintenance_Restore_Elementor_Adapter::rebuild_conditions( $dump_prefix, $database ); }
	public static function clear_elementor_cache( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null, array $event_context = array() ): array { return DD_Maintenance_Restore_Elementor_Adapter::clear_cache( $dump_prefix, $database, $event_context ); }
	/** @return bool */
	public static function create_mu_plugin_loader( array $event_context = array() ): bool { return DD_Maintenance_Restore_Implementation::create_mu_plugin_loader( $event_context ); }
	public static function install_permanent_elementor_shield(): void { DD_Maintenance_Elementor_Compatibility::install_permanent_elementor_shield(); }
	/** @return bool */
	public static function patch_elementor_php8_compatibility(): bool { return DD_Maintenance_Elementor_Compatibility::patch_elementor_php8_compatibility(); }
	/** @return bool */
	public static function remove_mu_plugin_loader( array $event_context = array() ): bool { return DD_Maintenance_Restore_Implementation::remove_mu_plugin_loader( $event_context ); }
}
