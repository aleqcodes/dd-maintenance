<?php
/**
 * Armazenamento atômico e validado das sessões de backup e restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Session_Store {

	const SCHEMA_VERSION = 1;

	/**
	 * Persiste um estado de sessão em state.json usando escrita atômica.
	 *
	 * @param string $directory Diretório da sessão.
	 * @param array  $data      Estado da sessão.
	 * @return bool
	 */
	public function save( string $directory, array $data ): bool {
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			return false;
		}

		$state_file = rtrim( $directory, '/\\' ) . '/state.json';
		$temp_file  = $state_file . '.tmp';
		$data['_schema_version'] = self::SCHEMA_VERSION;
		unset( $data['_state_checksum'] );
		$data['_state_checksum'] = $this->checksum( $data );
		$json = wp_json_encode( $data );

		if ( false === $json ) {
			return false;
		}

		$bytes = file_put_contents( $temp_file, $json, LOCK_EX );
		if ( false === $bytes || (int) $bytes !== strlen( $json ) ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			return false;
		}

		if ( function_exists( 'chmod' ) && ! chmod( $temp_file, 0600 ) ) {
			unlink( $temp_file );
			return false;
		}

		if ( ! rename( $temp_file, $state_file ) ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
			return false;
		}

		return true;
	}

	/**
	 * Carrega e valida um estado de sessão.
	 *
	 * Sessões sem versão continuam sendo aceitas para compatibilidade com o
	 * formato anterior. Versões futuras são recusadas para evitar interpretar
	 * dados desconhecidos como um estado válido.
	 *
	 * @param string $directory     Diretório da sessão.
	 * @param string $missing_code  Código para arquivo inexistente.
	 * @param string $corrupt_code  Código para conteúdo inválido.
	 * @return array|WP_Error
	 */
	public function load( string $directory, string $missing_code, string $corrupt_code ) {
		$state_file = rtrim( $directory, '/\\' ) . '/state.json';
		if ( ! is_file( $state_file ) ) {
			return new WP_Error( $missing_code, __( 'Estado da sessão não encontrado.', 'dd-maintenance' ) );
		}

		$json = file_get_contents( $state_file );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( $corrupt_code, __( 'Dados da sessão corrompidos.', 'dd-maintenance' ) );
		}

		if ( isset( $data['_schema_version'] ) && (int) $data['_schema_version'] > self::SCHEMA_VERSION ) {
			return new WP_Error( $corrupt_code, __( 'Versão dos dados da sessão não suportada.', 'dd-maintenance' ) );
		}

		if ( ! isset( $data['_state_checksum'] ) ) {
			return $data;
		}

		$checksum = $data['_state_checksum'];
		unset( $data['_state_checksum'] );
		if ( ! is_string( $checksum ) || ! hash_equals( $this->checksum( $data ), $checksum ) ) {
			return new WP_Error( $corrupt_code, __( 'A integridade dos dados da sessão não pôde ser validada.', 'dd-maintenance' ) );
		}

		$data['_state_checksum'] = $checksum;
		return $data;
	}
	/**
	 * Calcula o digest do payload sem o próprio campo de integridade.
	 *
	 * @param array $data Estado da sessão.
	 * @return string
	 */
	private function checksum( array $data ): string {
		unset( $data['_state_checksum'] );
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/**
	 * Remove recursivamente um diretório de sessão.
	 *
	 * @param string $directory Diretório a remover.
	 * @return bool
	 */
	public function remove_directory( string $directory ): bool {
		if ( is_link( $directory ) ) {
			return unlink( $directory );
		}
		if ( ! is_dir( $directory ) ) {
			return true;
		}

		$success = true;
		$items   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				if ( ! rmdir( $path ) ) {
					$success = false;
				}
			} elseif ( ! unlink( $path ) ) {
				$success = false;
			}
		}

		if ( ! rmdir( $directory ) ) {
			$success = false;
		}

		return $success;
	}
}
