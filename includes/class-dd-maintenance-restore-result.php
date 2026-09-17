<?php
/**
 * Resultado explícito da restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Result {
	private $success;
	private $log;
	private $db_stats;
	private $files;
	private $warnings;

	private function __construct( array $data ) {
		$this->success  = ! empty( $data['success'] );
		$this->log      = is_array( $data['log'] ?? null ) ? array_values( $data['log'] ) : array();
		$this->db_stats = is_array( $data['db_stats'] ?? null ) ? $data['db_stats'] : null;
		$this->files    = max( 0, (int) ( $data['files'] ?? 0 ) );
		$this->warnings = is_array( $data['warnings'] ?? null ) ? array_values( $data['warnings'] ) : array();
	}

	/** @param array $data Resultado legado. @return self */
	public static function from_array( array $data ): self { return new self( $data ); }
	public function is_success(): bool { return $this->success; }
	public function log(): array { return $this->log; }
	public function db_stats(): ?array { return $this->db_stats; }
	public function files(): int { return $this->files; }
	public function warnings(): array { return $this->warnings; }

	/** @return array */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'log' => $this->log,
			'db_stats' => $this->db_stats,
			'files' => $this->files,
			'warnings' => $this->warnings,
		);
	}
}
