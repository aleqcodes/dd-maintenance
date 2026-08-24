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
	 * Limites por requisição e por volume. Os arquivos são armazenados sem
	 * compressão; a margem cobre cabeçalhos e o índice central do ZIP.
	 */
	const BATCH_FILE_COUNT    = 1000;
	const DB_BATCH_SIZE       = 1000;
	const STEP_TIME_LIMIT     = 8;
	const VOLUME_PAYLOAD_SIZE = 25165824;
	/**
	 * Inicializa uma sessão de backup em lotes (cria pasta e metadados da sessão).
	 *
	 * @return array|WP_Error Dados da sessão inicializada.
	 */
	public function init_session() {
		$this->set_time_and_memory_limits();

		self::purge_orphaned_sessions();

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
				'split_size_mb'     => 200,
			)
		);
		$session_data = array(
			'session_id'         => $session_id,
			'session_dir'        => $session_dir,
			'base_name'          => $base,
			'settings'           => $settings,
			'db_file'              => $session_dir . '/database.sql',
			'manifest_file'        => $session_dir . '/manifest.jsonl',
			'index_queue_file'     => $session_dir . '/index-queue.jsonl',
			'total_files'          => 0,
			'processed'            => 0,
			'db_initialized'       => false,
			'db_completed'         => false,
			'db_table_index'       => 0,
			'db_row_offset'        => 0,
			'db_schema_written'    => false,
			'db_position'          => 0,
			'db_manifested'        => false,
			'index_initialized'    => false,
			'index_completed'      => false,
			'index_queue_offset'   => 0,
			'index_queue_size'     => 0,
			'manifest_size'        => 0,
			'indexed_dirs'         => 0,
			'zip_manifest_offset'  => 0,
			'volume_index'         => 1,
			'large_file_offset'    => 0,
			'large_file_target'    => '',
			'large_files'          => array(),
			'metadata_added'       => false,
			'volume_count'         => 0,
			'volumes_completed'    => false,
			'total_size'           => 0,
			'parts'                => array(),
			'created_at'           => time(),
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
			if ( ! empty( $rows ) ) {
				$chunks = array_chunk( $rows, 100 );
				foreach ( $chunks as $chunk ) {
					$row_sqls = array();
					foreach ( $chunk as $row ) {
						$values = array();
						foreach ( $row as $value ) {
							$values[] = null === $value ? 'NULL' : "'" . $wpdb->_real_escape( (string) $value ) . "'";
						}
						$row_sqls[] = '(' . implode( ', ', $values ) . ')';
					}
					$sql .= "INSERT INTO `{$quoted_name}` VALUES\n" . implode( ",\n", $row_sqls ) . ";\n";
				}
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
	 * Etapa 2: Indexa os arquivos em um manifesto leve, sem carregá-los na memória.
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
				'log'         => sprintf( __( '[OK] %d arquivos catalogados para os lotes de 25MB.', 'dd-maintenance' ), $session['total_files'] ),
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
			if ( empty( $session['db_manifested'] ) && ! empty( $session['settings']['include_db'] ) && is_file( $session['db_file'] ) ) {
				$entry = array( 'path' => $session['db_file'], 'target' => 'database.sql' );
				file_put_contents( $session['manifest_file'], wp_json_encode( $entry ) . "\n", FILE_APPEND );
				clearstatcache( true, $session['manifest_file'] );
				$session['manifest_size'] = (int) filesize( $session['manifest_file'] );
				$session['total_files']++;
				$session['db_manifested'] = true;
			}
			$session['index_completed'] = true;
			$this->save_session_data( $session['session_dir'], $session );
		}

		return array(
			'completed'    => $completed,
			'total_files'  => (int) $session['total_files'],
			'indexed_dirs' => (int) $session['indexed_dirs'],
			'log'          => $completed
				? sprintf( __( '[OK] %d arquivos catalogados para os lotes de 25MB.', 'dd-maintenance' ), $session['total_files'] )
				: sprintf( __( '[Indexando] %1$d arquivos encontrados em %2$d pastas...', 'dd-maintenance' ), $session['total_files'], $session['indexed_dirs'] ),
		);
	}

	/**
	 * Etapa 3: Distribui arquivos em volumes ZIP de até 25MB usando somente CM_STORE.
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
		$manifest_offset = (int) $session['zip_manifest_offset'];
		if ( $manifest_offset >= $manifest_size ) {
			return $this->format_batch_result( $session, true, 0, 100 );
		}

		$manifest = fopen( $session['manifest_file'], 'rb' );
		if ( ! $manifest ) {
			return new WP_Error( 'manifest_read', __( 'Não foi possível ler o manifesto de arquivos.', 'dd-maintenance' ) );
		}
		fseek( $manifest, $manifest_offset );

		$deadline       = microtime( true ) + self::STEP_TIME_LIMIT;
		$batch_count    = 0;
		$zip            = null;
		$volume_path    = '';
		$estimated_size = 22;

		while ( $batch_count < self::BATCH_FILE_COUNT && microtime( true ) < $deadline ) {
			$line_offset = ftell( $manifest );
			$line        = fgets( $manifest );
			if ( false === $line ) {
				break;
			}

			$next_offset = ftell( $manifest );
			$item        = json_decode( $line, true );
			if ( ! is_array( $item ) || empty( $item['path'] ) || empty( $item['target'] ) || ! is_file( $item['path'] ) ) {
				$manifest_offset = $next_offset;
				$session['processed']++;
				$batch_count++;
				continue;
			}

			$payload_limit = $this->get_payload_size( $session );
			$file_size     = (int) filesize( $item['path'] );
			if ( $file_size > $payload_limit ) {
				if ( $zip instanceof ZipArchive ) {
					$closed = $this->close_volume( $zip, $volume_path, $session );
					if ( is_wp_error( $closed ) ) {
						fclose( $manifest );
						return $closed;
					}
					if ( file_exists( $volume_path ) && filesize( $volume_path ) > 22 ) {
						$session['volume_index']++;
					}
					$zip = null;
				}
				$large_result = $this->store_large_file_chunk( $session, $item, $line_offset, $next_offset );
				if ( is_wp_error( $large_result ) ) {
					fclose( $manifest );
					return $large_result;
				}
				$session         = $large_result['session'];
				$manifest_offset = (int) $session['zip_manifest_offset'];
				$batch_count    += ! empty( $large_result['file_completed'] ) ? 1 : 0;
				$this->save_session_data( $session['session_dir'], $session );
				break;
			}

			$entry_size = $file_size + ( 2 * strlen( $item['target'] ) ) + 256;
			if ( ! $zip instanceof ZipArchive ) {
				$volume_path = $this->volume_path( $session, (int) $session['volume_index'] );
				$zip         = $this->open_volume( $volume_path );
				if ( is_wp_error( $zip ) ) {
					fclose( $manifest );
					return $zip;
				}
				$estimated_size = file_exists( $volume_path ) ? (int) filesize( $volume_path ) : 22;
			}

			if ( $zip->numFiles > 0 && $estimated_size + $entry_size > $payload_limit ) {
				$closed = $this->close_volume( $zip, $volume_path, $session );
				if ( is_wp_error( $closed ) ) {
					fclose( $manifest );
					return $closed;
				}
				$session['volume_index']++;
				$volume_path = $this->volume_path( $session, (int) $session['volume_index'] );
				$zip         = $this->open_volume( $volume_path );
				if ( is_wp_error( $zip ) ) {
					fclose( $manifest );
					return $zip;
				}
				$estimated_size = file_exists( $volume_path ) ? (int) filesize( $volume_path ) : 22;
			}

			if ( false === $zip->locateName( $item['target'] ) ) {
				if ( ! $zip->addFile( $item['path'], $item['target'] ) ) {
					$zip->close();
					fclose( $manifest );
					return new WP_Error( 'zip_add_failed', sprintf( __( 'Não foi possível adicionar %s ao lote.', 'dd-maintenance' ), $item['target'] ) );
				}
				if ( method_exists( $zip, 'setCompressionName' ) ) {
					$zip->setCompressionName( $item['target'], ZipArchive::CM_STORE );
				}
				$estimated_size += $entry_size;
			}

			$manifest_offset = $next_offset;
			$session['processed']++;
			$batch_count++;
		}

		fclose( $manifest );
		if ( $zip instanceof ZipArchive ) {
			$closed = $this->close_volume( $zip, $volume_path );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
		}

		$session['zip_manifest_offset'] = $manifest_offset;
		$session['processed']           = min( (int) $session['total_files'], (int) $session['processed'] );
		$this->save_session_data( $session['session_dir'], $session );

		$completed = $manifest_offset >= $manifest_size;
		$percent   = $manifest_size > 0 ? min( 100, (int) floor( ( $manifest_offset / $manifest_size ) * 100 ) ) : 100;
		return $this->format_batch_result( $session, $completed, $batch_count, $percent );
	}

	/**
	 * Etapa 4: Finaliza e publica os volumes ZIP independentes.
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
		if ( ! empty( $session['volumes_completed'] ) ) {
			return $this->format_final_result( $session );
		}

		if ( empty( $session['metadata_added'] ) ) {
			$volume_files = $this->session_volume_files( $session );
			if ( empty( $volume_files ) ) {
				return new WP_Error( 'volume_empty', __( 'Nenhum lote de backup foi gerado.', 'dd-maintenance' ) );
			}

			if ( ! empty( $session['large_files'] ) ) {
				$metadata = wp_json_encode(
					array(
						'version' => 1,
						'files'   => array_values( $session['large_files'] ),
					)
				);
				$last_volume = end( $volume_files );
				$entry_size  = strlen( $metadata ) + 1024;
				$payload_limit = $this->get_payload_size( $session );
				if ( filesize( $last_volume ) + $entry_size > $payload_limit ) {
					$session['volume_index'] = count( $volume_files ) + 1;
					$last_volume = $this->volume_path( $session, (int) $session['volume_index'] );
				}

				$zip = $this->open_volume( $last_volume );
				if ( is_wp_error( $zip ) ) {
					return $zip;
				}
				if ( false === $zip->locateName( '__dd_chunks__/manifest.json' ) ) {
					$zip->addFromString( '__dd_chunks__/manifest.json', $metadata );
					$zip->setCompressionName( '__dd_chunks__/manifest.json', ZipArchive::CM_STORE );
				}
				$closed = $this->close_volume( $zip, $last_volume, $session );
				if ( is_wp_error( $closed ) ) {
					return $closed;
				}
			}

			$session['metadata_added'] = true;
			$session['volume_count']   = count( $this->session_volume_files( $session ) );
			$this->save_session_data( $session['session_dir'], $session );
		}

		$backup_dir  = DD_Maintenance::backup_dir();
		$volume_count = (int) $session['volume_count'];
		$parts        = array();
		$total_size   = 0;

		for ( $index = 1; $index <= $volume_count; $index++ ) {
			$source = $this->volume_path( $session, $index );
			$name   = 1 === $volume_count
				? $session['base_name'] . '.zip'
				: sprintf( '%s.part%03d.zip', $session['base_name'], $index );
			$target = $backup_dir . '/' . $name;

			if ( file_exists( $source ) && ! rename( $source, $target ) ) {
				return new WP_Error( 'volume_move_failed', sprintf( __( 'Não foi possível finalizar o lote %s.', 'dd-maintenance' ), $name ) );
			}
			if ( ! is_file( $target ) ) {
				return new WP_Error( 'volume_missing', sprintf( __( 'O lote %s não foi encontrado.', 'dd-maintenance' ), $name ) );
			}

			$size        = (int) filesize( $target );
			$chunk_limit = $this->get_chunk_size( $session );
			if ( $size > $chunk_limit ) {
				return new WP_Error( 'volume_oversize', sprintf( __( 'O lote %1$s excedeu o limite configurado (%2$s).', 'dd-maintenance' ), $name, size_format( $size ) ) );
			}
			$parts[] = array(
				'file' => $target,
				'name' => $name,
				'size' => $size,
				'part' => $index,
			);
			$total_size += $size;
		}

		if ( ! empty( $session['settings']['keep_local'] ) && is_file( $session['db_file'] ) ) {
			copy( $session['db_file'], $backup_dir . '/' . $session['base_name'] . '.sql' );
		}

		$session['parts']             = $parts;
		$session['total_size']        = $total_size;
		$session['volumes_completed'] = true;
		$this->save_session_data( $session['session_dir'], $session );

		return $this->format_final_result( $session );
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
	 * Executa a limpeza automática de um backup que falhou, removendo pastas residuais,
	 * temporários e salvando o log da falha na pasta de uploads.
	 *
	 * @param string $session_id      ID da sessão de backup.
	 * @param string $error_message   Mensagem de erro explicativa.
	 * @param array  $accumulated_log Linhas de log acumuladas até a falha.
	 * @return array
	 */
	public function cleanup_failed_session( string $session_id, string $error_message = '', array $accumulated_log = array() ): array {
		$session_id  = sanitize_file_name( $session_id );
		$session     = $this->get_session_data( $session_id );
		$base_name   = ! is_wp_error( $session ) && ! empty( $session['base_name'] ) ? $session['base_name'] : '';
		$session_dir = ! is_wp_error( $session ) && ! empty( $session['session_dir'] ) ? $session['session_dir'] : '';

		if ( ! empty( $session_dir ) && is_dir( $session_dir ) ) {
			$this->clean_session_directory( $session_dir );
		} elseif ( ! empty( $session_id ) ) {
			$backup_dir = DD_Maintenance::backup_dir();
			$this->clean_session_directory( $backup_dir . '/session_' . $session_id );
		}

		if ( ! empty( $base_name ) ) {
			$backup_dir = DD_Maintenance::backup_dir();
			$leftovers  = glob( $backup_dir . '/' . $base_name . '.volume*.zip' );
			if ( is_array( $leftovers ) ) {
				foreach ( $leftovers as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file );
					}
				}
			}
		}

		$log = ! empty( $accumulated_log ) ? $accumulated_log : array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) );
		if ( ! empty( $error_message ) ) {
			$log[] = '[ERRO] Falha no backup: ' . $error_message;
		}
		$log[] = '[AUTOLIMPEZA] Arquivos temporários residuais removidos da pasta de uploads com sucesso.';
		$log[] = '[Fim com Erro] ' . current_time( 'Y-m-d H:i:s' );

		DD_Maintenance::save_log( $log, 'failure', $base_name );

		return array(
			'cleaned' => true,
			'log'     => $log,
		);
	}

	/**
	 * Varre a pasta de backups e remove pastas temporárias de sessões abandonadas
	 * ou arquivos residuais com mais de X segundos (padrão: 2 horas).
	 *
	 * @param int $max_age_seconds Idade máxima em segundos.
	 * @return int Quantidade de itens limpos.
	 */
	public static function purge_orphaned_sessions( int $max_age_seconds = 7200 ): int {
		$backup_dir = DD_Maintenance::backup_dir();
		$now        = time();
		$cleaned    = 0;

		$dirs = glob( $backup_dir . '/{session_*,upload-temp-*,temp-restore-*}', GLOB_BRACE | GLOB_ONLYDIR );
		if ( is_array( $dirs ) ) {
			foreach ( $dirs as $dir ) {
				$mtime = filemtime( $dir );
				if ( false !== $mtime && ( $now - $mtime ) >= $max_age_seconds ) {
					$instance = new self();
					$instance->clean_session_directory( $dir );
					$cleaned++;
				}
			}
		}

		$temp_files = glob( $backup_dir . '/*.{volume*.zip,tmp}', GLOB_BRACE );
		if ( is_array( $temp_files ) ) {
			foreach ( $temp_files as $file ) {
				if ( is_file( $file ) ) {
					$mtime = filemtime( $file );
					if ( false !== $mtime && ( $now - $mtime ) >= $max_age_seconds ) {
						@unlink( $file );
						$cleaned++;
					}
				}
			}
		}

		return $cleaned;
	}

	/**
	 * Monta a resposta de progresso da criação dos volumes.
	 *
	 * @param array $session     Estado atual.
	 * @param bool  $completed   Todos os arquivos processados.
	 * @param int   $batch_count Arquivos processados nesta chamada.
	 * @param int   $percent     Progresso.
	 * @return array
	 */
	private function format_batch_result( array $session, bool $completed, int $batch_count, int $percent ): array {
		return array(
			'completed'   => $completed,
			'processed'   => (int) $session['processed'],
			'total_files' => (int) $session['total_files'],
			'batch_count' => $batch_count,
			'next_offset' => (int) $session['processed'],
			'percent'     => $percent,
			'log'         => $completed
				? __( '[OK] Todos os arquivos foram distribuídos nos lotes de 25MB.', 'dd-maintenance' )
				: sprintf(
					__( '[Lotes] %1$d/%2$d arquivos processados; volume atual: %3$d (%4$d%%)...', 'dd-maintenance' ),
					$session['processed'],
					$session['total_files'],
					$session['volume_index'],
					$percent
				),
		);
	}

	/**
	 * Monta a resposta final dos volumes independentes.
	 *
	 * @param array $session Estado final.
	 * @return array
	 */
	private function format_final_result( array $session ): array {
		$parts      = isset( $session['parts'] ) && is_array( $session['parts'] ) ? $session['parts'] : array();
		$total_size = (int) $session['total_size'];

		foreach ( $parts as &$p ) {
			if ( ! isset( $p['size_formatted'] ) && isset( $p['size'] ) ) {
				$p['size_formatted'] = size_format( (int) $p['size'] );
			}
		}
		unset( $p );

		$result = array(
			'completed'   => true,
			'base'        => $session['base_name'],
			'parts'       => $parts,
			'total_size'  => $total_size,
			'total_parts' => count( $parts ),
			'percent'     => 100,
			'log'         => sprintf(
				__( '[OK] Backup finalizado: %1$d lote(s) ZIP sem compressão (Total: %2$s)', 'dd-maintenance' ),
				count( $parts ),
				size_format( $total_size )
			),
		);

		$backup_dir = DD_Maintenance::backup_dir();
		$sql_file   = $backup_dir . '/' . $session['base_name'] . '.sql';
		if ( file_exists( $sql_file ) && is_file( $sql_file ) ) {
			$sql_size                    = (int) filesize( $sql_file );
			$result['has_sql']           = true;
			$result['sql_filename']      = $session['base_name'] . '.sql';
			$result['sql_size']          = $sql_size;
			$result['sql_size_formatted'] = size_format( $sql_size );
		}

		if ( ! empty( $parts ) ) {
			$result['file'] = $parts[0]['file'];
			$result['name'] = $parts[0]['name'];
			$result['size'] = $total_size;
		}
		return $result;
	}

	/**
	 * Caminho temporário de um volume.
	 *
	 * @param array $session Estado atual.
	 * @param int   $index   Número do volume.
	 * @return string
	 */
	private function volume_path( array $session, int $index ): string {
		return sprintf( '%s/%s.volume%06d.zip', $session['session_dir'], $session['base_name'], $index );
	}

	/**
	 * Abre um volume existente ou novo.
	 *
	 * @param string $path Caminho do volume.
	 * @return ZipArchive|WP_Error
	 */
	private function open_volume( string $path ) {
		$zip   = new ZipArchive();
		$flags = file_exists( $path ) ? 0 : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $zip->open( $path, $flags ) ) {
			return new WP_Error( 'volume_open_failed', __( 'Não foi possível abrir um lote ZIP de 25MB.', 'dd-maintenance' ) );
		}
		return $zip;
	}

	/**
	 * Fecha e valida o limite físico do volume.
	 *
	 * @param ZipArchive $zip     Volume aberto.
	 * @param string     $path    Caminho do volume.
	 * @param array      $session Dados da sessão.
	 * @return true|WP_Error
	 */
	private function close_volume( $zip, string $path, array $session = array() ) {
		if ( ! $zip->close() ) {
			return new WP_Error( 'volume_close_failed', __( 'Não foi possível concluir um lote ZIP.', 'dd-maintenance' ) );
		}
		clearstatcache( true, $path );
		$chunk_limit = $this->get_chunk_size( $session );
		if ( ! is_file( $path ) || filesize( $path ) > $chunk_limit ) {
			return new WP_Error( 'volume_oversize', sprintf( __( 'Um lote ultrapassou o limite físico configurado (%s).', 'dd-maintenance' ), size_format( $chunk_limit ) ) );
		}
		return true;
	}

	/**
	 * Armazena um trecho de arquivo maior que um volume e persiste o mapa para restauração.
	 *
	 * @param array $session     Estado atual.
	 * @param array $item        Arquivo do manifesto.
	 * @param int   $line_offset Início da linha no manifesto.
	 * @param int   $next_offset Próxima linha no manifesto.
	 * @return array|WP_Error
	 */
	private function store_large_file_chunk( array $session, array $item, int $line_offset, int $next_offset ) {
		$file_size     = (int) filesize( $item['path'] );
		$offset        = $session['large_file_target'] === $item['target'] ? (int) $session['large_file_offset'] : 0;
		$hash          = sha1( $item['target'] );
		$chunk_name    = sprintf( '__dd_chunks__/%s.part%06d', $hash, 1 );
		$payload_limit = $this->get_payload_size( $session );
		$max_data      = $payload_limit - ( 2 * strlen( $chunk_name ) ) - 512;
		$part          = (int) floor( $offset / $max_data ) + 1;
		$chunk_name    = sprintf( '__dd_chunks__/%s.part%06d', $hash, $part );
		$length        = min( $max_data, $file_size - $offset );
		if ( ! $handle ) {
			return new WP_Error( 'large_file_read', sprintf( __( 'Não foi possível ler o arquivo grande %s.', 'dd-maintenance' ), $item['target'] ) );
		}
		$data = stream_get_contents( $handle, $length, $offset );
		fclose( $handle );
		if ( false === $data || strlen( $data ) !== $length ) {
			return new WP_Error( 'large_file_chunk', sprintf( __( 'Falha ao ler um trecho de %s.', 'dd-maintenance' ), $item['target'] ) );
		}

		$volume_path = $this->volume_path( $session, (int) $session['volume_index'] );
		$zip         = $this->open_volume( $volume_path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		if ( false === $zip->locateName( $chunk_name ) && $zip->numFiles > 0 ) {
			$closed = $this->close_volume( $zip, $volume_path );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
			$session['volume_index']++;
			$volume_path = $this->volume_path( $session, (int) $session['volume_index'] );
			$zip         = $this->open_volume( $volume_path );
			if ( is_wp_error( $zip ) ) {
				return $zip;
			}
		}

		if ( false === $zip->locateName( $chunk_name ) ) {
			if ( ! $zip->addFromString( $chunk_name, $data ) ) {
				$zip->close();
				return new WP_Error( 'large_file_add', sprintf( __( 'Não foi possível adicionar um trecho de %s.', 'dd-maintenance' ), $item['target'] ) );
			}
			$zip->setCompressionName( $chunk_name, ZipArchive::CM_STORE );
		}
		unset( $data );

		$closed = $this->close_volume( $zip, $volume_path );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}

		if ( ! isset( $session['large_files'][ $hash ] ) ) {
			$session['large_files'][ $hash ] = array(
				'target' => $item['target'],
				'size'   => $file_size,
				'chunks' => array(),
			);
		}
		$session['large_files'][ $hash ]['chunks'][ $part ] = $chunk_name;
		$offset += $length;
		$file_completed = $offset >= $file_size;
		$session['large_file_target']   = $file_completed ? '' : $item['target'];
		$session['large_file_offset']   = $file_completed ? 0 : $offset;
		$session['zip_manifest_offset'] = $file_completed ? $next_offset : $line_offset;
		$session['volume_index']++;
		if ( $file_completed ) {
			$session['processed']++;
		}

		return array(
			'session'        => $session,
			'file_completed' => $file_completed,
		);
	}

	/**
	 * Lista os volumes temporários em ordem numérica.
	 *
	 * @param array $session Estado atual.
	 * @return array
	 */
	private function session_volume_files( array $session ): array {
		$files = glob( $session['session_dir'] . '/' . $session['base_name'] . '.volume*.zip' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		natsort( $files );
		return array_values( $files );
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

	/**
	 * Retorna o tamanho máximo de cada volume em bytes (configurável pelo usuário).
	 *
	 * @param array $session Dados da sessão.
	 * @return int
	 */
	public function get_chunk_size( array $session = array() ): int {
		$split_mb = isset( $session['settings']['split_size_mb'] ) ? (int) $session['settings']['split_size_mb'] : 0;
		if ( $split_mb < 25 ) {
			$settings = get_option( 'dd_maintenance_settings', array() );
			$split_mb = isset( $settings['split_size_mb'] ) ? (int) $settings['split_size_mb'] : 200;
		}
		$split_mb = max( 25, min( 1000, $split_mb ) );
		return $split_mb * 1048576;
	}

	/**
	 * Retorna o tamanho útil de payload por volume em bytes.
	 *
	 * @param array $session Dados da sessão.
	 * @return int
	 */
	public function get_payload_size( array $session = array() ): int {
		return (int) round( $this->get_chunk_size( $session ) * 0.96 );
	}
}
