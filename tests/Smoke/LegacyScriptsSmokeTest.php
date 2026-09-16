<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Smoke;

use DDMaintenance\Tests\Support\LegacyScriptRunner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/LegacyScriptRunner.php';

final class LegacyScriptsSmokeTest extends TestCase {
	/**
	 * @dataProvider scriptProvider
	 */
	public function testLegacyScriptCompletesSuccessfully( string $script, string $expectedOutput ): void {
		$result = LegacyScriptRunner::run( $script );

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( $expectedOutput, $result['stdout'] );
	}

	public static function scriptProvider(): array {
		return array(
			'backup batches' => array( 'backup-batch-self-check.php', 'backup volume self-check: OK' ),
			'chunked upload' => array( 'test-chunked-upload-restore.php', 'upload sequencial' ),
			'file security' => array( 'test-file-security.php', 'File security paths validated' ),
			'local backups' => array( 'test-local-backup-download.php', 'listagem, download' ),
			'serialized replace' => array( 'test-serialized-replace.php', 'Search & Replace' ),
		);
	}
}
