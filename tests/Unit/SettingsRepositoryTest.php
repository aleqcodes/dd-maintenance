<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-dd-maintenance-settings-repository.php';

final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['dd_phpunit_options'] = array();
	}

	public function testDefaultsExposeEffectiveSplitSize(): void {
		$repository = new \DD_Maintenance_Settings_Repository();

		$this->assertSame( 200, $repository->get_split_size_mb() );
		$this->assertSame( 200, $repository->get()['split_size_mb'] );
	}

	/**
	 * @dataProvider invalidSplitSizeProvider
	 */
	public function testSplitSizeIsClampedAtRepositoryBoundary( $value, int $expected ): void {
		$repository = new \DD_Maintenance_Settings_Repository();

		$this->assertSame( $expected, $repository->normalize_split_size_mb( $value ) );
	}

	public static function invalidSplitSizeProvider(): array {
		return array(
			'below minimum' => array( 1, 25 ),
			'configured value' => array( 50, 50 ),
			'above maximum' => array( 5000, 1000 ),
		);
	}

	public function testSavedConfigurationOverridesDefaultWithoutAutoloading(): void {
		$GLOBALS['dd_phpunit_options']['dd_maintenance_settings'] = array( 'split_size_mb' => 50 );
		$repository = new \DD_Maintenance_Settings_Repository();

		$this->assertSame( 50, $repository->get_split_size_mb() );
	}
}
