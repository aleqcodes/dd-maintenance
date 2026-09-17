<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-backup.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore.php';

final class DecompositionContractsTest extends TestCase {
	public function testBackupFacadeRoutesInvalidStepInputThroughComponentContract(): void {
		$backup = new \DD_Maintenance_Backup();
		$result = $backup->dump_database_step( '' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'session_id_missing', $result->get_error_code() );
	}

	public function testRestoreFacadeRoutesInvalidStepInputThroughComponentContract(): void {
		$restore = new \DD_Maintenance_Restore();
		$result = $restore->restore_database_step( '' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'session_id_missing', $result->get_error_code() );
	}

	public function testArchiveWorkflowRejectsAnEmptyVolumeSetBeforeWriting(): void {
		$implementation = new \DD_Maintenance_Restore_Implementation();
		$workflow = new \DD_Maintenance_Restore_Archive_Service( $implementation );
		$result = $workflow->run( array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'join_empty_list', $result->get_error_code() );
	}
}
