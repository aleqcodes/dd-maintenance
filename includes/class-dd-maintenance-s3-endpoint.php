<?php
/**
 * Normalização segura de endpoints S3 compatíveis.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_S3_Endpoint {
	public function normalize( string $bucket, string $region, string $configured = '' ): string {
		if ( '' === trim( $configured ) ) {
			return 'https://' . $bucket . '.' . $region . '.digitaloceanspaces.com';
		}
		$endpoint = trim( $configured );
		if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$endpoint = 'https://' . $endpoint;
		}
		$endpoint = rtrim( $endpoint, '/' );
		$parsed   = parse_url( $endpoint );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || ! in_array( strtolower( (string) ( $parsed['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( (string) $parsed['host'] );
		if ( false !== strpos( $host, 'digitaloceanspaces.com' ) && 0 !== strpos( $host, strtolower( $bucket ) . '.' ) ) {
			$port = ! empty( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
			return $parsed['scheme'] . '://' . $bucket . '.' . $host . $port;
		}
		return $endpoint;
	}

	public function host( string $endpoint ): string {
		$parsed = parse_url( $endpoint );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}
		$host = (string) $parsed['host'];
		if ( ! empty( $parsed['port'] ) && ! in_array( (int) $parsed['port'], array( 80, 443 ), true ) ) {
			$host .= ':' . (int) $parsed['port'];
		}
		return $host;
	}

	public function object_uri( string $key ): string {
		return '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
	}
}
