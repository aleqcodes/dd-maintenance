<?php
/**
 * Resultado explícito da restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Restore_Result {

	public $success = false;
	public $log = array();
	public $db_stats = null;
	public $files = 0;
	public $warnings = array();

	/**
	 * Converte o array legado de restauração para um resultado explícito.
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
