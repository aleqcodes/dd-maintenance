<?php
/** @package DD_Maintenance */
defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Cleanup_Result {
	private $clean;
	private $removed;
	private $errors;

	private function __construct( array $data ) {
		$this->clean   = ! empty( $data['clean'] ) && empty( $data['errors'] );
		$this->removed = max( 0, (int) ( $data['removed'] ?? 0 ) );
		$this->errors  = is_array( $data['errors'] ?? null ) ? array_values( $data['errors'] ) : array();
	}

	/** @param array $data Resultado legado. @return self */
	public static function from_array( array $data ): self { return new self( $data ); }
	public function is_clean(): bool { return $this->clean; }
	public function removed(): int { return $this->removed; }
	public function errors(): array { return $this->errors; }
	public function to_array(): array { return array( 'clean' => $this->clean, 'removed' => $this->removed, 'errors' => $this->errors ); }
}
