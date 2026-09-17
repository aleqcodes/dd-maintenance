<?php
/**
 * Repositório de configurações persistidas do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Settings_Repository {
	const OPTION_NAME           = 'dd_maintenance_settings';
	const MIN_SPLIT_SIZE_MB     = 25;
	const MAX_SPLIT_SIZE_MB     = 1000;
	const DEFAULT_SPLIT_SIZE_MB = 200;

	/** @var string[] */
	private const ALLOWED_KEYS = array(
		's3_access_key',
		's3_secret_key',
		's3_bucket',
		's3_region',
		's3_endpoint',
		'include_db',
		'include_wpcontent',
		'include_wpconfig',
		'include_entire',
		'keep_local',
		'split_size_mb',
		'schedule_enabled',
		'schedule_frequency',
		'schedule_time',
		'retention_local',
	);

	/** @return array */
	public function defaults(): array {
		return array(
			's3_access_key'      => '',
			's3_secret_key'      => '',
			's3_bucket'          => '',
			's3_region'          => 'nyc3',
			's3_endpoint'        => '',
			'include_db'         => 1,
			'include_wpcontent'  => 1,
			'include_wpconfig'   => 1,
			'include_entire'     => 1,
			'keep_local'         => 1,
			'split_size_mb'      => self::DEFAULT_SPLIT_SIZE_MB,
			'schedule_enabled'   => 0,
			'schedule_frequency' => 'daily',
			'schedule_time'      => '03:00',
			'retention_local'    => 5,
		);
	}

	/** @param mixed $value @return int */
	public function normalize_split_size_mb( $value ): int {
		return max( self::MIN_SPLIT_SIZE_MB, min( self::MAX_SPLIT_SIZE_MB, (int) $value ) );
	}

	/** @param array $settings @return int */
	public function get_split_size_mb( array $settings = array() ): int {
		if ( empty( $settings ) ) {
			$settings = $this->get();
		}
		return $this->normalize_split_size_mb( $settings['split_size_mb'] ?? self::DEFAULT_SPLIT_SIZE_MB );
	}

	/** @return array */
	public function get(): array {
		$saved = get_option( self::OPTION_NAME, array() );
		return $this->normalize( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	/**
	 * Normaliza e filtra configurações na única borda de persistência.
	 *
	 * @param array $settings Configurações recebidas.
	 * @param array $previous Valores efetivos anteriores para preservar segredos.
	 * @return array
	 */
	public function normalize( array $settings, array $previous = array() ): array {
		$defaults = $this->defaults();
		$previous = array_merge( $defaults, $previous );
		$result   = array_merge( $defaults, array_intersect_key( $settings, array_flip( self::ALLOWED_KEYS ) ) );

		foreach ( array( 'include_db', 'include_wpcontent', 'include_wpconfig', 'include_entire', 'keep_local', 'schedule_enabled' ) as $key ) {
			$result[ $key ] = array_key_exists( $key, $settings ) ? ( empty( $settings[ $key ] ) ? 0 : 1 ) : (int) $previous[ $key ];
		}
		$result['retention_local'] = max( 0, min( 3650, (int) ( $settings['retention_local'] ?? $previous['retention_local'] ) ) );

		$frequency = isset( $settings['schedule_frequency'] ) ? sanitize_key( (string) $settings['schedule_frequency'] ) : (string) $previous['schedule_frequency'];
		$result['schedule_frequency'] = in_array( $frequency, array( 'daily', 'weekly', 'biweekly', 'monthly' ), true ) ? $frequency : $previous['schedule_frequency'];
		$time = isset( $settings['schedule_time'] ) ? trim( (string) $settings['schedule_time'] ) : (string) $previous['schedule_time'];
		$result['schedule_time'] = preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : $previous['schedule_time'];

		foreach ( array( 's3_access_key', 's3_secret_key', 's3_bucket' ) as $key ) {
			$raw_value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			$value = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw_value ) : strip_tags( $raw_value ) );
			$result[ $key ] = '' !== $value ? $value : (string) $previous[ $key ];
		}
		$raw_region = isset( $settings['s3_region'] ) ? (string) $settings['s3_region'] : '';
		$region = function_exists( 'sanitize_key' ) ? sanitize_key( $raw_region ) : strtolower( preg_replace( '/[^a-z0-9_-]/', '', $raw_region ) );
		$raw_endpoint = isset( $settings['s3_endpoint'] ) ? (string) $settings['s3_endpoint'] : '';
		$endpoint = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw_endpoint ) : strip_tags( $raw_endpoint ) );
		$result['s3_endpoint'] = '' === $endpoint || preg_match( '#^https?://[^\\s/]+(?:/[^\\s]*)?$#i', $endpoint ) ? $endpoint : (string) $previous['s3_endpoint'];

		return $result;
	}

	/** @param array $settings @return bool */
	public function save( array $settings ): bool {
		$normalized = $this->normalize( $settings, $this->get() );
		$updated    = update_option( self::OPTION_NAME, $normalized, false );
		if ( $updated ) {
			return true;
		}
		$persisted = get_option( self::OPTION_NAME, null );
		return is_array( $persisted ) && $this->normalize( $persisted, $normalized ) === $normalized;
	}

	/** @param array $changes @return bool */
	public function update( array $changes ): bool {
		return $this->save( array_merge( $this->get(), $changes ) );
	}
}
