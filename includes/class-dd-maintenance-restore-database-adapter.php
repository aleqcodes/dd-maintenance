<?php
/**
 * Adaptador de consultas usado durante a restauração do banco.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Restore_Database_Adapter {
	/** @var object */
	private $wpdb;

	/** @var mixed */
	private $dbh;

	/** @var int */
	private $errors;

	/** @var string[] */
	private $error_samples;

	/** @var array<string, string> */
	private $event_context;


	/**
	 * @param object   $wpdb          API de banco do WordPress.
	 * @param mixed    $dbh           Conexão mysqli opcional.
	 * @param int      $errors        Erros já acumulados.
	 * @param string[] $error_samples Amostras já acumuladas.
	 * @param array    $event_context Contexto operacional seguro.
	 */
	public function __construct( $wpdb, $dbh = null, int $errors = 0, array $error_samples = array(), array $event_context = array() ) {
		$this->wpdb          = $wpdb;
		$this->dbh           = $dbh;
		$this->errors        = $errors;
		$this->error_samples = $error_samples;
		$this->event_context = $event_context;
	}

	/** @return bool */
	public function uses_mysqli(): bool {
		return class_exists( 'mysqli' ) && $this->dbh instanceof mysqli;
	}

	/**
	 * Executa uma consulta e registra falhas sem expor SQL ou valores sensíveis.
	 *
	 * @param string $sql SQL.
	 * @return bool
	 */
	public function execute( string $sql ): bool {
		if ( class_exists( 'mysqli' ) && $this->dbh instanceof mysqli ) {
			$result = mysqli_query( $this->dbh, $sql );
			if ( false !== $result ) {
				return true;
			}
			$this->record_error( mysqli_error( $this->dbh ) );
			return false;
		}

		$result = $this->wpdb->query( $sql );
		if ( false !== $result ) {
			return true;
		}
		$this->record_error( isset( $this->wpdb->last_error ) ? $this->wpdb->last_error : '' );
		return false;
	}

	/**
	 * Executa consulta obrigatória e transforma a falha em WP_Error estável.
	 *
	 * @param string $sql     SQL.
	 * @param string $code    Código de erro.
	 * @param string $message Mensagem.
	 * @return true|WP_Error
	 */
	public function execute_required( string $sql, string $code, string $message ) {
		if ( $this->execute( $sql ) ) {
			return true;
		}
		if ( class_exists( 'DD_Maintenance' ) && method_exists( 'DD_Maintenance', 'record_event' ) ) {
			DD_Maintenance::record_event(
				'restore',
				'required_query_failed',
				array(
					'step'           => $this->event_context['step'] ?? 'restore_database',
					'session_id'     => $this->event_context['session_id'] ?? '',
					'correlation_id' => $this->event_context['correlation_id'] ?? '',
					'status'         => 'failure',
					'failure_code'   => $code,
					'error_count'    => 1,
				)
			);
		}
		return new WP_Error( $code, $message );
	}

	/**
	 * Registra uma falha sem SQL, preservando no máximo três amostras sanitizadas.
	 *
	 * @param string $message Mensagem do driver.
	 */
	public function record_error( string $message = '' ): void {
		$this->errors++;
		$message = $this->sanitize_error( $message );
		if ( '' !== $message && count( $this->error_samples ) < 3 && ! in_array( $message, $this->error_samples, true ) ) {
			$this->error_samples[] = $message;
		}
	}

	/** @return int */
	public function error_count(): int {
		return $this->errors;
	}

	/** @return string[] */
	public function error_samples(): array {
		return $this->error_samples;
	}

	/**
	 * Retorna uma mensagem de falha sem credenciais, SQL ou conteúdo ilimitado.
	 *
	 * @param mixed $message Mensagem do driver.
	 * @return string
	 */
	private function sanitize_error( $message ): string {
		$message = preg_replace( '/\s+/', ' ', trim( (string) $message ) );
		$message = preg_replace( '/(password|secret|token|access[_ ]?key)\s*[=:]\s*[^ ]+/i', '$1=[redacted]', $message );
		return substr( (string) $message, 0, 240 );
	}

	/**
	 * @param string $sql  Consulta preparada.
	 * @param mixed  ...$args Argumentos.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		return (string) call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
	}

	/** @return mixed */
	public function get_var( string $sql ) {
		return $this->wpdb->get_var( $sql );
	}

	/** @return mixed */
	public function get_row( string $sql, $format = ARRAY_A ) {
		return $this->wpdb->get_row( $sql, $format );
	}

	/** @return mixed */
	public function get_results( string $sql, $format = ARRAY_A ) {
		return $this->wpdb->get_results( $sql, $format );
	}

	/** @return mixed */
	public function get_col( string $sql ) {
		return $this->wpdb->get_col( $sql );
	}

	/**
	 * Valida identificadores antes de interpolá-los em SQL.
	 *
	 * @param string $identifier Identificador.
	 * @return bool
	 */
	public static function is_safe_identifier( string $identifier ): bool {
		return '' !== $identifier && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $identifier );
	}
}
