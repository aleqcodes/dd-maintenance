<?php
/**
 * Resultado explícito de uploads para armazenamento remoto.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Storage_Upload_Result {

	public $success = true;
	public $uploaded = 0;
	public $total = 0;
	public $logs = array();
	public $total_size = 0;
	public $errors = array();

	/**
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'success'    => (bool) $this->success,
			'uploaded'   => (int) $this->uploaded,
			'total'      => (int) $this->total,
			'total_size' => (int) $this->total_size,
			'logs'       => $this->logs,
			'errors'     => $this->errors,
		);
	}
}
