<?php
/**
 * Transporte HTTP sem redirects para operações S3.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_S3_Transport {
	/** @return array|WP_Error */
	public function request( string $url, string $method, array $headers = array(), string $body = '', int $timeout = 30 ) {
		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => max( 1, $timeout ),
			'redirection' => 0,
			'sslverify'   => true,
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		$response = 'GET' === strtoupper( $method ) && function_exists( 'wp_remote_get' )
			? wp_remote_get( $url, $args )
			: wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => array(
				'x-amz-request-id' => (string) wp_remote_retrieve_header( $response, 'x-amz-request-id' ),
				'x-amz-id-2'       => (string) wp_remote_retrieve_header( $response, 'x-amz-id-2' ),
				'etag'             => (string) wp_remote_retrieve_header( $response, 'etag' ),
			),
		);
	}

	/** @return array|WP_Error */
	public function put_with_callback( callable $callback, string $url, array $headers, string $file, int $size ) {
		$result = call_user_func( $callback, $url, $headers, $file, $size );
		if ( is_wp_error( $result ) || is_array( $result ) ) {
			return $result;
		}
		return new WP_Error( 's3_transport_contract', __( 'O transporte de upload retornou um resultado inválido.', 'dd-maintenance' ) );
	}
}
