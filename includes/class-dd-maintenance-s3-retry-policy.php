<?php
/**
 * Política explícita para repetições de operações S3 idempotentes.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_S3_Retry_Policy {
	private $max_attempts;

	public function __construct( int $max_attempts = 2 ) {
		$this->max_attempts = max( 1, $max_attempts );
	}

	public function max_attempts(): int {
		return $this->max_attempts;
	}

	public function should_retry( $result, int $attempt, bool $idempotent = false ): bool {
		if ( ! $idempotent || $attempt >= $this->max_attempts ) {
			return false;
		}
		if ( is_wp_error( $result ) ) {
			return in_array( (string) $result->get_error_code(), array( 'http_request_failed', 's3_transport', 's3_upload_retryable' ), true );
		}
		return false;
	}
}
