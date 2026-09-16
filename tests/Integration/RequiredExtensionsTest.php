<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RequiredExtensionsTest extends TestCase {
	public function testRuntimeProvidesRequiredBackupExtensions(): void {
		$this->assertTrue( extension_loaded( 'curl' ), 'A extensão cURL é obrigatória para upload S3 streaming.' );
		$this->assertTrue( extension_loaded( 'mysqli' ), 'A extensão mysqli é obrigatória para dump e restore do banco.' );
		$this->assertTrue( extension_loaded( 'zip' ) && class_exists( 'ZipArchive' ), 'A extensão ZipArchive é obrigatória para volumes.' );
	}
	public function testWordPressCoreMatrixProvidesARealCoreTree(): void {
		$core_dir = getenv( 'WP_CORE_DIR' );
		if ( false === $core_dir || '' === $core_dir ) {
			$this->markTestSkipped( 'WP_CORE_DIR não foi configurado fora da matriz de CI.' );
		}

		$this->assertFileExists( $core_dir . '/wp-includes/version.php' );
	}
}
