<?php
/**
 * Valida o contrato de evidências da matriz de staging.
 *
 * Uso: php validate-evidence.php [--require-pass] evidence.json
 */

declare( strict_types=1 );

const DD_STAGING_SCENARIOS = array(
	'small-site',
	'large-database',
	'oversized-file',
	'multiple-volumes',
	'elementor-active',
	'alternate-prefix',
	'different-urls',
	'interrupt-extraction',
	'interrupt-sql',
	'interrupt-file-copy',
	'disk-full',
	's3-network-failure',
	'timeout-retry',
	'invalid-checksum',
	'concurrent-session',
	'public-continuation',
	'rollback-mode',
	'cleanup-failure',
);

const DD_STAGING_FIELDS = array(
	'status',
	'correlation_id',
	'session_id',
	'step',
	'failure_code',
	'events_jsonl',
	'text_log',
	'volume_checksums',
	'files',
	'tables',
	'queries',
	'errors',
	'warnings',
	'final_urls',
	'final_config',
	'elementor',
	'cleanup',
	'duration_seconds',
	'repeat_result',
);


/** @param mixed $value */
function dd_staging_find_sensitive_keys( $value, string $path = '' ): array {
	$found = array();
	if ( ! is_array( $value ) ) {
		return $found;
	}
	foreach ( $value as $key => $child ) {
		$current = '' === $path ? (string) $key : $path . '.' . $key;
		if ( preg_match( '/(?:secret|password|token|authorization|cookie|access[_ -]?key)/i', (string) $key ) ) {
			$found[] = $current;
		}
		$found = array_merge( $found, dd_staging_find_sensitive_keys( $child, $current ) );
	}
	return $found;
}

/** @param mixed $value */
function dd_staging_require_type( array &$errors, string $path, $value, string $type ): void {
	$valid = 'string' === $type ? is_string( $value ) : ( 'array' === $type ? is_array( $value ) : ( 'number' === $type && ( is_int( $value ) || is_float( $value ) ) ) );
	if ( ! $valid ) {
		$errors[] = sprintf( '%s deve ser %s.', $path, $type );
	}
}

/** @return int */
function dd_staging_validate( string $filename, bool $require_pass = false ): int {
	$errors = array();
	if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
		$errors[] = sprintf( 'Arquivo de evidências não encontrado ou ilegível: %s', $filename );
	} else {
		$raw = file_get_contents( $filename );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			$errors[] = sprintf( 'JSON inválido: %s.', json_last_error_msg() );
		} else {
			foreach ( array( 'commit', 'plugin_version', 'php', 'wordpress', 'extensions', 'scenarios' ) as $field ) {
				if ( ! array_key_exists( $field, $data ) ) {
					$errors[] = sprintf( 'Campo obrigatório ausente: %s.', $field );
				}
			}
			foreach ( array( 'commit', 'plugin_version', 'php', 'wordpress' ) as $field ) {
				if ( array_key_exists( $field, $data ) ) {
					dd_staging_require_type( $errors, $field, $data[ $field ], 'string' );
				}
			}
			if ( isset( $data['extensions'] ) ) {
				dd_staging_require_type( $errors, 'extensions', $data['extensions'], 'array' );
			}
			if ( isset( $data['scenarios'] ) ) {
				dd_staging_require_type( $errors, 'scenarios', $data['scenarios'], 'array' );
			}
			$scenarios = is_array( $data['scenarios'] ?? null ) ? $data['scenarios'] : array();
			foreach ( DD_STAGING_SCENARIOS as $scenario_id ) {
				if ( ! array_key_exists( $scenario_id, $scenarios ) ) {
					$errors[] = sprintf( 'Cenário obrigatório ausente: %s.', $scenario_id );
					continue;
				}
				$scenario = $scenarios[ $scenario_id ];
				if ( ! is_array( $scenario ) ) {
					$errors[] = sprintf( 'Cenário %s deve ser um objeto JSON.', $scenario_id );
					continue;
				}
				foreach ( DD_STAGING_FIELDS as $field ) {
					if ( ! array_key_exists( $field, $scenario ) ) {
						$errors[] = sprintf( 'Campo ausente em %s: %s.', $scenario_id, $field );
					}
				}
				$status = $scenario['status'] ?? null;
				if ( ! is_string( $status ) || ! in_array( $status, array( 'pending', 'pass', 'fail', 'blocked' ), true ) ) {
					$errors[] = sprintf( 'Status inválido em %s; use pending, pass, fail ou blocked.', $scenario_id );
				}
				foreach ( array( 'events_jsonl', 'text_log' ) as $field ) {
					if ( array_key_exists( $field, $scenario ) ) {
						dd_staging_require_type( $errors, $scenario_id . '.' . $field, $scenario[ $field ], 'string' );
					}
				}
			foreach ( array( 'volume_checksums', 'files', 'tables', 'queries', 'errors', 'warnings', 'final_urls', 'final_config', 'elementor', 'cleanup' ) as $field ) {
					if ( array_key_exists( $field, $scenario ) ) {
						dd_staging_require_type( $errors, $scenario_id . '.' . $field, $scenario[ $field ], 'array' );
					}
				}
				if ( array_key_exists( 'duration_seconds', $scenario ) ) {
					dd_staging_require_type( $errors, $scenario_id . '.duration_seconds', $scenario['duration_seconds'], 'number' );
				}
				if ( $require_pass && 'pass' !== $status ) {
					$errors[] = sprintf( 'Cenário %s não está aprovado: status=%s.', $scenario_id, (string) $status );
				}
			}
			$sensitive = dd_staging_find_sensitive_keys( $data );
			if ( ! empty( $sensitive ) ) {
				$errors[] = 'Evidência contém chaves sensíveis; redija-as antes de armazenar: ' . implode( ', ', $sensitive );
			}
		}
	}
	if ( empty( $errors ) ) {
		fwrite( STDOUT, sprintf( "staging evidence: OK (%s)\n", $filename ) );
		return 0;
	}
	fwrite( STDERR, "staging evidence: INVALID\n" );
	foreach ( $errors as $error ) {
		fwrite( STDERR, '- ' . $error . "\n" );
	}
	return 1;
}

$args = $argv;
array_shift( $args );
$require_pass = false;
if ( in_array( '--require-pass', $args, true ) ) {
	$require_pass = true;
	$args = array_values( array_diff( $args, array( '--require-pass' ) ) );
}
if ( 1 !== count( $args ) ) {
	fwrite( STDERR, "Uso: php validate-evidence.php [--require-pass] evidence.json\n" );
	exit( 2 );
}
exit( dd_staging_validate( $args[0], $require_pass ) );
