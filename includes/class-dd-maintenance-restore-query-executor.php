<?php
/**
 * Executor de consultas SQL da restauração com contrato de erros estável.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-restore-database-adapter.php';

final class DD_Maintenance_Restore_Query_Executor {
	/** @var DD_Maintenance_Restore_Database_Adapter */
	private $database;

	public function __construct( DD_Maintenance_Restore_Database_Adapter $database ) {
		$this->database = $database;
	}

	public function execute( string $sql ): bool {
		return $this->database->execute( $sql );
	}

	/** @return true|WP_Error */
	public function execute_required( string $sql, string $code, string $message ) {
		return $this->database->execute_required( $sql, $code, $message );
	}

	public function error_count(): int {
		return $this->database->error_count();
	}

	/** @return string[] */
	public function error_samples(): array {
		return $this->database->error_samples();
	}

	public function database(): DD_Maintenance_Restore_Database_Adapter {
		return $this->database;
	}
}
