<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Unit;

use DDMaintenance\Tests\Support\LegacyScriptRunner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/LegacyScriptRunner.php';

final class LegacyScriptRunnerTest extends TestCase {
	public function testRestoreFailurePublishesAJsonArtifact(): void {
		$artifact = dirname( __DIR__ ) . '/artifacts/failure-missing-restore-fixture.php.json';
		if ( file_exists( $artifact ) ) {
			unlink( $artifact );
		}

		try {
			$result = LegacyScriptRunner::run( 'missing-restore-fixture.php' );

			$this->assertNotSame( 0, $result['exit_code'] );
			$this->assertFileExists( $artifact );
			$payload = json_decode( file_get_contents( $artifact ), true );
			$this->assertIsArray( $payload );
			$this->assertSame( 'missing-restore-fixture.php', $payload['script'] );
			$this->assertSame( $result['stderr'], $payload['stderr'] );
		} finally {
			if ( file_exists( $artifact ) ) {
				unlink( $artifact );
			}
		}
	}
}
