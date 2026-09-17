<?php
/**
 * Armazenamento atômico, versionado e bloqueado das sessões.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-session-policy.php';

class DD_Maintenance_Session_Store {
	const SCHEMA_VERSION = DD_Maintenance_Session_Policy::SCHEMA_VERSION;

	/** @var resource[] */
	private $locks = array();

	/** @param string $directory Diretório da sessão. @param array $data Estado. @param string $type Tipo. @return bool */
	public function save( string $directory, array $data, string $type = 'generic' ): bool {
		if ( ! is_dir( $directory ) || ! $this->directory_is_writable( $directory ) ) {
			return false;
		}
		if ( ! $this->acquire( $directory ) ) {
			return false;
		}

		$data = DD_Maintenance_Session_Policy::migrate( $data, $type );
		$validation = DD_Maintenance_Session_Policy::validate( $data, $type );
		if ( true !== $validation ) {
			$this->release( $directory );
			return false;
		}
		$data['updated_at'] = time();
		unset( $data['_state_checksum'] );
		$data['_state_checksum'] = $this->checksum( $data );
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			$this->release( $directory );
			return false;
		}

		$state_file = rtrim( $directory, '/\\' ) . '/state.json';
		$temp_file  = $state_file . '.tmp';
		$bytes      = file_put_contents( $temp_file, $json, LOCK_EX );
		if ( false === $bytes || (int) $bytes !== strlen( $json ) ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			$this->release( $directory );
			return false;
		}
		if ( function_exists( 'chmod' ) && ! chmod( $temp_file, 0600 ) ) {
			unlink( $temp_file );
			$this->release( $directory );
			return false;
		}
		if ( ! rename( $temp_file, $state_file ) ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			$this->release( $directory );
			return false;
		}
		$this->release( $directory );
		return true;
	}

	/** @param string $directory Diretório. @param string $missing_code Código ausente. @param string $corrupt_code Código corrompido. @param string $type Tipo. @return array|WP_Error */
	public function load( string $directory, string $missing_code, string $corrupt_code, string $type = 'generic' ) {
		$state_file = rtrim( $directory, '/\\' ) . '/state.json';
		if ( ! is_file( $state_file ) ) {
			return new WP_Error( $missing_code, __( 'Estado da sessão não encontrado.', 'dd-maintenance' ) );
		}
		if ( ! $this->acquire( $directory ) ) {
			return new WP_Error( 'session_locked', __( 'A sessão está sendo processada por outra requisição.', 'dd-maintenance' ) );
		}
		$json = file_get_contents( $state_file );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			$this->release( $directory );
			return new WP_Error( $corrupt_code, __( 'Dados da sessão corrompidos.', 'dd-maintenance' ) );
		}
		if ( isset( $data['_schema_version'] ) && (int) $data['_schema_version'] > self::SCHEMA_VERSION ) {
			$this->release( $directory );
			return new WP_Error( 'session_schema_version', __( 'Versão dos dados da sessão não suportada.', 'dd-maintenance' ) );
		}
		if ( isset( $data['_state_checksum'] ) ) {
			$checksum = $data['_state_checksum'];
			unset( $data['_state_checksum'] );
			if ( ! is_string( $checksum ) || ! hash_equals( $this->checksum( $data ), $checksum ) ) {
				$this->release( $directory );
				return new WP_Error( $corrupt_code, __( 'A integridade dos dados da sessão não pôde ser validada.', 'dd-maintenance' ) );
			}
			$data['_state_checksum'] = $checksum;
		}
		$data = DD_Maintenance_Session_Policy::migrate( $data, $type );
		$validation = DD_Maintenance_Session_Policy::validate( $data, $type );
		if ( true !== $validation ) {
			$this->release( $directory );
			return $validation;
		}
		return $data;
	}

	/** @param string $directory Sessão. @param string $from Estado atual. @param string $to Próximo estado. @param string $type Tipo. @return bool */
	public function transition( string $directory, string $from, string $to, string $type = 'generic' ): bool {
		$data = $this->load( $directory, 'session_not_found', 'session_corrupted', $type );
		if ( is_wp_error( $data ) ) {
			return false;
		}
		if ( (string) ( $data['status'] ?? '' ) !== $from || ! DD_Maintenance_Session_Policy::can_transition( $from, $to ) ) {
			$this->release( $directory );
			return false;
		}
		$data['status'] = $to;
		$data['last_step'] = (string) ( $data['last_step'] ?? 'init' );
		if ( DD_Maintenance_Session_Policy::STATUS_RUNNING === $to && empty( $data['started_at'] ) ) {
			$data['started_at'] = time();
		}
		if ( in_array( $to, array( DD_Maintenance_Session_Policy::STATUS_COMPLETED, DD_Maintenance_Session_Policy::STATUS_FAILED, DD_Maintenance_Session_Policy::STATUS_CLEANED ), true ) ) {
			$data['finished_at'] = time();
		}
		return $this->save( $directory, $data, $type );
	}

	/** @param string $directory Diretório. @return void */
	public function release( string $directory ): void {
		$key = rtrim( $directory, '/\\' );
		if ( isset( $this->locks[ $key ] ) && is_resource( $this->locks[ $key ] ) ) {
			flock( $this->locks[ $key ], LOCK_UN );
			fclose( $this->locks[ $key ] );
		}
		unset( $this->locks[ $key ] );
	}

	/** @return void */
	public function release_all(): void {
		foreach ( array_keys( $this->locks ) as $directory ) {
			$this->release( $directory );
		}
	}

	/** @param string $root Raiz. @param int $max_age Idade máxima. @param int|null $now Relógio opcional. @return string[] */
	public function garbage_collect( string $root, int $max_age, ?int $now = null ): array {
		$removed = array();
		$now = $now ?? time();
		if ( ! is_dir( $root ) ) {
			return $removed;
		}
		$items = new DirectoryIterator( $root );
		foreach ( $items as $item ) {
			if ( $item->isDot() || ! $item->isDir() || $item->isLink() ) {
				continue;
			}
			$directory = $item->getPathname();
			if ( ! $this->acquire( $directory ) ) {
				continue;
			}
			$state_file = rtrim( $directory, '/\\' ) . '/state.json';
			$state = is_file( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : array();
			$updated = (int) ( $state['updated_at'] ?? $state['created_at'] ?? $item->getMTime() );
			$stale = $updated > 0 && ( $now - $updated ) > $max_age;
			$this->release( $directory );
			if ( $stale && $this->remove_directory( $directory ) ) {
				$removed[] = $directory;
			}
		}
		return $removed;
	}

	/** @param array $data Estado. @return string */
	private function checksum( array $data ): string {
		unset( $data['_state_checksum'] );
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/** @param string $directory Diretório. @return bool */
	private function acquire( string $directory ): bool {
		$key = rtrim( $directory, '/\\' );
		if ( isset( $this->locks[ $key ] ) ) {
			return true;
		}
		if ( ! is_dir( $key ) ) {
			return false;
		}
		$handle = fopen( $key . '/.session.lock', 'c' );
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}
		$this->locks[ $key ] = $handle;
		return true;
	}

	private function directory_is_writable( string $directory ): bool {
		$permissions = @fileperms( $directory );
		return false !== $permissions && 0 !== ( $permissions & 0222 ) && is_writable( $directory );
	}

	/** @param string $directory Diretório. @return bool */
	public function remove_directory( string $directory ): bool {
		if ( isset( $this->locks[ rtrim( $directory, '/\\' ) ] ) ) {
			$this->release( $directory );
		}
		if ( is_link( $directory ) ) {
			return unlink( $directory );
		}
		if ( ! is_dir( $directory ) ) {
			return true;
		}
		$lock = fopen( rtrim( $directory, '/\\' ) . '/.session.gc.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return false;
		}
		$success = true;
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				if ( ! rmdir( $path ) ) { $success = false; }
			} elseif ( ! unlink( $path ) ) { $success = false; }
		}
		if ( ! rmdir( $directory ) ) { $success = false; }
		flock( $lock, LOCK_UN );
		fclose( $lock );
		return $success;
	}

	public function __destruct() {
		$this->release_all();
	}
}
