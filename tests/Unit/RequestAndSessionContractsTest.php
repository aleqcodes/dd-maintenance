<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-request.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-progress.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-backup-result.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-storage-upload-result.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-session-policy.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-session-store.php';

final class RequestAndSessionContractsTest extends TestCase {
	protected function tearDown(): void {
		$_POST = array();
		$_GET = array();
		$_FILES = array();
	}

	public function testAdministrativeRequestSnapshotsAndSanitizesInput(): void {
		$_POST = array(
			'active_tab' => 's3<script>',
			'include_db' => '1',
			'split_size_mb' => '200',
		);
		$_GET = array( 'page' => 'dd_maintenance' );

		$request = \DD_Maintenance_Settings_Request::from_globals();

		$this->assertSame( 's3script', $request->post_key( 'active_tab' ) );
		$this->assertTrue( $request->post_flag( 'include_db' ) );
		$this->assertSame( 200, $request->post_int( 'split_size_mb' ) );
		$this->assertSame( 'dd_maintenance', $request->get_key( 'page' ) );
	}

	public function testCompletedBackupRequiresArtifactAndProgressKeepsTypedContract(): void {
		$unfinished = \DD_Maintenance_Backup_Result::from_array( array( 'completed' => true ) );
		$finished = \DD_Maintenance_Backup_Result::from_array( array( 'completed' => true, 'file' => '/tmp/backup.zip' ) );
		$progress = \DD_Maintenance_Progress::from_array( array( 'completed' => true, 'percent' => 140, 'total_files' => 3 ) );

		$this->assertFalse( $unfinished->is_completed() );
		$this->assertTrue( $finished->is_completed() );
		$this->assertSame( 100, $progress->percent() );
		$this->assertSame( 3, $progress->total_items() );
	}

	public function testUploadResultCannotReportSuccessBeforeCompletion(): void {
		$result = \DD_Maintenance_Storage_Upload_Result::start( 2, 20 );
		$result->mark_uploaded();
		$this->assertFalse( $result->is_success() );
		$result->complete();
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $result->uploaded() );
	}

	public function testSessionPolicyDefinesTransitionsAndExpiry(): void {
		$this->assertTrue( \DD_Maintenance_Session_Policy::can_transition( 'created', 'running' ) );
		$this->assertTrue( \DD_Maintenance_Session_Policy::can_transition( 'running', 'cleanup_pending' ) );
		$this->assertFalse( \DD_Maintenance_Session_Policy::can_transition( 'completed', 'running' ) );
		$this->assertTrue( \DD_Maintenance_Session_Policy::is_expired( array( 'auth_expires_at' => time() - 1 ) ) );
	}

	public function testSessionStorePersistsVersionAndRejectsConcurrentReader(): void {
		$directory = sys_get_temp_dir() . '/dd-session-contract-' . uniqid( '', true );
		mkdir( $directory, 0755, true );
		$first = new \DD_Maintenance_Session_Store();
		$second = new \DD_Maintenance_Session_Store();
		$state = array( 'session_id' => 'contract-test', 'step' => 'init' );

		$this->assertTrue( $first->save( $directory, $state ) );
		$this->assertTrue( $first->transition( $directory, 'created', 'running' ) );
		$loaded = $first->load( $directory, 'missing', 'corrupt' );
		$this->assertIsArray( $loaded );
		$this->assertSame( 'running', $loaded['status'] );
		$loaded = $first->load( $directory, 'missing', 'corrupt' );
		$this->assertIsArray( $loaded );
		$this->assertSame( \DD_Maintenance_Session_Policy::SCHEMA_VERSION, $loaded['_schema_version'] );
		$this->assertWPErrorWithCode( $second->load( $directory, 'missing', 'corrupt' ), 'session_locked' );
		$first->release( $directory );
		$this->assertIsArray( $second->load( $directory, 'missing', 'corrupt' ) );
		$second->release( $directory );
		$this->assertTrue( $first->remove_directory( $directory ) );
	}
	private function assertWPErrorWithCode( $value, string $code ): void {
		$this->assertTrue( is_wp_error( $value ) );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
