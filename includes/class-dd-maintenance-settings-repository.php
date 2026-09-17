<?php
/**
 * Repositório de configurações persistidas do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Settings_Repository {

	const OPTION_NAME          = 'dd_maintenance_settings';
	const MIN_SPLIT_SIZE_MB    = 25;
	const MAX_SPLIT_SIZE_MB    = 1000;
	const DEFAULT_SPLIT_SIZE_MB = 200;

	/**
	 * Valores padrão usados somente na borda de leitura.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			's3_access_key'     => '',
			's3_secret_key'     => '',
			's3_bucket'         => '',
			's3_region'         => 'nyc3',
			's3_endpoint'       => '',
			'include_db'        => 1,
			'include_wpcontent' => 1,
			'include_wpconfig'  => 1,
			'include_entire'    => 1,
			'keep_local'        => 1,
			'split_size_mb'     => self::DEFAULT_SPLIT_SIZE_MB,
			'schedule_enabled'  => 0,
			'schedule_frequency'=> 'daily',
			'schedule_time'     => '03:00',
			'retention_local'   => 5,
		);
	}

	/**
	 * Normaliza o limite configurável dos volumes.
	 *
	 * @param mixed $value Valor recebido da configuração.
	 * @return int
	 */
	public function normalize_split_size_mb( $value ): int {
		return max( self::MIN_SPLIT_SIZE_MB, min( self::MAX_SPLIT_SIZE_MB, (int) $value ) );
	}

	/**
	 * Retorna o limite efetivo dos volumes em megabytes.
	 *
	 * @param array $settings Configurações opcionais já carregadas.
	 * @return int
	 */
	public function get_split_size_mb( array $settings = array() ): int {
		if ( empty( $settings ) ) {
			$settings = $this->get();
		}
		return $this->normalize_split_size_mb( $settings['split_size_mb'] ?? self::DEFAULT_SPLIT_SIZE_MB );
	}

	/**
	 * Lê as configurações normalizadas.
	 *
	 * @return array
	 */
	public function get(): array {
		$saved = get_option( self::OPTION_NAME, array() );
		return array_merge( $this->defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Persiste configurações sem carregamento automático pela opção global.
	 *
	 * @param array $settings Configurações completas.
	 * @return bool
	 */
	public function save( array $settings ): bool {
		$updated = update_option( self::OPTION_NAME, $settings, false );
		if ( $updated ) {
			return true;
		}
		$persisted = get_option( self::OPTION_NAME, null );
		return is_array( $persisted ) && $persisted === $settings;
	}

	/**
	 * Atualiza somente os campos fornecidos.
	 *
	 * @param array $changes Alterações parciais.
	 * @return bool
	 */
	public function update( array $changes ): bool {
		$settings = $this->get();
		foreach ( $changes as $key => $value ) {
			$settings[ $key ] = $value;
		}
		return $this->save( $settings );
	}
}
