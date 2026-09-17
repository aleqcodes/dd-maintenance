<?php

declare(strict_types=1);

namespace DDMaintenance\Tests\Support;

final class LegacyScriptRunner {
	/**
	 * Executa um script legado em processo separado para isolar funções WordPress globais.
	 *
	 * @param string $script Caminho relativo a tests/.
	 * @return array{exit_code:int,stdout:string,stderr:string}
	 */
	public static function run( string $script ): array {
		$path = dirname( __DIR__, 2 ) . '/tests/' . ltrim( $script, '/' );
		$command = implode(
			' ',
			array(
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( '-d' ),
				escapeshellarg( 'zend.assertions=1' ),
				escapeshellarg( '-d' ),
				escapeshellarg( 'assert.exception=1' ),
				escapeshellarg( $path ),
			)
		);
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $descriptors, $pipes, dirname( __DIR__, 2 ) );
		if ( ! is_resource( $process ) ) {
			return array( 'exit_code' => 127, 'stdout' => '', 'stderr' => 'Não foi possível iniciar o processo.' );
		}
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		$exit_code = proc_close( $process );
		if ( 0 !== $exit_code ) {
			$artifact_dir = dirname( __DIR__ ) . '/artifacts';
			if ( ! is_dir( $artifact_dir ) ) {
				mkdir( $artifact_dir, 0755, true );
			}
			file_put_contents(
				$artifact_dir . '/failure-' . preg_replace( '/[^a-z0-9_.-]+/i', '-', $script ) . '.json',
				json_encode(
					array(
						'script'    => $script,
						'step'      => 'legacy_script:' . $script,
						'exit_code' => $exit_code,
						'stdout'    => $stdout,
						'stderr'    => $stderr,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
				)
			);
		}
		return array(
			'exit_code' => (int) $exit_code,
			'stdout'    => (string) $stdout,
			'stderr'    => (string) $stderr,
		);
	}
}
