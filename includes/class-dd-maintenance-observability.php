<?php
/**
 * Contrato comum para eventos operacionais sem dados sensíveis.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Observability {
	const SCHEMA_VERSION                = 1;
	const REDACTED                      = '[redacted]';
	const MAX_CONTEXT_STRING_LENGTH     = 500;
	const MAX_IDENTIFIER_LENGTH         = 120;
	const LOG_RETENTION_FILES         = 30;
	const EVENT_RETENTION_FILES         = 30;
	const CATALOG                       = array(
		'backup'  => array( 'operation_started', 'session_created', 'step_started', 'step_finished', 'operation_finished', 'operation_failed', 'cleanup_finished', 'cleanup_failed', 'checkpoint_failed', 'upload_started', 'upload_part_finished', 'upload_finished', 'upload_failed', 'log_saved', 'ajax_started', 'ajax_rejected', 'ajax_step_started', 'full_operation_started', 'full_operation_finished', 'full_operation_failed' ),
		'restore' => array( 'operation_started', 'operation_finished', 'operation_failed', 'step_started', 'step_finished', 'checkpoint_failed', 'cleanup_finished', 'cleanup_failed', 'required_query_failed', 'ajax_started', 'ajax_rejected', 'ajax_step_started', 'elementor_cache_clear_failed', 'mu_loader_create_failed', 'mu_loader_remove_failed' ),
		'cron'    => array( 'cron_started', 'cron_resumed', 'cron_expired', 'cron_failed', 'cron_session_created', 'cron_step_started', 'cron_step_finished', 'checkpoint_failed' ),
		's3'      => array( 'request_started', 'request_finished', 'request_failed', 'retry_started', 'retry_exhausted' ),
		'elementor' => array( 'compatibility_skipped', 'compatibility_patched', 'compatibility_already_patched', 'compatibility_undone', 'compatibility_undo_conflict', 'compatibility_rejected' ),
		'settings'  => array( 'checkpoint_failed' ),
	);

	/**
	 * Cria um evento normalizado para backup, restore e cron.
	 *
	 * @param string $operation Operação principal.
	 * @param string $event     Nome do evento.
	 * @param array  $context   Metadados operacionais.
	 * @return array
	 */
	public static function make_event( string $operation, string $event, array $context = array() ): array {
		$operation     = self::safe_identifier( $operation );
		$event         = self::safe_identifier( $event );
		$session_id    = self::safe_identifier( $context['session_id'] ?? '' );
		$correlation_id = self::safe_identifier( $context['correlation_id'] ?? '' );
		if ( '' === $correlation_id ) {
			$correlation_id = '' !== $session_id ? 'session-' . substr( hash( 'sha256', $session_id ), 0, 16 ) : self::new_identifier( 'request' );
		}

		$status       = isset( $context['status'] ) ? self::status( $context['status'] ) : 'info';
		$step         = self::safe_identifier( $context['step'] ?? '' );
		$failure_code = self::safe_identifier( $context['failure_code'] ?? '' );
		if ( 'failure' === $status ) {
			$step         = '' !== $step ? $step : 'unknown';
			$failure_code = '' !== $failure_code ? $failure_code : 'unknown_failure';
		}
		$known = array(
			'status'         => $status,
			'progress'       => self::nullable_int( $context['progress'] ?? null ),
			'bytes_processed'=> max( 0, (int) ( $context['bytes_processed'] ?? 0 ) ),
			'duration_ms'    => max( 0, (int) ( $context['duration_ms'] ?? 0 ) ),
			'error_count'    => max( 0, (int) ( $context['error_count'] ?? 0 ) ),
			'warning_count'  => max( 0, (int) ( $context['warning_count'] ?? 0 ) ),
			'failure_code'   => $failure_code,
		);
		$extra = array_intersect_key(
			$context,
			array(
				'parts_total' => true,
				'part_index'  => true,
				'base_name'   => true,
				'cleanup'     => true,
			)
		);

		return array(
			'schema_version'     => self::SCHEMA_VERSION,
			'event_id'           => self::new_identifier( 'event' ),
			'timestamp'          => gmdate( 'c' ),
			'operation'          => $operation,
			'event'              => $event,
			'correlation_id'     => $correlation_id,
			'session_id'         => $session_id,
			'step'               => $step,
			'status'             => $known['status'],
			'progress'          => $known['progress'],
			'bytes_processed'   => $known['bytes_processed'],
			'duration_ms'       => $known['duration_ms'],
			'error_count'       => $known['error_count'],
			'warning_count'     => $known['warning_count'],
			'failure_code'      => $known['failure_code'],
			'persistence_status' => 'created',
			'context'           => self::sanitize_context( $extra ),
		);
	}

	/** @return array<string, array<int, string>> */
	public static function catalog(): array {
		return self::CATALOG;
	}

	public static function is_catalog_event( string $operation, string $event ): bool {
		return isset( self::CATALOG[ $operation ] ) && in_array( $event, self::CATALOG[ $operation ], true );
	}

	/**
	 * Retorna um resumo seguro para a tela administrativa.
	 *
	 * @param array $event Evento normalizado.
	 * @return array
	 */
	public static function summary( array $event ): array {
		$persistence = (string) ( $event['persistence_status'] ?? 'created' );
		$status      = (string) ( $event['status'] ?? 'info' );
		return array(
			'status'          => 'persisted' === $persistence ? $status : 'persistence_failure',
			'correlation_id'  => self::safe_identifier( $event['correlation_id'] ?? '' ),
			'session_id'      => self::safe_identifier( $event['session_id'] ?? '' ),
			'step'            => self::safe_identifier( $event['step'] ?? '' ),
			'failure_code'    => self::safe_identifier( $event['failure_code'] ?? '' ),
			'persistence'     => $persistence,
			'text'            => self::format( $event ),
		);
	}

	/**
	 * Redige e limita linhas antes de persistir logs legados.
	 *
	 * @param array $lines Linhas de log.
	 * @return array
	 */
	public static function sanitize_log_lines( array $lines ): array {
		$clean = array();
		foreach ( $lines as $line ) {
			$value  = self::sanitize_string( trim( (string) $line ) );
			$clean[] = $value;
		}
		return array_values( array_filter( $clean, static function ( $line ) { return '' !== $line; } ) );
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
		foreach ( array( 'correlation_id', 'session_id', 'step', 'progress', 'bytes_processed', 'duration_ms', 'error_count', 'warning_count', 'failure_code' ) as $field ) {
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
		$value = preg_replace( '/\b(authorization|cookie|token|secret|password|access[_-]?key)\s*[:=]\s*\S+/i', '$1=' . self::REDACTED, (string) $value );
		$value = preg_replace( '/\b(?:SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\b.*$/is', self::REDACTED . ' SQL', (string) $value );
		return is_string( $value ) ? substr( $value, 0, self::MAX_CONTEXT_STRING_LENGTH ) : self::REDACTED;
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
