<?php
/**
 * Parser comum de respostas HTTP/XML do S3.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_S3_Response_Parser {
	private $body_limit;

	public function __construct( int $body_limit = 8192 ) {
		$this->body_limit = max( 1024, $body_limit );
	}

	/** @return array|WP_Error */
	public function parse( array $response, string $operation = 'request' ) {
		$status    = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$body      = isset( $response['body'] ) ? (string) $response['body'] : '';
		$error     = isset( $response['error'] ) ? $this->redact( (string) $response['error'] ) : '';
		$headers   = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
		$request_id = '';
		foreach ( array( 'x-amz-request-id', 'x-amz-id-2' ) as $header ) {
			if ( ! empty( $headers[ $header ] ) ) {
				$request_id = $this->redact( (string) $headers[ $header ] );
				break;
			}
		}
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'status'     => $status,
				'etag'       => isset( $headers['etag'] ) ? trim( (string) $headers['etag'] ) : '',
				'request_id' => $request_id,
				'body'       => $body,
			);
		}
		$code    = $this->xml_value( $body, 'Code' );
		$message = $this->xml_value( $body, 'Message' );
		if ( '' === $message ) {
			$message = $error;
		}
		if ( '' === $message ) {
			$message = $body;
		}
		$message = $this->redact( $message );
		if ( '' === $message ) {
			$message = sprintf( 'Erro HTTP %d ao executar operação S3.', $status );
		}
		$error_slug = function_exists( 'sanitize_key' ) ? sanitize_key( $operation ) : strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $operation ) );
		return new WP_Error(
			's3_' . $error_slug . '_error',
			$this->format_message( $status, $code, $message, $request_id )
		);
	}

	public function is_retryable_status( int $status ): bool {
		return 408 === $status || 429 === $status || ( $status >= 500 && $status <= 599 );
	}

	private function xml_value( string $body, string $tag ): string {
		if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '>([^<]*)<\/' . preg_quote( $tag, '/' ) . '>/i', substr( $body, 0, $this->body_limit ), $matches ) ) {
			return trim( html_entity_decode( $matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}
		return '';
	}

	private function format_message( int $status, string $code, string $message, string $request_id ): string {
		$formatted = $code ? $code . ': ' . $message : $message;
		if ( $status > 0 ) {
			$formatted .= sprintf( ' (HTTP %d)', $status );
		}
		if ( '' !== $request_id ) {
			$formatted .= ' (Request ID: ' . $request_id . ')';
		}
		return $formatted;
	}

	private function redact( string $value ): string {
		$value = preg_replace( '/(secret|token|password|authorization|access[_ -]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value );
		return substr( trim( (string) $value ), 0, $this->body_limit );
	}
}
