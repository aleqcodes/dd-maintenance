<?php
/**
 * Assinatura AWS Signature V4 para S3 compatível.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_S3_Signer {
	private $access_key;
	private $secret_key;
	private $region;

	public function __construct( string $access_key, string $secret_key, string $region ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = $region;
	}

	/** @return array */
	public function sign( string $method, string $uri, string $query, string $payload_hash, string $host, array $extra_headers = array() ): array {
		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );
		$headers    = array_merge(
			array(
				'host'                 => $host,
				'x-amz-content-sha256' => $payload_hash,
				'x-amz-date'           => $amz_date,
			),
			$extra_headers
		);
		$canonical = array();
		foreach ( $headers as $name => $value ) {
			$canonical[ strtolower( trim( $name ) ) ] = trim( (string) $value );
		}
		ksort( $canonical );
		$canonical_headers = '';
		foreach ( $canonical as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers  = implode( ';', array_keys( $canonical ) );
		$canonical_query = $query;
		$canonical_request = strtoupper( $method ) . "\n{$uri}\n{$canonical_query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
		$scope        = $date_stamp . '/' . $this->region . '/s3/aws4_request';
		$string_sign  = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signature    = hash_hmac( 'sha256', $string_sign, $this->signing_key( $date_stamp ) );

		return array(
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
			'Host'                 => $host,
		);
	}

	private function signing_key( string $date ): string {
		$date_key    = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$region_key  = hash_hmac( 'sha256', $this->region, $date_key, true );
		$service_key = hash_hmac( 'sha256', 's3', $region_key, true );
		return hash_hmac( 'sha256', 'aws4_request', $service_key, true );
	}
}
