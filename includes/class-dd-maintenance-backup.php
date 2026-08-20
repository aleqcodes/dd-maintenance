<?php
/**
 * Responsável pela criação do backup (banco de dados + arquivos) com divisão em partes de 25MB e motor de lotes assíncronos de alta performance.
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
	 * Quantidade de arquivos adicionados ao zip por lote AJAX (mantém a execução abaixo de 10s).
	 *
	 * @var int
	 */
	const BATCH_FILE_COUNT = 800;

	/**
	 * Extensões de mídia pré-compactadas que usam CM_STORE para máxima velocidade.
	 *
	 * @var array
	 */
	const PRECOMPRESSED_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico',
		'mp4', 'mov', 'avi', 'mkv', 'webm', 'mp3', 'ogg', 'wav',
		'zip', 'gz', 'tar', 'tgz', 'rar', '7z', 'bz2', 'pdf',
		'woff', 'woff2', 'ttf', 'eot',
	);

	/**
	 * Inicializa uma sessão de backup em lotes (cria pasta e metadados da sessão).
	 *
	 * @return array|WP_Error Dados da sessão inicializada.
	 */
	public function init_session() {
		$this->set_time_and_memory_limits();

		$backup_dir = DD_Maintenance::backup_dir();
		$session_id = 'bk_' . time() . '_' . wp_generate_password( 8, false );
		$session_dir = $backup_dir . '/session_' . $session_id;

		if ( ! wp_mkdir_p( $session_dir ) ) {
			return new WP_Error( 'session_mkdir_failed', __( 'Não foi possível criar a pasta temporária da sessão de backup.', 'dd-maintenance' ) );
		}

		$slug = sanitize_title( get_bloginfo( 'name' ) );
		$slug = $slug ? $slug : 'site';
		$base = $slug . '-' . current_time( 'Y-m-d-Hi' );

		$settings = wp_parse_args(
			get_option( 'dd_maintenance_settings', array() ),
			array(
				'include_db'        => 1,
				'include_wpcontent' => 1,
				'include_wpconfig'  => 1,
				'include_entire'    => 1,
				'keep_local'        => 1,
			)
		);

		$session_data = array(
			'session_id'   => $session_id,
			'session_dir'  => $session_dir,
			'base_name'    => $base,
			'settings'     => $settings,
			'db_file'      => $session_dir . '/database.sql',
			'zip_file'     => $session_dir . '/' . $base . '.raw.zip',
			'manifest_file'=> $session_dir . '/manifest.json',
			'total_files'  => 0,
			'processed'    => 0,
			'created_at'   => time(),
		);

		$this->save_session_data( $session_dir, $session_data );

		return $session_data;
	}

	/**
	 * Salva o estado da sessão de backup no arquivo state.json.
	 *
	 * @param string $session_dir Pasta da sessão.
	 * @param array  $data        Dados da sessão.
	 * @return bool
	 */
	private function save_session_data( string $session_dir, array $data ): bool {
		return (bool) file_put_contents( $session_dir . '/state.json', wp_json_encode( $data ) );
	}

	/**
	 * Carrega o estado de uma sessão de backup existente.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function get_session_data( string $session_id ) {
		$session_id  = sanitize_file_name( $session_id );
		$backup_dir  = DD_Maintenance::backup_dir();
		$session_dir = $backup_dir . '/session_' . $session_id;
		$state_file  = $session_dir . '/state.json';

		if ( ! file_exists( $state_file ) ) {
			return new WP_Error( 'session_not_found', __( 'Sessão de backup não encontrada ou expirada.', 'dd-maintenance' ) );
		}

		$data = json_decode( (string) file_get_contents( $state_file ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'session_corrupted', __( 'Dados da sessão corrompidos.', 'dd-maintenance' ) );
		}

		return $data;
	}

	/**
	 * Etapa 1: Dump do banco de dados em SQL.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function dump_database_step( string $session_id ) {
		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( empty( $session['settings']['include_db'] ) ) {
			return array(
				'skipped' => true,
				'log'     => __( '[Banco] Dump do banco de dados desmarcado nas opções.', 'dd-maintenance' ),
			);
		}

		$start_time = microtime( true );
		$dump_ok    = $this->dump_database( $session['db_file'] );

		if ( is_wp_error( $dump_ok ) ) {
			return $dump_ok;
		}

		$elapsed = round( microtime( true ) - $start_time, 2 );
		$size    = (int) filesize( $session['db_file'] );

		return array(
			'success' => true,
			'size'    => $size,
			'elapsed' => $elapsed,
			'log'     => sprintf(
				/* translators: 1: Tamanho do dump SQL, 2: Tempo em segundos */
				__( '[OK] Dump do banco gerado com sucesso: %1$s em %2$ss.', 'dd-maintenance' ),
				size_format( $size ),
				$elapsed
			),
		);
	}

	/**
	 * Etapa 2: Indexa todos os arquivos que serão compactados em um manifesto leve.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function index_files_step( string $session_id ) {
		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$sources     = array();
		$backup_dirs = array(
			wp_normalize_path( DD_Maintenance::backup_dir() ),
			wp_normalize_path( WP_CONTENT_DIR . '/uploads/backuper' ),
			wp_normalize_path( WP_CONTENT_DIR . '/cache' ),
		);

		if ( ! empty( $session['settings']['include_entire'] ) ) {
			$sources['site'] = ABSPATH;
		} else {
			if ( ! empty( $session['settings']['include_wpconfig'] ) && file_exists( ABSPATH . 'wp-config.php' ) ) {
				$sources['wp-config.php'] = ABSPATH . 'wp-config.php';
			}
			if ( ! empty( $session['settings']['include_wpcontent'] ) ) {
				$sources['wp-content'] = WP_CONTENT_DIR;
			}
		}

		$file_list = array();

		foreach ( $sources as $archive_name => $path ) {
			$archive_name = trim( $archive_name, '/' );

			if ( is_file( $path ) ) {
				$file_list[] = array(
					'path'   => wp_normalize_path( $path ),
					'target' => $archive_name,
				);
			} elseif ( is_dir( $path ) ) {
				$abs_path = wp_normalize_path( $path );

				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $abs_path, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $item ) {
					$item_path = wp_normalize_path( $item->getPathname() );

					// Ignora pastas de backup e cache
					$is_ignored = false;
					foreach ( $backup_dirs as $b_dir ) {
						if ( $item_path === $b_dir || 0 === strpos( $item_path, $b_dir . '/' ) ) {
							$is_ignored = true;
							break;
						}
					}

					$filename = $item->getFilename();
					if ( '.git' === $filename || 'node_modules' === $filename || '.temp' === $filename ) {
						$is_ignored = true;
					}

					if ( $is_ignored || ! $item->isFile() ) {
						continue;
					}

					$relative = ltrim( substr( $item_path, strlen( $abs_path ) ), '/' );
					$relative = str_replace( '\\', '/', $relative );
					$target   = $archive_name . '/' . $relative;

					$file_list[] = array(
						'path'   => $item_path,
						'target' => $target,
					);
				}
			}
		}

		$total = count( $file_list );
		file_put_contents( $session['manifest_file'], wp_json_encode( $file_list ) );

		$session['total_files'] = $total;
		$this->save_session_data( $session['session_dir'], $session );

		return array(
			'total_files' => $total,
			'total_batches' => ceil( $total / self::BATCH_FILE_COUNT ),
			'log'         => sprintf(
				/* translators: %d: Quantidade de arquivos catalogados */
				__( '[OK] %d arquivos catalogados para compactação.', 'dd-maintenance' ),
				$total
			),
		);
	}

	/**
	 * Etapa 3: Compacta um lote de arquivos no .zip da sessão (execução ultrarrápida com CM_STORE).
	 *
	 * @param string $session_id ID da sessão.
	 * @param int    $offset     Índice do arquivo inicial do lote.
	 * @return array|WP_Error
	 */
	public function zip_batch_step( string $session_id, int $offset = 0 ) {
		$this->set_time_and_memory_limits();

		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! file_exists( $session['manifest_file'] ) ) {
			return new WP_Error( 'manifest_missing', __( 'Manifesto de arquivos não encontrado.', 'dd-maintenance' ) );
		}

		$file_list = json_decode( (string) file_get_contents( $session['manifest_file'] ), true );
		if ( ! is_array( $file_list ) ) {
			return new WP_Error( 'manifest_invalid', __( 'Lista de arquivos inválida.', 'dd-maintenance' ) );
		}

		$total_files = count( $file_list );
		if ( $offset >= $total_files ) {
			return array(
				'completed'   => true,
				'processed'   => $total_files,
				'total_files' => $total_files,
				'percent'     => 100,
				'log'         => __( '[OK] Todos os arquivos foram compactados no pacote.', 'dd-maintenance' ),
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'Extensão ZipArchive não disponível.', 'dd-maintenance' ) );
		}

		$zip   = new ZipArchive();
		$flags = file_exists( $session['zip_file'] ) ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $zip->open( $session['zip_file'], $flags ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Não foi possível abrir o arquivo zip para adicionar o lote.', 'dd-maintenance' ) );
		}

		$batch_items = array_slice( $file_list, $offset, self::BATCH_FILE_COUNT );
		$batch_count = count( $batch_items );

		foreach ( $batch_items as $item ) {
			if ( ! file_exists( $item['path'] ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $item['path'], PATHINFO_EXTENSION ) );
			$zip->addFile( $item['path'], $item['target'] );

			// Se for mídia pré-compactada (imagens, vídeos, pdfs, zips), usa CM_STORE para velocidade instantânea (zero delay de CPU).
			if ( in_array( $ext, self::PRECOMPRESSED_EXTENSIONS, true ) && method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $item['target'], ZipArchive::CM_STORE );
			}
		}

		$zip->close();

		$new_offset = $offset + $batch_count;
		$is_done    = $new_offset >= $total_files;
		$pct        = $total_files > 0 ? min( 100, round( ( $new_offset / $total_files ) * 100 ) ) : 100;

		$session['processed'] = $new_offset;
		$this->save_session_data( $session['session_dir'], $session );

		return array(
			'completed'   => $is_done,
			'processed'   => $new_offset,
			'total_files' => $total_files,
			'batch_count' => $batch_count,
			'next_offset' => $new_offset,
			'percent'     => $pct,
			'log'         => sprintf(
				/* translators: 1: Processados, 2: Total, 3: Porcentagem */
				__( '[Compactando] %1$d / %2$d arquivos adicionados ao zip (%3$d%%)...', 'dd-maintenance' ),
				$new_offset,
				$total_files,
				$pct
			),
		);
	}

	/**
	 * Etapa 4: Finaliza o zip (adiciona o database.sql), divide em partes de 25MB e limpa a sessão.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function finalize_and_split_step( string $session_id ) {
		$this->set_time_and_memory_limits();

		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$backup_dir = DD_Maintenance::backup_dir();
		$zip_file   = $session['zip_file'];

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'Extensão ZipArchive não disponível.', 'dd-maintenance' ) );
		}

		$zip   = new ZipArchive();
		$flags = file_exists( $zip_file ) ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $zip->open( $zip_file, $flags ) ) {
			return new WP_Error( 'zip_finalize_open_failed', __( 'Falha ao abrir zip para inclusão do banco de dados.', 'dd-maintenance' ) );
		}

		// Adiciona o dump SQL na raiz do arquivo zip (database.sql)
		if ( ! empty( $session['settings']['include_db'] ) && file_exists( $session['db_file'] ) ) {
			$zip->addFile( $session['db_file'], 'database.sql' );
		}

		$zip->close();

		if ( ! file_exists( $zip_file ) || filesize( $zip_file ) <= 0 ) {
			$this->clean_session_directory( $session['session_dir'] );
			return new WP_Error( 'zip_empty', __( 'O arquivo zip gerado está vazio.', 'dd-maintenance' ) );
		}

		$total_size = (int) filesize( $zip_file );
		$base_name  = $session['base_name'];

		// Move o SQL para retenção local se configurado
		if ( ! empty( $session['settings']['keep_local'] ) && file_exists( $session['db_file'] ) ) {
			@copy( $session['db_file'], $backup_dir . '/' . $base_name . '.sql' );
		}

		// Divide o arquivo .zip em partes de 25MB e move para a pasta principal de backups
		$parts = $this->split_file_if_needed( $zip_file, $base_name, $backup_dir );

		// Limpa a pasta temporária da sessão
		$this->clean_session_directory( $session['session_dir'] );

		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$total_parts = count( $parts );

		return array(
			'base'        => $base_name,
			'parts'       => $parts,
			'total_size'  => $total_size,
			'total_parts' => $total_parts,
			'file'        => $parts[0]['file'],
			'name'        => $parts[0]['name'],
			'size'        => $total_size,
			'log'         => sprintf(
				/* translators: 1: Quantidade de partes, 2: Tamanho formatado */
				__( '[OK] Backup finalizado: %1$d parte(s) de até 25MB geradas (Total: %2$s)', 'dd-maintenance' ),
				$total_parts,
				size_format( $total_size )
			),
		);
	}

	/**
	 * Executa o backup completo em uma única chamada (utilizado pelo WP-Cron e CLI).
	 * Utiliza a mesma compressão ultrarrápida com CM_STORE para arquivos de mídia.
	 *
	 * @return array|WP_Error
	 */
	public function run() {
		$session = $this->init_session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session_id = $session['session_id'];

		// 1. Dump do banco
		$db_res = $this->dump_database_step( $session_id );
		if ( is_wp_error( $db_res ) ) {
			$this->clean_session_directory( $session['session_dir'] );
			return $db_res;
		}

		// 2. Indexa arquivos
		$idx_res = $this->index_files_step( $session_id );
		if ( is_wp_error( $idx_res ) ) {
			$this->clean_session_directory( $session['session_dir'] );
			return $idx_res;
		}

		// 3. Compacta em loop de lotes
		$offset = 0;
		while ( $offset < $idx_res['total_files'] ) {
			$batch_res = $this->zip_batch_step( $session_id, $offset );
			if ( is_wp_error( $batch_res ) ) {
				$this->clean_session_directory( $session['session_dir'] );
				return $batch_res;
			}
			$offset = $batch_res['next_offset'];
		}

		// 4. Finaliza e divide em 25MB
		return $this->finalize_and_split_step( $session_id );
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
	 * Gera um dump SQL completo do banco de dados em chunks.
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
		fwrite( $handle, '-- Site: ' . ( function_exists( 'home_url' ) ? home_url() : '' ) . "\n" );
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
	 * Remove recursivamente uma pasta temporária de sessão.
	 *
	 * @param string $dir Caminho da pasta.
	 * @return void
	 */
	private function clean_session_directory( string $dir ): void {
		$dir = wp_normalize_path( $dir );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $dir );
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
			@ini_set( 'max_execution_time', '3600' );
		}
		@ignore_user_abort( true );
	}
}
