<?php
/**
 * Responsável pela restauração de backups (banco de dados + arquivos), suportando arquivos únicos e divididos em partes de 25MB.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Restore {

	/**
	 * Restaura o site a partir de arquivo(s) .zip enviados via upload (suporta arquivo único ou múltiplas partes).
	 *
	 * @param array $file_input Array de upload ($_FILES['backup_zip']).
	 * @return array|WP_Error
	 */
	public function restore_from_upload( array $file_input ) {
		$this->set_time_and_memory_limits();

		if ( empty( $file_input['tmp_name'] ) ) {
			return new WP_Error( 'restore_upload_empty', __( 'Nenhum arquivo de backup foi enviado.', 'dd-maintenance' ) );
		}

		$backup_dir = DD_Maintenance::backup_dir();
		$temp_dir   = $backup_dir . '/upload-temp-' . time() . '-' . wp_generate_password( 8, false );

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'restore_mkdir_failed', __( 'Não foi possível criar o diretório temporário para processamento.', 'dd-maintenance' ) );
		}

		$uploaded_files = array();

		// Normaliza upload simples ou múltiplo (multiple="multiple").
		if ( is_array( $file_input['tmp_name'] ) ) {
			foreach ( $file_input['tmp_name'] as $i => $tmp_name ) {
				if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
					continue;
				}

				$orig_name = isset( $file_input['name'][ $i ] ) ? sanitize_file_name( $file_input['name'][ $i ] ) : 'part_' . $i . '.zip';
				$dest_path = $temp_dir . '/' . $orig_name;

				if ( move_uploaded_file( $tmp_name, $dest_path ) ) {
					$uploaded_files[] = $dest_path;
				}
			}
		} elseif ( is_uploaded_file( $file_input['tmp_name'] ) ) {
			$orig_name = isset( $file_input['name'] ) ? sanitize_file_name( $file_input['name'] ) : 'backup.zip';
			$dest_path = $temp_dir . '/' . $orig_name;

			if ( move_uploaded_file( $file_input['tmp_name'], $dest_path ) ) {
				$uploaded_files[] = $dest_path;
			}
		}

		if ( empty( $uploaded_files ) ) {
			$this->delete_directory( $temp_dir );
			return new WP_Error( 'restore_no_files_saved', __( 'Falha ao salvar os arquivos enviados no servidor.', 'dd-maintenance' ) );
		}

		// Se foi enviada apenas 1 parte e é um .zip padrão (sem ser .part002+).
		if ( 1 === count( $uploaded_files ) ) {
			$single_file = $uploaded_files[0];
			$ext         = strtolower( pathinfo( $single_file, PATHINFO_EXTENSION ) );

			if ( 'zip' !== $ext ) {
				$this->delete_directory( $temp_dir );
				return new WP_Error( 'restore_invalid_ext', __( 'O arquivo precisa estar no formato .zip.', 'dd-maintenance' ) );
			}

			if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $single_file ) ) ) {
				$this->delete_directory( $temp_dir );
				return new WP_Error(
					'restore_missing_other_parts',
					sprintf(
						__( 'O arquivo %s pertence a um backup em lotes. Selecione todas as partes juntas.', 'dd-maintenance' ),
						basename( $single_file )
					)
				);
			}

			$result = $this->restore_archive( $single_file );
			$this->delete_directory( $temp_dir );
			return $result;
		}
		return $this->restore_from_temp_directory( $temp_dir );
	}

	/**
	 * Restaura o site a partir de uma pasta temporária onde arquivos de backup foram salvos/enviados.
	 *
	 * @param string $temp_dir Caminho completo da pasta temporária.
	 * @return array|WP_Error
	 */
	public function restore_from_temp_directory( string $temp_dir ) {
		$this->set_time_and_memory_limits();

		$temp_dir = wp_normalize_path( realpath( $temp_dir ) ? realpath( $temp_dir ) : $temp_dir );
		if ( ! is_dir( $temp_dir ) ) {
			return new WP_Error( 'restore_temp_dir_missing', __( 'Pasta temporária de restauração não encontrada.', 'dd-maintenance' ) );
		}

		$files = glob( $temp_dir . '/*.zip' );
		if ( empty( $files ) ) {
			$this->delete_directory( $temp_dir );
			return new WP_Error( 'restore_no_zip_files', __( 'Nenhum arquivo .zip encontrado na pasta de restauração.', 'dd-maintenance' ) );
		}

		// Se foi enviada apenas 1 parte e é um .zip padrão (sem ser .part002+).
		if ( 1 === count( $files ) ) {
			$single_file = $files[0];
			$ext         = strtolower( pathinfo( $single_file, PATHINFO_EXTENSION ) );

			if ( 'zip' !== $ext ) {
				$this->delete_directory( $temp_dir );
				return new WP_Error( 'restore_invalid_ext', __( 'O arquivo precisa estar no formato .zip.', 'dd-maintenance' ) );
			}

			if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $single_file ) ) ) {
				$this->delete_directory( $temp_dir );
				return new WP_Error(
					'restore_missing_other_parts',
					sprintf(
						__( 'O arquivo %s pertence a um backup em lotes. Selecione todas as partes juntas.', 'dd-maintenance' ),
						basename( $single_file )
					)
				);
			}

			$result = $this->restore_archive( $single_file );
			$this->delete_directory( $temp_dir );
			return $result;
		}

		$result = $this->restore_part_files( $files, $temp_dir );
		$this->delete_directory( $temp_dir );
		return $result;
	}

	/**
	 * Restaura o site a partir de um backup local na pasta de backups.
	 * Suporta tanto o nome de um arquivo único (.zip) quanto o nome base de um backup em partes.
	 *
	 * @param string $identifier Nome do arquivo ou identificador base do backup.
	 * @return array|WP_Error
	 */
	public function restore_from_local_file( string $identifier ) {
		$this->set_time_and_memory_limits();

		$identifier = sanitize_file_name( $identifier );
		$backup_dir = DD_Maintenance::backup_dir();

		// Caso 1: Arquivo direto existe (ex: site-2026-08-20.zip).
		$direct_path = $backup_dir . '/' . $identifier;
		if ( file_exists( $direct_path ) && is_file( $direct_path ) && ! preg_match( '/\.part\d+\.zip$/i', $identifier ) ) {
			return $this->restore_archive( $direct_path );
		}

		// Caso 2: Backup dividido em partes (procura todas as partes do mesmo backup base).
		$base_name = preg_replace( '/\.part\d+\.zip$/i', '', $identifier );
		$base_name = preg_replace( '/\.zip$/i', '', $base_name );

		$part_files = glob( $backup_dir . '/' . $base_name . '.part*.zip' );

		if ( empty( $part_files ) ) {
			// Tenta arquivo zip simples com a base
			if ( file_exists( $backup_dir . '/' . $base_name . '.zip' ) ) {
				return $this->restore_archive( $backup_dir . '/' . $base_name . '.zip' );
			}

			return new WP_Error( 'restore_local_not_found', __( 'Arquivo(s) de backup local não encontrado(s).', 'dd-maintenance' ) );
		}

		$temp_dir = $backup_dir . '/local-restore-' . time() . '-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'restore_temp_failed', __( 'Não foi possível criar pasta temporária para processar os lotes.', 'dd-maintenance' ) );
		}

		$result = $this->restore_part_files( $part_files, $temp_dir );
		$this->delete_directory( $temp_dir );
		return $result;
	}

	/**
	 * Restaura volumes ZIP independentes e mantém compatibilidade com partes binárias antigas.
	 *
	 * @param array  $part_files Arquivos enviados ou locais.
	 * @param string $temp_dir   Pasta para eventual união legada.
	 * @return array|WP_Error
	 */
	private function restore_part_files( array $part_files, string $temp_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'restore_zip_missing', __( 'A extensão PHP ZipArchive não está disponível no servidor.', 'dd-maintenance' ) );
		}
		$part_files = $this->sort_part_files( $part_files );
		if ( is_wp_error( $part_files ) ) {
			return $part_files;
		}

		$independent = true;
		foreach ( $part_files as $file ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				$independent = false;
				break;
			}
			$zip->close();
		}

		if ( $independent ) {
			return $this->restore_archive_set( $part_files );
		}

		$merged = $temp_dir . '/merged_legacy_' . time() . '.zip';
		$joined = $this->join_part_files( $part_files, $merged );
		if ( is_wp_error( $joined ) ) {
			return $joined;
		}
		return $this->restore_archive( $merged );
	}

	/**
	 * Ordena e valida a sequência .part001.zip, .part002.zip...
	 *
	 * @param array $part_files Arquivos das partes.
	 * @return array|WP_Error
	 */
	private function sort_part_files( array $part_files ) {
		if ( empty( $part_files ) ) {
			return new WP_Error( 'join_empty_list', __( 'Nenhuma parte fornecida para restauração.', 'dd-maintenance' ) );
		}

		usort(
			$part_files,
			function( $a, $b ) {
				preg_match( '/\.part(\d+)\.zip$/i', basename( $a ), $ma );
				preg_match( '/\.part(\d+)\.zip$/i', basename( $b ), $mb );
				return (int) ( $ma[1] ?? 0 ) - (int) ( $mb[1] ?? 0 );
			}
		);

		$expected = 1;
		foreach ( $part_files as $file ) {
			if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $file ), $match ) ) {
				$current = (int) $match[1];
				if ( $current !== $expected ) {
					return new WP_Error(
						'join_sequence_missing',
						sprintf(
							__( 'Sequência de partes incompleta: era esperada a parte %1$03d, mas foi encontrada a parte %2$03d.', 'dd-maintenance' ),
							$expected,
							$current
						)
					);
				}
				$expected++;
			}
		}
		return $part_files;
	}

	/**
	 * Junta várias partes (.part001.zip, .part002.zip, ...) em um arquivo .zip unificado.
	 *
	 * @param array  $part_files  Lista de caminhos absolutos das partes.
	 * @param string $output_file Caminho do arquivo unificado de saída.
	 * @return true|WP_Error
	 */
	public function join_part_files( array $part_files, string $output_file ) {
		$part_files = $this->sort_part_files( $part_files );
		if ( is_wp_error( $part_files ) ) {
			return $part_files;
		}

		$out_handle = fopen( $output_file, 'wb' );
		if ( ! $out_handle ) {
			return new WP_Error( 'join_open_output_failed', __( 'Não foi possível criar o arquivo temporário de união das partes.', 'dd-maintenance' ) );
		}

		$buffer_size = 1048576; // 1 MB buffer

		foreach ( $part_files as $file ) {
			$in_handle = fopen( $file, 'rb' );
			if ( ! $in_handle ) {
				fclose( $out_handle );
				@unlink( $output_file );
				return new WP_Error(
					'join_open_input_failed',
					sprintf(
						/* translators: %s: Nome do arquivo da parte */
						__( 'Não foi possível ler a parte %s.', 'dd-maintenance' ),
						basename( $file )
					)
				);
			}

			while ( ! feof( $in_handle ) ) {
				$chunk = fread( $in_handle, $buffer_size );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				fwrite( $out_handle, $chunk );
			}

			fclose( $in_handle );
		}

		fclose( $out_handle );

		if ( ! file_exists( $output_file ) || filesize( $output_file ) <= 0 ) {
			@unlink( $output_file );
			return new WP_Error( 'join_file_empty', __( 'O arquivo reconstruído a partir das partes está vazio.', 'dd-maintenance' ) );
		}

		return true;
	}

	/**
	 * Executa o fluxo de descompactação e restauração de banco e arquivos.
	 *
	 * @param string $zip_path Caminho absoluto do arquivo .zip.
	 * @return array|WP_Error
	 */
	public function restore_archive( string $zip_path ) {
		return $this->restore_archive_set( array( $zip_path ) );
	}

	/**
	 * Inicia uma sessão de restauração em etapas gravando o estado em disco.
	 *
	 * @param array  $zip_paths       Caminhos dos volumes .zip.
	 * @param string $temp_upload_dir Pasta temporária de upload (se houver).
	 * @return array|WP_Error
	 */
	public function init_restore_session( array $zip_paths, string $temp_upload_dir = '' ) {
		$this->set_time_and_memory_limits();
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'restore_zip_missing', __( 'A extensão PHP ZipArchive não está disponível no servidor.', 'dd-maintenance' ) );
		}

		$zip_paths = $this->sort_part_files( $zip_paths );
		if ( is_wp_error( $zip_paths ) ) {
			return $zip_paths;
		}

		$session_id  = 'rst_' . time() . '_' . wp_generate_password( 8, false );
		$backup_dir  = DD_Maintenance::backup_dir();
		$extract_dir = $backup_dir . '/restore_exec_' . $session_id;

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'restore_mkdir_failed', __( 'Não foi possível criar a pasta temporária de extração.', 'dd-maintenance' ) );
		}

		$session = array(
			'session_id'        => $session_id,
			'extract_dir'       => $extract_dir,
			'temp_upload_dir'   => $temp_upload_dir,
			'zip_paths'         => array_values( $zip_paths ),
			'total_volumes'     => count( $zip_paths ),
			'current_index'     => 0,
			'large_rebuilt'     => false,
			'db_done'           => false,
			'db_stats'          => null,
			'files_done'        => false,
			'files_copied'      => 0,
			'log'               => array( '[Início da Restauração] ' . current_time( 'Y-m-d H:i:s' ) ),
			'created_at'        => time(),
		);

		$this->save_restore_session_data( $extract_dir, $session );
		return $session;
	}

	/**
	 * Carrega o estado da sessão de restauração.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function get_restore_session_data( string $session_id ) {
		$session_id  = sanitize_file_name( $session_id );
		$backup_dir  = DD_Maintenance::backup_dir();
		$extract_dir = $backup_dir . '/restore_exec_' . $session_id;
		$state_file  = $extract_dir . '/state.json';

		if ( ! file_exists( $state_file ) ) {
			return new WP_Error( 'restore_session_missing', __( 'Sessão de restauração não encontrada ou expirada.', 'dd-maintenance' ) );
		}

		$data = json_decode( (string) file_get_contents( $state_file ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'restore_session_corrupted', __( 'Dados da sessão de restauração corrompidos.', 'dd-maintenance' ) );
		}

		return $data;
	}

	/**
	 * Grava o estado da sessão de restauração.
	 *
	 * @param string $extract_dir Pasta da sessão.
	 * @param array  $session     Dados da sessão.
	 * @return void
	 */
	public function save_restore_session_data( string $extract_dir, array $session ): void {
		file_put_contents( $extract_dir . '/state.json', wp_json_encode( $session ) );
	}

	/**
	 * Extrai um lote de volumes ZIP (ex: até 5 volumes ou 6 segundos por chamada).
	 *
	 * @param string $session_id  ID da sessão.
	 * @param int    $batch_limit Quantidade máxima de volumes por lote.
	 * @return array|WP_Error
	 */
	public function extract_volume_step( string $session_id, int $batch_limit = 10 ) {
		$this->set_time_and_memory_limits();

		$session = $this->get_restore_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$extract_dir   = $session['extract_dir'];
		$zip_paths     = $session['zip_paths'];
		$total_volumes = (int) $session['total_volumes'];
		$current_index = (int) $session['current_index'];

		if ( $current_index >= $total_volumes ) {
			if ( empty( $session['large_rebuilt'] ) ) {
				$rebuilt = $this->reassemble_large_files( $extract_dir );
				if ( is_wp_error( $rebuilt ) ) {
					return $rebuilt;
				}
				$session['large_rebuilt'] = true;
				$this->save_restore_session_data( $extract_dir, $session );
			}

			return array(
				'completed'     => true,
				'current_index' => $total_volumes,
				'total_volumes' => $total_volumes,
				'percent'       => 100,
				'log'           => __( '[OK] Extração de todos os volumes concluída.', 'dd-maintenance' ),
			);
		}

		$processed = 0;
		$deadline  = microtime( true ) + 8.0;
		$log_lines = array();

		while ( $current_index < $total_volumes && $processed < $batch_limit && microtime( true ) < $deadline ) {
			$zip_path = $zip_paths[ $current_index ];

			if ( ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
				return new WP_Error( 'restore_zip_invalid', sprintf( __( 'Arquivo de lote inválido: %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return new WP_Error( 'restore_zip_open_failed', sprintf( __( 'Falha ao abrir o lote %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}

			for ( $idx = 0; $idx < $zip->numFiles; $idx++ ) {
				$entry_name = wp_normalize_path( (string) $zip->getNameIndex( $idx ) );
				if ( 0 === strpos( $entry_name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $entry_name ) ) {
					$zip->close();
					return new WP_Error( 'restore_zip_slip_detected', __( 'Arquivo de backup rejeitado por conter caminhos inválidos (Zip Slip).', 'dd-maintenance' ) );
				}
			}

			$extracted = $zip->extractTo( $extract_dir );
			$zip->close();

			if ( ! $extracted ) {
				return new WP_Error( 'restore_extract_failed', sprintf( __( 'Falha ao extrair o lote %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}

			// Se o lote veio de um upload temporário, remove o .zip imediatamente após extrair para economizar espaço em disco
			$temp_upload_dir = $session['temp_upload_dir'] ?? '';
			if ( ! empty( $temp_upload_dir ) && 0 === strpos( wp_normalize_path( $zip_path ), wp_normalize_path( $temp_upload_dir ) ) ) {
				@unlink( $zip_path );
			}
			$current_index++;
			$processed++;

			$line        = sprintf( '[Extração %d/%d] %s (%s)', $current_index, $total_volumes, basename( $zip_path ), size_format( (int) filesize( $zip_path ) ) );
			$log_lines[] = $line;
			$session['log'][] = $line;
		}

		$session['current_index'] = $current_index;
		$completed                = $current_index >= $total_volumes;

		if ( $completed && empty( $session['large_rebuilt'] ) ) {
			$rebuilt = $this->reassemble_large_files( $extract_dir );
			if ( is_wp_error( $rebuilt ) ) {
				return $rebuilt;
			}
			$session['large_rebuilt'] = true;
			$log_lines[]              = __( '[OK] Arquivos grandes remontados com sucesso.', 'dd-maintenance' );
		}

		$this->save_restore_session_data( $extract_dir, $session );

		$percent = (int) round( ( $current_index / $total_volumes ) * 100 );

		return array(
			'completed'     => $completed,
			'current_index' => $current_index,
			'total_volumes' => $total_volumes,
			'percent'       => $percent,
			'log'           => implode( "\n", $log_lines ),
		);
	}

	/**
	 * Etapa: Restauração progressiva em lotes do banco de dados SQL.
	 *
	 * @param string $session_id          ID da sessão.
	 * @param float  $time_limit_seconds  Tempo máximo em segundos por chamada (padrão: 5.0s).
	 * @return array|WP_Error
	 */
	public function restore_database_step( string $session_id, float $time_limit_seconds = 7.0 ) {
		global $wpdb;
		$this->set_time_and_memory_limits();

		$session = $this->get_restore_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! empty( $session['db_done'] ) ) {
			return array(
				'completed' => true,
				'has_sql'   => ! empty( $session['db_file'] ),
				'queries'   => $session['db_queries'] ?? 0,
				'tables'    => $session['db_tables'] ?? 0,
				'percent'   => 100,
				'log'       => __( '[OK] Restauração do banco SQL já concluída.', 'dd-maintenance' ),
			);
		}

		$extract_dir = $session['extract_dir'];

		// Inicializa metadados do SQL na primeira chamada desta etapa
		if ( ! isset( $session['db_file'] ) ) {
			$sql_file = $this->find_sql_file( $extract_dir, $session['temp_upload_dir'] ?? '', $session['zip_paths'] ?? array() );
			if ( ! $sql_file || ! is_file( $sql_file ) || filesize( $sql_file ) <= 0 ) {
				$session['db_done']  = true;
				$session['db_file']  = '';
				$session['log'][]    = __( '[Aviso] Nenhum arquivo .sql encontrado no backup (banco de dados mantido).', 'dd-maintenance' );
				$this->save_restore_session_data( $extract_dir, $session );

				return array(
					'completed' => true,
					'has_sql'   => false,
					'queries'   => 0,
					'tables'    => 0,
					'percent'   => 100,
					'log'       => __( '[Aviso] Nenhum arquivo .sql encontrado no backup (banco de dados mantido).', 'dd-maintenance' ),
				);
			}

			$session['db_file']            = $sql_file;
			$session['db_file_size']       = (int) filesize( $sql_file );
			$session['db_offset']          = 0;
			$session['db_queries']         = 0;
			$session['db_tables']          = 0;
			$session['db_errors']          = 0;
			$session['db_error_samples']   = array();
			$session['db_current_siteurl'] = get_option( 'siteurl', '' );
			$session['db_current_home']    = get_option( 'home', '' );
			$session['db_query_buffer']    = '';
			$session['db_in_string']       = false;
			$session['db_string_char']     = '';
			$this->save_restore_session_data( $extract_dir, $session );
		}

		$sql_file      = $session['db_file'];
		$file_size     = max( 1, (int) $session['db_file_size'] );
		$offset        = (int) $session['db_offset'];
		$queries       = (int) $session['db_queries'];
		$tables        = (int) $session['db_tables'];
		$errors        = (int) $session['db_errors'];
		$error_samples = (array) ( $session['db_error_samples'] ?? array() );
		$buffer        = (string) ( $session['db_query_buffer'] ?? '' );
		$in_string     = (bool) ( $session['db_in_string'] ?? false );
		$string_char   = (string) ( $session['db_string_char'] ?? '' );

		$handle = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'restore_sql_open_failed', __( 'Não foi possível ler o arquivo SQL do banco de dados.', 'dd-maintenance' ) );
		}

		fseek( $handle, $offset );

		$dbh        = ! empty( $wpdb->dbh ) ? $wpdb->dbh : null;
		$use_mysqli = ( $dbh instanceof mysqli );

		if ( $use_mysqli ) {
			@mysqli_query( $dbh, 'SET FOREIGN_KEY_CHECKS = 0;' );
			@mysqli_query( $dbh, 'SET UNIQUE_CHECKS = 0;' );
			@mysqli_query( $dbh, 'SET AUTOCOMMIT = 0;' );
			@mysqli_query( $dbh, "SET sql_mode = '';" );
			@mysqli_query( $dbh, 'START TRANSACTION;' );
		} else {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0;' );
		}

		$deadline       = microtime( true ) + $time_limit_seconds;
		$batch_count    = 0;
		$uncommited_cnt = 0;
		$eof_reached    = false;

		while ( ! feof( $handle ) && ( microtime( true ) < $deadline || $batch_count < 500 ) ) {
			$line = fgets( $handle, 1048576 );
			if ( false === $line ) {
				$eof_reached = true;
				break;
			}

			$trimmed = trim( $line );

			// Pula linhas de comentário simples caso não esteja dentro de uma string literal
			if ( ! $in_string ) {
				if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) || 0 === strpos( $trimmed, '#' ) ) {
					continue;
				}
			}

			$buffer .= $line;

			// Rastreia se estamos dentro de aspas simples '...'
			$len = strlen( $line );
			for ( $i = 0; $i < $len; $i++ ) {
				$char = $line[ $i ];
				if ( "'" === $char || '"' === $char ) {
					if ( ! $in_string ) {
						$in_string   = true;
						$string_char = $char;
					} elseif ( $string_char === $char ) {
						$escaped = false;
						$j       = $i - 1;
						while ( $j >= 0 && '\\' === $line[ $j ] ) {
							$escaped = ! $escaped;
							$j--;
						}
						if ( ! $escaped ) {
							$in_string   = false;
							$string_char = '';
						}
					}
				}
			}

			if ( ! $in_string && preg_match( '/;\s*$/', $trimmed ) ) {
				$sql    = trim( $buffer );
				$buffer = '';

				if ( '' !== $sql ) {
					// Ignora comandos de criação ou troca de banco de dados
					if ( ! preg_match( '/^(CREATE DATABASE|DROP DATABASE|USE\s+)/i', $sql ) ) {
						if ( preg_match( '/^(CREATE TABLE|DROP TABLE)/i', $sql ) ) {
							$tables++;
						}

						if ( $use_mysqli ) {
							$res = @mysqli_query( $dbh, $sql );
							if ( false === $res ) {
								$errors++;
								$err_msg = mysqli_error( $dbh );
								if ( $err_msg && count( $error_samples ) < 3 && ! in_array( $err_msg, $error_samples, true ) ) {
									$error_samples[] = $err_msg;
								}
							}
						} else {
							$res = $wpdb->query( $sql );
							if ( false === $res && ! empty( $wpdb->last_error ) ) {
								$errors++;
								if ( count( $error_samples ) < 3 && ! in_array( $wpdb->last_error, $error_samples, true ) ) {
									$error_samples[] = $wpdb->last_error;
								}
							}
						}
						$queries++;
						$batch_count++;
						$uncommited_cnt++;

						if ( $use_mysqli && $uncommited_cnt >= 1000 ) {
							@mysqli_query( $dbh, 'COMMIT;' );
							@mysqli_query( $dbh, 'START TRANSACTION;' );
							$uncommited_cnt = 0;
						}
					}
				}
			}
		}

		if ( feof( $handle ) ) {
			$eof_reached = true;
		}

		$current_offset = ftell( $handle );
		fclose( $handle );

		if ( $use_mysqli ) {
			@mysqli_query( $dbh, 'COMMIT;' );
		}

		$session['db_offset']        = $current_offset;
		$session['db_queries']       = $queries;
		$session['db_tables']        = $tables;
		$session['db_errors']        = $errors;
		$session['db_error_samples'] = $error_samples;
		$session['db_query_buffer']  = $buffer;
		if ( $eof_reached ) {
			if ( $use_mysqli ) {
				@mysqli_query( $dbh, 'COMMIT;' );
				@mysqli_query( $dbh, 'SET AUTOCOMMIT = 1;' );
				@mysqli_query( $dbh, 'SET FOREIGN_KEY_CHECKS = 1;' );
				@mysqli_query( $dbh, 'SET UNIQUE_CHECKS = 1;' );
			} else {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1;' );
			}

			// 1. Detecta o prefixo das tabelas que acabaram de ser restauradas no banco
			$dump_prefix = null;
			$detected_tables = $wpdb->get_col( "SHOW TABLES LIKE '%options'" );
			if ( ! empty( $detected_tables ) ) {
				foreach ( $detected_tables as $tbl ) {
					if ( preg_match( '/^(.+)options$/', $tbl, $m ) ) {
						$dump_prefix = $m[1];
						break;
					}
				}
			}
			if ( empty( $dump_prefix ) ) {
				$dump_prefix = ! empty( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
			}

			// Se o prefixo do backup for diferente do wp-config.php atual, sincroniza no wp-config.php
			if ( $dump_prefix !== $wpdb->prefix && class_exists( 'DD_Maintenance_Config' ) ) {
				$config_res = DD_Maintenance_Config::update_table_prefix( $dump_prefix );
				if ( ! is_wp_error( $config_res ) ) {
					$old_prefix = $wpdb->prefix;
					$wpdb->query( $wpdb->prepare( "UPDATE `{$dump_prefix}usermeta` SET `meta_key` = REPLACE(`meta_key`, %s, %s) WHERE `meta_key` LIKE %s", $old_prefix, $dump_prefix, $old_prefix . '%' ) );
					$wpdb->set_prefix( $dump_prefix );
				}
			}

			// 2. Lê a URL original do backup para fazer Search & Replace
			$options_table = $dump_prefix . 'options';
			$old_siteurl   = (string) $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'siteurl' LIMIT 1" );

			$siteurl = $session['db_current_siteurl'] ?? '';
			$home    = $session['db_current_home'] ?? '';

			// Atualiza siteurl e home para o domínio atual onde o site está sendo restaurado
			if ( ! empty( $siteurl ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'siteurl'", $siteurl ) );
			}
			if ( ! empty( $home ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'home'", $home ) );
			}

			// 3. Se a URL de origem do backup era diferente da URL atual, realiza Search & Replace
			// para que imagens, galerias, posts, Elementor, links e temas venham 100% visíveis
			if ( ! empty( $old_siteurl ) && ! empty( $siteurl ) && rtrim( $old_siteurl, '/' ) !== rtrim( $siteurl, '/' ) ) {
				$from_url       = rtrim( $old_siteurl, '/' );
				$to_url         = rtrim( $siteurl, '/' );
				$posts_table    = $dump_prefix . 'posts';
				$postmeta_table = $dump_prefix . 'postmeta';

				// Posts e páginas (conteúdo, excerpt e guid)
				$wpdb->query( $wpdb->prepare( "UPDATE `{$posts_table}` SET `post_content` = REPLACE(`post_content`, %s, %s), `guid` = REPLACE(`guid`, %s, %s)", $from_url, $to_url, $from_url, $to_url ) );

				// Metadados de posts (Elementor, galerias, custom fields)
				$wpdb->query( $wpdb->prepare( "UPDATE `{$postmeta_table}` SET `meta_value` = REPLACE(`meta_value`, %s, %s) WHERE `meta_value` LIKE %s", $from_url, $to_url, '%' . $wpdb->esc_like( $from_url ) . '%' ) );

				// Opções gerais do site (exceto siteurl/home que já foram atualizados)
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = REPLACE(`option_value`, %s, %s) WHERE `option_name` NOT IN ('siteurl', 'home') AND `option_value` LIKE %s", $from_url, $to_url, '%' . $wpdb->esc_like( $from_url ) . '%' ) );
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				@wp_cache_flush();
			}

			$session['db_done']  = true;
			$session['db_stats'] = array(
				'queries' => $queries,
				'tables'  => $tables,
				'errors'  => $errors,
			);
			$final_line          = sprintf( __( '[OK] Banco restaurado: %1$s comandos executados (%2$d tabelas).', 'dd-maintenance' ), number_format_i18n( $queries ), $tables );
			if ( $errors > 0 && ! empty( $error_samples ) ) {
				$final_line .= ' (' . sprintf( __( '%d avisos SQL', 'dd-maintenance' ), $errors ) . ')';
			}
			$session['log'][]    = $final_line;
			$this->save_restore_session_data( $extract_dir, $session );

			return array(
				'completed' => true,
				'has_sql'   => true,
				'queries'   => $queries,
				'tables'    => $tables,
				'errors'    => $errors,
				'percent'   => 100,
				'log'       => $final_line,
			);
		}

		$this->save_restore_session_data( $extract_dir, $session );

		$progress_line = sprintf( __( '[Banco] Dump SQL: %1$d%% (%2$s comandos executados, %3$d tabelas)...', 'dd-maintenance' ), $percent, number_format_i18n( $queries ), $tables );

		return array(
			'completed' => false,
			'has_sql'   => true,
			'queries'   => $queries,
			'tables'    => $tables,
			'errors'    => $errors,
			'percent'   => $percent,
			'log'       => $progress_line,
		);
	}

	/**
	 * Etapa: Cópia e restauração progressiva dos arquivos do site na raiz.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function restore_files_step( string $session_id ) {
		$this->set_time_and_memory_limits();

		$session = $this->get_restore_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! empty( $session['files_done'] ) ) {
			return array(
				'completed' => true,
				'copied'    => $session['files_copied'] ?? 0,
				'total'     => $session['files_total'] ?? 0,
				'percent'   => 100,
				'log'       => __( '[OK] Arquivos já restaurados com sucesso.', 'dd-maintenance' ),
			);
		}

		$extract_dir = $session['extract_dir'];
		$queue_file  = $extract_dir . '/restore_queue.jsonl';

		// Inicializa a fila de arquivos a serem restaurados na primeira chamada desta etapa
		if ( empty( $session['files_queue_created'] ) ) {
			$q_handle    = fopen( $queue_file, 'wb' );
			$total_files = 0;

			$backup_dirs = array(
				wp_normalize_path( DD_Maintenance::backup_dir() ),
				wp_normalize_path( $extract_dir ),
			);

			$copy_tasks = array();
			if ( is_dir( $extract_dir . '/site' ) ) {
				$copy_tasks[] = array( 'source' => $extract_dir . '/site', 'dest' => ABSPATH );
			} elseif ( is_dir( $extract_dir . '/wp-content' ) ) {
				$copy_tasks[] = array( 'source' => $extract_dir . '/wp-content', 'dest' => WP_CONTENT_DIR );
			} else {
				$copy_tasks[] = array( 'source' => $extract_dir, 'dest' => ABSPATH );
			}

			foreach ( $copy_tasks as $task ) {
				$source_dir = wp_normalize_path( $task['source'] );
				$dest_dir   = wp_normalize_path( $task['dest'] );
				if ( ! is_dir( $source_dir ) ) {
					continue;
				}

				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $item ) {
					$item_path      = wp_normalize_path( $item->getPathname() );
					$filename_lower = strtolower( $item->getFilename() );

					// NUNCA sobrescreve o wp-config.php e não copia arquivos SQL soltos
					if ( 'wp-config.php' === $filename_lower || 'database.sql' === $filename_lower || preg_match( '/\.sql$/i', $filename_lower ) ) {
						continue;
					}

					$relative = ltrim( substr( $item_path, strlen( $source_dir ) ), '/' );
					$target   = $dest_dir . '/' . $relative;

					$skip = false;
					foreach ( $backup_dirs as $ignore ) {
						if ( 0 === strpos( $target, $ignore ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip ) {
						continue;
					}

					if ( $item->isFile() ) {
						fwrite( $q_handle, wp_json_encode( array( 'src' => $item_path, 'dst' => $target ) ) . "\n" );
						$total_files++;
					}
				}
			}
			fclose( $q_handle );

			$session['files_queue_created'] = true;
			$session['files_total']         = $total_files;
			$session['files_copied']        = 0;
			$session['files_queue_offset']  = 0;
			$this->save_restore_session_data( $extract_dir, $session );
		}

		$total_files  = max( 1, (int) ( $session['files_total'] ?? 1 ) );
		$copied       = (int) ( $session['files_copied'] ?? 0 );
		$queue_offset = (int) ( $session['files_queue_offset'] ?? 0 );

		$deadline    = microtime( true ) + 6.0;
		$batch_count = 0;
		$completed   = false;

		if ( file_exists( $queue_file ) ) {
			$q_handle = fopen( $queue_file, 'rb' );
			fseek( $q_handle, $queue_offset );

			while ( ! feof( $q_handle ) && ( microtime( true ) < $deadline || $batch_count < 300 ) ) {
				$line = fgets( $q_handle );
				if ( false === $line ) {
					$completed = true;
					break;
				}
				$task = json_decode( $line, true );
				if ( is_array( $task ) && ! empty( $task['src'] ) && ! empty( $task['dst'] ) ) {
					if ( is_file( $task['src'] ) ) {
						$parent = dirname( $task['dst'] );
						if ( ! is_dir( $parent ) ) {
							wp_mkdir_p( $parent );
						}
						if ( @copy( $task['src'], $task['dst'] ) ) {
							$copied++;
						}
					}
				}
				$batch_count++;
			}

			$new_offset = ftell( $q_handle );
			if ( feof( $q_handle ) ) {
				$completed = true;
			}
			fclose( $q_handle );
			$session['files_queue_offset'] = $new_offset;
		} else {
			$completed = true;
		}

		$session['files_copied'] = $copied;
		$percent = min( 100, max( 0, (int) round( ( $copied / $total_files ) * 100 ) ) );

		if ( $completed || $copied >= $total_files ) {
			$session['files_done'] = true;
			$log_line              = sprintf( __( '[OK] Arquivos restaurados: %1$s arquivos copiados com sucesso.', 'dd-maintenance' ), number_format_i18n( $copied ) );
			$session['log'][]      = $log_line;
			$this->save_restore_session_data( $extract_dir, $session );

			return array(
				'completed' => true,
				'copied'    => $copied,
				'total'     => $total_files,
				'percent'   => 100,
				'log'       => $log_line,
			);
		}

		$this->save_restore_session_data( $extract_dir, $session );
		$progress_line = sprintf( __( '[Arquivos] %1$s/%2$s arquivos copiados (%3$d%%)...', 'dd-maintenance' ), number_format_i18n( $copied ), number_format_i18n( $total_files ), $percent );

		return array(
			'completed' => false,
			'copied'    => $copied,
			'total'     => $total_files,
			'percent'   => $percent,
			'log'       => $progress_line,
		);
	}
	/**
	 * Finaliza a restauração limpando pastas temporárias e consolidando o log final.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array|WP_Error
	 */
	public function finalize_restore_step( string $session_id ) {
		$session = $this->get_restore_session_data( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$extract_dir     = $session['extract_dir'];
		$temp_upload_dir = $session['temp_upload_dir'];

		$this->delete_directory( $extract_dir );
		if ( ! empty( $temp_upload_dir ) && is_dir( $temp_upload_dir ) ) {
			$this->delete_directory( $temp_upload_dir );
		}

		if ( class_exists( 'DD_Maintenance_Backup' ) ) {
			DD_Maintenance_Backup::purge_orphaned_sessions( 300 );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			@wp_cache_flush();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		$session['log'][] = '[Fim da Restauração] ' . current_time( 'Y-m-d H:i:s' );

		return array(
			'success'  => true,
			'log'      => $session['log'],
			'db_stats' => $session['db_stats'],
			'files'    => $session['files_copied'],
		);
	}

	/**
	 * Limpa a sessão de restauração em caso de falha.
	 *
	 * @param string $session_id ID da sessão.
	 * @return void
	 */
	public function cleanup_failed_restore( string $session_id ) {
		$session = $this->get_restore_session_data( $session_id );
		if ( ! is_wp_error( $session ) ) {
			$this->delete_directory( $session['extract_dir'] );
			if ( ! empty( $session['temp_upload_dir'] ) && is_dir( $session['temp_upload_dir'] ) ) {
				$this->delete_directory( $session['temp_upload_dir'] );
			}
		}
		if ( class_exists( 'DD_Maintenance_Backup' ) ) {
			DD_Maintenance_Backup::purge_orphaned_sessions( 300 );
		}
	}

	/**
	 * Executa a restauração completa em loop sequencial síncrono (para CLI/WP-Cron).
	 *
	 * @param array $zip_paths Caminhos dos volumes ZIP.
	 * @return array|WP_Error
	 */
	private function restore_archive_set( array $zip_paths ) {
		$session = $this->init_restore_session( $zip_paths );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session_id = $session['session_id'];

		do {
			$ext_res = $this->extract_volume_step( $session_id, 10 );
			if ( is_wp_error( $ext_res ) ) {
				$this->cleanup_failed_restore( $session_id );
				return $ext_res;
			}
		} while ( empty( $ext_res['completed'] ) );

		$db_res = $this->restore_database_step( $session_id );
		if ( is_wp_error( $db_res ) ) {
			$this->cleanup_failed_restore( $session_id );
			return $db_res;
		}

		$files_res = $this->restore_files_step( $session_id );
		if ( is_wp_error( $files_res ) ) {
			$this->cleanup_failed_restore( $session_id );
			return $files_res;
		}

		return $this->finalize_restore_step( $session_id );
	}
	/**
	 * Reconstrói arquivos que atravessaram mais de um volume.
	 *
	 * @param string $extract_dir Pasta compartilhada de extração.
	 * @return true|WP_Error
	 */
	private function reassemble_large_files( string $extract_dir ) {
		$chunk_dir    = $extract_dir . '/__dd_chunks__';
		$manifest_file = $chunk_dir . '/manifest.json';
		if ( ! is_file( $manifest_file ) ) {
			return true;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return new WP_Error( 'restore_chunk_manifest', __( 'Manifesto de arquivos grandes inválido.', 'dd-maintenance' ) );
		}

		foreach ( $manifest['files'] as $file ) {
			if ( empty( $file['target'] ) || empty( $file['chunks'] ) || ! is_array( $file['chunks'] ) ) {
				return new WP_Error( 'restore_chunk_entry', __( 'Entrada inválida no manifesto de arquivos grandes.', 'dd-maintenance' ) );
			}

			$target = wp_normalize_path( $file['target'] );
			if ( 0 === strpos( $target, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $target ) ) {
				return new WP_Error( 'restore_chunk_path', __( 'Caminho inválido no manifesto de arquivos grandes.', 'dd-maintenance' ) );
			}

			$destination = $extract_dir . '/' . $target;
			if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
				return new WP_Error( 'restore_chunk_mkdir', sprintf( __( 'Não foi possível criar a pasta para %s.', 'dd-maintenance' ), $target ) );
			}
			$output = fopen( $destination, 'wb' );
			if ( ! $output ) {
				return new WP_Error( 'restore_chunk_output', sprintf( __( 'Não foi possível reconstruir %s.', 'dd-maintenance' ), $target ) );
			}

			$chunks = $file['chunks'];
			ksort( $chunks, SORT_NUMERIC );
			foreach ( $chunks as $chunk ) {
				$chunk = wp_normalize_path( $chunk );
				if ( 0 !== strpos( $chunk, '__dd_chunks__/' ) || preg_match( '#(^|/)\.\.(/|$)#', $chunk ) ) {
					fclose( $output );
					return new WP_Error( 'restore_chunk_path', __( 'Caminho de trecho inválido no manifesto.', 'dd-maintenance' ) );
				}
				$input = fopen( $extract_dir . '/' . $chunk, 'rb' );
				if ( ! $input ) {
					fclose( $output );
					return new WP_Error( 'restore_chunk_missing', sprintf( __( 'Trecho ausente ao reconstruir %s.', 'dd-maintenance' ), $target ) );
				}
				stream_copy_to_stream( $input, $output );
				fclose( $input );
			}
			fclose( $output );
			clearstatcache( true, $destination );
			if ( isset( $file['size'] ) && filesize( $destination ) !== (int) $file['size'] ) {
				return new WP_Error( 'restore_chunk_size', sprintf( __( 'Tamanho reconstruído inválido para %s.', 'dd-maintenance' ), $target ) );
			}
		}

		$this->delete_directory( $chunk_dir );
		return true;
	}

	/**
	 * Localiza o arquivo SQL dentro da pasta extraída ou pastas de upload/backup.
	 *
	 * @param string $extract_dir     Pasta raiz da extração.
	 * @param string $temp_upload_dir Pasta temporária de upload (opcional).
	 * @param array  $zip_paths       Caminhos dos volumes .zip (opcional).
	 * @return string|null Caminho completo do arquivo SQL ou null.
	 */
	private function find_sql_file( string $extract_dir, string $temp_upload_dir = '', array $zip_paths = array() ): ?string {
		// 1. database.sql na raiz da extração
		if ( file_exists( $extract_dir . '/database.sql' ) && filesize( $extract_dir . '/database.sql' ) > 0 ) {
			return $extract_dir . '/database.sql';
		}

		// 2. Qualquer arquivo .sql na raiz da extração
		$files = glob( $extract_dir . '/*.sql' );
		if ( ! empty( $files ) ) {
			foreach ( $files as $f ) {
				if ( is_file( $f ) && filesize( $f ) > 0 ) {
					return $f;
				}
			}
		}

		// 3. Subpastas da extração (ex: extract_dir/site/*.sql)
		$sub_files = glob( $extract_dir . '/*/*.sql' );
		if ( ! empty( $sub_files ) ) {
			foreach ( $sub_files as $f ) {
				if ( is_file( $f ) && filesize( $f ) > 0 ) {
					return $f;
				}
			}
		}

		// 4. Pasta temporária de upload (se o .sql foi enviado no mesmo upload)
		if ( ! empty( $temp_upload_dir ) && is_dir( $temp_upload_dir ) ) {
			if ( file_exists( $temp_upload_dir . '/database.sql' ) && filesize( $temp_upload_dir . '/database.sql' ) > 0 ) {
				@copy( $temp_upload_dir . '/database.sql', $extract_dir . '/database.sql' );
				return $extract_dir . '/database.sql';
			}
			$upload_sqls = glob( $temp_upload_dir . '/*.sql' );
			if ( ! empty( $upload_sqls ) ) {
				foreach ( $upload_sqls as $f ) {
					if ( is_file( $f ) && filesize( $f ) > 0 ) {
						@copy( $f, $extract_dir . '/' . basename( $f ) );
						return $extract_dir . '/' . basename( $f );
					}
				}
			}
		}

		// 5. Pasta local de backups
		$backup_dir = DD_Maintenance::backup_dir();
		if ( ! empty( $zip_paths ) ) {
			foreach ( $zip_paths as $zp ) {
				$base      = preg_replace( '/\.part\d+\.zip$/i', '', basename( $zp ) );
				$base      = preg_replace( '/\.zip$/i', '', $base );
				$candidate = $backup_dir . '/' . $base . '.sql';
				if ( file_exists( $candidate ) && is_file( $candidate ) && filesize( $candidate ) > 0 ) {
					@copy( $candidate, $extract_dir . '/' . basename( $candidate ) );
					return $extract_dir . '/' . basename( $candidate );
				}
			}
		}

		return null;
	}

	/**
	 * Executa a restauração do banco de dados a partir de um dump SQL.
	 *
	 * @param string $sql_file Caminho do arquivo .sql.
	 * @return array|WP_Error
	 */
	public function restore_database( string $sql_file ) {
		global $wpdb;

		// Salva as URLs atuais do site para preservar a navegação no domínio atual após a restauração.
		$current_siteurl = get_option( 'siteurl', '' );
		$current_home    = get_option( 'home', '' );

		$handle = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'restore_sql_open_failed', __( 'Não foi possível ler o arquivo SQL do banco de dados.', 'dd-maintenance' ) );
		}

		// Desativa temporariamente checagem de foreign keys.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0;' );

		$query_buffer  = '';
		$query_count   = 0;
		$table_count   = 0;
		$error_count   = 0;
		$in_string     = false;
		$string_char   = '';

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			$trimmed = trim( $line );

			// Pula linhas de comentário simples caso não esteja dentro de uma string.
			if ( ! $in_string ) {
				if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) || 0 === strpos( $trimmed, '#' ) ) {
					continue;
				}
			}

			$query_buffer .= $line;

			// Verifica fim de comando SQL delimitado por ponto e vírgula.
			$len = strlen( $line );
			for ( $i = 0; $i < $len; $i++ ) {
				$char = $line[ $i ];

				if ( "'" === $char || '"' === $char || '`' === $char ) {
					if ( ! $in_string ) {
						$in_string   = true;
						$string_char = $char;
					} elseif ( $string_char === $char ) {
						// Verifica se não é escape de barra (\').
						$escaped = false;
						$j       = $i - 1;
						while ( $j >= 0 && '\\' === $line[ $j ] ) {
							$escaped = ! $escaped;
							$j--;
						}
						if ( ! $escaped ) {
							$in_string   = false;
							$string_char = '';
						}
					}
				}
			}

			if ( ! $in_string && preg_match( '/;\s*$/', $trimmed ) ) {
				$sql = trim( $query_buffer );
				$query_buffer = '';

				if ( '' !== $sql ) {
					// Ignora comandos de criação ou troca de banco de dados para manter sempre o banco ativo do wp-config.php.
					if ( preg_match( '/^(CREATE DATABASE|DROP DATABASE|USE\s+)/i', $sql ) ) {
						continue;
					}

					if ( preg_match( '/^(CREATE TABLE|DROP TABLE)/i', $sql ) ) {
						$table_count++;
					}

					$result = $wpdb->query( $sql );
					if ( false === $result && ! empty( $wpdb->last_error ) ) {
						$error_count++;
					}
					$query_count++;
				}
			}
		}

		fclose( $handle );

		// Restaura checagem de foreign keys.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1;' );

		// Garante que o site continue acessível no domínio atual (caso o backup tenha vindo de outro domínio).
		if ( ! empty( $current_siteurl ) ) {
			$options_table = ! empty( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
			$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'siteurl'", $current_siteurl ) );
		}
		if ( ! empty( $current_home ) ) {
			$options_table = ! empty( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
			$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'home'", $current_home ) );
		}

		return array(
			'queries' => $query_count,
			'tables'  => $table_count,
			'errors'  => $error_count,
		);
	}

	/**
	 * Restaura os arquivos do backup para o site.
	 *
	 * @param string $extract_dir Pasta onde o zip foi descompactado.
	 * @return array|WP_Error
	 */
	public function restore_files( string $extract_dir ) {
		$copied_files = 0;

		$backup_dirs = array(
			wp_normalize_path( DD_Maintenance::backup_dir() ),
			wp_normalize_path( $extract_dir ),
		);

		// Estrutura 1: Site inteiro na pasta "site/".
		if ( is_dir( $extract_dir . '/site' ) ) {
			$copied_files += $this->copy_directory( $extract_dir . '/site', ABSPATH, $backup_dirs );
		} else {
			// Estrutura 2: wp-content/ solto na raiz do backup.
			if ( is_dir( $extract_dir . '/wp-content' ) ) {
				$copied_files += $this->copy_directory( $extract_dir . '/wp-content', WP_CONTENT_DIR, $backup_dirs );
			}
		}
		return array(
			'copied' => $copied_files,
		);
	}

	/**
	 * Copia arquivos recursivamente de uma pasta para outra, ignorando pastas de backup.
	 *
	 * @param string $source_dir   Pasta de origem.
	 * @param string $dest_dir     Pasta de destino.
	 * @param array  $ignore_paths Pastas a ignorar.
	 * @return int Quantidade de arquivos copiados.
	 */
	private function copy_directory( string $source_dir, string $dest_dir, array $ignore_paths = array() ): int {
		$copied = 0;
		$source_dir = wp_normalize_path( $source_dir );
		$dest_dir   = wp_normalize_path( $dest_dir );

		if ( ! is_dir( $source_dir ) ) {
			return 0;
		}
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		$dest_canonical = wp_normalize_path( realpath( $dest_dir ) ? realpath( $dest_dir ) : $dest_dir );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );

			$filename_lower = strtolower( $item->getFilename() );

			// NUNCA sobrescreve o wp-config.php e não copia arquivos SQL soltos durante a cópia de arquivos.
			if ( 'wp-config.php' === $filename_lower || 'database.sql' === $filename_lower || preg_match( '/\.sql$/i', $filename_lower ) ) {
				continue;
			}

			$relative = ltrim( substr( $item_path, strlen( $source_dir ) ), '/' );
			$target   = $dest_dir . '/' . $relative;

			$skip = false;
			foreach ( $ignore_paths as $ignore ) {
				if ( 0 === strpos( $target, $ignore ) ) {
					$skip = true;
					break;
				}
			}

			if ( $skip ) {
				continue;
			}
			// Proteção de travessia de diretório: o destino precisa estar estritamente dentro da pasta de destino.
			$target_normalized = wp_normalize_path( $target );
			if ( 0 !== strpos( $target_normalized, $dest_canonical . '/' ) && $target_normalized !== $dest_canonical ) {
				continue;
			}
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
			} elseif ( $item->isFile() ) {
				$parent_dir = dirname( $target );
				if ( ! is_dir( $parent_dir ) ) {
					wp_mkdir_p( $parent_dir );
				}
				if ( @copy( $item_path, $target ) ) {
					$copied++;
				}
			}
		}

		return $copied;
	}

	/**
	 * Retorna a lista de backups locais salvos em wp-content/uploads/dd-maintenance/ agrupados por pacote.
	 *
	 * @return array
	 */
	public static function get_local_backups(): array {
		$backup_dir = DD_Maintenance::backup_dir();
		$files      = glob( $backup_dir . '/*.zip' );
		$sql_files  = glob( $backup_dir . '/*.sql' );
		$groups     = array();

		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}

				$filename = basename( $file );
				$size     = (int) filesize( $file );
				$mtime    = filemtime( $file );

				// Identifica se é uma parte de um backup multi-part (ex: site-2026-08-20-1330.part001.zip)
				if ( preg_match( '/^(.+)\.part(\d+)\.zip$/i', $filename, $matches ) ) {
					$base_name = $matches[1];
					$part_num  = (int) $matches[2];

					if ( ! isset( $groups[ $base_name ] ) ) {
						$groups[ $base_name ] = array(
							'base_name'     => $base_name,
							'display_name'  => $base_name,
							'is_multipart'  => true,
							'parts'         => array(),
							'total_size'    => 0,
							'latest_mtime'  => $mtime,
							'has_sql'       => false,
							'sql_filename'  => '',
							'sql_size'      => 0,
						);
					}

					$groups[ $base_name ]['parts'][ $part_num ] = array(
						'filename'       => $filename,
						'path'           => $file,
						'size'           => $size,
						'size_formatted' => size_format( $size ),
						'part'           => $part_num,
					);
					$groups[ $base_name ]['total_size'] += $size;
					if ( $mtime > $groups[ $base_name ]['latest_mtime'] ) {
						$groups[ $base_name ]['latest_mtime'] = $mtime;
					}
				} else {
					// Arquivo zip simples de parte única
					$base_name = preg_replace( '/\.zip$/i', '', $filename );

					if ( ! isset( $groups[ $base_name ] ) ) {
						$groups[ $base_name ] = array(
							'base_name'     => $base_name,
							'display_name'  => $filename,
							'is_multipart'  => false,
							'parts'         => array(
								1 => array(
									'filename'       => $filename,
									'path'           => $file,
									'size'           => $size,
									'size_formatted' => size_format( $size ),
									'part'           => 1,
								),
							),
							'total_size'    => $size,
							'latest_mtime'  => $mtime,
							'has_sql'       => false,
							'sql_filename'  => '',
							'sql_size'      => 0,
						);
					} else {
						$groups[ $base_name ]['parts'][1] = array(
							'filename'       => $filename,
							'path'           => $file,
							'size'           => $size,
							'size_formatted' => size_format( $size ),
							'part'           => 1,
						);
						$groups[ $base_name ]['total_size'] += $size;
					}
				}
			}
		}

		// Detecta dumps SQL associados ou avulsos na pasta local
		if ( ! empty( $sql_files ) ) {
			foreach ( $sql_files as $sql_file ) {
				if ( ! is_file( $sql_file ) ) {
					continue;
				}

				$sql_filename = basename( $sql_file );
				$sql_base     = preg_replace( '/\.sql$/i', '', $sql_filename );
				$sql_size     = (int) filesize( $sql_file );
				$sql_mtime    = filemtime( $sql_file );

				if ( isset( $groups[ $sql_base ] ) ) {
					$groups[ $sql_base ]['has_sql']           = true;
					$groups[ $sql_base ]['sql_filename']      = $sql_filename;
					$groups[ $sql_base ]['sql_size']          = $sql_size;
					$groups[ $sql_base ]['sql_size_formatted'] = size_format( $sql_size );
					$groups[ $sql_base ]['total_size']       += $sql_size;
					if ( $sql_mtime > $groups[ $sql_base ]['latest_mtime'] ) {
						$groups[ $sql_base ]['latest_mtime'] = $sql_mtime;
					}
				} else {
					$groups[ $sql_base ] = array(
						'base_name'          => $sql_base,
						'display_name'       => $sql_filename . ' (' . __( 'Dump SQL', 'dd-maintenance' ) . ')',
						'is_multipart'       => false,
						'parts'              => array(),
						'total_size'         => $sql_size,
						'latest_mtime'       => $sql_mtime,
						'has_sql'            => true,
						'sql_filename'       => $sql_filename,
						'sql_size'           => $sql_size,
						'sql_size_formatted' => size_format( $sql_size ),
					);
				}
			}
		}

		$backups = array();
		foreach ( $groups as $base => $data ) {
			ksort( $data['parts'], SORT_NUMERIC );
			$parts_list = array_values( $data['parts'] );
			$count      = count( $parts_list );
			$mtime      = $data['latest_mtime'];

			$backups[] = array(
				'identifier'         => $base,
				'display_name'       => $data['is_multipart'] ? sprintf( '%s (%d partes de 25MB)', $base, $count ) : $data['display_name'],
				'is_multipart'       => $data['is_multipart'],
				'total_parts'        => $count,
				'parts'              => $parts_list,
				'has_sql'            => ! empty( $data['has_sql'] ),
				'sql_filename'       => $data['sql_filename'] ?? '',
				'sql_size'           => $data['sql_size'] ?? 0,
				'sql_size_formatted' => $data['sql_size_formatted'] ?? ( ! empty( $data['sql_size'] ) ? size_format( $data['sql_size'] ) : '' ),
				'size'               => $data['total_size'],
				'size_formatted'     => size_format( $data['total_size'] ),
				'timestamp'          => $mtime,
				'date_formatted'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ), 'd/m/Y H:i:s' ),
			);
		}

		// Ordena do backup mais recente para o mais antigo.
		usort(
			$backups,
			function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			}
		);

		return $backups;
	}

	/**
	 * Exclui todas as partes de um backup local a partir de seu identificador.
	 *
	 * @param string $identifier Nome base do backup ou nome do arquivo zip.
	 * @return bool
	 */
	public static function delete_local_backup( string $identifier ): bool {
		$identifier = sanitize_file_name( $identifier );
		$backup_dir = DD_Maintenance::backup_dir();
		$base_name  = preg_replace( '/\.part\d+\.zip$/i', '', $identifier );
		$base_name  = preg_replace( '/\.zip$/i', '', $base_name );

		$deleted = false;

		// Exclui todas as partes se for multipart
		$parts = glob( $backup_dir . '/' . $base_name . '.part*.zip' );
		if ( ! empty( $parts ) ) {
			foreach ( $parts as $p ) {
				if ( is_file( $p ) ) {
					@unlink( $p );
					$deleted = true;
				}
			}
		}

		// Exclui arquivo zip simples se existir
		$single = $backup_dir . '/' . $base_name . '.zip';
		if ( file_exists( $single ) && is_file( $single ) ) {
			@unlink( $single );
			$deleted = true;
		}

		// Exclui dump sql local se existir com a mesma base
		$sql = $backup_dir . '/' . $base_name . '.sql';
		if ( file_exists( $sql ) && is_file( $sql ) ) {
			@unlink( $sql );
		}

		return $deleted;
	}

	/**
	 * Remove recursivamente uma pasta e seus arquivos.
	 *
	 * @param string $dir Caminho da pasta.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
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
	 * Tenta elevar limites de execução e memória para restaurar grandes backups.
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
