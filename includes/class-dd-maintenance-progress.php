<?php
/**
 * Progresso explícito de uma etapa incremental.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Progress {
	private $completed;
	private $percent;
	private $processed;
	private $total;
	private $current_index;
	private $total_items;
	private $log;
	private $extra;

	private function __construct( bool $completed, int $percent, int $processed, int $total, int $current_index, int $total_items, string $log, array $extra ) {
		$this->completed     = $completed;
		$this->percent       = max( 0, min( 100, $percent ) );
		$this->processed     = max( 0, $processed );
		$this->total         = max( 0, $total );
		$this->current_index = max( 0, $current_index );
		$this->total_items   = max( 0, $total_items );
		$this->log           = $log;
		$this->extra         = $extra;
	}

	/** @param array $data Resultado legado. @return self */
	public static function from_array( array $data ): self {
		$known = array( 'completed' => true, 'percent' => true, 'processed' => true, 'copied' => true, 'total' => true, 'total_files' => true, 'current_index' => true, 'total_volumes' => true, 'log' => true );
		$total = isset( $data['total'] ) ? (int) $data['total'] : ( isset( $data['total_files'] ) ? (int) $data['total_files'] : 0 );
		return new self(
			! empty( $data['completed'] ),
			isset( $data['percent'] ) ? (int) $data['percent'] : 0,
			isset( $data['processed'] ) ? (int) $data['processed'] : ( isset( $data['copied'] ) ? (int) $data['copied'] : 0 ),
			$total,
			isset( $data['current_index'] ) ? (int) $data['current_index'] : 0,
			isset( $data['total_volumes'] ) ? (int) $data['total_volumes'] : $total,
			isset( $data['log'] ) ? (string) $data['log'] : '',
			array_diff_key( $data, $known )
		);
	}

	public function is_completed(): bool { return $this->completed; }
	public function percent(): int { return $this->percent; }
	public function processed(): int { return $this->processed; }
	public function total(): int { return $this->total; }
	public function current_index(): int { return $this->current_index; }
	public function total_items(): int { return $this->total_items; }
	public function log(): string { return $this->log; }

	/** @return array */
	public function to_array(): array {
		return array_merge(
			$this->extra,
			array(
				'completed' => $this->completed,
				'percent' => $this->percent,
				'processed' => $this->processed,
				'total' => $this->total,
				'current_index' => $this->current_index,
				'total_items' => $this->total_items,
				'log' => $this->log,
			)
		);
	}
}
