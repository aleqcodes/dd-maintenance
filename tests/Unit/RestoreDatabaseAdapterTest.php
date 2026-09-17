<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-restore-database-adapter.php';

final class RestoreDatabaseAdapterTest extends TestCase {
	public function testQueryFailureIsCountedAndSanitized(): void {
		$wpdb = new class {
			public $last_error = 'Access token=super-secret password=hunter2';

			public function query( string $sql ): bool {
				return false;
			}
		};
		$adapter = new \DD_Maintenance_Restore_Database_Adapter( $wpdb );

		$this->assertFalse( $adapter->execute( 'UPDATE wp_options SET option_value = \'secret\'' ) );
		$this->assertSame( 1, $adapter->error_count() );
		$this->assertCount( 1, $adapter->error_samples() );
		$this->assertStringContainsString( 'redacted', $adapter->error_samples()[0] );
		$this->assertStringNotContainsString( 'super-secret', $adapter->error_samples()[0] );
	}

	public function testRequiredQueryReturnsStableError(): void {
		$wpdb = new class {
			public $last_error = '';

			public function query( string $sql ): bool {
				return false;
			}
		};
		$adapter = new \DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$result = $adapter->execute_required( 'UPDATE wp_options SET option_value = 1', 'restore_option_update_failed', 'option update failed' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'restore_option_update_failed', $result->get_error_code() );
	}

	public function testOnlySafeDatabaseIdentifiersAreAccepted(): void {
		$this->assertTrue( \DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( 'wp_options' ) );
		$this->assertFalse( \DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( 'wp-options' ) );
		$this->assertFalse( \DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( 'wp_options` WHERE 1=1' ) );
	}
}
