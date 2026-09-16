<?php
/**
 * Resultado explícito da criação de backup.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Backup_Result {

	public $completed = false;
	public $base = '';
	public $parts = array();
	public $total_size = 0;
	public $total_parts = 0;
	public $chunk_size_mb = 0;
	public $has_sql = false;
	public $sql_filename = '';
	public $sql_size = 0;
	public $sql_size_formatted = '';
	public $file = '';
	public $name = '';
	public $size = 0;
	public $percent = 0;
	public $log = '';

	/**
	 * Converte o array legado de backup para um resultado tipado por contrato.
	 *
	 * @param array $data Resultado legado.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$result = new self();
		foreach ( get_object_vars( $result ) as $property => $default ) {
			if ( array_key_exists( $property, $data ) ) {
				$result->{$property} = $data[ $property ];
			}
		}
		return $result;
	}

	/**
	 * Expõe o formato legado na borda de compatibilidade.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
