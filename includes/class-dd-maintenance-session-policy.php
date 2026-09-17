<?php
/**
 * Contrato, migração e transições dos estados de sessão.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Session_Policy {
	const SCHEMA_VERSION = 2;

	const STATUS_CREATED          = 'created';
	const STATUS_RUNNING          = 'running';
	const STATUS_COMPLETED        = 'completed';
	const STATUS_FAILED           = 'failed';
	const STATUS_CLEANUP_PENDING  = 'cleanup_pending';
	const STATUS_CLEANED          = 'cleaned';

	/** @var string[] */
	private static $statuses = array(
		self::STATUS_CREATED,
		self::STATUS_RUNNING,
		self::STATUS_COMPLETED,
		self::STATUS_FAILED,
		self::STATUS_CLEANUP_PENDING,
		self::STATUS_CLEANED,
	);

	/**
	 * Converte o formato anterior para o contrato versionado sem descartar campos.
	 *
	 * @param array  $data Estado legado.
	 * @param string $type Tipo da sessão.
	 * @return array
	 */
	public static function migrate( array $data, string $type = 'generic' ): array {
		$now = time();
		$data['_schema_version'] = self::SCHEMA_VERSION;
		$data['session_type'] = $type;
		$data['created_at'] = isset( $data['created_at'] ) ? (int) $data['created_at'] : $now;
		$data['updated_at'] = isset( $data['updated_at'] ) ? (int) $data['updated_at'] : $data['created_at'];
		$data['started_at'] = isset( $data['started_at'] ) ? (int) $data['started_at'] : $data['created_at'];
		$data['finished_at'] = isset( $data['finished_at'] ) ? (int) $data['finished_at'] : null;
		$data['last_step'] = isset( $data['last_step'] ) ? (string) $data['last_step'] : (string) ( $data['phase'] ?? $data['step'] ?? 'init' );
		$data['status'] = self::normalize_status( $data['status'] ?? null, $data );

		if ( 'backup' === $type ) {
			$data['session_dir'] = (string) ( $data['session_dir'] ?? '' );
			$data['base_name'] = (string) ( $data['base_name'] ?? '' );
			$data['settings'] = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		}
		if ( 'restore' === $type ) {
			$data['extract_dir'] = (string) ( $data['extract_dir'] ?? '' );
			$data['zip_paths'] = is_array( $data['zip_paths'] ?? null ) ? array_values( $data['zip_paths'] ) : array();
			$data['auth_token_hash'] = (string) ( $data['auth_token_hash'] ?? '' );
			$data['auth_expires_at'] = isset( $data['auth_expires_at'] ) ? (int) $data['auth_expires_at'] : 0;
		}
		return $data;
	}

	/**
	 * Valida o contrato sem normalizar silenciosamente um estado inválido.
	 *
	 * @param array  $data Estado migrado.
	 * @param string $type Tipo da sessão.
	 * @return true|WP_Error
	 */
	public static function validate( array $data, string $type = 'generic' ) {
		if ( ! isset( $data['_schema_version'] ) || (int) $data['_schema_version'] !== self::SCHEMA_VERSION ) {
			return new WP_Error( 'session_schema_version', __( 'Versão dos dados da sessão inválida.', 'dd-maintenance' ) );
		}
		if ( empty( $data['session_id'] ) || ! is_string( $data['session_id'] ) ) {
			return new WP_Error( 'session_schema_invalid', __( 'Identificador da sessão ausente ou inválido.', 'dd-maintenance' ) );
		}
		if ( ! in_array( (string) ( $data['status'] ?? '' ), self::$statuses, true ) ) {
			return new WP_Error( 'session_schema_invalid', __( 'Estado da sessão inválido.', 'dd-maintenance' ) );
		}
		foreach ( array( 'created_at', 'updated_at', 'started_at' ) as $timestamp ) {
			if ( ! is_int( $data[ $timestamp ] ) || $data[ $timestamp ] <= 0 ) {
				return new WP_Error( 'session_schema_invalid', __( 'Timestamp da sessão inválido.', 'dd-maintenance' ) );
			}
		}
		if ( ! is_string( $data['last_step'] ?? null ) || '' === $data['last_step'] ) {
			return new WP_Error( 'session_schema_invalid', __( 'Última etapa da sessão inválida.', 'dd-maintenance' ) );
		}
		if ( 'backup' === $type && ( '' === (string) ( $data['session_dir'] ?? '' ) || '' === (string) ( $data['base_name'] ?? '' ) || ! is_array( $data['settings'] ?? null ) ) ) {
			return new WP_Error( 'session_schema_invalid', __( 'Metadados da sessão de backup incompletos.', 'dd-maintenance' ) );
		}
		if ( 'restore' === $type ) {
			if ( '' === (string) ( $data['extract_dir'] ?? '' ) || ! is_array( $data['zip_paths'] ?? null ) ) {
				return new WP_Error( 'session_schema_invalid', __( 'Metadados da sessão de restauração incompletos.', 'dd-maintenance' ) );
			}
			$auth_hash = (string) ( $data['auth_token_hash'] ?? '' );
			$auth_expires = (int) ( $data['auth_expires_at'] ?? 0 );
			if ( ( '' === $auth_hash ) !== ( $auth_expires <= 0 ) ) {
				return new WP_Error( 'session_schema_invalid', __( 'Autorização da sessão de restauração inválida.', 'dd-maintenance' ) );
			}
			if ( $auth_expires > 0 && self::is_expired( $data ) ) {
				return new WP_Error( 'session_expired', __( 'A sessão de restauração expirou.', 'dd-maintenance' ) );
			}
		}
		return true;
	}

	/** @param string $from Estado atual. @param string $to Próximo estado. @return bool */
	public static function can_transition( string $from, string $to ): bool {
		$allowed = array(
			self::STATUS_CREATED => array( self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_CLEANUP_PENDING ),
			self::STATUS_RUNNING => array( self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CLEANUP_PENDING ),
			self::STATUS_COMPLETED => array( self::STATUS_CLEANUP_PENDING, self::STATUS_CLEANED ),
			self::STATUS_FAILED => array( self::STATUS_CLEANUP_PENDING, self::STATUS_CLEANED ),
			self::STATUS_CLEANUP_PENDING => array( self::STATUS_CLEANED, self::STATUS_CLEANUP_PENDING ),
			self::STATUS_CLEANED => array( self::STATUS_CLEANED ),
		);
		return in_array( $to, $allowed[ $from ] ?? array(), true );
	}

	/** @param array $data Sessão. @return bool */
	public static function is_expired( array $data ): bool {
		return isset( $data['auth_expires_at'] ) && (int) $data['auth_expires_at'] > 0 && time() > (int) $data['auth_expires_at'];
	}

	/** @param mixed $status Estado armazenado. @param array $data Sessão. @return string */
	private static function normalize_status( $status, array $data ): string {
		$status = (string) $status;
		if ( in_array( $status, self::$statuses, true ) ) {
			return $status;
		}
		if ( ! empty( $data['finished_at'] ) ) {
			return self::STATUS_COMPLETED;
		}
		$step = (string) ( $data['phase'] ?? $data['step'] ?? '' );
		return '' !== $step && 'init' !== $step ? self::STATUS_RUNNING : self::STATUS_CREATED;
	}
}
