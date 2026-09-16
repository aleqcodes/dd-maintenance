<?php
/**
 * Progresso explícito de uma etapa incremental.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Progress {

	public $completed = false;
	public $percent = 0;
	public $processed = 0;
	public $total = 0;
	public $current_index = 0;
	public $total_items = 0;
	public $log = '';
	private $extra = array();

	/**
	 * Converte uma resposta incremental antiga para o contrato comum.
	 *
	 * @param array $data Resposta legada.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$result = new self();
		$result->completed = ! empty( $data['completed'] );
		$result->percent = isset( $data['percent'] ) ? (int) $data['percent'] : 0;
		$result->processed = isset( $data['processed'] ) ? (int) $data['processed'] : ( isset( $data['copied'] ) ? (int) $data['copied'] : 0 );
		$result->total = isset( $data['total'] ) ? (int) $data['total'] : ( isset( $data['total_files'] ) ? (int) $data['total_files'] : 0 );
		$result->current_index = isset( $data['current_index'] ) ? (int) $data['current_index'] : 0;
		$result->total_items = isset( $data['total_volumes'] ) ? (int) $data['total_volumes'] : $result->total;
		$result->log = isset( $data['log'] ) ? (string) $data['log'] : '';
		$result->extra = array_diff_key(
			$data,
			array(
				'completed' => true,
				'percent' => true,
				'processed' => true,
				'copied' => true,
				'total' => true,
				'total_files' => true,
				'current_index' => true,
				'total_volumes' => true,
				'log' => true,
			)
		);
		return $result;
	}

	/**
	 * Expõe os campos comuns para os handlers legados.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array_merge(
			$this->extra,
			array(
				'completed'      => $this->completed,
				'percent'        => $this->percent,
				'processed'      => $this->processed,
				'total'          => $this->total,
				'current_index'  => $this->current_index,
				'total_items'    => $this->total_items,
				'log'            => $this->log,
			)
		);
	}
}
