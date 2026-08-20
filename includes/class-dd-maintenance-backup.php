<?php
/**
 * Responsável pela criação do backup (banco de dados + arquivos) com divisão em partes de 25MB.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Backup {

	/**
	 * Tamanho máximo de cada parte do backup (25 MB = 25 * 1024 * 1024 bytes).
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 26214400;

	/**
	 * Executa o backup completo e retorna os dados dos arquivos/partes gerados.
	 *
	 * @return array|WP_Error
	 */
	public function run() {
		$this->set_time_and_memory_limits();

		$saved_settings = get_option( 'dd_maintenance_settings', null );
		if ( null === $saved_settings ) {
			$saved_settings = get_option( 'backuper_settings', array() );
		}

		$settings = wp_parse_args(
			$saved_settings,
			array(
				'include_db'        => 1,
				'include_wpcontent' => 1,
				'include_wpconfig'  => 1,
				'include_entire'    => 1,
				'keep_local'        => 1,
			)
		);

		$dir = DD_Maintenance::backup_dir();
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return new WP_Error( 'backup_dir', __( 'A pasta de backup não é gravável.', 'dd-maintenance' ) );
		}

		$slug = sanitize_title( get_bloginfo( 'name' ) );
		$slug = $slug ? $slug : 'site';
		$base = $slug . '-' . current_time( 'Y-m-d-Hi' );

		$db_file = '';
		if ( ! empty( $settings['include_db'] ) ) {
			$db_file = $dir . '/' . $base . '.sql';
			$db_ok   = $this->dump_database( $db_file );
			if ( is_wp_error( $db_ok ) ) {
				return $db_ok;
			}
		}

		$zip_file = $dir . '/' . $base . '.temp.zip';

		$sources = array();

		if ( ! empty( $settings['include_entire'] ) ) {
			// Site inteiro: cobre wp-config, wp-content e todo o resto (o banco é adicionado abaixo).
			$sources['site'] = ABSPATH;
		} else {
			if ( ! empty( $settings['include_wpconfig'] ) && file_exists( ABSPATH . 'wp-config.php' ) ) {
				$sources['wp-config.php'] = ABSPATH . 'wp-config.php';
			}
			if ( ! empty( $settings['include_wpcontent'] ) ) {
				$sources['wp-content'] = WP_CONTENT_DIR;
			}
		}

		if ( ! empty( $settings['include_db'] ) && file_exists( $db_file ) ) {
			$sources['database.sql'] = $db_file;
		}

		if ( empty( $sources ) ) {
			if ( $db_file && file_exists( $db_file ) ) {
				@unlink( $db_file );
			}
			return new WP_Error( 'no_sources', __( 'Nenhum item selecionado para o backup.', 'dd-maintenance' ) );
		}

		$zip = $this->create_zip( $sources, $zip_file );
		if ( is_wp_error( $zip ) ) {
			if ( $db_file && file_exists( $db_file ) ) {
				@unlink( $db_file );
			}
			return $zip;
		}

		// Remove o SQL intermediário caso o local não deva ser mantido.
		if ( ! empty( $settings['keep_local'] ) && $db_file ) {
			$keep = $dir . '/' . $base . '.sql';
			@rename( $db_file, $keep );
		} elseif ( $db_file && file_exists( $db_file ) ) {
			@unlink( $db_file );
		}

		$total_size = (int) filesize( $zip_file );

		// Processa divisão em partes de 25MB se necessário.
		$parts = $this->split_file_if_needed( $zip_file, $base, $dir );

		if ( is_wp_error( $parts ) ) {
			@unlink( $zip_file );
			return $parts;
		}

		return array(
			'base'        => $base,
			'parts'       => $parts,
			'total_size'  => $total_size,
			'total_parts' => count( $parts ),
			// Campos de compatibilidade para consumidores legados de parte única:
			'file'        => $parts[0]['file'],
			'name'        => $parts[0]['name'],
			'size'        => $total_size,
		);
	}

	/**
	 * Divide o arquivo .zip em partes de 25MB caso o tamanho exceda o limite.
	 *
	 * @param string $source_zip Caminho do arquivo zip original.
	 * @param string $base       Nome base do backup (ex: site-2026-08-20-1330).
	 * @param string $dir        Diretório onde salvar as partes.
	 * @return array|WP_Error Lista de partes geradas.
	 */
	public function split_file_if_needed( string $source_zip, string $base, string $dir ) {
		$size = (int) filesize( $source_zip );

		// Se o arquivo for menor ou igual a 25MB, renomeia para nome final .zip simples.
		if ( $size <= self::CHUNK_SIZE ) {
			$final_zip = $dir . '/' . $base . '.zip';
			if ( $source_zip !== $final_zip ) {
				@rename( $source_zip, $final_zip );
			}

			return array(
				array(
					'file' => $final_zip,
					'name' => basename( $final_zip ),
					'size' => $size,
					'part' => 1,
				),
			);
		}

		// Divide em partes de 25MB.
		$in_handle = fopen( $source_zip, 'rb' );
		if ( ! $in_handle ) {
			return new WP_Error( 'split_read_failed', __( 'Não foi possível ler o arquivo zip para divisão.', 'dd-maintenance' ) );
		}

		$parts       = array();
		$part_index  = 1;
		$buffer_size = 1048576; // 1 MB buffer de leitura

		while ( ! feof( $in_handle ) ) {
			$part_name = sprintf( '%s.part%03d.zip', $base, $part_index );
			$part_path = $dir . '/' . $part_name;

			$out_handle = fopen( $part_path, 'wb' );
			if ( ! $out_handle ) {
				fclose( $in_handle );
				return new WP_Error( 'split_write_failed', sprintf( __( 'Não foi possível gravar a parte %s.', 'dd-maintenance' ), $part_name ) );
			}

			$bytes_written_this_part = 0;

			while ( ! feof( $in_handle ) && $bytes_written_this_part < self::CHUNK_SIZE ) {
				$bytes_to_read = min( $buffer_size, self::CHUNK_SIZE - $bytes_written_this_part );
				$chunk = fread( $in_handle, $bytes_to_read );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}

				$written = fwrite( $out_handle, $chunk );
				if ( false === $written ) {
					fclose( $out_handle );
					fclose( $in_handle );
					return new WP_Error( 'split_write_chunk_failed', __( 'Erro ao escrever dados na parte do arquivo.', 'dd-maintenance' ) );
				}

				$bytes_written_this_part += $written;
			}

			fclose( $out_handle );

			if ( $bytes_written_this_part > 0 ) {
				$parts[] = array(
					'file' => $part_path,
					'name' => $part_name,
					'size' => $bytes_written_this_part,
					'part' => $part_index,
				);
				$part_index++;
			} else {
				@unlink( $part_path );
			}
		}

		fclose( $in_handle );

		// Remove o zip temporário original não dividido para economizar espaço em disco.
		@unlink( $source_zip );

		return $parts;
	}

	/**
	 * Gera um dump SQL completo do banco de dados.
	 *
	 * @param string $file Caminho do arquivo SQL de saída.
	 * @return true|WP_Error
	 */
	private function dump_database( $file ) {
		global $wpdb;

		$handle = fopen( $file, 'w' );
		if ( ! $handle ) {
			return new WP_Error( 'db_file', __( 'Não foi possível criar o arquivo do banco de dados.', 'dd-maintenance' ) );
		}

		fwrite( $handle, "-- DD Maintenance database dump\n" );
		fwrite( $handle, '-- Site: ' . home_url() . "\n" );
		fwrite( $handle, '-- Data: ' . date( 'Y-m-d H:i:s' ) . "\n\n" );
		fwrite( $handle, 'SET NAMES utf8mb4;' . "\n" );
		fwrite( $handle, 'SET FOREIGN_KEY_CHECKS = 0;' . "\n\n" );

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( empty( $create[1] ) ) {
				continue;
			}

			fwrite( $handle, "\nDROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create[1] . ";\n\n" );

			$offset = 0;
			$chunk  = 500;

			while ( true ) {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						if ( null === $value ) {
							$values[] = 'NULL';
						} else {
							$values[] = "'" . $wpdb->_real_escape( (string) $value ) . "'";
						}
					}
					fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ', ', $values ) . ");\n" );
				}

				$offset += $chunk;
				if ( count( $rows ) < $chunk ) {
					break;
				}
			}
		}

		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n" );
		fwrite( $handle, '-- Fim do dump' . "\n" );

		fclose( $handle );

		return true;
	}

	/**
	 * Cria um arquivo .zip a partir das fontes informadas.
	 *
	 * @param array  $sources   Mapa (nome_no_zip => caminho_absoluto).
	 * @param string $zip_file  Caminho do arquivo zip de saída.
	 * @return true|WP_Error
	 */
	private function create_zip( $sources, $zip_file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'A extensão PHP ZipArchive não está disponível no servidor.', 'dd-maintenance' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_open', __( 'Não foi possível criar o arquivo .zip.', 'dd-maintenance' ) );
		}

		foreach ( $sources as $archive_name => $path ) {
			$archive_name = trim( $archive_name, '/' );

			if ( is_dir( $path ) ) {
				$this->add_dir_to_zip( $zip, $path, $archive_name );
			} elseif ( is_file( $path ) ) {
				$zip->addFile( $path, $archive_name );
			}
		}

		$zip->close();

		if ( ! file_exists( $zip_file ) || filesize( $zip_file ) <= 0 ) {
			return new WP_Error( 'zip_empty', __( 'O arquivo .zip gerado está vazio.', 'dd-maintenance' ) );
		}

		return true;
	}

	/**
	 * Adiciona recursivamente uma pasta ao zip, ignorando as pastas de backup.
	 *
	 * @param ZipArchive $zip          Objeto zip.
	 * @param string     $abs_path     Caminho absoluto da pasta.
	 * @param string     $archive_path Caminho dentro do zip.
	 */
	private function add_dir_to_zip( $zip, $abs_path, $archive_path ) {
		$backup_dirs = array(
			wp_normalize_path( DD_Maintenance::backup_dir() ),
			wp_normalize_path( WP_CONTENT_DIR . '/uploads/backuper' ),
			wp_normalize_path( WP_CONTENT_DIR . '/cache' ),
		);

		$abs_path = wp_normalize_path( $abs_path );

		foreach ( $backup_dirs as $b_dir ) {
			if ( $abs_path === $b_dir || 0 === strpos( $abs_path, $b_dir . '/' ) ) {
				return;
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $abs_path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );

			$is_ignored = false;
			foreach ( $backup_dirs as $b_dir ) {
				if ( $item_path === $b_dir || 0 === strpos( $item_path, $b_dir . '/' ) ) {
					$is_ignored = true;
					break;
				}
			}

			// Ignora pastas desnecessárias como .git, node_modules, cache
			$filename = $item->getFilename();
			if ( '.git' === $filename || 'node_modules' === $filename || '.temp' === $filename ) {
				$is_ignored = true;
			}

			if ( $is_ignored ) {
				continue;
			}

			$relative = ltrim( substr( $item_path, strlen( $abs_path ) ), '/' );
			$relative = str_replace( '\\', '/', $relative );
			$target   = $archive_path . '/' . $relative;

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $target );
			} elseif ( $item->isFile() ) {
				$zip->addFile( $item_path, $target );
			}
		}
	}

	/**
	 * Aumenta limites de tempo e memória durante o backup.
	 */
	private function set_time_and_memory_limits() {
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			@set_time_limit( 0 );
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '512M' );
		}
	}
}
