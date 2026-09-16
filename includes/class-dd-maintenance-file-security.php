<?php
/**
 * Valida caminhos e tipos de arquivo usados pelos fluxos de backup e restauração.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_File_Security {

	/**
	 * Normaliza um caminho relativo de arquivo sem permitir traversal.
	 *
	 * @param mixed $path Caminho recebido do manifesto ou ZIP.
	 * @return string|WP_Error
	 */
	public static function normalize_relative_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return new WP_Error( 'dd_path_invalid', __( 'Caminho de arquivo inválido.', 'dd-maintenance' ) );
		}

		$path = str_replace( '\\', '/', $path );
		for ( $i = 0; $i < 2; $i++ ) {
			$decoded = rawurldecode( $path );
			if ( $decoded === $path ) {
				break;
			}
			$path = str_replace( '\\', '/', $decoded );
		}

		if ( false === preg_match( '//u', $path ) || 0 === strpos( $path, '/' ) || preg_match( '#^[A-Za-z]:/#', $path ) || 0 === strpos( $path, '//' ) ) {
			return new WP_Error( 'dd_path_absolute', __( 'Caminho absoluto não permitido.', 'dd-maintenance' ) );
		}

		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return new WP_Error( 'dd_path_traversal', __( 'Travessia de diretório não permitida.', 'dd-maintenance' ) );
			}
			$parts[] = $part;
		}

		if ( empty( $parts ) ) {
			return new WP_Error( 'dd_path_empty', __( 'Caminho de arquivo vazio.', 'dd-maintenance' ) );
		}
		return implode( '/', $parts );
	}

	/**
	 * Retorna um filho seguro de uma raiz existente, protegendo cada componente pai.
	 *
	 * @param string $root Raiz autorizada.
	 * @param string $relative Caminho relativo.
	 * @param bool $create_parent Cria os pais ausentes quando true.
	 * @return string|WP_Error
	 */
	public static function safe_child_path( string $root, string $relative, bool $create_parent = false ) {
		$normalized = self::normalize_relative_path( $relative );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( is_link( $root ) ) {
			return new WP_Error( 'dd_root_symlink', __( 'Raiz simbólica não permitida.', 'dd-maintenance' ) );
		}
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return new WP_Error( 'dd_root_invalid', __( 'Raiz de arquivo inválida.', 'dd-maintenance' ) );
		}
		$root_real = rtrim( self::normalize_path( $root_real ), '/' );
		$parts     = explode( '/', $normalized );
		$parent    = $root_real;
		$last      = array_pop( $parts );

		foreach ( $parts as $part ) {
			$next = $parent . '/' . $part;
			if ( is_link( $next ) || ( file_exists( $next ) && ! is_dir( $next ) ) ) {
				return new WP_Error( 'dd_path_parent_unsafe', __( 'Diretório pai inseguro.', 'dd-maintenance' ) );
			}
			if ( ! file_exists( $next ) ) {
				if ( ! $create_parent && ! is_dir( $next ) ) {
					return new WP_Error( 'dd_path_parent_missing', __( 'Diretório pai ausente.', 'dd-maintenance' ) );
				}
				if ( $create_parent && ! mkdir( $next, 0755 ) && ! is_dir( $next ) ) {
					return new WP_Error( 'dd_path_parent_create', __( 'Não foi possível criar o diretório pai.', 'dd-maintenance' ) );
				}
			}
			$real_next = realpath( $next );
			if ( false === $real_next || ! is_dir( $real_next ) || is_link( $next ) ) {
				return new WP_Error( 'dd_path_parent_realpath', __( 'O diretório pai não é seguro.', 'dd-maintenance' ) );
			}
			$real_next = rtrim( self::normalize_path( $real_next ), '/' );
			if ( $real_next !== $root_real && 0 !== strpos( $real_next, $root_real . '/' ) ) {
				return new WP_Error( 'dd_path_outside_root', __( 'Destino fora da raiz autorizada.', 'dd-maintenance' ) );
			}
			$parent = $real_next;
		}

		$target = $parent . '/' . $last;
		if ( is_link( $target ) ) {
			return new WP_Error( 'dd_path_target_symlink', __( 'Destino simbólico não permitido.', 'dd-maintenance' ) );
		}
		$parent_real = realpath( $parent );
		if ( false === $parent_real || ! is_dir( $parent_real ) ) {
			return new WP_Error( 'dd_path_parent_realpath', __( 'O diretório pai não é seguro.', 'dd-maintenance' ) );
		}
		$parent_real = rtrim( self::normalize_path( $parent_real ), '/' );
		if ( $parent_real !== $root_real && 0 !== strpos( $parent_real, $root_real . '/' ) ) {
			return new WP_Error( 'dd_path_outside_root', __( 'Destino fora da raiz autorizada.', 'dd-maintenance' ) );
		}
		return $parent_real . '/' . $last;
	}

	/**
	 * Valida um arquivo de origem regular, sem seguir links simbólicos.
	 *
	 * @param string $path Caminho de origem.
	 * @return true|WP_Error
	 */
	public static function assert_regular_source( string $path ) {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'dd_source_unsupported', __( 'Tipo de arquivo de origem não suportado.', 'dd-maintenance' ) );
		}
		return true;
	}

	/**
	 * Pré-valida todas as entradas de um ZIP antes da primeira escrita.
	 *
	 * @param ZipArchive $zip Arquivo ZIP aberto.
	 * @param string     $root Raiz de extração.
	 * @return true|WP_Error
	 */
	public static function validate_archive( ZipArchive $zip, string $root ) {
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );
			$name = rtrim( str_replace( '\\', '/', rawurldecode( $name ) ), '/' );
			$normalized = self::normalize_relative_path( $name );
			if ( is_wp_error( $normalized ) ) {
				return new WP_Error( 'dd_zip_entry_path', sprintf( __( 'Entrada ZIP inválida: %s.', 'dd-maintenance' ), $name ) );
			}
			$mode = 0;
			$opsys = 0;
			$attributes = 0;
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$zip->getExternalAttributesIndex( $index, $opsys, $attributes );
			}
			if ( defined( 'ZipArchive::OPSYS_UNIX' ) && ZipArchive::OPSYS_UNIX === $opsys ) {
				$mode = ( $attributes >> 16 ) & 0xFFFF;
			}
			$type = $mode & 0170000;
			$is_dir = '/' === substr( (string) $zip->getNameIndex( $index ), -1 ) || 0040000 === $type;
			if ( 0120000 === $type || ( 0 !== $type && 0040000 !== $type && 0100000 !== $type ) ) {
				return new WP_Error( 'dd_zip_entry_type', sprintf( __( 'Tipo de entrada ZIP não suportado: %s.', 'dd-maintenance' ), $name ) );
			}
			$target = self::safe_child_path( $root, $normalized, false );
			if ( is_wp_error( $target ) && 'dd_path_parent_missing' !== $target->get_error_code() ) {
				return $target;
			}
			if ( ! is_wp_error( $target ) && $is_dir && file_exists( $target ) && ! is_dir( $target ) ) {
				return new WP_Error( 'dd_zip_entry_conflict', __( 'Entrada ZIP conflita com um arquivo existente.', 'dd-maintenance' ) );
			}
		}
		return true;
	}
	/**
	 * @param string     $root Raiz de extração.
	 * @return true|WP_Error
	 */
	public static function extract_archive( ZipArchive $zip, string $root ) {
		$validated = self::validate_archive( $zip, $root );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$raw_name = (string) $zip->getNameIndex( $index );
			$is_dir   = '/' === substr( $raw_name, -1 );
			$name     = self::normalize_relative_path( rtrim( str_replace( '\\', '/', rawurldecode( $raw_name ) ), '/' ) );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
			$target = self::safe_child_path( $root, $name, true );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			if ( $is_dir ) {
				if ( ! is_dir( $target ) && ! mkdir( $target, 0755 ) && ! is_dir( $target ) ) {
					return new WP_Error( 'dd_zip_mkdir', __( 'Não foi possível criar diretório extraído.', 'dd-maintenance' ) );
				}
				continue;
			}
			$stream = $zip->getStream( $index );
			if ( ! is_resource( $stream ) && isset( $zip->filename ) && '' !== $zip->filename ) {
				$stream_path = 'zip://' . $zip->filename . '#' . str_replace( array( '#', '?' ), array( '%23', '%3F' ), $raw_name );
				$stream      = fopen( $stream_path, 'rb' );
			}
			if ( ! is_resource( $stream ) ) {
				return new WP_Error( 'dd_zip_stream', __( 'Não foi possível ler entrada ZIP.', 'dd-maintenance' ) );
			}
			if ( is_link( $target ) ) {
				fclose( $stream );
				return new WP_Error( 'dd_zip_target_symlink', __( 'Destino simbólico não permitido.', 'dd-maintenance' ) );
			}
			$output = fopen( $target, 'wb' );
			if ( ! $output ) {
				fclose( $stream );
				return new WP_Error( 'dd_zip_write', __( 'Não foi possível escrever entrada ZIP.', 'dd-maintenance' ) );
			}
			while ( ! feof( $stream ) ) {
				$buffer = fread( $stream, 1048576 );
				if ( false === $buffer ) {
					fclose( $stream );
					fclose( $output );
					return new WP_Error( 'dd_zip_read', __( 'Falha ao ler entrada ZIP.', 'dd-maintenance' ) );
				}
				if ( '' !== $buffer && strlen( $buffer ) !== fwrite( $output, $buffer ) ) {
					fclose( $stream );
					fclose( $output );
					return new WP_Error( 'dd_zip_write', __( 'Falha ao escrever entrada ZIP.', 'dd-maintenance' ) );
				}
			}
			fclose( $stream );
			fclose( $output );
		}
		return true;
	}

	/**
	 * Copia uma origem regular para uma raiz autorizada.
	 *
	 * @param string $source Origem.
	 * @param string $root Raiz.
	 * @param string $relative Destino relativo.
	 * @return true|WP_Error
	 */
	public static function copy_to_root( string $source, string $root, string $relative ) {
		$valid_source = self::assert_regular_source( $source );
		if ( is_wp_error( $valid_source ) ) {
			return $valid_source;
		}
		$target = self::safe_child_path( $root, $relative, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'dd_copy_failed', __( 'Não foi possível copiar arquivo para a raiz autorizada.', 'dd-maintenance' ) );
		}
		return true;
	}

	/**
	 * Normaliza separadores sem depender do WordPress em testes isolados.
	 *
	 * @param string $path Caminho.
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', $path );
	}
}
