<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ObservabilityTest extends TestCase {
	protected function tearDown(): void {
		\DD_Maintenance::clear_all_saved_logs();
	}

	public function testEventContainsOperationalSchemaAndCorrelationFields(): void {
		$event = \DD_Maintenance_Observability::make_event(
			'backup',
			'step_failed',
			array(
				'correlation_id'  => 'corr-42',
				'session_id'      => 'sess-42',
				'step'            => 'upload',
				'status'          => 'failure',
				'progress'        => 72,
				'duration_ms'     => 812,
				'bytes_processed' => 4096,
				'error_count'     => 2,
				'failure_code'    => 'network_error',
			)
		);

		$this->assertSame( 1, $event['schema_version'] );
		$this->assertSame( 'corr-42', $event['correlation_id'] );
		$this->assertSame( 'sess-42', $event['session_id'] );
		$this->assertSame( 'upload', $event['step'] );
		$this->assertSame( 72, $event['progress'] );
		$this->assertSame( 812, $event['duration_ms'] );
		$this->assertSame( 4096, $event['bytes_processed'] );
		$this->assertSame( 2, $event['error_count'] );
		$this->assertSame( 'network_error', $event['failure_code'] );
	}

	public function testSensitiveContextIsRedactedBeforeEncoding(): void {
		$event = \DD_Maintenance_Observability::make_event(
			'restore',
			'step_finished',
			array(
				'session_id' => 'sess-99',
				'context'    => array(
					'token'        => 'restore-secret-token',
					'access_key'   => 'DO00SECRET',
					'sql_query'    => 'SELECT password FROM users',
					'authorization' => 'Bearer abc.def.ghi',
					'files'        => array( 'count' => 4 ),
				),
			)
		);
		$json = \DD_Maintenance_Observability::encode( $event );

		$this->assertSame( '[redacted]', $event['context']['context']['token'] );
		$this->assertSame( '[redacted]', $event['context']['context']['access_key'] );
		$this->assertSame( '[redacted]', $event['context']['context']['sql_query'] );
		$this->assertSame( '[redacted]', $event['context']['context']['authorization'] );
		$this->assertSame( 4, $event['context']['context']['files']['count'] );
		$this->assertStringNotContainsString( 'restore-secret-token', $json );
		$this->assertStringNotContainsString( 'DO00SECRET', $json );
		$this->assertStringNotContainsString( 'SELECT password FROM users', $json );
		$this->assertStringNotContainsString( 'abc.def.ghi', $json );
	}

	public function testEventIsPersistedAndFormattedWithoutContext(): void {
		$event = \DD_Maintenance::record_event(
			'restore',
			'step_failed',
			array(
				'correlation_id' => 'corr-persisted',
				'session_id'     => 'sess-persisted',
				'step'           => 'restore_files',
				'status'         => 'failure',
				'failure_code'   => 'disk_full',
				'token'          => 'must-not-appear',
			)
		);

		$this->assertSame( $event, \DD_Maintenance::get_last_event() );
		$this->assertStringContainsString( 'session_id=sess-persisted', \DD_Maintenance_Observability::format( $event ) );
		$this->assertStringContainsString( 'step=restore_files', \DD_Maintenance_Observability::format( $event ) );
		$this->assertStringContainsString( 'failure_code=disk_full', \DD_Maintenance_Observability::format( $event ) );
		$this->assertStringNotContainsString( 'must-not-appear', \DD_Maintenance_Observability::format( $event ) );
	}
}
