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
	 * Limites de trabalho por requisição. O relógio, e não apenas a quantidade de
	 * arquivos, mantém cada resposta bem abaixo do timeout do proxy.
	 */
	const BATCH_FILE_COUNT = 100;
	const DB_BATCH_SIZE    = 250;
	const STEP_TIME_LIMIT  = 8;

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
			'session_id'         => $session_id,
			'session_dir'        => $session_dir,
			'base_name'          => $base,
			'settings'           => $settings,
			'db_file'            => $session_dir . '/database.sql',
			'zip_file'           => $session_dir . '/' . $base . '.raw.zip',
			'manifest_file'      => $session_dir . '/manifest.jsonl',
			'index_queue_file'   => $session_dir . '/index-queue.jsonl',
			'total_files'        => 0,
			'processed'          => 0,
			'db_initialized'      => false,
			'db_completed'        => false,
			'db_table_index'      => 0,
			'db_row_offset'       => 0,
			'db_schema_written'   => false,
			'db_position'         => 0,
			'index_initialized'   => false,
			'index_completed'     => false,
			'index_queue_offset'  => 0,
			'index_queue_size'    => 0,
			'manifest_size'       => 0,
			'indexed_dirs'        => 0,
			'zip_manifest_offset' => 0,
			'archive_finalized'   => false,
			'split_completed'     => false,
			'split_offset'        => 0,
			'split_part'          => 1,
			'total_size'          => 0,
			'parts'               => array(),
			'created_at'          => time(),
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
		$state_file = $session_dir . '/state.json';
		$temp_file  = $state_file . '.tmp';
		$json       = wp_json_encode( $data );

		if ( false === $json || false === file_put_contents( $temp_file, $json, LOCK_EX ) ) {
			return false;
		}

		return rename( $temp_file, $state_file );
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
		global $wpdb;

		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( empty( $session['settings']['include_db'] ) ) {
			return array(
				'completed' => true,
				'skipped'   => true,
				'log'       => __( '[Banco] Dump do banco de dados desmarcado nas opções.', 'dd-maintenance' ),
			);
		}

		if ( ! empty( $session['db_completed'] ) ) {
			return array(
				'completed' => true,
				'percent'   => 100,
				'size'      => file_exists( $session['db_file'] ) ? (int) filesize( $session['db_file'] ) : 0,
				'log'       => __( '[OK] Dump do banco gerado com sucesso.', 'dd-maintenance' ),
			);
		}

		$tables      = $wpdb->get_col( 'SHOW TABLES' );
		$total       = count( $tables );
		$deadline    = microtime( true ) + self::STEP_TIME_LIMIT;
		$initialized = ! empty( $session['db_initialized'] );
		$handle      = fopen( $session['db_file'], $initialized ? 'c+b' : 'w+b' );

		if ( ! $handle ) {
			return new WP_Error( 'db_file', __( 'Não foi possível criar o arquivo do banco de dados.', 'dd-maintenance' ) );
		}

		if ( ! $initialized ) {
			$header = "-- DD Maintenance database dump\n"
				. '-- Site: ' . ( function_exists( 'home_url' ) ? home_url() : '' ) . "\n"
				. '-- Data: ' . date( 'Y-m-d H:i:s' ) . "\n\n"
				. "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
			fwrite( $handle, $header );
			$session['db_initialized'] = true;
			$session['db_position']    = ftell( $handle );
			$this->save_session_data( $session['session_dir'], $session );
		} else {
			$position = isset( $session['db_position'] ) ? (int) $session['db_position'] : 0;
			ftruncate( $handle, $position );
			fseek( $handle, $position );
		}

		while ( $session['db_table_index'] < $total && microtime( true ) < $deadline ) {
			$table       = $tables[ $session['db_table_index'] ];
			$quoted_name = str_replace( '`', '``', $table );

			if ( empty( $session['db_schema_written'] ) ) {
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$quoted_name}`", ARRAY_N );
				if ( empty( $create[1] ) ) {
					$session['db_table_index']++;
					continue;
				}

				fwrite( $handle, "\nDROP TABLE IF EXISTS `{$quoted_name}`;\n" . $create[1] . ";\n\n" );
				fflush( $handle );
				$session['db_schema_written'] = true;
				$session['db_position']       = ftell( $handle );
				$this->save_session_data( $session['session_dir'], $session );
			}

			$offset = (int) $session['db_row_offset'];
			$rows   = $wpdb->get_results(
				"SELECT * FROM `{$quoted_name}` LIMIT {$offset}, " . self::DB_BATCH_SIZE,
				ARRAY_A
			);

			if ( ! empty( $wpdb->last_error ) ) {
				fclose( $handle );
				return new WP_Error( 'db_query', $wpdb->last_error );
			}

			$sql = '';
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					$values[] = null === $value ? 'NULL' : "'" . $wpdb->_real_escape( (string) $value ) . "'";
				}
				$sql .= "INSERT INTO `{$quoted_name}` VALUES (" . implode( ', ', $values ) . ");\n";
			}

			if ( '' !== $sql ) {
				fwrite( $handle, $sql );
				fflush( $handle );
			}

			$row_count = count( $rows );
			if ( $row_count < self::DB_BATCH_SIZE ) {
				$session['db_table_index']++;
				$session['db_row_offset']     = 0;
				$session['db_schema_written'] = false;
			} else {
				$session['db_row_offset'] += $row_count;
			}

			$session['db_position'] = ftell( $handle );
			$this->save_session_data( $session['session_dir'], $session );
		}

		$completed = $session['db_table_index'] >= $total;
		if ( $completed ) {
			fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- Fim do dump\n" );
			fflush( $handle );
			$session['db_completed'] = true;
			$session['db_position']  = ftell( $handle );
			$this->save_session_data( $session['session_dir'], $session );
		}
		fclose( $handle );

		$processed = min( $total, (int) $session['db_table_index'] );
		$percent   = $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 100;

		return array(
			'completed'        => $completed,
			'processed_tables' => $processed,
			'total_tables'     => $total,
			'percent'          => $percent,
			'size'             => file_exists( $session['db_file'] ) ? (int) filesize( $session['db_file'] ) : 0,
			'log'              => $completed
				? __( '[OK] Dump do banco gerado com sucesso.', 'dd-maintenance' )
				: sprintf( __( '[Banco] %1$d/%2$d tabelas processadas (%3$d%%).', 'dd-maintenance' ), $processed, $total, $percent ),
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

		if ( ! empty( $session['index_completed'] ) ) {
			return array(
				'completed'   => true,
				'total_files' => (int) $session['total_files'],
				'log'         => sprintf( __( '[OK] %d arquivos catalogados para compactação.', 'dd-maintenance' ), $session['total_files'] ),
			);
		}

		$backup_dirs = array(
			rtrim( wp_normalize_path( DD_Maintenance::backup_dir() ), '/' ),
			rtrim( wp_normalize_path( WP_CONTENT_DIR . '/uploads/backuper' ), '/' ),
			rtrim( wp_normalize_path( WP_CONTENT_DIR . '/cache' ), '/' ),
		);

		if ( empty( $session['index_initialized'] ) ) {
			$sources = array();
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

			$manifest = fopen( $session['manifest_file'], 'wb' );
			$queue    = fopen( $session['index_queue_file'], 'wb' );
			if ( ! $manifest || ! $queue ) {
				if ( $manifest ) {
					fclose( $manifest );
				}
				if ( $queue ) {
					fclose( $queue );
				}
				return new WP_Error( 'index_file', __( 'Não foi possível criar os arquivos de índice do backup.', 'dd-maintenance' ) );
			}

			foreach ( $sources as $archive_name => $path ) {
				$path         = rtrim( wp_normalize_path( $path ), '/' );
				$archive_name = trim( $archive_name, '/' );
				$entry        = array( 'path' => $path, 'target' => $archive_name );
				if ( is_file( $path ) ) {
					fwrite( $manifest, wp_json_encode( $entry ) . "\n" );
					$session['total_files']++;
				} elseif ( is_dir( $path ) ) {
					fwrite( $queue, wp_json_encode( $entry ) . "\n" );
				}
			}

			fclose( $manifest );
			fclose( $queue );
			$session['index_initialized'] = true;
			$session['manifest_size']     = (int) filesize( $session['manifest_file'] );
			$session['index_queue_size']  = (int) filesize( $session['index_queue_file'] );
			$this->save_session_data( $session['session_dir'], $session );
		} else {
			if ( ! $this->truncate_file( $session['manifest_file'], (int) $session['manifest_size'] )
				|| ! $this->truncate_file( $session['index_queue_file'], (int) $session['index_queue_size'] ) ) {
				return new WP_Error( 'index_recover', __( 'Não foi possível recuperar o último checkpoint da indexação.', 'dd-maintenance' ) );
			}
		}

		$deadline  = microtime( true ) + self::STEP_TIME_LIMIT;
		$completed = false;

		while ( microtime( true ) < $deadline ) {
			$reader = fopen( $session['index_queue_file'], 'rb' );
			if ( ! $reader ) {
				return new WP_Error( 'index_queue_read', __( 'Não foi possível ler a fila de indexação.', 'dd-maintenance' ) );
			}
			fseek( $reader, (int) $session['index_queue_offset'] );
			$line        = fgets( $reader );
			$next_offset = ftell( $reader );
			fclose( $reader );

			if ( false === $line ) {
				$completed = true;
				break;
			}

			$directory = json_decode( $line, true );
			if ( ! is_array( $directory ) || empty( $directory['path'] ) || empty( $directory['target'] ) ) {
				$session['index_queue_offset'] = $next_offset;
				continue;
			}

			$children = @scandir( $directory['path'] );
			$manifest = fopen( $session['manifest_file'], 'ab' );
			$queue    = fopen( $session['index_queue_file'], 'ab' );
			if ( ! $manifest || ! $queue ) {
				if ( $manifest ) {
					fclose( $manifest );
				}
				if ( $queue ) {
					fclose( $queue );
				}
				return new WP_Error( 'index_append', __( 'Não foi possível atualizar o índice do backup.', 'dd-maintenance' ) );
			}

			if ( is_array( $children ) ) {
				foreach ( $children as $filename ) {
					if ( '.' === $filename || '..' === $filename || in_array( $filename, array( '.git', 'node_modules', '.temp' ), true ) ) {
						continue;
					}

					$item_path = wp_normalize_path( rtrim( $directory['path'], '/' ) . '/' . $filename );
					$ignored   = false;
					foreach ( $backup_dirs as $backup_dir ) {
						if ( $item_path === $backup_dir || 0 === strpos( $item_path, $backup_dir . '/' ) ) {
							$ignored = true;
							break;
						}
					}
					if ( $ignored || is_link( $item_path ) ) {
						continue;
					}

					$entry = array(
						'path'   => $item_path,
						'target' => trim( $directory['target'], '/' ) . '/' . $filename,
					);
					if ( is_file( $item_path ) ) {
						fwrite( $manifest, wp_json_encode( $entry ) . "\n" );
						$session['total_files']++;
					} elseif ( is_dir( $item_path ) ) {
						fwrite( $queue, wp_json_encode( $entry ) . "\n" );
					}
				}
			}

			fclose( $manifest );
			fclose( $queue );
			clearstatcache( true, $session['index_queue_file'] );
			clearstatcache( true, $session['manifest_file'] );
			$session['index_queue_offset'] = $next_offset;
			$session['index_queue_size']   = (int) filesize( $session['index_queue_file'] );
			$session['manifest_size']      = (int) filesize( $session['manifest_file'] );
			$session['indexed_dirs']++;
			$this->save_session_data( $session['session_dir'], $session );
		}

		if ( $completed ) {
			$session['index_completed'] = true;
			$this->save_session_data( $session['session_dir'], $session );
		}

		return array(
			'completed'    => $completed,
			'total_files'  => (int) $session['total_files'],
			'indexed_dirs' => (int) $session['indexed_dirs'],
			'log'          => $completed
				? sprintf( __( '[OK] %d arquivos catalogados para compactação.', 'dd-maintenance' ), $session['total_files'] )
				: sprintf( __( '[Indexando] %1$d arquivos encontrados em %2$d pastas...', 'dd-maintenance' ), $session['total_files'], $session['indexed_dirs'] ),
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
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'Extensão ZipArchive não disponível.', 'dd-maintenance' ) );
		}

		$manifest_size   = (int) filesize( $session['manifest_file'] );
		$manifest_offset = isset( $session['zip_manifest_offset'] ) ? (int) $session['zip_manifest_offset'] : 0;
		if ( $manifest_offset >= $manifest_size ) {
			return array(
				'completed'   => true,
				'processed'   => (int) $session['processed'],
				'total_files' => (int) $session['total_files'],
				'percent'     => 100,
				'next_offset' => (int) $session['processed'],
				'log'         => __( '[OK] Todos os arquivos foram compactados no pacote.', 'dd-maintenance' ),
			);
		}

		$manifest = fopen( $session['manifest_file'], 'rb' );
		if ( ! $manifest ) {
			return new WP_Error( 'manifest_read', __( 'Não foi possível ler o manifesto de arquivos.', 'dd-maintenance' ) );
		}
		fseek( $manifest, $manifest_offset );

		$zip   = new ZipArchive();
		$flags = file_exists( $session['zip_file'] ) ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $zip->open( $session['zip_file'], $flags ) ) {
			fclose( $manifest );
			return new WP_Error( 'zip_open_failed', __( 'Não foi possível abrir o arquivo zip para adicionar o lote.', 'dd-maintenance' ) );
		}

		$deadline    = microtime( true ) + self::STEP_TIME_LIMIT;
		$batch_count = 0;
		while ( $batch_count < self::BATCH_FILE_COUNT && microtime( true ) < $deadline ) {
			$line = fgets( $manifest );
			if ( false === $line ) {
				break;
			}

			$manifest_offset = ftell( $manifest );
			$item            = json_decode( $line, true );
			$batch_count++;

			if ( ! is_array( $item ) || empty( $item['path'] ) || empty( $item['target'] ) || ! is_file( $item['path'] ) ) {
				continue;
			}
			if ( false !== $zip->locateName( $item['target'] ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $item['path'], PATHINFO_EXTENSION ) );
			if ( ! $zip->addFile( $item['path'], $item['target'] ) ) {
				$zip->close();
				fclose( $manifest );
				return new WP_Error( 'zip_add_failed', sprintf( __( 'Não foi possível adicionar %s ao backup.', 'dd-maintenance' ), $item['target'] ) );
			}

			$is_large = filesize( $item['path'] ) >= 8 * 1024 * 1024;
			if ( method_exists( $zip, 'setCompressionName' ) && ( $is_large || in_array( $ext, self::PRECOMPRESSED_EXTENSIONS, true ) ) ) {
				$zip->setCompressionName( $item['target'], ZipArchive::CM_STORE );
			}
		}

		fclose( $manifest );
		if ( ! $zip->close() ) {
			return new WP_Error( 'zip_close_failed', __( 'Não foi possível concluir o lote de compactação.', 'dd-maintenance' ) );
		}

		$session['zip_manifest_offset'] = $manifest_offset;
		$session['processed']           = min( (int) $session['total_files'], (int) $session['processed'] + $batch_count );
		$this->save_session_data( $session['session_dir'], $session );

		$completed = $manifest_offset >= $manifest_size;
		$percent   = $manifest_size > 0 ? min( 100, (int) floor( ( $manifest_offset / $manifest_size ) * 100 ) ) : 100;

		return array(
			'completed'   => $completed,
			'processed'   => (int) $session['processed'],
			'total_files' => (int) $session['total_files'],
			'batch_count' => $batch_count,
			'next_offset' => (int) $session['processed'],
			'percent'     => $percent,
			'log'         => $completed
				? __( '[OK] Todos os arquivos foram compactados no pacote.', 'dd-maintenance' )
				: sprintf(
					__( '[Compactando] %1$d / %2$d arquivos adicionados ao zip (%3$d%%)...', 'dd-maintenance' ),
					$session['processed'],
					$session['total_files'],
					$percent
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
		if ( ! empty( $session['split_completed'] ) ) {
			return $this->format_final_result( $session, true );
		}

		$backup_dir = DD_Maintenance::backup_dir();
		$zip_file   = $session['zip_file'];
		$base_name  = $session['base_name'];

		if ( empty( $session['archive_finalized'] ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'zip_missing', __( 'Extensão ZipArchive não disponível.', 'dd-maintenance' ) );
			}

			$zip   = new ZipArchive();
			$flags = file_exists( $zip_file ) ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
			if ( true !== $zip->open( $zip_file, $flags ) ) {
				return new WP_Error( 'zip_finalize_open_failed', __( 'Falha ao abrir zip para inclusão do banco de dados.', 'dd-maintenance' ) );
			}

			if ( ! empty( $session['settings']['include_db'] ) && file_exists( $session['db_file'] ) && false === $zip->locateName( 'database.sql' ) ) {
				$zip->addFile( $session['db_file'], 'database.sql' );
			}
			if ( ! $zip->close() || ! file_exists( $zip_file ) || filesize( $zip_file ) <= 0 ) {
				return new WP_Error( 'zip_empty', __( 'O arquivo zip gerado está vazio.', 'dd-maintenance' ) );
			}

			$session['archive_finalized'] = true;
			$session['total_size']        = (int) filesize( $zip_file );
			if ( ! empty( $session['settings']['keep_local'] ) && file_exists( $session['db_file'] ) ) {
				copy( $session['db_file'], $backup_dir . '/' . $base_name . '.sql' );
			}
			$this->save_session_data( $session['session_dir'], $session );
		}

		$total_size = (int) $session['total_size'];
		if ( $total_size <= self::CHUNK_SIZE ) {
			$final_zip = $backup_dir . '/' . $base_name . '.zip';
			if ( $zip_file !== $final_zip && file_exists( $zip_file ) && ! rename( $zip_file, $final_zip ) ) {
				return new WP_Error( 'zip_move_failed', __( 'Não foi possível mover o backup final para a pasta de backups.', 'dd-maintenance' ) );
			}

			$session['parts'] = array(
				array(
					'file' => $final_zip,
					'name' => basename( $final_zip ),
					'size' => $total_size,
					'part' => 1,
				),
			);
			$session['split_completed'] = true;
			$session['split_offset'] = $total_size;
			$this->save_session_data( $session['session_dir'], $session );
			return $this->format_final_result( $session, true );
		}

		$part_index = (int) $session['split_part'];
		$part_name  = sprintf( '%s.part%03d.zip', $base_name, $part_index );
		$part_path  = $backup_dir . '/' . $part_name;
		$input      = fopen( $zip_file, 'rb' );
		$output     = fopen( $part_path, 'wb' );
		if ( ! $input || ! $output ) {
			if ( $input ) {
				fclose( $input );
			}
			if ( $output ) {
				fclose( $output );
			}
			return new WP_Error( 'split_file', __( 'Não foi possível abrir os arquivos para divisão do backup.', 'dd-maintenance' ) );
		}

		fseek( $input, (int) $session['split_offset'] );
		$copied = stream_copy_to_stream( $input, $output, self::CHUNK_SIZE );
		fclose( $input );
		fclose( $output );
		if ( false === $copied || $copied <= 0 ) {
			@unlink( $part_path );
			return new WP_Error( 'split_write', __( 'Não foi possível gravar a próxima parte do backup.', 'dd-maintenance' ) );
		}

		$session['parts'][] = array(
			'file' => $part_path,
			'name' => $part_name,
			'size' => (int) $copied,
			'part' => $part_index,
		);
		$session['split_offset'] += (int) $copied;
		$session['split_part']++;
		$completed = $session['split_offset'] >= $total_size;
		if ( $completed ) {
			$session['split_completed'] = true;
		}
		$this->save_session_data( $session['session_dir'], $session );
		if ( $completed ) {
			@unlink( $zip_file );
		}

		return $this->format_final_result( $session, $completed );
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
		do {
			$result = $this->dump_database_step( $session_id );
			if ( is_wp_error( $result ) ) {
				$this->clean_session_directory( $session['session_dir'] );
				return $result;
			}
		} while ( empty( $result['completed'] ) );

		do {
			$result = $this->index_files_step( $session_id );
			if ( is_wp_error( $result ) ) {
				$this->clean_session_directory( $session['session_dir'] );
				return $result;
			}
		} while ( empty( $result['completed'] ) );

		do {
			$result = $this->zip_batch_step( $session_id );
			if ( is_wp_error( $result ) ) {
				$this->clean_session_directory( $session['session_dir'] );
				return $result;
			}
		} while ( empty( $result['completed'] ) );

		do {
			$result = $this->finalize_and_split_step( $session_id );
			if ( is_wp_error( $result ) ) {
				$this->clean_session_directory( $session['session_dir'] );
				return $result;
			}
		} while ( empty( $result['completed'] ) );

		$this->clean_session_directory( $session['session_dir'] );
		return $result;
	}

	/**
	 * Remove os temporários após todas as partes terem sido enviadas.
	 *
	 * @param string $session_id ID da sessão.
	 * @return true|WP_Error
	 */
	public function cleanup_session_step( string $session_id ) {
		$session = $this->get_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$this->clean_session_directory( $session['session_dir'] );
		return true;
	}

	/**
	 * Monta a resposta da divisão sem perder o progresso persistido.
	 *
	 * @param array $session   Estado atual.
	 * @param bool  $completed Divisão concluída.
	 * @return array
	 */
	private function format_final_result( array $session, bool $completed ): array {
		$total_size  = (int) $session['total_size'];
		$total_parts = $total_size > 0 ? (int) ceil( $total_size / self::CHUNK_SIZE ) : 0;
		$parts       = isset( $session['parts'] ) && is_array( $session['parts'] ) ? $session['parts'] : array();
		$result      = array(
			'completed'   => $completed,
			'base'        => $session['base_name'],
			'parts'       => $parts,
			'total_size'  => $total_size,
			'total_parts' => $total_parts,
			'percent'     => $completed ? 100 : ( $total_size > 0 ? min( 100, (int) floor( ( (int) $session['split_offset'] / $total_size ) * 100 ) ) : 100 ),
			'log'         => $completed
				? sprintf(
					__( '[OK] Backup finalizado: %1$d parte(s) de até 25MB geradas (Total: %2$s)', 'dd-maintenance' ),
					count( $parts ),
					size_format( $total_size )
				)
				: sprintf(
					__( '[Dividindo] %1$d/%2$d parte(s) de 25MB geradas...', 'dd-maintenance' ),
					count( $parts ),
					$total_parts
				),
		);

		if ( $completed && ! empty( $parts ) ) {
			$result['file'] = $parts[0]['file'];
			$result['name'] = $parts[0]['name'];
			$result['size'] = $total_size;
		}

		return $result;
	}

	/**
	 * Reverte uma gravação incompleta para o último checkpoint persistido.
	 *
	 * @param string $file Caminho do arquivo.
	 * @param int    $size Tamanho confirmado.
	 * @return bool
	 */
	private function truncate_file( string $file, int $size ): bool {
		$handle = fopen( $file, 'c+b' );
		if ( ! $handle ) {
			return false;
		}
		$result = ftruncate( $handle, $size );
		fclose( $handle );
		return $result;
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
			@set_time_limit( 30 );
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '512M' );
			@ini_set( 'max_execution_time', '30' );
		}
		@ignore_user_abort( true );
	}
}
