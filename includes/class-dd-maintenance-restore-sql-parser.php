<?php
/**
 * Parser incremental de dumps SQL para restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

final class DD_Maintenance_Restore_Sql_Parser {
	/**
	 * Processa um recurso SQL e emite comandos completos ao callback.
	 *
	 * @param resource $handle Recurso aberto para leitura.
	 * @param callable $on_query Recebe cada comando SQL não ignorado.
	 * @param array    $state Estado incremental opcional.
	 * @param int      $max_queries Limite de comandos; zero significa sem limite.
	 * @param float    $deadline Timestamp monotônico; zero significa sem limite.
	 * @return array
	 */
	public function parse( $handle, callable $on_query, array $state = array(), int $max_queries = 0, float $deadline = 0.0 ): array {
		$buffer      = (string) ( $state['buffer'] ?? '' );
		$in_string   = (bool) ( $state['in_string'] ?? false );
		$string_char = (string) ( $state['string_char'] ?? '' );
		$in_comment  = (bool) ( $state['in_comment'] ?? false );
		$queries     = 0;
		$tables      = 0;
		$skipped     = 0;
		$eof         = false;

		while ( ! feof( $handle ) ) {
			if ( $deadline > 0.0 && $queries > 0 && microtime( true ) >= $deadline ) {
				break;
			}
			if ( $max_queries > 0 && $queries >= $max_queries ) {
				break;
			}

			$line = fgets( $handle, 1048576 );
			if ( false === $line ) {
				$eof = true;
				break;
			}

			$line_length = strlen( $line );
			$line_sql    = '';
			for ( $i = 0; $i < $line_length; $i++ ) {
				$char      = $line[ $i ];
				$next_char = $i + 1 < $line_length ? $line[ $i + 1 ] : '';

				if ( $in_comment ) {
					if ( '*' === $char && '/' === $next_char ) {
						$in_comment = false;
						$i++;
					}
					continue;
				}

				if ( ! $in_string && '/' === $char && '*' === $next_char ) {
					$in_comment = true;
					$i++;
					continue;
				}
				if ( ! $in_string && '#' === $char ) {
					break;
				}
				if ( ! $in_string && '-' === $char && '-' === $next_char ) {
					break;
				}

				$line_sql .= $char;
				if ( in_array( $char, array( "'", '"', '`' ), true ) ) {
					if ( ! $in_string ) {
						$in_string   = true;
						$string_char = $char;
					} elseif ( $string_char === $char && ! $this->is_escaped( $line_sql, strlen( $line_sql ) - 1 ) ) {
						$in_string   = false;
						$string_char = '';
					}
				}
			}

			$buffer .= $line_sql;
			if ( ! $in_string && false !== strpos( $line_sql, ';' ) ) {
				$statements = $this->split_complete_statements( $buffer );
				$buffer     = $statements['remainder'];
				foreach ( $statements['queries'] as $sql ) {
					if ( $this->is_ignored_statement( $sql ) ) {
						$skipped++;
						continue;
					}
					if ( preg_match( '/^(CREATE|DROP)\s+TABLE\b/i', $sql ) ) {
						$tables++;
					}
					$queries++;
					$result = call_user_func( $on_query, $sql );
					if ( false === $result ) {
						return array(
							'queries'     => $queries,
							'tables'      => $tables,
							'skipped'     => $skipped,
							'eof'         => false,
							'aborted'     => true,
							'buffer'      => $buffer,
							'in_string'   => $in_string,
							'string_char' => $string_char,
							'in_comment'  => $in_comment,
						);
					}
				}
			}
		}

		return array(
			'queries'     => $queries,
			'tables'      => $tables,
			'skipped'     => $skipped,
			'eof'         => $eof,
			'aborted'     => false,
			'buffer'      => $buffer,
			'in_string'   => $in_string,
			'string_char' => $string_char,
			'in_comment'  => $in_comment,
		);
	}

	/** @param string $buffer @return array */
	private function split_complete_statements( string $buffer ): array {
		$queries = array();
		$start   = 0;
		$length  = strlen( $buffer );
		$quote   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $buffer[ $i ];
			if ( in_array( $char, array( "'", '"', '`' ), true ) ) {
				if ( '' === $quote ) {
					$quote = $char;
				} elseif ( $quote === $char && ! $this->is_escaped( $buffer, $i ) ) {
					$quote = '';
				}
			} elseif ( ';' === $char && '' === $quote ) {
				$sql = trim( substr( $buffer, $start, $i - $start + 1 ) );
				if ( '' !== $sql ) {
					$queries[] = $sql;
				}
				$start = $i + 1;
			}
		}
		return array(
			'queries'   => $queries,
			'remainder' => substr( $buffer, $start ),
		);
	}

	private function is_ignored_statement( string $sql ): bool {
		return (bool) preg_match( '/^(CREATE\s+DATABASE|DROP\s+DATABASE|USE)\b/i', trim( $sql ) );
	}

	private function is_escaped( string $value, int $position ): bool {
		$slashes = 0;
		for ( $i = $position - 1; $i >= 0 && '\\' === $value[ $i ]; $i-- ) {
			$slashes++;
		}
		return 1 === $slashes % 2;
	}
}
