<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore-implementation.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore-sql-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore-database-finalizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-s3-endpoint.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-s3-signer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-s3-response-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-s3-retry-policy.php';

final class RestoreAndS3DecompositionTest extends TestCase {
	public function testSqlParserKeepsQuotedSemicolonsAndSkipsDatabaseSelection(): void {
		$file = tmpfile();
		fwrite( $file, "CREATE DATABASE ignored;\nUSE ignored;\nINSERT INTO wp_posts (post_content) VALUES ('one;two');\nCREATE TABLE wp_posts (ID int);\n" );
		rewind( $file );
		$queries = array();
		$parser  = new \DD_Maintenance_Restore_Sql_Parser();
		$stats   = $parser->parse( $file, static function ( string $sql ) use ( &$queries ): bool {
			$queries[] = $sql;
			return true;
		} );
		fclose( $file );

		$this->assertSame( 2, $stats['queries'] );
		$this->assertSame( 1, $stats['tables'] );
		$this->assertSame( 2, $stats['skipped'] );
		$this->assertStringContainsString( "'one;two'", $queries[0] );
	}

	public function testPrefixValidatorRejectsSqlInjectionBeforeConfigBoundary(): void {
		$this->assertTrue( \DD_Maintenance_Restore_Database_Finalizer::validate_prefix( 'wp_' ) );
		$this->assertFalse( \DD_Maintenance_Restore_Database_Finalizer::validate_prefix( 'wp_` WHERE 1=1' ) );
	}

	public function testUrlReplacementMapIsIdempotentForSameTarget(): void {
		$map = new \DD_Maintenance_Restore_Url_Migrator();
		$this->assertSame( array(), $map->replacement_map( 'https://example.test', 'https://example.test' ) );
	}

	public function testEndpointNormalizesDigitalOceanBucketHost(): void {
		$endpoint = new \DD_Maintenance_S3_Endpoint();
		$this->assertSame( 'https://bucket.nyc3.digitaloceanspaces.com', $endpoint->normalize( 'bucket', 'nyc3', 'nyc3.digitaloceanspaces.com' ) );
		$this->assertSame( 'host:9443', $endpoint->host( 'https://host:9443' ) );
	}

	public function testSignerDoesNotExposeSecretKey(): void {
		$signer  = new \DD_Maintenance_S3_Signer( 'public-key', 'secret-value', 'nyc3' );
		$headers = $signer->sign( 'GET', '/', '', hash( 'sha256', '' ), 'bucket.nyc3.digitaloceanspaces.com' );
		$this->assertStringContainsString( 'public-key', $headers['Authorization'] );
		$this->assertStringNotContainsString( 'secret-value', wp_json_encode( $headers ) );
	}

	public function testResponseParserBoundsAndRedactsFailure(): void {
		$parser = new \DD_Maintenance_S3_Response_Parser( 1024 );
		$error  = $parser->parse(
			array(
				'status'  => 403,
				'body'    => '<Error><Code>AccessDenied</Code><Message>token=private-value</Message></Error>',
				'headers' => array( 'x-amz-request-id' => 'request-1' ),
			),
			'list'
		);
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 's3_list_error', $error->get_error_code() );
		$this->assertStringNotContainsString( 'private-value', $error->get_error_message() );
		$this->assertStringContainsString( 'request-1', $error->get_error_message() );
	}

	public function testRetryPolicyOnlyRetriesIdempotentTransportFailures(): void {
		$policy = new \DD_Maintenance_S3_Retry_Policy( 2 );
		$error  = new \WP_Error( 'http_request_failed', 'temporary' );
		$this->assertTrue( $policy->should_retry( $error, 1, true ) );
		$this->assertFalse( $policy->should_retry( $error, 1, false ) );
		$this->assertFalse( $policy->should_retry( $error, 2, true ) );
	}
}
