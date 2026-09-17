<?php
/**
 * Resultado explícito da criação de backup.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Backup_Result {
	private $completed;
	private $base;
	private $parts;
	private $total_size;
	private $total_parts;
	private $chunk_size_mb;
	private $has_sql;
	private $sql_filename;
	private $sql_size;
	private $sql_size_formatted;
	private $file;
	private $name;
	private $size;
	private $percent;
	private $log;
	private $correlation_id;

	private function __construct( array $data ) {
		$has_artifact = ! empty( $data['file'] ) || ! empty( $data['name'] ) || ! empty( $data['parts'] );
		$this->completed          = ! empty( $data['completed'] ) && $has_artifact;
		$this->base               = (string) ( $data['base'] ?? '' );
		$this->parts              = is_array( $data['parts'] ?? null ) ? array_values( $data['parts'] ) : array();
		$this->total_size         = max( 0, (int) ( $data['total_size'] ?? 0 ) );
		$this->total_parts        = max( 0, (int) ( $data['total_parts'] ?? count( $this->parts ) ) );
		$this->chunk_size_mb      = max( 0, (int) ( $data['chunk_size_mb'] ?? 0 ) );
		$this->has_sql            = ! empty( $data['has_sql'] );
		$this->sql_filename       = (string) ( $data['sql_filename'] ?? '' );
		$this->sql_size           = max( 0, (int) ( $data['sql_size'] ?? 0 ) );
		$this->sql_size_formatted = (string) ( $data['sql_size_formatted'] ?? '' );
		$this->file               = (string) ( $data['file'] ?? '' );
		$this->name               = (string) ( $data['name'] ?? '' );
		$this->size               = max( 0, (int) ( $data['size'] ?? 0 ) );
		$this->percent            = max( 0, min( 100, (int) ( $data['percent'] ?? 0 ) ) );
		$this->log                = (string) ( $data['log'] ?? '' );
		$this->correlation_id     = (string) ( $data['correlation_id'] ?? '' );
	}

	/** @param array $data Resultado legado. @return self */
	public static function from_array( array $data ): self { return new self( $data ); }
	public function is_completed(): bool { return $this->completed; }
	public function base(): string { return $this->base; }
	public function parts(): array { return $this->parts; }
	public function total_size(): int { return $this->total_size; }
	public function total_parts(): int { return $this->total_parts; }
	public function chunk_size_mb(): int { return $this->chunk_size_mb; }
	public function has_sql(): bool { return $this->has_sql; }
	public function sql_filename(): string { return $this->sql_filename; }
	public function sql_size(): int { return $this->sql_size; }
	public function sql_size_formatted(): string { return $this->sql_size_formatted; }
	public function file(): string { return $this->file; }
	public function name(): string { return $this->name; }
	public function size(): int { return $this->size; }
	public function percent(): int { return $this->percent; }
	public function log(): string { return $this->log; }
	public function correlation_id(): string { return $this->correlation_id; }

	/** @return array */
	public function to_array(): array {
		return array(
			'completed' => $this->completed,
			'base' => $this->base,
			'parts' => $this->parts,
			'total_size' => $this->total_size,
			'total_parts' => $this->total_parts,
			'chunk_size_mb' => $this->chunk_size_mb,
			'has_sql' => $this->has_sql,
			'sql_filename' => $this->sql_filename,
			'sql_size' => $this->sql_size,
			'sql_size_formatted' => $this->sql_size_formatted,
			'file' => $this->file,
			'name' => $this->name,
			'size' => $this->size,
			'percent' => $this->percent,
			'log' => $this->log,
			'correlation_id' => $this->correlation_id,
		);
	}
}
