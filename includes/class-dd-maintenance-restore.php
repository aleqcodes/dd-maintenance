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

		$session_id   = 'rst_' . time() . '_' . wp_generate_password( 8, false );
		$restore_token = wp_generate_password( 48, false, false );
		$backup_dir   = DD_Maintenance::backup_dir();
		$extract_dir  = $backup_dir . '/restore_exec_' . $session_id;

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'restore_mkdir_failed', __( 'Não foi possível criar a pasta temporária de extração.', 'dd-maintenance' ) );
		}

		self::create_mu_plugin_loader();
		$scheme = ( is_ssl() || ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ? 'https://' : 'http://';
		$host   = $_SERVER['HTTP_HOST'] ?? '';
		$detected_url = ! empty( $host ) ? untrailingslashit( $scheme . $host ) : '';

		$current_siteurl = get_option( 'siteurl', '' );
		$current_home    = get_option( 'home', '' );

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
			'target_siteurl'    => ! empty( $current_siteurl ) ? $current_siteurl : $detected_url,
			'target_home'       => ! empty( $current_home ) ? $current_home : $detected_url,
			'files_done'        => false,
			'files_copied'      => 0,
			'auth_token_hash'   => hash( 'sha256', $restore_token ),
			'auth_expires_at'   => time() + 7200,
			'log'               => array( '[Início da Restauração] ' . current_time( 'Y-m-d H:i:s' ) ),
			'created_at'        => time(),
		);
		$this->save_restore_session_data( $extract_dir, $session );
		$session['restore_token'] = $restore_token;
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
	 * Valida o token efêmero que autoriza a continuação da restauração após
	 * a troca do banco invalidar a sessão e o nonce do administrador.
	 *
	 * @param string $session_id ID da sessão.
	 * @param string $token      Token recebido na inicialização.
	 * @return bool
	 */
	public function verify_restore_token( string $session_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$session = $this->get_restore_session_data( $session_id );
		if ( is_wp_error( $session )
			|| empty( $session['auth_token_hash'] )
			|| empty( $session['auth_expires_at'] )
			|| time() > (int) $session['auth_expires_at'] ) {
			return false;
		}

		if ( ! hash_equals( (string) $session['auth_token_hash'], hash( 'sha256', $token ) ) ) {
			return false;
		}

		$session['auth_expires_at'] = time() + 7200;
		$this->save_restore_session_data( $session['extract_dir'], $session );
		return true;
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

		$sql_file  = $session['db_file'];
		$file_size = (int) ( $session['db_file_size'] ?? 0 );
		if ( $file_size <= 0 && is_file( $sql_file ) ) {
			clearstatcache( true, $sql_file );
			$file_size = (int) filesize( $sql_file );
			$session['db_file_size'] = $file_size;
		}
		$file_size     = max( 1, $file_size );
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

		$current_offset = ftell( $handle );
		if ( false === $current_offset ) {
			fclose( $handle );
			return new WP_Error( 'restore_sql_offset_failed', __( 'Não foi possível salvar o ponto de continuação do arquivo SQL.', 'dd-maintenance' ) );
		}
		$percent = min( 100, max( 0, (int) floor( ( $current_offset / $file_size ) * 100 ) ) );
		if ( feof( $handle ) ) {
			$eof_reached = true;
		}
		fclose( $handle );
		if ( $use_mysqli ) {
			@mysqli_query( $dbh, 'COMMIT;' );
		}
		// Ao final de CADA lote, garante que todas as tabelas options existentes tenham active_plugins, siteurl e home apontando para o site atual
		$opt_tables = $wpdb->get_col( "SHOW TABLES LIKE '%options'" );
		if ( ! empty( $opt_tables ) && is_array( $opt_tables ) ) {
			foreach ( $opt_tables as $opt_table ) {
				$ap_raw = $wpdb->get_var( "SELECT `option_value` FROM `{$opt_table}` WHERE `option_name` = 'active_plugins' LIMIT 1" );
				if ( ! empty( $ap_raw ) ) {
					$ap_list = maybe_unserialize( $ap_raw );
					if ( is_array( $ap_list ) ) {
						$modified  = false;
						$our_slugs = array( 'dd-maintenance/dd-maintenance.php', 'backuper/backuper.php' );
						foreach ( $our_slugs as $slug ) {
							if ( ! in_array( $slug, $ap_list, true ) ) {
								$ap_list[] = $slug;
								$modified  = true;
							}
						}
						if ( $modified ) {
							$wpdb->query( $wpdb->prepare( "UPDATE `{$opt_table}` SET `option_value` = %s WHERE `option_name` = 'active_plugins'", serialize( $ap_list ) ) );
						}
					}
				}

				$target_siteurl = ! empty( $session['target_siteurl'] ) ? $session['target_siteurl'] : '';
				$target_home    = ! empty( $session['target_home'] ) ? $session['target_home'] : '';
				if ( ! empty( $target_siteurl ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE `{$opt_table}` SET `option_value` = %s WHERE `option_name` = 'siteurl'", untrailingslashit( $target_siteurl ) ) );
				}
				if ( ! empty( $target_home ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE `{$opt_table}` SET `option_value` = %s WHERE `option_name` = 'home'", untrailingslashit( $target_home ) ) );
				}
			}
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

			// 1. Detecta o prefixo das tabelas restauradas no banco
			$dump_prefix = ! empty( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
			$detected_tables = $wpdb->get_col( "SHOW TABLES LIKE '%options'" );
			if ( ! empty( $detected_tables ) ) {
				foreach ( $detected_tables as $tbl ) {
					if ( preg_match( '/^(.+)options$/', $tbl, $m ) ) {
						$dump_prefix = $m[1];
						break;
					}
				}
			}

			// 2. Se o prefixo do backup for diferente do wp-config.php atual, sincroniza no wp-config.php
			if ( $dump_prefix !== $wpdb->prefix && class_exists( 'DD_Maintenance_Config' ) ) {
				$config_res = DD_Maintenance_Config::update_table_prefix( $dump_prefix );
				if ( ! is_wp_error( $config_res ) ) {
					$old_prefix = $wpdb->prefix;
					$wpdb->query( $wpdb->prepare( "UPDATE `{$dump_prefix}usermeta` SET `meta_key` = REPLACE(`meta_key`, %s, %s) WHERE `meta_key` LIKE %s", $old_prefix, $dump_prefix, $old_prefix . '%' ) );
					$wpdb->set_prefix( $dump_prefix );
				}
			}

			$options_table = $dump_prefix . 'options';

			// 3. Lê o siteurl antigo do backup antes de sobrescrever
			$old_siteurl = (string) $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'siteurl' LIMIT 1" );

			// 4. Garante que DD Maintenance e Backuper estejam SEMPRE ativos em active_plugins da nova tabela
			$active_raw  = $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'active_plugins' LIMIT 1" );
			$active_list = maybe_unserialize( $active_raw );
			if ( ! is_array( $active_list ) ) {
				$active_list = array();
			}
			$our_plugins = array( 'dd-maintenance/dd-maintenance.php', 'backuper/backuper.php' );
			$modified    = false;
			foreach ( $our_plugins as $p_slug ) {
				if ( ! in_array( $p_slug, $active_list, true ) ) {
					$active_list[] = $p_slug;
					$modified      = true;
				}
			}
			if ( $modified ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'active_plugins'", serialize( $active_list ) ) );
			}

			// 5. Atualiza siteurl e home na tabela de opções restaurada para o domínio atual do servidor
			$target_siteurl = ! empty( $session['target_siteurl'] ) ? $session['target_siteurl'] : ( ! empty( $session['db_current_siteurl'] ) ? $session['db_current_siteurl'] : get_option( 'siteurl', '' ) );
			$target_home    = ! empty( $session['target_home'] ) ? $session['target_home'] : ( ! empty( $session['db_current_home'] ) ? $session['db_current_home'] : get_option( 'home', '' ) );
			if ( ! empty( $target_siteurl ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'siteurl'", untrailingslashit( $target_siteurl ) ) );
			}
			if ( ! empty( $target_home ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = 'home'", untrailingslashit( $target_home ) ) );
			}

			// 6. Atualiza URLs de links e assets no banco e higieniza dados do Elementor
			if ( ! empty( $old_siteurl ) && ! empty( $target_siteurl ) ) {
				$this->perform_url_search_replace( $old_siteurl, $target_siteurl, $dump_prefix );
			} else {
				self::ensure_elementor_active_kit( $dump_prefix );
				self::rebuild_elementor_theme_builder_conditions( $dump_prefix );
				self::clear_elementor_cache( $dump_prefix );
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
			if ( ! $q_handle ) {
				return new WP_Error( 'restore_queue_create_failed', __( 'Não foi possível criar a fila de arquivos da restauração.', 'dd-maintenance' ) );
			}
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

					// NUNCA sobrescreve o wp-config.php, arquivos SQL soltos e o próprio plugin em execução
					if ( 'wp-config.php' === $filename_lower || 'database.sql' === $filename_lower || preg_match( '/\.sql$/i', $filename_lower ) ) {
						continue;
					}

					$relative = ltrim( substr( $item_path, strlen( $source_dir ) ), '/' );
					$rel_lower = strtolower( $relative );
					if ( 0 === strpos( $rel_lower, 'wp-content/plugins/dd-maintenance/' ) || 0 === strpos( $rel_lower, 'wp-content/plugins/backuper/' ) || 0 === strpos( $rel_lower, 'plugins/dd-maintenance/' ) || 0 === strpos( $rel_lower, 'plugins/backuper/' ) ) {
						continue;
					}

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
						if ( false === fwrite( $q_handle, wp_json_encode( array( 'src' => $item_path, 'dst' => $target ) ) . "\n" ) ) {
							fclose( $q_handle );
							return new WP_Error( 'restore_queue_write_failed', __( 'Não foi possível registrar todos os arquivos para restauração.', 'dd-maintenance' ) );
						}
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

		$total_files  = (int) ( $session['files_total'] ?? 0 );
		$copied       = (int) ( $session['files_copied'] ?? 0 );
		$queue_offset = (int) ( $session['files_queue_offset'] ?? 0 );

		$deadline    = microtime( true ) + 6.0;
		$batch_count = 0;
		$completed   = false;

		if ( file_exists( $queue_file ) ) {
			$q_handle = fopen( $queue_file, 'rb' );
			if ( ! $q_handle ) {
				return new WP_Error( 'restore_queue_read_failed', __( 'Não foi possível ler a fila de arquivos da restauração.', 'dd-maintenance' ) );
			}
			fseek( $q_handle, $queue_offset );

			while ( ! feof( $q_handle ) && ( microtime( true ) < $deadline || $batch_count < 300 ) ) {
				$line = fgets( $q_handle );
				if ( false === $line ) {
					$completed = true;
					break;
				}
				$task = json_decode( $line, true );
				if ( is_array( $task ) && ! empty( $task['src'] ) && ! empty( $task['dst'] ) ) {
					if ( ! is_file( $task['src'] ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_source_missing', sprintf( __( 'Arquivo de origem ausente durante a restauração: %s.', 'dd-maintenance' ), basename( $task['src'] ) ) );
					}

					$parent = dirname( $task['dst'] );
					if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_target_mkdir_failed', sprintf( __( 'Não foi possível criar a pasta de destino para %s.', 'dd-maintenance' ), basename( $task['dst'] ) ) );
					}
					if ( ! @copy( $task['src'], $task['dst'] ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_copy_failed', sprintf( __( 'Não foi possível restaurar o arquivo %s.', 'dd-maintenance' ), basename( $task['dst'] ) ) );
					}
					if ( preg_match( '#dynamic-tags/manager\.php$#i', $task['dst'] ) ) {
						self::patch_elementor_php8_compatibility();
					}
					$copied++;
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
		$percent = $total_files > 0 ? min( 100, max( 0, (int) round( ( $copied / $total_files ) * 100 ) ) ) : 100;

		if ( $completed || $copied >= $total_files ) {
			$session['files_done'] = true;
			$log_line              = sprintf( __( '[OK] Arquivos restaurados: %1$s arquivos copiados com sucesso.', 'dd-maintenance' ), number_format_i18n( $copied ) );
			$session['log'][]      = $log_line;
			self::patch_elementor_php8_compatibility();
			self::install_permanent_elementor_shield();
			self::clear_elementor_cache();
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
		self::patch_elementor_php8_compatibility();
		self::install_permanent_elementor_shield();
		self::ensure_elementor_active_kit();
		self::rebuild_elementor_theme_builder_conditions();
		self::clear_elementor_cache();


		$this->delete_directory( $extract_dir );
		if ( ! empty( $temp_upload_dir ) && is_dir( $temp_upload_dir ) ) {
			$this->delete_directory( $temp_upload_dir );
		}

		self::remove_mu_plugin_loader();

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
		self::remove_mu_plugin_loader();
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

		do {
			$db_res = $this->restore_database_step( $session_id );
			if ( is_wp_error( $db_res ) ) {
				$this->cleanup_failed_restore( $session_id );
				return $db_res;
			}
		} while ( empty( $db_res['completed'] ) );

		do {
			$files_res = $this->restore_files_step( $session_id );
			if ( is_wp_error( $files_res ) ) {
				$this->cleanup_failed_restore( $session_id );
				return $files_res;
			}
		} while ( empty( $files_res['completed'] ) );

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
			$result = $this->copy_directory( $extract_dir . '/site', ABSPATH, $backup_dirs );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$copied_files += $result;
		} else {
			// Estrutura 2: wp-content/ solto na raiz do backup.
			if ( is_dir( $extract_dir . '/wp-content' ) ) {
				$result = $this->copy_directory( $extract_dir . '/wp-content', WP_CONTENT_DIR, $backup_dirs );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$copied_files += $result;
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
	 * @return int|WP_Error Quantidade de arquivos copiados ou erro.
	 */
	private function copy_directory( string $source_dir, string $dest_dir, array $ignore_paths = array() ) {
		$copied = 0;
		$source_dir = wp_normalize_path( $source_dir );
		$dest_dir   = wp_normalize_path( $dest_dir );

		if ( ! is_dir( $source_dir ) ) {
			return 0;
		}
		if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'restore_target_mkdir_failed', __( 'Não foi possível criar a pasta de destino da restauração.', 'dd-maintenance' ) );
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
				if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
					return new WP_Error( 'restore_target_mkdir_failed', sprintf( __( 'Não foi possível criar a pasta de destino %s.', 'dd-maintenance' ), basename( $target ) ) );
				}
			} elseif ( $item->isFile() ) {
				$parent_dir = dirname( $target );
				if ( ! is_dir( $parent_dir ) && ! wp_mkdir_p( $parent_dir ) ) {
					return new WP_Error( 'restore_target_mkdir_failed', sprintf( __( 'Não foi possível criar a pasta de destino para %s.', 'dd-maintenance' ), basename( $target ) ) );
				}
				if ( ! @copy( $item_path, $target ) ) {
					return new WP_Error( 'restore_copy_failed', sprintf( __( 'Não foi possível restaurar o arquivo %s.', 'dd-maintenance' ), basename( $target ) ) );
				}
				$copied++;
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

		$sql = $backup_dir . '/' . $base_name . '.sql';
		if ( file_exists( $sql ) && is_file( $sql ) ) {
			if ( @unlink( $sql ) ) {
				$deleted = true;
			}
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
	/**
	 * Constrói um mapa completo de variações de URL (normal, escapada para JSON, urlencoded, esquemas)
	 * para garantir substituição 100% precisa em todos os tipos de dados do WordPress e Elementor.
	 *
	 * @param string $from_url URL de origem.
	 * @param string $to_url   URL de destino.
	 * @return array
	 */
	public static function build_url_replacement_map( string $from_url, string $to_url ): array {
		$from_url = rtrim( trim( $from_url ), '/' );
		$to_url   = rtrim( trim( $to_url ), '/' );
		if ( empty( $from_url ) || empty( $to_url ) || $from_url === $to_url ) {
			return array();
		}

		$from_no_proto = preg_replace( '#^https?:?//#i', '', $from_url );
		$to_no_proto   = preg_replace( '#^https?:?//#i', '', $to_url );

		$is_to_ssl = ( 0 === stripos( $to_url, 'https://' ) );

		$map = array();

		// 1. URLs escapadas para JSON (\/)
		$from_esc_https = 'https:\/\/' . str_replace( '/', '\/', $from_no_proto );
		$from_esc_http  = 'http:\/\/' . str_replace( '/', '\/', $from_no_proto );
		$from_esc_proto = '\/\/' . str_replace( '/', '\/', $from_no_proto );

		$to_esc_target = ( $is_to_ssl ? 'https:\/\/' : 'http:\/\/' ) . str_replace( '/', '\/', $to_no_proto );
		$to_esc_proto  = '\/\/' . str_replace( '/', '\/', $to_no_proto );

		$map[ $from_esc_https ] = $to_esc_target;
		$map[ $from_esc_http ]  = $to_esc_target;
		$map[ $from_esc_proto ] = $to_esc_proto;

		// 2. URLs codificadas (URL-encoded para tags dinâmicas e atributos de widgets)
		$map[ rawurlencode( 'https://' . $from_no_proto ) ] = rawurlencode( $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto );
		$map[ rawurlencode( 'http://' . $from_no_proto ) ]  = rawurlencode( $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto );
		$map[ urlencode( 'https://' . $from_no_proto ) ]    = urlencode( $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto );
		$map[ urlencode( 'http://' . $from_no_proto ) ]     = urlencode( $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto );
		$map[ rawurlencode( '//' . $from_no_proto ) ]       = rawurlencode( '//' . $to_no_proto );
		$map[ urlencode( '//' . $from_no_proto ) ]          = urlencode( '//' . $to_no_proto );

		// 3. URLs normais completas
		$map[ 'https://' . $from_no_proto ] = $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto;
		$map[ 'http://' . $from_no_proto ]  = $is_to_ssl ? 'https://' . $to_no_proto : 'http://' . $to_no_proto;
		$map[ '//' . $from_no_proto ]       = '//' . $to_no_proto;

		// Ordena por comprimento decrescente para priorizar padrões mais específicos
		uksort( $map, static function( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );

		return $map;
	}

	/**
	 * Normaliza tags dinâmicas do Elementor para garantir compatibilidade estrita com PHP 8.0+.
	 * Se o atributo settings="" estiver ausente, vazio ou inválido, insere settings="%7B%7D"
	 * para que o json_decode retorne array vazio em vez de null.
	 *
	 * @param mixed $content Texto ou dado a ser higienizado.
	 * @return mixed
	 */
	public static function fix_elementor_dynamic_tags( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[elementor-tag' ) ) {
			return $content;
		}

		$trimmed = trim( $content );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$json = json_decode( $trimmed, true );
			if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
				$unslashed = stripslashes( $trimmed );
				$json      = json_decode( $unslashed, true );
			}
			if ( null !== $json && JSON_ERROR_NONE === json_last_error() ) {
				array_walk_recursive( $json, static function( &$item ) {
					if ( is_string( $item ) && false !== strpos( $item, '[elementor-tag' ) ) {
						$item = self::fix_elementor_dynamic_tags_string( $item );
					}
				} );
				return wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		return self::fix_elementor_dynamic_tags_string( $content );
	}

	/**
	 * Higieniza uma string individual de tag dinamica preservando aspas escapadas caso faca parte de JSON cru.
	 *
	 * @param string $content String contendo tag do Elementor.
	 * @return string
	 */
	public static function fix_elementor_dynamic_tags_string( string $content ): string {
		return preg_replace_callback(
			'/\[elementor-tag\s+((?:\\\\"[^\\\\"]*\\\\"|[^\]])+)\]/i',
			static function( $matches ) {
				$attrs_str  = $matches[1];
				$is_escaped = false !== strpos( $attrs_str, '\"' );
				$q          = $is_escaped ? '\"' : '"';

				if ( preg_match( '/\bsettings\s*=\s*(?:\\\\"([^\\\\"]*)\\\\"|"([^"]*)")/i', $attrs_str, $sm ) ) {
					$val      = isset( $sm[2] ) && '' !== $sm[2] ? $sm[2] : ( $sm[1] ?? '' );
					$val_trim = trim( $val );
					if ( '' !== $val_trim && '%22%22' !== $val_trim && 'null' !== strtolower( $val_trim ) && '%7B%7D' !== $val_trim ) {
						return $matches[0];
					}
				}

				$cleaned = preg_replace( '/\bsettings\s*=\s*(?:\\\\"([^\\\\"]*)\\\\"|"[^"]*")/i', '', $attrs_str );
				$cleaned = trim( preg_replace( '/\s+/', ' ', $cleaned ) );

				return '[elementor-tag ' . $cleaned . ' settings=' . $q . '%7B%7D' . $q . ']';
			},
			$content
		);
	}

	/**
	 * Realiza Search & Replace recursivo seguro com suporte a strings serializadas PHP e JSON.
	 *
	 * @param mixed $from           Texto de busca ou mapa associativo [de => para].
	 * @param string $to            Texto de substituição (se $from for string).
	 * @param mixed  $data          Dado a ser processado.
	 * @param bool   $was_serialized Indica se o dado original era serializado.
	 * @return mixed
	 */
	public static function recursive_search_replace( $from, $to, $data, bool $was_serialized = false ) {
		if ( is_array( $from ) ) {
			$map = $from;
		} else {
			if ( empty( $from ) || $from === $to ) {
				return is_string( $data ) ? self::fix_elementor_dynamic_tags( $data ) : $data;
			}
			$map = self::build_url_replacement_map( (string) $from, (string) $to );
			if ( empty( $map ) ) {
				$map = array( (string) $from => (string) $to );
			}
		}

		try {
			if ( is_string( $data ) ) {
				$trimmed = trim( $data );
				if ( '' === $trimmed ) {
					return $data;
				}

				// 1. String PHP serializada
				if ( ( 0 === strpos( $trimmed, 'a:' ) || 0 === strpos( $trimmed, 's:' ) || 0 === strpos( $trimmed, 'O:' ) ) && false === strpos( $trimmed, 'O:8:"DateTime":0:{}' ) ) {
					$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) );
					if ( false === $unserialized && 'b:0;' !== $data ) {
						$unserialized = @unserialize( $data );
					}
					if ( false !== $unserialized || 'b:0;' === $data ) {
						$replaced = self::recursive_search_replace( $map, '', $unserialized, true );
						return serialize( $replaced );
					}
				}

				// 2. String JSON (Elementor _elementor_data, _elementor_page_settings, Gutenberg)
				if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
					$json = json_decode( $trimmed, true );
					if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
						$unslashed = stripslashes( $trimmed );
						$json      = json_decode( $unslashed, true );
					}
					if ( null !== $json && JSON_ERROR_NONE === json_last_error() ) {
						$replaced = self::recursive_search_replace( $map, '', $json, false );
						return wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					}
				}

				// 3. String simples (substituição via mapa de pares)
				$replaced = str_replace( array_keys( $map ), array_values( $map ), $data );
				return self::fix_elementor_dynamic_tags( $replaced );
			}

			if ( is_array( $data ) ) {
				$new_array = array();
				foreach ( $data as $key => $val ) {
					$new_key               = is_string( $key ) ? str_replace( array_keys( $map ), array_values( $map ), $key ) : $key;
					$new_array[ $new_key ] = self::recursive_search_replace( $map, '', $val, false );
				}
				return $new_array;
			}

			if ( is_object( $data ) ) {
				$new_obj = clone $data;
				foreach ( get_object_vars( $data ) as $prop => $val ) {
					$new_obj->$prop = self::recursive_search_replace( $map, '', $val, false );
				}
				return $new_obj;
			}
		} catch ( Throwable $e ) {
			return $data;
		}

		return $data;
	}

	/**
	 * Executa Search & Replace seguro e paginado para evitar estourar memória do PHP ou travar o servidor.
	 *
	 * @param string $from_url    URL de origem do backup.
	 * @param string $to_url      URL de destino atual.
	 * @param string $dump_prefix Prefixo das tabelas no banco.
	 * @return void
	 */
	private function perform_url_search_replace( string $from_url, string $to_url, string $dump_prefix ): void {
		global $wpdb;

		$from_url       = rtrim( trim( $from_url ), '/' );
		$to_url         = rtrim( trim( $to_url ), '/' );
		$replace_map    = self::build_url_replacement_map( $from_url, $to_url );
		$from_escaped   = str_replace( '/', '\/', $from_url );
		$from_no_proto  = preg_replace( '#^https?:?//#i', '', $from_url );
		$posts_table    = $dump_prefix . 'posts';
		$postmeta_table = $dump_prefix . 'postmeta';
		$options_table  = $dump_prefix . 'options';

		// 1. Posts e templates (Elementor Library, Header, Footer, páginas)
		$last_post_id = 0;
		while ( true ) {
			$post_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `ID`, `post_content`, `post_excerpt`, `guid` FROM `{$posts_table}` WHERE `ID` > %d ORDER BY `ID` ASC LIMIT 100",
					$last_post_id
				),
				ARRAY_A
			);
			if ( empty( $post_rows ) || ! is_array( $post_rows ) ) {
				break;
			}
			foreach ( $post_rows as $prow ) {
				$last_post_id = (int) $prow['ID'];
				$content      = $prow['post_content'];
				$excerpt      = $prow['post_excerpt'];
				$guid         = $prow['guid'];
				$changed      = false;

				if ( ! empty( $replace_map ) ) {
					if ( is_string( $content ) && ( false !== strpos( $content, $from_url ) || false !== strpos( $content, $from_escaped ) || false !== strpos( $content, $from_no_proto ) ) ) {
						$content = str_replace( array_keys( $replace_map ), array_values( $replace_map ), $content );
						$changed = true;
					}
					if ( is_string( $excerpt ) && ( false !== strpos( $excerpt, $from_url ) || false !== strpos( $excerpt, $from_no_proto ) ) ) {
						$excerpt = str_replace( array_keys( $replace_map ), array_values( $replace_map ), $excerpt );
						$changed = true;
					}
					if ( is_string( $guid ) && ( false !== strpos( $guid, $from_url ) || false !== strpos( $guid, $from_no_proto ) ) ) {
						$guid    = str_replace( array_keys( $replace_map ), array_values( $replace_map ), $guid );
						$changed = true;
					}
				}

				if ( is_string( $content ) && false !== strpos( $content, '[elementor-tag' ) ) {
					$fixed_content = self::fix_elementor_dynamic_tags( $content );
					if ( $fixed_content !== $content ) {
						$content = $fixed_content;
						$changed = true;
					}
				}

				if ( $changed ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE `{$posts_table}` SET `post_content` = %s, `post_excerpt` = %s, `guid` = %s WHERE `ID` = %d",
							$content,
							$excerpt,
							$guid,
							(int) $prow['ID']
						)
					);
				}
			}
			if ( count( $post_rows ) < 100 ) {
				break;
			}
		}

		// 2. Metadados de posts (Elementor, galerias, custom fields) - paginação por meta_id
		$last_meta_id = 0;
		while ( true ) {
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `meta_id`, `meta_key`, `meta_value` FROM `{$postmeta_table}` WHERE `meta_id` > %d AND (`meta_key` IN ('_elementor_data', '_elementor_page_settings', '_elementor_controls_usage', '_elementor_conditions') OR `meta_value` LIKE %s OR `meta_value` LIKE %s OR `meta_value` LIKE %s OR `meta_value` LIKE %s) ORDER BY `meta_id` ASC LIMIT 100",
					$last_meta_id,
					'%' . $wpdb->esc_like( $from_url ) . '%',
					'%' . $wpdb->esc_like( $from_escaped ) . '%',
					'%' . $wpdb->esc_like( $from_no_proto ) . '%',
					'%[elementor-tag%'
				),
				ARRAY_A
			);
			if ( empty( $meta_rows ) || ! is_array( $meta_rows ) ) {
				break;
			}
			foreach ( $meta_rows as $mrow ) {
				$last_meta_id = (int) $mrow['meta_id'];
				$orig_val     = $mrow['meta_value'];
				if ( ! is_string( $orig_val ) || '' === $orig_val ) {
					continue;
				}
				$fixed_val = $orig_val;
				if ( ! empty( $replace_map ) && ( false !== strpos( $orig_val, $from_url ) || false !== strpos( $orig_val, $from_escaped ) || false !== strpos( $orig_val, $from_no_proto ) ) ) {
					$fixed_val = self::recursive_search_replace( $replace_map, '', $orig_val );
				}
				$fixed_val = self::fix_elementor_dynamic_tags( $fixed_val );
				if ( $fixed_val !== $orig_val ) {
					$wpdb->query( $wpdb->prepare( "UPDATE `{$postmeta_table}` SET `meta_value` = %s WHERE `meta_id` = %d", $fixed_val, (int) $mrow['meta_id'] ) );
				}
			}
			if ( count( $meta_rows ) < 100 ) {
				break;
			}
		}

		// 3. Opções gerais do site (widgets, kits do Elementor, condições do Pro Elements)
		$last_opt_id = 0;
		while ( true ) {
			$opt_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `option_id`, `option_name`, `option_value` FROM `{$options_table}` WHERE `option_id` > %d AND `option_name` NOT IN ('siteurl', 'home') AND (`option_name` LIKE %s OR `option_value` LIKE %s OR `option_value` LIKE %s OR `option_value` LIKE %s OR `option_value` LIKE %s) ORDER BY `option_id` ASC LIMIT 100",
					$last_opt_id,
					'%elementor%',
					'%' . $wpdb->esc_like( $from_url ) . '%',
					'%' . $wpdb->esc_like( $from_escaped ) . '%',
					'%' . $wpdb->esc_like( $from_no_proto ) . '%',
					'%[elementor-tag%'
				),
				ARRAY_A
			);
			if ( empty( $opt_rows ) || ! is_array( $opt_rows ) ) {
				break;
			}
			foreach ( $opt_rows as $orow ) {
				$last_opt_id = (int) $orow['option_id'];
				$orig_val    = $orow['option_value'];
				if ( ! is_string( $orig_val ) || '' === $orig_val ) {
					continue;
				}
				$fixed_val = $orig_val;
				if ( ! empty( $replace_map ) && ( false !== strpos( $orig_val, $from_url ) || false !== strpos( $orig_val, $from_escaped ) || false !== strpos( $orig_val, $from_no_proto ) ) ) {
					$fixed_val = self::recursive_search_replace( $replace_map, '', $orig_val );
				}
				$fixed_val = self::fix_elementor_dynamic_tags( $fixed_val );
				if ( $fixed_val !== $orig_val ) {
					$wpdb->query( $wpdb->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_id` = %d", $fixed_val, (int) $orow['option_id'] ) );
				}
			}
			if ( count( $opt_rows ) < 100 ) {
				break;
			}
		}

		// 4. Termmeta e Usermeta (caso existam)
		$termmeta_table = $dump_prefix . 'termmeta';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $termmeta_table ) ) && ! empty( $replace_map ) ) {
			$last_tm_id = 0;
			while ( true ) {
				$tm_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT `meta_id`, `meta_value` FROM `{$termmeta_table}` WHERE `meta_id` > %d AND (`meta_value` LIKE %s OR `meta_value` LIKE %s) ORDER BY `meta_id` ASC LIMIT 100",
						$last_tm_id,
						'%' . $wpdb->esc_like( $from_url ) . '%',
						'%' . $wpdb->esc_like( $from_escaped ) . '%'
					),
					ARRAY_A
				);
				if ( empty( $tm_rows ) || ! is_array( $tm_rows ) ) {
					break;
				}
				foreach ( $tm_rows as $trow ) {
					$last_tm_id = (int) $trow['meta_id'];
					$orig_val   = $trow['meta_value'];
					if ( is_string( $orig_val ) && '' !== $orig_val ) {
						$fixed_val = self::recursive_search_replace( $replace_map, '', $orig_val );
						if ( $fixed_val !== $orig_val ) {
							$wpdb->query( $wpdb->prepare( "UPDATE `{$termmeta_table}` SET `meta_value` = %s WHERE `meta_id` = %d", $fixed_val, (int) $trow['meta_id'] ) );
						}
					}
				}
				if ( count( $tm_rows ) < 100 ) {
					break;
				}
			}
		}

		// 5. Garante integridade do Elementor Kit, Theme Builder e limpa caches
		self::ensure_elementor_active_kit( $dump_prefix );
		self::rebuild_elementor_theme_builder_conditions( $dump_prefix );
		self::clear_elementor_cache( $dump_prefix );
	}

	/**
	 * Verifica e repara o Elementor Kit Ativo (elementor_active_kit) no banco restaurado.
	 * Se o kit ativo estiver ausente, corrompido ou despublicado, restaura a referência correta
	 * para evitar que o Elementor reinicialize um kit em branco perdendo todas as fontes e cores.
	 *
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @return void
	 */
	public static function ensure_elementor_active_kit( string $dump_prefix = '' ): void {
		global $wpdb;

		if ( empty( $dump_prefix ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = $wpdb->prefix;
		}

		$options_table  = $dump_prefix . 'options';
		$posts_table    = $dump_prefix . 'posts';
		$postmeta_table = $dump_prefix . 'postmeta';

		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $options_table ) ) ) {
			return;
		}

		$kit_id    = (int) $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'elementor_active_kit' LIMIT 1" );
		$valid_kit = false;

		if ( $kit_id > 0 ) {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT `ID`, `post_status` FROM `{$posts_table}` WHERE `ID` = %d AND `post_type` = 'elementor_library' LIMIT 1", $kit_id ) );
			if ( $post ) {
				$valid_kit = true;
				if ( 'publish' !== $post->post_status ) {
					$wpdb->query( $wpdb->prepare( "UPDATE `{$posts_table}` SET `post_status` = 'publish' WHERE `ID` = %d", $kit_id ) );
				}
			}
		}

		if ( ! $valid_kit ) {
			$found_kit_id = (int) $wpdb->get_var(
				"SELECT p.`ID` FROM `{$posts_table}` p
				 INNER JOIN `{$postmeta_table}` pm ON p.`ID` = pm.`post_id`
				 WHERE p.`post_type` = 'elementor_library' AND pm.`meta_key` = '_elementor_template_type' AND pm.`meta_value` = 'kit'
				 ORDER BY p.`ID` DESC LIMIT 1"
			);

			if ( ! $found_kit_id ) {
				$found_kit_id = (int) $wpdb->get_var(
					"SELECT `ID` FROM `{$posts_table}`
					 WHERE `post_type` = 'elementor_library' AND (`post_title` LIKE '%Kit%' OR `post_name` LIKE '%kit%')
					 ORDER BY `ID` DESC LIMIT 1"
				);
			}

			if ( $found_kit_id > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$posts_table}` SET `post_status` = 'publish' WHERE `ID` = %d", $found_kit_id ) );
				$wpdb->query( $wpdb->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_active_kit', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", (string) $found_kit_id, (string) $found_kit_id ) );

				$has_type = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_id` FROM `{$postmeta_table}` WHERE `post_id` = %d AND `meta_key` = '_elementor_template_type' LIMIT 1", $found_kit_id ) );
				if ( ! $has_type ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO `{$postmeta_table}` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, '_elementor_template_type', 'kit')", $found_kit_id ) );
				}

				$has_mode = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_id` FROM `{$postmeta_table}` WHERE `post_id` = %d AND `meta_key` = '_elementor_edit_mode' LIMIT 1", $found_kit_id ) );
				if ( ! $has_mode ) {
					$wpdb->query( $wpdb->prepare( "INSERT INTO `{$postmeta_table}` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, '_elementor_edit_mode', 'builder')", $found_kit_id ) );
				}
			}
		}
	}

	/**
	 * Reconstrói as condições globais de Header, Footer e Templates do Theme Builder do Elementor Pro / Pro Elements
	 * caso o array serializado na tabela options tenha sido perdido na restauração.
	 *
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @return void
	 */
	public static function rebuild_elementor_theme_builder_conditions( string $dump_prefix = '' ): void {
		global $wpdb;

		if ( empty( $dump_prefix ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = $wpdb->prefix;
		}

		$options_table  = $dump_prefix . 'options';
		$postmeta_table = $dump_prefix . 'postmeta';

		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $options_table ) ) ) {
			return;
		}

		$current_opt = $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'elementor_pro_theme_builder_conditions' LIMIT 1" );
		$current_val = is_string( $current_opt ) ? @unserialize( $current_opt ) : null;

		if ( empty( $current_val ) || ! is_array( $current_val ) ) {
			$condition_rows = $wpdb->get_results(
				"SELECT pm.`post_id`, pm.`meta_value` as `conditions`, pt.`meta_value` as `template_type`
				 FROM `{$postmeta_table}` pm
				 LEFT JOIN `{$postmeta_table}` pt ON pm.`post_id` = pt.`post_id` AND pt.`meta_key` = '_elementor_template_type'
				 WHERE pm.`meta_key` = '_elementor_conditions'",
				ARRAY_A
			);

			if ( ! empty( $condition_rows ) && is_array( $condition_rows ) ) {
				$rebuilt = array();
				foreach ( $condition_rows as $crow ) {
					$cond = @unserialize( $crow['conditions'] );
					if ( is_array( $cond ) && ! empty( $cond ) ) {
						$type = ! empty( $crow['template_type'] ) ? $crow['template_type'] : 'single';
						if ( ! isset( $rebuilt[ $type ] ) ) {
							$rebuilt[ $type ] = array();
						}
						$rebuilt[ $type ][ (int) $crow['post_id'] ] = $cond;
					}
				}
				if ( ! empty( $rebuilt ) ) {
					$serialized = serialize( $rebuilt );
					$wpdb->query( $wpdb->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_pro_theme_builder_conditions', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $serialized, $serialized ) );
				}
			}
		}
	}

	/**
	 * Limpa todos os caches compilados de CSS e metadados do Elementor para forçar regeneração limpa com novas URLs.
	 *
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @return void
	 */
	public static function clear_elementor_cache( string $dump_prefix = '' ): void {
		global $wpdb;

		if ( empty( $dump_prefix ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = $wpdb->prefix;
		}

		if ( ! empty( $dump_prefix ) && isset( $wpdb ) && is_object( $wpdb ) ) {
			$options_table  = $dump_prefix . 'options';
			$postmeta_table = $dump_prefix . 'postmeta';

			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $postmeta_table ) ) ) {
				// 1. Limpa metadados de CSS compilado e cache de elementos nos posts
				$wpdb->query( "DELETE FROM `{$postmeta_table}` WHERE `meta_key` IN ('_elementor_css', '_elementor_element_cache', '_elementor_inline_svg', '_elementor_page_assets')" );
			}

			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $options_table ) ) ) {
				// 2. Limpa transients e opções de cache do Elementor
				$wpdb->query( "DELETE FROM `{$options_table}` WHERE `option_name` LIKE '%_elementor_%' AND (`option_name` LIKE '%_transient_%' OR `option_name` LIKE '%_cache%')" );
				$wpdb->query( "DELETE FROM `{$options_table}` WHERE `option_name` IN ('_elementor_global_css', '_elementor_assets_data', 'elementor_remote_info_library')" );

				// 3. Força atualização do timestamp global de CSS do Elementor
				$now = (string) time();
				$wpdb->query( $wpdb->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_global_css_time', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $now, $now ) );
				$wpdb->query( $wpdb->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_css_version', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $now, $now ) );
			}
		}

		// 4. Exclui todos os arquivos .css em wp-content/uploads/elementor/css/
		$upload_dir_base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : ( defined( 'ABSPATH' ) ? ABSPATH . 'wp-content/uploads' : '' );
		if ( ! empty( $upload_dir_base ) ) {
			$css_dir = $upload_dir_base . '/elementor/css';
			if ( is_dir( $css_dir ) ) {
				$files = glob( $css_dir . '/*.css' );
				if ( is_array( $files ) ) {
					foreach ( $files as $f ) {
						if ( is_file( $f ) ) {
							@unlink( $f );
						}
					}
				}
			}
		}

		// 5. Se o Elementor estiver ativo na memória do PHP, invoca o Files_Manager do próprio plugin
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Throwable $e ) {
				// Suprime silenciosamente caso o Elementor não esteja pronto
			}
		}
	}

	/**
	 * Cria um drop-in temporário em wp-content/mu-plugins/ para garantir que o DD Maintenance
	 * permaneça carregado pelo WordPress mesmo enquanto o banco de dados está sendo reconstruído.
	 */
	public static function create_mu_plugin_loader(): void {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! is_dir( $mu_dir ) ) {
			@wp_mkdir_p( $mu_dir );
		}
		if ( is_dir( $mu_dir ) ) {
			$loader_code = "<?php\n"
				. "// DD Maintenance restore persistence and database recovery drop-in\n"
				. "defined( 'ABSPATH' ) || exit;\n\n"
				. "if ( ( isset( \$_POST['action'] ) && 'dd_maintenance_ajax_restore' === \$_POST['action'] )"
				. " || ( isset( \$_GET['action'] ) && 'dd_maintenance_ajax_restore' === \$_GET['action'] ) ) {\n"
				. "    add_filter( 'pre_option_siteurl', static function( \$val ) {\n"
				. "        return ! empty( \$val ) ? \$val : ( ( is_ssl() ? 'https://' : 'http://' ) . ( \$_SERVER['HTTP_HOST'] ?? 'localhost' ) );\n"
				. "    }, 1 );\n"
				. "    add_filter( 'pre_option_home', static function( \$val ) {\n"
				. "        return ! empty( \$val ) ? \$val : ( ( is_ssl() ? 'https://' : 'http://' ) . ( \$_SERVER['HTTP_HOST'] ?? 'localhost' ) );\n"
				. "    }, 1 );\n"
				. "    add_filter( 'wp_die_handler', static function() {\n"
				. "        return static function( \$message, \$title = '', \$args = array() ) {\n"
				. "            if ( is_string( \$message ) && false !== strpos( \$message, 'database tables are unavailable' ) ) {\n"
				. "                return;\n"
				. "            }\n"
				. "            if ( function_exists( '_default_wp_die_handler' ) ) {\n"
				. "                _default_wp_die_handler( \$message, \$title, \$args );\n"
				. "            }\n"
				. "        };\n"
				. "    }, 1 );\n"
				. "}\n\n"
				. "if ( ! class_exists( 'DD_Maintenance' ) ) {\n"
				. "    \$candidates = array(\n"
				. "        __DIR__ . '/../plugins/dd-maintenance/dd-maintenance.php',\n"
				. "        __DIR__ . '/../plugins/backuper/backuper.php',\n"
				. "        defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/dd-maintenance/dd-maintenance.php' : '',\n"
				. "        defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/backuper/backuper.php' : '',\n"
				. "    );\n"
				. "    foreach ( \$candidates as \$file ) {\n"
				. "        if ( ! empty( \$file ) && file_exists( \$file ) ) {\n"
				. "            require_once \$file;\n"
				. "            break;\n"
				. "        }\n"
				. "    }\n"
				. "}\n"
				. "if ( class_exists( 'DD_Maintenance' ) ) {\n"
				. "    DD_Maintenance::instance();\n"
				. "}\n";
			@file_put_contents( $mu_dir . '/dd-maintenance-loader.php', $loader_code );
		}
	}

	/**
	 * Instala um drop-in permanente em mu-plugins para blindar o Elementor no site restaurado contra erros de PHP 8.0+.
	 */
	public static function install_permanent_elementor_shield(): void {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! is_dir( $mu_dir ) ) {
			@wp_mkdir_p( $mu_dir );
		}
		if ( is_dir( $mu_dir ) ) {
			$shield_code = "<?php\n"
				. "/**\n"
				. " * Plugin Name: DD Maintenance - Elementor & Pro Elements PHP 8.2 Compatibility Shield\n"
				. " * Description: Previne Fatal TypeError no Elementor / Pro Elements em PHP 8.0+ normalizando tags dinamicas sem settings.\n"
				. " */\n\n"
				. "defined( 'ABSPATH' ) || exit;\n\n"
				. "// 1. Auto-patch em disco para compatibilidade com PHP 8.0+\n"
				. "\$p_dir = defined( 'WP_PLUGIN_DIR' ) ? str_replace( '\\\\', '/', (string) WP_PLUGIN_DIR ) : '';\n"
				. "\$c_dir = defined( 'WP_CONTENT_DIR' ) ? str_replace( '\\\\', '/', (string) WP_CONTENT_DIR ) : '';\n"
				. "\$a_dir = defined( 'ABSPATH' ) ? str_replace( '\\\\', '/', (string) ABSPATH ) : '';\n"
				. "\$el_candidates = array();\n"
				. "if ( ! empty( \$p_dir ) ) {\n"
				. "    \$el_candidates[] = \$p_dir . '/elementor/core/dynamic-tags/manager.php';\n"
				. "    \$el_candidates[] = \$p_dir . '/pro-elements/core/dynamic-tags/manager.php';\n"
				. "    if ( is_dir( \$p_dir ) ) {\n"
				. "        \$g1 = glob( \$p_dir . '/*elementor*/core/dynamic-tags/manager.php' );\n"
				. "        if ( is_array( \$g1 ) ) \$el_candidates = array_merge( \$el_candidates, \$g1 );\n"
				. "        \$g2 = glob( \$p_dir . '/*pro-elements*/core/dynamic-tags/manager.php' );\n"
				. "        if ( is_array( \$g2 ) ) \$el_candidates = array_merge( \$el_candidates, \$g2 );\n"
				. "    }\n"
				. "}\n"
				. "if ( ! empty( \$c_dir ) ) {\n"
				. "    \$el_candidates[] = \$c_dir . '/plugins/elementor/core/dynamic-tags/manager.php';\n"
				. "    \$el_candidates[] = \$c_dir . '/plugins/pro-elements/core/dynamic-tags/manager.php';\n"
				. "}\n"
				. "if ( ! empty( \$a_dir ) ) {\n"
				. "    \$el_candidates[] = \$a_dir . 'wp-content/plugins/elementor/core/dynamic-tags/manager.php';\n"
				. "    \$el_candidates[] = \$a_dir . 'wp-content/plugins/pro-elements/core/dynamic-tags/manager.php';\n"
				. "}\n"
				. "foreach ( \$el_candidates as \$el_f ) {\n"
				. "    \$el_f = str_replace( '\\\\', '/', (string) \$el_f );\n"
				. "    if ( ! empty( \$el_f ) && file_exists( \$el_f ) && is_file( \$el_f ) ) {\n"
				. "        \$el_src = (string) @file_get_contents( \$el_f );\n"
				. "        if ( ! empty( \$el_src ) ) {\n"
				. "            \$el_patched = preg_replace(\n"
				. "                '/function\\s+([a-zA-Z0-9_]+)\\s*\\(\\s*([^)]*?\\b)array\\s*(\\\$settings\\b)/i',\n"
				. "                'function \$1( \$2\$3',\n"
				. "                \$el_src\n"
				. "            );\n"
				. "            if ( false === strpos( \$el_patched, '\$settings = is_array( \$settings ) ? \$settings : [];' ) ) {\n"
				. "                \$el_patched = preg_replace(\n"
				. "                    '/(public\\s+function\\s+create_tag\\s*\\([^)]*\\)\\s*\\{)/i',\n"
				. "                    \"\$1\\n\\t\\t\" . '\$settings = is_array( \$settings ) ? \$settings : [];',\n"
				. "                    \$el_patched,\n"
				. "                    1\n"
				. "                );\n"
				. "                \$el_patched = preg_replace(\n"
				. "                    '/(public\\s+function\\s+get_tag_data_content\\s*\\([^)]*\\)\\s*\\{)/i',\n"
				. "                    \"\$1\\n\\t\\t\" . '\$settings = is_array( \$settings ) ? \$settings : [];',\n"
				. "                    \$el_patched,\n"
				. "                    1\n"
				. "                );\n"
				. "            }\n"
				. "            if ( is_string( \$el_patched ) && \$el_patched !== \$el_src ) {\n"
				. "                @file_put_contents( \$el_f, \$el_patched );\n"
				. "            }\n"
				. "        }\n"
				. "    }\n"
				. "}\n\n"
				. "if ( ! function_exists( 'dd_fix_elementor_dynamic_tags_shield' ) ) {\n"
				. "    function dd_fix_elementor_dynamic_tags_shield( \$content ) {\n"
				. "        if ( ! is_string( \$content ) || false === strpos( \$content, '[elementor-tag' ) ) {\n"
				. "            return \$content;\n"
				. "        }\n"
				. "        if ( 0 === strpos( \$content, '{' ) || 0 === strpos( \$content, '[' ) ) {\n"
				. "            \$json = json_decode( \$content, true );\n"
				. "            if ( null !== \$json && JSON_ERROR_NONE === json_last_error() ) {\n"
				. "                array_walk_recursive( \$json, static function( &\$item ) {\n"
				. "                    if ( is_string( \$item ) && false !== strpos( \$item, '[elementor-tag' ) ) {\n"
				. "                        \$item = dd_fix_elementor_dynamic_tags_string_shield( \$item );\n"
				. "                    }\n"
				. "                } );\n"
				. "                return json_encode( \$json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );\n"
				. "            }\n"
				. "        }\n"
				. "        return dd_fix_elementor_dynamic_tags_string_shield( \$content );\n"
				. "    }\n\n"
				. "    function dd_fix_elementor_dynamic_tags_string_shield( \$content ) {\n"
				. "        if ( ! is_string( \$content ) || false === strpos( \$content, '[elementor-tag' ) ) {\n"
				. "            return \$content;\n"
				. "        }\n"
				. "        return preg_replace_callback(\n"
				. "            '/\\[elementor-tag\\s+((?:\\\\\"[^\\\\\"]*\\\\\"|[^\\]])+)\\]/i',\n"
				. "            static function( \$matches ) {\n"
				. "                \$attrs_str  = \$matches[1];\n"
				. "                \$is_escaped = false !== strpos( \$attrs_str, '\\\"' );\n"
				. "                \$q          = \$is_escaped ? '\\\"' : '\"';\n"
				. "                if ( preg_match( '/\\\\bsettings\\\\s*=\\\\s*(?:\\\\\"([^\\\\\"]*)\\\\\\\"|\"([^\"]*)\")/i', \$attrs_str, \$sm ) ) {\n"
				. "                    \$val = isset( \$sm[2] ) && '' !== \$sm[2] ? \$sm[2] : ( \$sm[1] ?? '' );\n"
				. "                    if ( '' !== trim( \$val ) && '%22%22' !== \$val && 'null' !== strtolower( trim( \$val ) ) ) {\n"
				. "                        return \$matches[0];\n"
				. "                    }\n"
				. "                }\n"
				. "                \$cleaned = preg_replace( '/\\\\bsettings\\\\s*=\\\\s*(?:\\\\\"([^\\\\\"]*)\\\\\\\"|\"[^\"]*\")/i', '', \$attrs_str );\n"
				. "                \$cleaned = trim( preg_replace( '/\\\\s+/', ' ', \$cleaned ) );\n"
				. "                return '[elementor-tag ' . \$cleaned . ' settings=' . \$q . '%7B%7D' . \$q . ']';\n"
				. "            },\n"
				. "            \$content\n"
				. "        );\n"
				. "    }\n\n"
				. "    add_filter( 'the_content', 'dd_fix_elementor_dynamic_tags_shield', 1 );\n"
				. "    add_filter( 'widget_text', 'dd_fix_elementor_dynamic_tags_shield', 1 );\n"
				. "    add_filter( 'elementor/dynamic_tags/parse_tag_text', 'dd_fix_elementor_dynamic_tags_shield', 999 );\n"
				. "    add_filter( 'get_post_metadata', static function( \$value, \$object_id, \$meta_key, \$single ) {\n"
				. "        if ( ! in_array( \$meta_key, array( '_elementor_data', '_elementor_page_settings', '_elementor_controls_usage' ), true ) ) {\n"
				. "            return \$value;\n"
				. "        }\n"
				. "        static \$in_filter = false;\n"
				. "        if ( \$in_filter ) {\n"
				. "            return \$value;\n"
				. "        }\n"
				. "        \$in_filter = true;\n"
				. "        \$meta      = get_post_meta( \$object_id, \$meta_key, true );\n"
				. "        \$in_filter = false;\n"
				. "        if ( is_string( \$meta ) && false !== strpos( \$meta, '[elementor-tag' ) ) {\n"
				. "            \$fixed = dd_fix_elementor_dynamic_tags_shield( \$meta );\n"
				. "            return \$single ? \$fixed : array( \$fixed );\n"
				. "        }\n"
				. "        return \$value;\n"
				. "    }, 10, 4 );\n"
				. "}\n";
			@file_put_contents( $mu_dir . '/dd-elementor-compat.php', $shield_code );
		}
	}

	/**
	 * Aplica correcao direta no arquivo do Elementor caso detecte a assinatura incompativel com PHP 8.2.
	 */
	public static function patch_elementor_php8_compatibility(): bool {
		$plugin_dir  = defined( 'WP_PLUGIN_DIR' ) ? str_replace( '\\', '/', (string) WP_PLUGIN_DIR ) : '';
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? str_replace( '\\', '/', (string) WP_CONTENT_DIR ) : '';
		$abs_path    = defined( 'ABSPATH' ) ? str_replace( '\\', '/', (string) ABSPATH ) : '';

		$candidates = array();

		if ( ! empty( $plugin_dir ) ) {
			$candidates[] = $plugin_dir . '/elementor/core/dynamic-tags/manager.php';
			$candidates[] = $plugin_dir . '/pro-elements/core/dynamic-tags/manager.php';
			if ( is_dir( $plugin_dir ) ) {
				$found = glob( $plugin_dir . '/*elementor*/core/dynamic-tags/manager.php' );
				if ( is_array( $found ) ) {
					$candidates = array_merge( $candidates, $found );
				}
				$found_pro = glob( $plugin_dir . '/*pro-elements*/core/dynamic-tags/manager.php' );
				if ( is_array( $found_pro ) ) {
					$candidates = array_merge( $candidates, $found_pro );
				}
			}
		}

		if ( ! empty( $content_dir ) ) {
			$candidates[] = $content_dir . '/plugins/elementor/core/dynamic-tags/manager.php';
			$candidates[] = $content_dir . '/plugins/pro-elements/core/dynamic-tags/manager.php';
			if ( is_dir( $content_dir . '/plugins' ) ) {
				$found = glob( $content_dir . '/plugins/*elementor*/core/dynamic-tags/manager.php' );
				if ( is_array( $found ) ) {
					$candidates = array_merge( $candidates, $found );
				}
			}
		}

		if ( ! empty( $abs_path ) ) {
			$candidates[] = $abs_path . 'wp-content/plugins/elementor/core/dynamic-tags/manager.php';
			$candidates[] = $abs_path . 'wp-content/plugins/pro-elements/core/dynamic-tags/manager.php';
		}

		$candidates[] = dirname( dirname( __DIR__ ) ) . '/elementor/core/dynamic-tags/manager.php';
		$candidates[] = dirname( dirname( dirname( __DIR__ ) ) ) . '/plugins/elementor/core/dynamic-tags/manager.php';
		$candidates[] = __DIR__ . '/../../elementor/core/dynamic-tags/manager.php';
		$candidates[] = __DIR__ . '/../../../plugins/elementor/core/dynamic-tags/manager.php';

		$patched_any = false;
		$seen        = array();

		foreach ( $candidates as $file ) {
			if ( empty( $file ) ) {
				continue;
			}
			$norm_file = str_replace( '\\', '/', (string) $file );
			if ( isset( $seen[ $norm_file ] ) ) {
				continue;
			}
			$seen[ $norm_file ] = true;

			if ( file_exists( $norm_file ) && is_file( $norm_file ) ) {
				$content = (string) @file_get_contents( $norm_file );
				if ( empty( $content ) ) {
					continue;
				}

				$patched = preg_replace(
					'/function\s+([a-zA-Z0-9_]+)\s*\(\s*(\$tag_id\s*,\s*\$tag_name\s*,)\s*array\s*(\$settings\b)/i',
					'function $1( $2 $3',
					$content
				);

				if ( false === strpos( $patched, '$settings = is_array( $settings ) ? $settings : [];' ) ) {
					$patched = preg_replace(
						'/(public\s+function\s+create_tag\s*\([^)]*\)\s*\{)/i',
						"$1\n\t\t" . '$settings = is_array( $settings ) ? $settings : [];',
						$patched,
						1
					);
					$patched = preg_replace(
						'/(public\s+function\s+get_tag_data_content\s*\([^)]*\)\s*\{)/i',
						"$1\n\t\t" . '$settings = is_array( $settings ) ? $settings : [];',
						$patched,
						1
					);
				}

				if ( is_string( $patched ) && $patched !== $content ) {
					if ( @file_put_contents( $norm_file, $patched ) ) {
						$patched_any = true;
					}
				}
			}
		}

		return $patched_any;
	}

	/**
	 * Remove o drop-in temporário do mu-plugins.
	 */
	public static function remove_mu_plugin_loader(): void {
		$mu_dir      = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$loader_file = $mu_dir . '/dd-maintenance-loader.php';
		if ( file_exists( $loader_file ) ) {
			@unlink( $loader_file );
		}
	}
}
