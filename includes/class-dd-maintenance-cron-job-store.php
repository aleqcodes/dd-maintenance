<?php
/**
 * Persistência do estado do job de manutenção agendado.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Cron_Job_Store {

	const OPTION_NAME = 'dd_maintenance_background_job';

	/**
	 * Retorna o job ativo ou um estado vazio.
	 *
	 * @return array
	 */
	public function get(): array {
		$job = get_option( self::OPTION_NAME, array() );
		return is_array( $job ) ? $job : array();
	}

	/**
	 * Persiste o estado do job sem autoload.
	 *
	 * @param array $job Estado do job.
	 * @return bool
	 */
	public function save( array $job ): bool {
		$updated = update_option( self::OPTION_NAME, $job, false );
		if ( $updated ) {
			return true;
		}
		$persisted = get_option( self::OPTION_NAME, null );
		return is_array( $persisted ) && $persisted === $job;
	}

	/**
	 * Remove o estado persistido do job.
	 *
	 * @return bool
	 */
	public function clear(): bool {
		return (bool) delete_option( self::OPTION_NAME );
	}
}
