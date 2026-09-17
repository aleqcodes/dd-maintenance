<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-cron-job-store.php';

final class CronJobStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dd_phpunit_options'] = array();
		$GLOBALS['dd_phpunit_fail_update_option'] = false;
	}

	protected function tearDown(): void {
		$GLOBALS['dd_phpunit_fail_update_option'] = false;
	}

	public function testSaveFailureDoesNotAdvancePersistedJob(): void {
		$store = new \DD_Maintenance_Cron_Job_Store();
		$initial = array( 'status' => 'running', 'phase' => 'database', 'session_id' => 'session-1' );
		$next = array( 'status' => 'running', 'phase' => 'index', 'session_id' => 'session-1' );

		$this->assertTrue( $store->save( $initial ) );
		$GLOBALS['dd_phpunit_fail_update_option'] = true;

		$this->assertFalse( $store->save( $next ) );
		$this->assertSame( $initial, $store->get() );
	}

	public function testSaveAcceptsAlreadyPersistedStateWhenUpdateReportsNoChange(): void {
		$store = new \DD_Maintenance_Cron_Job_Store();
		$job = array( 'status' => 'running', 'phase' => 'database', 'session_id' => 'session-1' );

		$this->assertTrue( $store->save( $job ) );
		$GLOBALS['dd_phpunit_fail_update_option'] = true;

		$this->assertTrue( $store->save( $job ) );
		$this->assertSame( $job, $store->get() );
	}
}
