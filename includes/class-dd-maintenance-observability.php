<?php
/**
 * Contrato comum para eventos operacionais sem dados sensíveis.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Observability {
	const SCHEMA_VERSION = 1;
	const REDACTED       = '[redacted]';

	/**
	 * Cria um evento normalizado para backup, restore e cron.
	 *
	 * @param string $operation Operação principal.
	 * @param string $event     Nome do evento.
	 * @param array  $context   Metadados operacionais.
	 * @return array
	 */
	public static function make_event( string $operation, string $event, array $context = array() ): array {
		$session_id    = self::safe_identifier( $context['session_id'] ?? '' );
		$correlation_id = self::safe_identifier( $context['correlation_id'] ?? '' );
		if ( '' === $correlation_id ) {
			$correlation_id = '' !== $session_id ? 'session-' . substr( hash( 'sha256', $session_id ), 0, 16 ) : self::new_identifier( 'request' );
		}

		$status = isset( $context['status'] ) ? self::status( $context['status'] ) : 'info';
		$known  = array(
			'status'           => $status,
			'progress'         => self::nullable_int( $context['progress'] ?? null ),
			'bytes_processed'  => max( 0, (int) ( $context['bytes_processed'] ?? 0 ) ),
			'duration_ms'      => max( 0, (int) ( $context['duration_ms'] ?? 0 ) ),
			'error_count'      => max( 0, (int) ( $context['error_count'] ?? 0 ) ),
			'failure_code'     => self::safe_identifier( $context['failure_code'] ?? '' ),
		);
		$extra = array_intersect_key(
			$context,
			array(
				'parts_total'   => true,
				'part_index'    => true,
				'base_name'     => true,
				'cleanup'       => true,
				'warning_count' => true,
			)
		);

		return array(
			'schema_version'      => self::SCHEMA_VERSION,
			'event_id'            => self::new_identifier( 'event' ),
			'timestamp'           => gmdate( 'c' ),
			'operation'           => self::safe_identifier( $operation ),
			'event'               => self::safe_identifier( $event ),
			'correlation_id'      => $correlation_id,
			'session_id'          => $session_id,
			'step'                => self::safe_identifier( $context['step'] ?? '' ),
			'status'              => $known['status'],
			'progress'            => $known['progress'],
			'bytes_processed'     => $known['bytes_processed'],
			'duration_ms'         => $known['duration_ms'],
			'error_count'         => $known['error_count'],
			'failure_code'        => $known['failure_code'],
			'persistence_status'  => 'created',
			'context'             => self::sanitize_context( $extra ),
		);

	}

	/**
	 * Serializa um evento como uma linha JSON segura para armazenamento.
	 *
	 * @param array $event Evento normalizado.
	 * @return string
	 */
	public static function encode( array $event ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $event ) : json_encode( $event );
		return false === $json ? '{}' : (string) $json;
	}

	/**
	 * Formata um evento para o console administrativo sem expor contexto sensível.
	 *
	 * @param array $event Evento normalizado.
	 * @return string
	 */
	public static function format( array $event ): string {
		$parts = array(
			'[' . ( $event['status'] ?? 'info' ) . ']',
			(string) ( $event['operation'] ?? 'operation' ) . '/' . (string) ( $event['event'] ?? 'event' ),
		);
		foreach ( array( 'correlation_id', 'session_id', 'step', 'progress', 'bytes_processed', 'duration_ms', 'error_count', 'failure_code' ) as $field ) {
			if ( '' !== (string) ( $event[ $field ] ?? '' ) && null !== ( $event[ $field ] ?? null ) ) {
				$parts[] = $field . '=' . $event[ $field ];
			}
		}
		$persistence_status = (string) ( $event['persistence_status'] ?? 'created' );
		if ( 'persisted' !== $persistence_status ) {
			$parts[] = 'persistence=' . self::safe_identifier( $persistence_status );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Remove chaves que poderiam carregar credenciais, tokens ou conteúdo SQL.
	 *
	 * @param array $context Contexto arbitrário.
	 * @return array
	 */
	public static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key_string = (string) $key;
			if ( preg_match( '/(?:token|secret|password|authorization|cookie|access[_-]?key|sql|query|body)/i', $key_string ) ) {
				$clean[ $key_string ] = self::REDACTED;
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key_string ] = self::sanitize_context( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key_string ] = is_string( $value ) ? self::sanitize_string( $value ) : $value;
			}
		}
		return $clean;
	}

	private static function sanitize_string( string $value ): string {
		$value = preg_replace( '/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer ' . self::REDACTED, $value );
		return is_string( $value ) ? substr( $value, 0, 500 ) : self::REDACTED;
	}

	private static function safe_identifier( $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.:-]/', '-', (string) $value );
		return is_string( $value ) ? substr( $value, 0, 120 ) : '';
	}

	private static function nullable_int( $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private static function status( $status ): string {
		$status = self::safe_identifier( $status );
		return in_array( $status, array( 'running', 'success', 'warning', 'failure', 'info' ), true ) ? $status : 'info';
	}

	private static function new_identifier( string $prefix ): string {
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $exception ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		return $prefix . '-' . $suffix;
	}
}
