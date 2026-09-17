<?php
/**
 * Resultado explícito de uploads para armazenamento remoto.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Storage_Upload_Result {
	private $success;
	private $uploaded;
	private $total;
	private $logs;
	private $total_size;
	private $errors;

	private function __construct( int $total, int $total_size ) {
		$this->success    = false;
		$this->uploaded   = 0;
		$this->total      = max( 0, $total );
		$this->logs       = array();
		$this->total_size = max( 0, $total_size );
		$this->errors     = array();
	}

	public static function start( int $total, int $total_size = 0 ): self { return new self( $total, $total_size ); }
	public function add_error( string $message ): void { $this->errors[] = $message; }
	public function add_log( string $message ): void { $this->logs[] = $message; }
	public function mark_uploaded(): void { $this->uploaded++; }
	public function complete(): void { $this->success = true; }
	public function is_success(): bool { return $this->success; }
	public function uploaded(): int { return $this->uploaded; }
	public function total(): int { return $this->total; }
	public function logs(): array { return $this->logs; }
	public function total_size(): int { return $this->total_size; }
	public function errors(): array { return $this->errors; }

	/** @return array */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'uploaded' => $this->uploaded,
			'total' => $this->total,
			'total_size' => $this->total_size,
			'logs' => $this->logs,
			'errors' => $this->errors,
		);
	}
}
