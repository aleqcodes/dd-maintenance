<?php
/**
 * Responsável pela restauração de backups (banco de dados + arquivos), suportando arquivos únicos e divididos em volumes configuráveis.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-dd-maintenance-session-store.php';
require_once __DIR__ . '/class-dd-maintenance-settings-repository.php';
require_once __DIR__ . '/class-dd-maintenance-file-security.php';
require_once __DIR__ . '/class-dd-maintenance-elementor-compatibility.php';
require_once __DIR__ . '/class-dd-maintenance-restore-database-adapter.php';
require_once __DIR__ . '/class-dd-maintenance-restore-archive-service.php';
require_once __DIR__ . '/class-dd-maintenance-restore-sql-parser.php';
require_once __DIR__ . '/class-dd-maintenance-restore-query-executor.php';
require_once __DIR__ . '/class-dd-maintenance-restore-database-finalizer.php';
require_once __DIR__ . '/class-dd-maintenance-restore-url-migrator.php';
require_once __DIR__ . '/class-dd-maintenance-restore-mu-loader.php';

 

class DD_Maintenance_Restore_Implementation {
	/**
	 * Armazenamento centralizado do estado das sessões.
	 *
	 * @var DD_Maintenance_Session_Store
	 */
	private $session_store;

	/**
	 * Construtor.
	 */
	public function __construct() {
		$this->session_store = new DD_Maintenance_Session_Store();
	}

	/**
	 * Migra URLs apenas depois que a integridade mínima do banco foi confirmada.
	 *
	 * @return string[]
	 */
	public function migrate_urls( string $from_url, string $to_url, string $dump_prefix, DD_Maintenance_Restore_Database_Adapter $database ): array {
		return $this->perform_url_search_replace( $from_url, $to_url, $dump_prefix, $database );
	}

	/**
	 * Restaura o site a partir de arquivo(s) .zip enviados via upload (suporta arquivo único ou múltiplas partes).
	 *
	 * @param array $file_input Array de upload ($_FILES['backup_zip']).
	 * @return array|WP_Error
	 */
	public function restore_from_upload( array $file_input, bool $apply_elementor_compatibility = false ) {
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

			$result = $this->restore_archive( $single_file, $apply_elementor_compatibility );
			$this->delete_directory( $temp_dir );
			return $result;
		}
		return $this->restore_from_temp_directory( $temp_dir, $apply_elementor_compatibility );
	}

	/**
	 * Restaura o site a partir de uma pasta temporária onde arquivos de backup foram salvos/enviados.
	 *
	 * @param string $temp_dir Caminho completo da pasta temporária.
	 * @return array|WP_Error
	 */
	public function restore_from_temp_directory( string $temp_dir, bool $apply_elementor_compatibility = false ) {
		$this->set_time_and_memory_limits();

		if ( is_link( $temp_dir ) ) {
			return new WP_Error( 'restore_temp_dir_unsafe', __( 'Pasta temporária simbólica não permitida.', 'dd-maintenance' ) );
		}
		$temp_real = realpath( $temp_dir );
		if ( false === $temp_real || ! is_dir( $temp_real ) ) {
			return new WP_Error( 'restore_temp_dir_missing', __( 'Pasta temporária de restauração não encontrada.', 'dd-maintenance' ) );
		}
		$temp_dir = wp_normalize_path( $temp_real );

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

			$result = $this->restore_archive( $single_file, $apply_elementor_compatibility );
			$this->delete_directory( $temp_dir );
			return $result;
		}

		$result = $this->restore_part_files( $files, $temp_dir, $apply_elementor_compatibility );
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
	public function restore_from_local_file( string $identifier, bool $apply_elementor_compatibility = false ) {
		$this->set_time_and_memory_limits();

		$identifier = sanitize_file_name( $identifier );
		$backup_dir = DD_Maintenance::backup_dir();

		// Caso 1: Arquivo direto existe (ex: site-2026-08-20.zip).
		$direct_path = $backup_dir . '/' . $identifier;
		if ( ! is_link( $direct_path ) && is_file( $direct_path ) && ! preg_match( '/\.part\d+\.zip$/i', $identifier ) ) {
			return $this->restore_archive( $direct_path, $apply_elementor_compatibility );
		}

		// Caso 2: Backup dividido em partes (procura todas as partes do mesmo backup base).
		$base_name = preg_replace( '/\.part\d+\.zip$/i', '', $identifier );
		$base_name = preg_replace( '/\.zip$/i', '', $base_name );

		$part_files = glob( $backup_dir . '/' . $base_name . '.part*.zip' );

		if ( empty( $part_files ) ) {
			// Tenta arquivo zip simples com a base
			if ( ! is_link( $backup_dir . '/' . $base_name . '.zip' ) && is_file( $backup_dir . '/' . $base_name . '.zip' ) ) {
				return $this->restore_archive( $backup_dir . '/' . $base_name . '.zip', $apply_elementor_compatibility );
			}

			return new WP_Error( 'restore_local_not_found', __( 'Arquivo(s) de backup local não encontrado(s).', 'dd-maintenance' ) );
		}

		$temp_dir = $backup_dir . '/local-restore-' . time() . '-' . wp_generate_password( 8, false );
		if ( is_link( $temp_dir ) || ! wp_mkdir_p( $temp_dir ) || is_link( $temp_dir ) ) {
			return new WP_Error( 'restore_temp_failed', __( 'Não foi possível criar pasta temporária para processar os lotes.', 'dd-maintenance' ) );
		}

		$result = $this->restore_part_files( $part_files, $temp_dir, $apply_elementor_compatibility );
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
	private function restore_part_files( array $part_files, string $temp_dir, bool $apply_elementor_compatibility = false ) {
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
			return $this->restore_archive_set( $part_files, $apply_elementor_compatibility );
		}

		$merged = $temp_dir . '/merged_legacy_' . time() . '.zip';
		$joined = $this->join_part_files( $part_files, $merged );
		if ( is_wp_error( $joined ) ) {
			return $joined;
		}
		return $this->restore_archive( $merged, $apply_elementor_compatibility );
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
			if ( is_link( $file ) || ! is_file( $file ) ) {
				return new WP_Error( 'join_source_unsupported', __( 'Parte de restauração inválida ou simbólica.', 'dd-maintenance' ) );
			}
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
		$parent      = dirname( $output_file );
		$parent_real = realpath( $parent );
		if ( is_link( $output_file ) || is_link( $parent ) || false === $parent_real || wp_normalize_path( $parent_real ) !== wp_normalize_path( $parent ) ) {
			return new WP_Error( 'join_output_unsafe', __( 'Destino temporário de união inseguro.', 'dd-maintenance' ) );
		}
		$out_handle = fopen( $output_file, 'wb' );
		if ( ! $out_handle ) {
			return new WP_Error( 'join_open_output_failed', __( 'Não foi possível criar o arquivo temporário de união das partes.', 'dd-maintenance' ) );
		}

		$buffer_size   = 1048576; // 1 MB buffer
		$expected_size = 0;
		foreach ( $part_files as $file ) {
			$part_size = filesize( $file );
			if ( false === $part_size ) {
				fclose( $out_handle );
				unlink( $output_file );
				return new WP_Error( 'join_input_stat_failed', sprintf( __( 'Não foi possível determinar o tamanho da parte %s.', 'dd-maintenance' ), basename( $file ) ) );
			}
			$expected_size += (int) $part_size;
		}

		foreach ( $part_files as $file ) {
			$in_handle = fopen( $file, 'rb' );
			if ( ! $in_handle ) {
				fclose( $out_handle );
				unlink( $output_file );
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
				if ( false === $chunk ) {
					fclose( $in_handle );
					fclose( $out_handle );
					unlink( $output_file );
					return new WP_Error( 'join_read_input_failed', sprintf( __( 'Falha ao ler a parte %s.', 'dd-maintenance' ), basename( $file ) ) );
				}
				if ( '' === $chunk ) {
					break;
				}
				$written = fwrite( $out_handle, $chunk );
				if ( false === $written || strlen( $chunk ) !== $written ) {
					fclose( $in_handle );
					fclose( $out_handle );
					unlink( $output_file );
					return new WP_Error( 'join_write_output_failed', __( 'Falha ao escrever o arquivo unido das partes.', 'dd-maintenance' ) );
				}
			}

			fclose( $in_handle );
		}

		fclose( $out_handle );

		clearstatcache( true, $output_file );
		if ( ! file_exists( $output_file ) || filesize( $output_file ) !== $expected_size ) {
			if ( file_exists( $output_file ) ) {
				unlink( $output_file );
			}
			return new WP_Error( 'join_file_incomplete', __( 'O arquivo reconstruído a partir das partes está incompleto.', 'dd-maintenance' ) );
		}

		return true;
	}

	/**
	 * Executa o fluxo de descompactação e restauração de banco e arquivos.
	 *
	 * @param string $zip_path Caminho absoluto do arquivo .zip.
	 * @return array|WP_Error
	 */
	public function restore_archive( string $zip_path, bool $apply_elementor_compatibility = false ) {
		return $this->restore_archive_set( array( $zip_path ), $apply_elementor_compatibility );
	}

	/**
	 * Inicia uma sessão de restauração em etapas gravando o estado em disco.
	 *
	 * @param array  $zip_paths       Caminhos dos volumes .zip.
	 * @param string $temp_upload_dir Pasta temporária de upload (se houver).
	 * @param bool   $apply_elementor_compatibility Aplicar compatibilidade Elementor.
	 * @param string $correlation_id  Correlação operacional da sessão.
	 * @return array|WP_Error
	 */
	public function init_restore_session( array $zip_paths, string $temp_upload_dir = '', bool $apply_elementor_compatibility = false, string $correlation_id = '' ) {
		$this->set_time_and_memory_limits();
		if ( '' === $correlation_id ) {
			$start_event    = DD_Maintenance::record_event( 'restore', 'operation_started', array( 'step' => 'restore_init', 'status' => 'running' ) );
			$correlation_id = $start_event['correlation_id'];
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'restore_zip_missing', __( 'A extensão PHP ZipArchive não está disponível no servidor.', 'dd-maintenance' ) );
		}

		$zip_paths = $this->sort_part_files( $zip_paths );
		if ( is_wp_error( $zip_paths ) ) {
			return $zip_paths;
		}
		$zip_sizes    = array();
		$zip_checksums = array();
		foreach ( $zip_paths as $zip_path ) {
			if ( is_link( $zip_path ) || ! is_file( $zip_path ) ) {
				return new WP_Error( 'restore_zip_invalid', __( 'Volume de restauração inválido ou simbólico.', 'dd-maintenance' ) );
			}
			$zip_sizes[] = (int) filesize( $zip_path );
			$zip_checksums[] = (string) hash_file( 'sha256', $zip_path );
		}
		if ( '' !== $temp_upload_dir && ( is_link( $temp_upload_dir ) || ! is_dir( $temp_upload_dir ) ) ) {
			return new WP_Error( 'restore_upload_dir_invalid', __( 'Diretório temporário de upload inválido.', 'dd-maintenance' ) );
		}

		$backup_dir  = DD_Maintenance::backup_dir();
		$backup_real = realpath( $backup_dir );
		if ( is_link( $backup_dir ) || false === $backup_real || wp_normalize_path( $backup_real ) !== wp_normalize_path( $backup_dir ) ) {
			return new WP_Error( 'restore_path_unsafe', __( 'A pasta de restauração não é segura.', 'dd-maintenance' ) );
		}
		if ( '' !== $temp_upload_dir ) {
			$temp_real = realpath( $temp_upload_dir );
			if ( is_link( $temp_upload_dir ) || false === $temp_real || ! is_dir( $temp_real ) || 0 !== strpos( wp_normalize_path( $temp_real ), rtrim( wp_normalize_path( $backup_real ), '/' ) . '/' ) ) {
				return new WP_Error( 'restore_upload_dir_invalid', __( 'Diretório temporário de upload fora da raiz autorizada.', 'dd-maintenance' ) );
			}
			$temp_upload_dir = wp_normalize_path( $temp_real );
		}
		$session_id    = 'rst_' . time() . '_' . wp_generate_password( 8, false );
		$restore_token = wp_generate_password( 48, false, false );
		$extract_dir   = $backup_dir . '/restore_exec_' . $session_id;

		if ( is_link( $extract_dir ) || ! wp_mkdir_p( $extract_dir ) || is_link( $extract_dir ) || false === realpath( $extract_dir ) ) {
			return new WP_Error( 'restore_mkdir_failed', __( 'Não foi possível criar a pasta temporária de extração.', 'dd-maintenance' ) );
		}

		$current_siteurl = self::trusted_site_url( get_option( 'siteurl', '' ) );
		$current_home    = self::trusted_site_url( get_option( 'home', '' ) );

		$session = array(
			'session_id'        => $session_id,
			'extract_dir'       => $extract_dir,
			'temp_upload_dir'   => $temp_upload_dir,
			'zip_paths'         => array_values( $zip_paths ),
			'zip_sizes'         => $zip_sizes,
			'zip_checksums'     => $zip_checksums,
			'total_volumes'     => count( $zip_paths ),
			'current_index'     => 0,
			'large_rebuilt'     => false,
			'db_done'           => false,
			'db_stats'          => null,
			'target_siteurl'    => $current_siteurl,
			'target_home'       => $current_home,
			'files_done'        => false,
			'files_copied'      => 0,
			'auth_token_hash'   => hash( 'sha256', $restore_token ),
			'auth_expires_at'   => time() + 7200,
			'log'               => array( '[Início da Restauração] ' . current_time( 'Y-m-d H:i:s' ) ),
			'status'            => DD_Maintenance_Session_Policy::STATUS_CREATED,
			'started_at'        => time(),
			'updated_at'        => time(),
			'finished_at'       => null,
			'last_step'         => 'restore_init',
			'created_at'        => time(),
		);
		if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
			$this->session_store->remove_directory( $extract_dir );
			return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o estado inicial da sessão de restauração.', 'dd-maintenance' ) );
		}
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

		return $this->session_store->load(
			$extract_dir,
			'restore_session_missing',
			'restore_session_corrupted',
			'restore'
		);
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
		if ( is_wp_error( $session ) ) {
			return false;
		}
		if ( empty( $session['auth_token_hash'] ) || empty( $session['auth_expires_at'] ) || time() > (int) $session['auth_expires_at'] ) {
			$this->session_store->release( $session['extract_dir'] );
			return false;
		}
		if ( ! hash_equals( (string) $session['auth_token_hash'], hash( 'sha256', $token ) ) ) {
			$this->session_store->release( $session['extract_dir'] );
			return false;
		}

		$session['auth_expires_at'] = time() + 7200;
		if ( ! $this->save_restore_session_data( $session['extract_dir'], $session ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Grava o estado da sessão de restauração.
	 *
	 * @param string $extract_dir Pasta da sessão.
	 * @param array  $session     Dados da sessão.
	 * @return bool
	 */
	public function save_restore_session_data( string $extract_dir, array $session ): bool {
		$saved = $this->session_store->save( $extract_dir, $session, 'restore' );
		if ( ! $saved && class_exists( 'DD_Maintenance' ) && method_exists( 'DD_Maintenance', 'record_event' ) ) {
			$step = 'restore_init';
			if ( array_key_exists( 'files_queue_created', $session ) || ! empty( $session['files_done'] ) ) {
				$step = 'restore_files';
			} elseif ( array_key_exists( 'db_offset', $session ) || ! empty( $session['db_done'] ) ) {
				$step = 'restore_database';
			} elseif ( array_key_exists( 'current_index', $session ) ) {
				$step = 'restore_extract';
			}
			DD_Maintenance::record_event(
				'restore',
				'checkpoint_failed',
				array(
					'step'           => $step,
					'session_id'     => $session['session_id'] ?? '',
					'correlation_id' => $session['correlation_id'] ?? '',
					'status'         => 'failure',
					'failure_code'   => 'restore_session_save_failed',
					'error_count'    => 1,
				)
			);
		}
		return $saved;
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
				if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
					return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint da reconstrução de arquivos grandes.', 'dd-maintenance' ) );
				}
			}

			$this->session_store->release( $extract_dir );
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
			$expected_size = (int) ( $session['zip_sizes'][ $current_index ] ?? 0 );
			$expected_checksum = (string) ( $session['zip_checksums'][ $current_index ] ?? '' );
			if ( is_link( $zip_path ) || ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
				return new WP_Error( 'restore_zip_invalid', sprintf( __( 'Arquivo de lote inválido: %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}
			if ( ( $expected_size > 0 && (int) filesize( $zip_path ) !== $expected_size ) || ( '' !== $expected_checksum && hash_file( 'sha256', $zip_path ) !== $expected_checksum ) ) {
				return new WP_Error( 'restore_volume_checksum_mismatch', sprintf( __( 'O volume de restauração foi alterado: %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return new WP_Error( 'restore_zip_open_failed', sprintf( __( 'Falha ao abrir o lote %s.', 'dd-maintenance' ), basename( $zip_path ) ) );
			}

			$extracted = DD_Maintenance_File_Security::extract_archive( $zip, $extract_dir );
			$zip->close();
			if ( is_wp_error( $extracted ) ) {
				return $extracted;
			}

			// Se o lote veio de um upload temporário, remove o .zip imediatamente após extrair para economizar espaço em disco
			$temp_upload_dir = $session['temp_upload_dir'] ?? '';
			if ( ! empty( $temp_upload_dir ) && 0 === strpos( wp_normalize_path( $zip_path ), wp_normalize_path( $temp_upload_dir ) ) ) {
				unlink( $zip_path );
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

		if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
			return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint da extração.', 'dd-maintenance' ) );
		}

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
			$this->session_store->release( $session['extract_dir'] );
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
				if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
					return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o estado do restore sem banco de dados.', 'dd-maintenance' ) );
				}

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
			$session['db_initialized']     = false;
			if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
				return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint inicial do banco de dados.', 'dd-maintenance' ) );
			}
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
		$use_mysqli = class_exists( 'mysqli' ) && $dbh instanceof mysqli;
		$database   = new DD_Maintenance_Restore_Database_Adapter(
			$wpdb,
			$dbh,
			$errors,
			$error_samples,
			array(
				'step'           => 'restore_database',
				'session_id'     => $session['session_id'] ?? '',
				'correlation_id' => $session['correlation_id'] ?? '',
			)
		);
		$restore_constraints = static function ( DD_Maintenance_Restore_Database_Adapter $adapter ): void {
			$adapter->execute( 'SET FOREIGN_KEY_CHECKS = 1;' );
		};

		if ( empty( $session['db_initialized'] ) ) {
			$setup_queries = $use_mysqli
				? array(
					'SET FOREIGN_KEY_CHECKS = 0;',
					'SET UNIQUE_CHECKS = 0;',
					'SET AUTOCOMMIT = 0;',
					"SET sql_mode = '';",
					'START TRANSACTION;',
				)
				: array( 'SET FOREIGN_KEY_CHECKS = 0;' );
			foreach ( $setup_queries as $setup_query ) {
				$setup_result = $database->execute_required( $setup_query, 'restore_db_init_failed', __( 'Não foi possível preparar o banco de dados para restauração.', 'dd-maintenance' ) );
				if ( is_wp_error( $setup_result ) ) {
					fclose( $handle );
					$restore_constraints( $database );
					return $setup_result;
				}
			}
			$session['db_initialized'] = true;
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

						$res = $database->execute( $sql );
						$queries++;
						$batch_count++;
						$uncommited_cnt++;

						if ( $use_mysqli && $uncommited_cnt >= 1000 ) {
							$commit_result = $database->execute_required( 'COMMIT;', 'restore_db_commit_failed', __( 'Não foi possível confirmar o lote restaurado.', 'dd-maintenance' ) );
							if ( is_wp_error( $commit_result ) ) {
								fclose( $handle );
								$restore_constraints( $database );
								return $commit_result;
							}
							$start_result = $database->execute_required( 'START TRANSACTION;', 'restore_db_commit_failed', __( 'Não foi possível reabrir a transação do banco restaurado.', 'dd-maintenance' ) );
							if ( is_wp_error( $start_result ) ) {
								fclose( $handle );
								$restore_constraints( $database );
								return $start_result;
							}
							$uncommited_cnt = 0;
						}
					}
				}
			}
		}

		$current_offset = ftell( $handle );
		if ( false === $current_offset ) {
			fclose( $handle );
			$restore_constraints( $database );
			return new WP_Error( 'restore_sql_offset_failed', __( 'Não foi possível salvar o ponto de continuação do arquivo SQL.', 'dd-maintenance' ) );
		}
		$percent = min( 100, max( 0, (int) floor( ( $current_offset / $file_size ) * 100 ) ) );
		if ( feof( $handle ) ) {
			$eof_reached = true;
		}
		fclose( $handle );
		if ( $use_mysqli ) {
			$commit_result = $database->execute_required( 'COMMIT;', 'restore_db_commit_failed', __( 'Não foi possível confirmar o restore do banco de dados.', 'dd-maintenance' ) );
			if ( is_wp_error( $commit_result ) ) {
				$restore_constraints( $database );
				return $commit_result;
			}
		}
		$errors        = $database->error_count();
		$error_samples = $database->error_samples();

		$session['db_offset']        = $current_offset;
		$session['db_queries']       = $queries;
		$session['db_tables']        = $tables;
		$session['db_errors']        = $errors;
		$session['db_error_samples'] = $error_samples;
		$session['db_query_buffer']  = $buffer;
		if ( $eof_reached ) {
			if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
				$restore_constraints( $database );
				return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível confirmar o checkpoint da importação antes do pós-processamento.', 'dd-maintenance' ) );
			}
			$postprocess = $this->finalize_database_restore( $session, $database );
			if ( is_wp_error( $postprocess ) ) {
				return $postprocess;
			}
			$errors        = $postprocess['errors'];
			$error_samples = $postprocess['error_samples'];
			$session['db_errors']        = $errors;
			$session['db_error_samples'] = $error_samples;
			$session['db_done']          = true;
			$session['db_stats']         = array(
				'queries'  => $queries,
				'tables'   => $tables,
				'errors'   => $errors,
				'warnings' => $postprocess['warnings'],
			);
			$final_line = sprintf( __( '[OK] Banco restaurado: %1$s comandos executados (%2$d tabelas).', 'dd-maintenance' ), number_format_i18n( $queries ), $tables );
			if ( $errors > 0 && ! empty( $error_samples ) ) {
				$final_line .= ' (' . sprintf( __( '%d avisos SQL', 'dd-maintenance' ), $errors ) . ')';
			}
			foreach ( $error_samples as $error_sample ) {
				$session['log'][] = '[Aviso] SQL: ' . $error_sample;
			}
			foreach ( $postprocess['warnings'] as $warning ) {
				$session['log'][] = '[Aviso] ' . $warning;
			}
			$session['log'][] = $final_line;
			if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
				return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o estado final do banco restaurado.', 'dd-maintenance' ) );
			}

			return array(
				'completed' => true,
				'has_sql'   => true,
				'queries'   => $queries,
				'tables'    => $tables,
				'errors'    => $errors,
				'error_samples' => $error_samples,
				'warnings'  => $postprocess['warnings'],
				'percent'   => 100,
				'log'       => $final_line,
			);
		}

		if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
			return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint do banco de dados.', 'dd-maintenance' ) );
		}

		$progress_line = sprintf( __( '[Banco] Dump SQL: %1$d%% (%2$s comandos executados, %3$d tabelas)...', 'dd-maintenance' ), $percent, number_format_i18n( $queries ), $tables );

		return array(
			'completed' => false,
			'has_sql'   => true,
			'queries'   => $queries,
			'tables'    => $tables,
			'error_samples' => $error_samples,
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
			$this->session_store->release( $session['extract_dir'] );
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
				if ( is_link( $source_dir ) ) {
					fclose( $q_handle );
					return new WP_Error( 'restore_source_symlink', __( 'A restauração rejeitou uma pasta de origem simbólica.', 'dd-maintenance' ) );
				}
				if ( ! is_dir( $source_dir ) ) {
					continue;
				}

				$real_dest_root = realpath( $dest_dir );
				if ( false === $real_dest_root || ! is_dir( $real_dest_root ) ) {
					fclose( $q_handle );
					return new WP_Error( 'restore_target_root_invalid', __( 'Diretório raiz de destino inválido na restauração.', 'dd-maintenance' ) );
				}
				$real_dest_root = wp_normalize_path( rtrim( $real_dest_root, '/' ) );
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $item ) {
					$item_path      = wp_normalize_path( $item->getPathname() );
					$filename_lower = strtolower( $item->getFilename() );
					if ( is_link( $item_path ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_source_symlink', sprintf( __( 'A restauração rejeitou o link simbólico %s.', 'dd-maintenance' ), $item->getFilename() ) );
					}
					if ( ! $item->isFile() && ! $item->isDir() ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_source_type', sprintf( __( 'A restauração rejeitou o tipo de arquivo %s.', 'dd-maintenance' ), $item->getFilename() ) );
					}

					// NUNCA sobrescreve o wp-config.php, arquivos SQL soltos e o próprio plugin em execução.
					if ( 'wp-config.php' === $filename_lower || 'database.sql' === $filename_lower || preg_match( '/\.sql$/i', $filename_lower ) ) {
						continue;
					}

					$relative = DD_Maintenance_File_Security::normalize_relative_path( ltrim( substr( $item_path, strlen( $source_dir ) ), '/' ) );
					if ( is_wp_error( $relative ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_target_invalid', __( 'Caminho de destino inválido na restauração.', 'dd-maintenance' ) );
					}
					$target    = $real_dest_root . '/' . $relative;
					$rel_lower = strtolower( $relative );
					if ( 0 === strpos( $rel_lower, 'wp-content/plugins/dd-maintenance/' ) || 0 === strpos( $rel_lower, 'wp-content/plugins/backuper/' ) || 0 === strpos( $rel_lower, 'plugins/dd-maintenance/' ) || 0 === strpos( $rel_lower, 'plugins/backuper/' ) ) {
						continue;
					}

					$skip = false;
					foreach ( $backup_dirs as $ignore ) {
						$ignore = rtrim( wp_normalize_path( $ignore ), '/' );
						if ( $target === $ignore || 0 === strpos( $target, $ignore . '/' ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip || ! $item->isFile() ) {
						continue;
					}

					$queue_json = wp_json_encode( array( 'src' => $item_path, 'dst' => $target, 'root' => $real_dest_root, 'relative' => $relative ) );
					$queue_line = false === $queue_json ? false : $queue_json . "\n";
					$written    = false === $queue_line ? false : fwrite( $q_handle, $queue_line );
					if ( false === $queue_line || false === $written || strlen( $queue_line ) !== (int) $written ) {
						fclose( $q_handle );
						$cleanup_errors = $this->cleanup_partial_file( $queue_file );
						$error = new WP_Error( 'restore_queue_write_failed', __( 'Não foi possível registrar todos os arquivos para restauração.', 'dd-maintenance' ) );
						return $this->merge_cleanup_error( $error, array( 'errors' => $cleanup_errors ) );
					}
					$total_files++;
				}
			}
			fclose( $q_handle );

			$session['files_queue_created'] = true;
			$session['files_total']         = $total_files;
			$session['files_copied']        = 0;
			$session['files_queue_offset']  = 0;
			if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
				return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint inicial dos arquivos.', 'dd-maintenance' ) );
			}
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
					if ( is_link( $task['src'] ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_source_unsupported', sprintf( __( 'Arquivo de origem simbólico durante a restauração: %s.', 'dd-maintenance' ), basename( $task['src'] ) ) );
					}
					if ( ! is_file( $task['src'] ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_source_missing', sprintf( __( 'Arquivo de origem ausente durante a restauração: %s.', 'dd-maintenance' ), basename( $task['src'] ) ) );
					}
					if ( empty( $task['root'] ) || empty( $task['relative'] ) ) {
						fclose( $q_handle );
						return new WP_Error( 'restore_target_invalid', __( 'Fila de restauração sem raiz ou caminho relativo seguro.', 'dd-maintenance' ) );
					}

					$copied_result = DD_Maintenance_File_Security::copy_to_root( $task['src'], $task['root'], $task['relative'] );
					if ( is_wp_error( $copied_result ) ) {
						fclose( $q_handle );
						return $copied_result;
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
			$session['log'][]       = $log_line;
			if ( ! empty( $session['apply_elementor_compatibility'] ) ) {
				$elementor_result = DD_Maintenance_Elementor_Compatibility::apply_restore_decision( true );
				$session['log'][]  = sprintf( '[Elementor] compatibilidade explícita: %s.', (string) ( $elementor_result['status'] ?? 'unknown' ) );
			}
			if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
				return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o estado final dos arquivos.', 'dd-maintenance' ) );
			}
			self::clear_elementor_cache(
				'',
				null,
				array(
					'step'           => 'restore_files',
					'session_id'     => $session['session_id'] ?? '',
					'correlation_id' => $session['correlation_id'] ?? '',
				)
			);
			return array(
				'completed' => true,
				'copied'    => $copied,
				'total'     => $total_files,
				'percent'   => 100,
				'log'       => $log_line,
			);
		}

		if ( ! $this->save_restore_session_data( $extract_dir, $session ) ) {
			return new WP_Error( 'restore_session_save_failed', __( 'Não foi possível salvar o checkpoint dos arquivos.', 'dd-maintenance' ) );
		}
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
		$event_context   = array(
			'step'           => 'restore_finalize',
			'session_id'     => $session_id,
			'correlation_id' => $session['correlation_id'] ?? '',
		);
		$elementor_warnings = array_merge(
			self::ensure_elementor_active_kit(),
			self::rebuild_elementor_theme_builder_conditions(),
			self::clear_elementor_cache( '', null, $event_context )
		);

		foreach ( $elementor_warnings as $warning ) {
			$session['log'][] = '[Aviso] ' . $warning;
		}
		$cleanup_errors = array();
		if ( ! $this->delete_directory( $extract_dir ) ) {
			$cleanup_errors[] = 'extract_dir';
		}
		if ( ! empty( $temp_upload_dir ) && is_dir( $temp_upload_dir ) && ! $this->delete_directory( $temp_upload_dir ) ) {
			$cleanup_errors[] = 'temp_upload_dir';
		}
		if ( ! self::remove_mu_plugin_loader( $event_context ) ) {
			$cleanup_errors[] = 'mu_plugin_loader';
		}
		if ( ! empty( $cleanup_errors ) ) {
			DD_Maintenance::record_event(
				'restore',
				'cleanup_failed',
				array(
					'step'           => 'restore_finalize',
					'session_id'     => $session_id,
					'correlation_id' => $session['correlation_id'] ?? '',
					'status'         => 'failure',
					'failure_code'   => 'restore_cleanup_failed',
					'error_count'    => count( $cleanup_errors ),
					'cleanup'        => $cleanup_errors,
				)
			);
			$this->session_store->release( $extract_dir );
			return new WP_Error( 'restore_cleanup_failed', sprintf( __( 'A restauração foi concluída, mas a limpeza falhou: %s.', 'dd-maintenance' ), implode( ', ', $cleanup_errors ) ) );
		}

		if ( class_exists( 'DD_Maintenance_Backup' ) ) {
			DD_Maintenance_Backup::purge_orphaned_sessions( 300 );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}

		$session['log'][] = '[Fim da Restauração] ' . current_time( 'Y-m-d H:i:s' );

		$this->session_store->release( $extract_dir );
		return array(
			'success'  => true,
			'log'      => $session['log'],
			'db_stats' => $session['db_stats'],
			'files'    => $session['files_copied'],
			'warnings' => $elementor_warnings,
		);
	}

	/**
	 * Limpa a sessão de restauração em caso de falha.
	 *
	 * @param string $session_id ID da sessão.
	 * @return array{cleaned:bool,errors:string[]}
	 */
	public function cleanup_failed_restore( string $session_id ): array {
		$errors       = array();
		$session      = $this->get_restore_session_data( $session_id );
		$session_data = is_array( $session ) ? $session : array();
		if ( ! is_wp_error( $session ) ) {
			if ( ! $this->delete_directory( $session['extract_dir'] ) ) {
				$errors[] = 'extract_dir';
			}
			if ( ! empty( $session['temp_upload_dir'] ) && is_dir( $session['temp_upload_dir'] ) && ! $this->delete_directory( $session['temp_upload_dir'] ) ) {
				$errors[] = 'temp_upload_dir';
			}
		}
		if ( ! self::remove_mu_plugin_loader( array(
			'step'           => 'restore_fail_cleanup',
			'session_id'     => $session_data['session_id'] ?? $session_id,
			'correlation_id' => $session_data['correlation_id'] ?? '',
		) ) ) {
			$errors[] = 'mu_plugin_loader';
		}
		if ( ! empty( $errors ) && class_exists( 'DD_Maintenance' ) ) {
			DD_Maintenance::record_event(
				'restore',
				'cleanup_failed',
				array(
					'step'           => 'restore_fail_cleanup',
					'session_id'     => $session_data['session_id'] ?? $session_id,
					'correlation_id' => $session_data['correlation_id'] ?? '',
					'status'         => 'failure',
					'failure_code'   => 'restore_cleanup_failed',
					'error_count'    => count( $errors ),
					'cleanup'        => $errors,
				)
			);
		}
		if ( class_exists( 'DD_Maintenance_Backup' ) ) {
			DD_Maintenance_Backup::purge_orphaned_sessions( 300 );
		}
		return array(
			'cleaned' => empty( $errors ),
			'errors'  => $errors,
		);
	}

	/**
	 * Executa a restauração completa em loop sequencial síncrono (para CLI/WP-Cron).
	 *
	 * @param array $zip_paths Caminhos dos volumes ZIP.
	 * @return array|WP_Error
	 */
	private function restore_archive_set( array $zip_paths, bool $apply_elementor_compatibility = false ) {
		$service = new DD_Maintenance_Restore_Archive_Service( $this );
		return $service->run( $zip_paths, $apply_elementor_compatibility );
	}
	/**
	 * Reconstrói arquivos que atravessaram mais de um volume.
	 *
	 * @param string $extract_dir Pasta compartilhada de extração.
	 * @return true|WP_Error
	 */
	private function reassemble_large_files( string $extract_dir ) {
		$chunk_dir     = $extract_dir . '/__dd_chunks__';
		$manifest_file = $chunk_dir . '/manifest.json';
		if ( is_link( $chunk_dir ) || is_link( $manifest_file ) ) {
			return new WP_Error( 'restore_chunk_symlink', __( 'Manifesto de arquivos grandes simbólico não permitido.', 'dd-maintenance' ) );
		}
		if ( ! is_file( $manifest_file ) ) {
			return true;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return new WP_Error( 'restore_chunk_manifest', __( 'Manifesto de arquivos grandes inválido.', 'dd-maintenance' ) );
		}

		$plans = array();
		foreach ( $manifest['files'] as $file ) {
			if ( empty( $file['target'] ) || empty( $file['chunks'] ) || ! is_array( $file['chunks'] ) ) {
				return new WP_Error( 'restore_chunk_entry', __( 'Entrada inválida no manifesto de arquivos grandes.', 'dd-maintenance' ) );
			}
			$target = DD_Maintenance_File_Security::normalize_relative_path( $file['target'] );
			if ( is_wp_error( $target ) ) {
				return new WP_Error( 'restore_chunk_path', __( 'Caminho inválido no manifesto de arquivos grandes.', 'dd-maintenance' ) );
			}
			$target_check = DD_Maintenance_File_Security::safe_child_path( $extract_dir, $target, false );
			if ( is_wp_error( $target_check ) && 'dd_path_parent_missing' !== $target_check->get_error_code() ) {
				return $target_check;
			}

			$chunks = $file['chunks'];
			ksort( $chunks, SORT_NUMERIC );
			$chunk_paths = array();
			foreach ( $chunks as $chunk ) {
				$chunk = DD_Maintenance_File_Security::normalize_relative_path( $chunk );
				if ( is_wp_error( $chunk ) || 0 !== strpos( $chunk, '__dd_chunks__/' ) ) {
					return new WP_Error( 'restore_chunk_path', __( 'Caminho de trecho inválido no manifesto.', 'dd-maintenance' ) );
				}
				$chunk_check = DD_Maintenance_File_Security::safe_child_path( $extract_dir, $chunk, false );
				if ( is_wp_error( $chunk_check ) && 'dd_path_parent_missing' !== $chunk_check->get_error_code() ) {
					return $chunk_check;
				}
				$chunk_path = $extract_dir . '/' . $chunk;
				$source_ok  = DD_Maintenance_File_Security::assert_regular_source( $chunk_path );
				if ( is_wp_error( $source_ok ) ) {
					return new WP_Error( 'restore_chunk_missing', sprintf( __( 'Trecho ausente ou inseguro ao reconstruir %s.', 'dd-maintenance' ), $target ) );
				}
				$chunk_paths[] = $chunk_path;
			}
			$plans[] = array(
				'target' => $target,
				'chunks' => $chunk_paths,
				'size'   => isset( $file['size'] ) ? (int) $file['size'] : null,
			);
		}

		foreach ( $plans as $plan ) {
			$destination = DD_Maintenance_File_Security::safe_child_path( $extract_dir, $plan['target'], true );
			if ( is_wp_error( $destination ) ) {
				return $destination;
			}
			$output = fopen( $destination, 'wb' );
			if ( ! $output ) {
				return new WP_Error( 'restore_chunk_output', sprintf( __( 'Não foi possível reconstruir %s.', 'dd-maintenance' ), $plan['target'] ) );
			}
			foreach ( $plan['chunks'] as $chunk_path ) {
				$input = fopen( $chunk_path, 'rb' );
				$expected = is_file( $chunk_path ) ? filesize( $chunk_path ) : false;
				$copied = $input && false !== $expected ? stream_copy_to_stream( $input, $output ) : false;
				if ( $input ) {
					fclose( $input );
				}
				if ( false === $copied || false === $expected || (int) $copied !== (int) $expected ) {
					fclose( $output );
					$cleanup_errors = $this->cleanup_partial_file( $destination );
					$error = new WP_Error( 'restore_chunk_read', sprintf( __( 'Falha ao ler trecho de %s.', 'dd-maintenance' ), $plan['target'] ) );
					return $this->merge_cleanup_error( $error, array( 'errors' => $cleanup_errors ) );
				}
			}
			fclose( $output );
			clearstatcache( true, $destination );
			if ( null !== $plan['size'] && ( ! is_file( $destination ) || filesize( $destination ) !== $plan['size'] ) ) {
				$cleanup_errors = $this->cleanup_partial_file( $destination );
				$error = new WP_Error( 'restore_chunk_size', sprintf( __( 'Tamanho reconstruído inválido para %s.', 'dd-maintenance' ), $plan['target'] ) );
				return $this->merge_cleanup_error( $error, array( 'errors' => $cleanup_errors ) );
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
		$candidates = array( $extract_dir . '/database.sql' );
		$root_files = glob( $extract_dir . '/*.sql' );
		$sub_files  = glob( $extract_dir . '/*/*.sql' );
		if ( is_array( $root_files ) ) {
			$candidates = array_merge( $candidates, $root_files );
		}
		if ( is_array( $sub_files ) ) {
			$candidates = array_merge( $candidates, $sub_files );
		}
		foreach ( $candidates as $candidate ) {
			if ( is_link( $candidate ) || ! is_file( $candidate ) || filesize( $candidate ) <= 0 ) {
				continue;
			}
			return $candidate;
		}

		$copy_sources = array();
		if ( ! empty( $temp_upload_dir ) && is_dir( $temp_upload_dir ) && ! is_link( $temp_upload_dir ) ) {
			$copy_sources[] = $temp_upload_dir . '/database.sql';
			$upload_sqls   = glob( $temp_upload_dir . '/*.sql' );
			if ( is_array( $upload_sqls ) ) {
				$copy_sources = array_merge( $copy_sources, $upload_sqls );
			}
		}
		$backup_dir = DD_Maintenance::backup_dir();
		if ( ! empty( $zip_paths ) && is_dir( $backup_dir ) && ! is_link( $backup_dir ) ) {
			foreach ( $zip_paths as $zip_path ) {
				$base             = preg_replace( '/\.part\d+\.zip$/i', '', basename( $zip_path ) );
				$base             = preg_replace( '/\.zip$/i', '', $base );
				$copy_sources[]   = $backup_dir . '/' . $base . '.sql';
			}
		}

		foreach ( $copy_sources as $source ) {
			if ( is_link( $source ) || ! is_file( $source ) || filesize( $source ) <= 0 ) {
				continue;
			}
			$copy_result = DD_Maintenance_File_Security::copy_to_root( $source, $extract_dir, basename( $source ) );
			if ( ! is_wp_error( $copy_result ) ) {
				return $extract_dir . '/' . basename( $source );
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

		$current_siteurl = get_option( 'siteurl', '' );
		$current_home    = get_option( 'home', '' );
		$handle          = fopen( $sql_file, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'restore_sql_open_failed', __( 'Não foi possível ler o arquivo SQL do banco de dados.', 'dd-maintenance' ) );
		}

		$database = new DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$executor = new DD_Maintenance_Restore_Query_Executor( $database );
		$parser   = new DD_Maintenance_Restore_Sql_Parser();
		$finalizer = new DD_Maintenance_Restore_Database_Finalizer();
		$warnings = array();
		$changed  = 0;

		$setup = $executor->execute_required(
			'SET FOREIGN_KEY_CHECKS = 0;',
			'restore_db_init_failed',
			__( 'Não foi possível preparar o banco de dados para restauração.', 'dd-maintenance' )
		);
		if ( is_wp_error( $setup ) ) {
			fclose( $handle );
			return $setup;
		}

		try {
			$parsed = $parser->parse(
				$handle,
				static function ( string $sql ) use ( $executor, &$changed ): bool {
					$result = $executor->execute( $sql );
					if ( false !== $result && is_int( $result ) ) {
						$changed += max( 0, $result );
					}
					return true;
				}
			);
		} finally {
			fclose( $handle );
		}

		$connection_result = $finalizer->restore_connection( $executor );
		if ( is_wp_error( $connection_result ) ) {
			return $connection_result;
		}
		if ( ! empty( $parsed['buffer'] ) ) {
			$warnings[] = 'O dump terminou com um comando SQL incompleto; o trecho final foi ignorado.';
		}

		$options_table = ! empty( $wpdb->options ) ? (string) $wpdb->options : (string) $wpdb->prefix . 'options';
		if ( ! DD_Maintenance_Restore_Database_Finalizer::validate_prefix( (string) $wpdb->prefix ) || ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $options_table ) ) {
			return new WP_Error( 'restore_prefix_invalid', __( 'O prefixo atual do banco contém caracteres inválidos.', 'dd-maintenance' ) );
		}

		foreach ( array( 'siteurl' => $current_siteurl, 'home' => $current_home ) as $option_name => $option_value ) {
			if ( ! is_string( $option_value ) || ! preg_match( '#^https?://[^\\s]+#i', $option_value ) ) {
				$warnings[] = sprintf( 'A opção %s não possui uma URL confiável; atualização ignorada.', $option_name );
				continue;
			}
			$result = $executor->execute_required(
				$database->prepare(
					"UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = %s",
					untrailingslashit( $option_value ),
					$option_name
				),
				'restore_option_update_failed',
				sprintf( __( 'Não foi possível atualizar a opção obrigatória %s.', 'dd-maintenance' ), $option_name )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'queries'       => $parsed['queries'],
			'tables'        => $parsed['tables'],
			'skipped'       => $parsed['skipped'],
			'errors'        => $executor->error_count(),
			'error_samples' => $executor->error_samples(),
			'warnings'      => $warnings,
			'rows_changed'  => $changed,
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
	public function copy_directory( string $source_dir, string $dest_dir, array $ignore_paths = array() ) {
		$source_dir = wp_normalize_path( $source_dir );
		$dest_root  = realpath( $dest_dir );
		if ( is_link( $source_dir ) || ! is_dir( $source_dir ) ) {
			return is_link( $source_dir ) ? new WP_Error( 'restore_source_symlink', __( 'Origem simbólica não permitida.', 'dd-maintenance' ) ) : 0;
		}
		if ( false === $dest_root || ! is_dir( $dest_root ) ) {
			return new WP_Error( 'restore_target_root_invalid', __( 'Diretório raiz de destino inválido.', 'dd-maintenance' ) );
		}
		$dest_root = wp_normalize_path( rtrim( $dest_root, '/' ) );
		$ignore_paths = array_map(
			static function ( $path ) {
				return rtrim( wp_normalize_path( $path ), '/' );
			},
			$ignore_paths
		);
		$copied  = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );
			if ( is_link( $item_path ) ) {
				return new WP_Error( 'restore_source_symlink', sprintf( __( 'A restauração rejeitou o link simbólico %s.', 'dd-maintenance' ), $item->getFilename() ) );
			}
			if ( ! $item->isFile() && ! $item->isDir() ) {
				return new WP_Error( 'restore_source_type', sprintf( __( 'Tipo de arquivo não suportado: %s.', 'dd-maintenance' ), $item->getFilename() ) );
			}
			$filename_lower = strtolower( $item->getFilename() );
			if ( 'wp-config.php' === $filename_lower || 'database.sql' === $filename_lower || preg_match( '/\.sql$/i', $filename_lower ) ) {
				continue;
			}
			$relative = DD_Maintenance_File_Security::normalize_relative_path( ltrim( substr( $item_path, strlen( $source_dir ) ), '/' ) );
			if ( is_wp_error( $relative ) ) {
				return $relative;
			}
			$target = $dest_root . '/' . $relative;
			$skip   = false;
			foreach ( $ignore_paths as $ignore ) {
				if ( $target === $ignore || 0 === strpos( $target, $ignore . '/' ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			if ( $item->isDir() ) {
				$target_path = DD_Maintenance_File_Security::safe_child_path( $dest_root, $relative, true );
				if ( is_wp_error( $target_path ) ) {
					return $target_path;
				}
				if ( ! is_dir( $target_path ) && ! mkdir( $target_path, 0755 ) && ! is_dir( $target_path ) ) {
					return new WP_Error( 'restore_target_mkdir_failed', sprintf( __( 'Não foi possível criar a pasta de destino %s.', 'dd-maintenance' ), basename( $target ) ) );
				}
			} else {
				$copy_result = DD_Maintenance_File_Security::copy_to_root( $item_path, $dest_root, $relative );
				if ( is_wp_error( $copy_result ) ) {
					return $copy_result;
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

		$chunk_size_mb = ( new DD_Maintenance_Settings_Repository() )->get_split_size_mb();
		$backups = array();
		foreach ( $groups as $base => $data ) {
			ksort( $data['parts'], SORT_NUMERIC );
			$parts_list = array_values( $data['parts'] );
			$count      = count( $parts_list );
			$mtime      = $data['latest_mtime'];

			$backups[] = array(
				'identifier'         => $base,
				'display_name'       => $data['is_multipart'] ? sprintf( '%s (%d partes de até %d MB)', $base, $count, $chunk_size_mb ) : $data['display_name'],
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

		$parts = glob( $backup_dir . '/' . $base_name . '.part*.zip' );
		if ( ! empty( $parts ) ) {
			foreach ( $parts as $part ) {
				if ( is_file( $part ) && unlink( $part ) ) {
					$deleted = true;
				}
			}
		}

		$single = $backup_dir . '/' . $base_name . '.zip';
		if ( is_file( $single ) && unlink( $single ) ) {
			$deleted = true;
		}

		$sql = $backup_dir . '/' . $base_name . '.sql';
		if ( is_file( $sql ) && unlink( $sql ) ) {
			$deleted = true;
		}

		return $deleted;
	}

	/**
	 * Remove recursivamente uma pasta e seus arquivos.
	 *
	 * @param string $dir Caminho da pasta.
	 * @return bool
	 */
	private function delete_directory( string $dir ): bool {
		return $this->session_store->remove_directory( wp_normalize_path( $dir ) );
	}

	/**
	 * Remove um artefato parcial e informa falha de limpeza sem ocultá-la.
	 *
	 * @param string $path Caminho do artefato.
	 * @return string[]
	 */
	private function cleanup_partial_file( string $path ): array {
		if ( is_link( $path ) ) {
			return unlink( $path ) ? array() : array( basename( $path ) );
		}
		if ( ! file_exists( $path ) || unlink( $path ) ) {
			return array();
		}
		return array( basename( $path ) );
	}
	/**
	 * Preserva a falha original quando a limpeza também falha.
	 *
	 * @param WP_Error $original Erro da operação.
	 * @param array    $cleanup  Resultado da limpeza.
	 * @return WP_Error
	 */
	private function merge_cleanup_error( WP_Error $original, array $cleanup ): WP_Error {
		if ( empty( $cleanup['errors'] ) ) {
			return $original;
		}
		return new WP_Error(
			$original->get_error_code(),
			$original->get_error_message() . ' [cleanup: ' . implode( ', ', $cleanup['errors'] ) . ']'
		);
	}

	/**
	 * Aceita apenas URLs de site persistidas e sem componentes ambíguos.
	 *
	 * O host da requisição não é uma fonte confiável para configurar uma
	 * restauração: ele pode ser controlado por cabeçalhos enviados ao servidor.
	 *
	 * @param mixed $value Valor persistido na opção do WordPress.
	 * @return string
	 */
	private static function trusted_site_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value  = trim( $value );
		$parsed = parse_url( $value );
		if ( false === $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}
		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
			return '';
		}
		return rtrim( $value, '/' );
	}

	/**
	 * Tenta elevar limites de execução e memória para restaurar grandes backups.
	 */
	private function set_time_and_memory_limits() {
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '512M' );
		}
	}

	/**
	 * Finaliza a importação SQL uma única vez, após o EOF confirmado.
	 *
	 * @param array $session  Estado da sessão.
	 * @param DD_Maintenance_Restore_Database_Adapter $database Adaptador.
	 * @return array|WP_Error
	 */
	private function finalize_database_restore( array $session, DD_Maintenance_Restore_Database_Adapter $database ) {
		global $wpdb;

		$finalizer = new DD_Maintenance_Restore_Database_Finalizer();
		$executor  = new DD_Maintenance_Restore_Query_Executor( $database );
		$connection_result = $finalizer->restore_connection( $executor );
		if ( is_wp_error( $connection_result ) ) {
			return $connection_result;
		}

		$discovered = $finalizer->discover_options_table(
			$database,
			! empty( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_'
		);
		if ( is_wp_error( $discovered ) ) {
			return $discovered;
		}
		$dump_prefix   = $discovered['prefix'];
		$options_table = $discovered['table'];
		if ( '' === $options_table ) {
			return array(
				'errors'        => $database->error_count(),
				'error_samples' => $database->error_samples(),
				'warnings'      => array( 'A tabela de opções restaurada não foi encontrada; pós-processamento ignorado.' ),
			);
		}

		$warnings = array();
		if ( $dump_prefix !== (string) $wpdb->prefix ) {
			if ( ! class_exists( 'DD_Maintenance_Config' ) || ! method_exists( 'DD_Maintenance_Config', 'update_table_prefix' ) ) {
				return new WP_Error( 'restore_prefix_sync_failed', __( 'Não foi possível sincronizar o prefixo do banco restaurado.', 'dd-maintenance' ) );
			}
			$config_result = DD_Maintenance_Config::update_table_prefix( $dump_prefix );
			if ( is_wp_error( $config_result ) || false === $config_result ) {
				$message = is_wp_error( $config_result ) ? $config_result->get_error_message() : __( 'Falha ao atualizar o wp-config.php.', 'dd-maintenance' );
				return new WP_Error( 'restore_prefix_sync_failed', $message );
			}
			$usermeta_table = $dump_prefix . 'usermeta';
			if ( ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $usermeta_table ) ) {
				return new WP_Error( 'restore_prefix_invalid', __( 'O identificador de usermeta restaurado é inválido.', 'dd-maintenance' ) );
			}
			if ( $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $usermeta_table ) ) ) {
				$usermeta_result = $database->execute_required(
					$database->prepare(
						"UPDATE `{$usermeta_table}` SET `meta_key` = REPLACE(`meta_key`, %s, %s) WHERE `meta_key` LIKE %s",
						(string) $wpdb->prefix,
						$dump_prefix,
						(string) $wpdb->prefix . '%'
					),
					'restore_usermeta_prefix_failed',
					__( 'Não foi possível sincronizar o prefixo da tabela usermeta.', 'dd-maintenance' )
				);
				if ( is_wp_error( $usermeta_result ) ) {
					return $usermeta_result;
				}
			}
			if ( method_exists( $wpdb, 'set_prefix' ) ) {
				$wpdb->set_prefix( $dump_prefix );
			}
		}

		$old_siteurl = (string) $database->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'siteurl' LIMIT 1" );
		$active_raw  = $database->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'active_plugins' LIMIT 1" );
		$active_list = maybe_unserialize( $active_raw );
		$active_list = is_array( $active_list ) ? $active_list : array();
		foreach ( array( 'dd-maintenance/dd-maintenance.php', 'backuper/backuper.php' ) as $plugin_slug ) {
			if ( ! in_array( $plugin_slug, $active_list, true ) ) {
				$active_list[] = $plugin_slug;
			}
		}
		$active_result = $database->execute_required(
			$database->prepare(
				"INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('active_plugins', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s",
				serialize( $active_list ),
				serialize( $active_list )
			),
			'restore_active_plugins_failed',
			__( 'Não foi possível atualizar os plugins ativos no banco restaurado.', 'dd-maintenance' )
		);
		if ( is_wp_error( $active_result ) ) {
			return $active_result;
		}

		$target_siteurl = ! empty( $session['target_siteurl'] ) ? $session['target_siteurl'] : ( ! empty( $session['db_current_siteurl'] ) ? $session['db_current_siteurl'] : get_option( 'siteurl', '' ) );
		$target_home    = ! empty( $session['target_home'] ) ? $session['target_home'] : ( ! empty( $session['db_current_home'] ) ? $session['db_current_home'] : get_option( 'home', '' ) );
		foreach ( array( 'siteurl' => $target_siteurl, 'home' => $target_home ) as $option_name => $option_value ) {
			if ( empty( $option_value ) ) {
				$warnings[] = sprintf( 'A opção obrigatória %s não possui um valor de destino; atualização ignorada.', $option_name );
				continue;
			}
			$option_result = $database->execute_required(
				$database->prepare(
					"INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s",
					$option_name,
					untrailingslashit( $option_value ),
					untrailingslashit( $option_value )
				),
				'restore_option_update_failed',
				sprintf( __( 'Não foi possível atualizar a opção obrigatória %s.', 'dd-maintenance' ), $option_name )
			);
			if ( is_wp_error( $option_result ) ) {
				return $option_result;
			}
		}

		if ( ! empty( $old_siteurl ) && ! empty( $target_siteurl ) ) {
			$migrator = new DD_Maintenance_Restore_Url_Migrator();
			$warnings = array_merge( $warnings, $migrator->warnings( $this, $old_siteurl, $target_siteurl, $dump_prefix, $database ) );
		}
		$warnings = array_merge( $warnings, self::ensure_elementor_active_kit( $dump_prefix, $database ) );
		$warnings = array_merge( $warnings, self::rebuild_elementor_theme_builder_conditions( $dump_prefix, $database ) );
		$warnings = array_merge(
			$warnings,
			self::clear_elementor_cache(
				$dump_prefix,
				$database,
				array(
					'step'           => 'restore_database',
					'session_id'     => $session['session_id'] ?? '',
					'correlation_id' => $session['correlation_id'] ?? '',
				)
			)
		);
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'errors'        => $database->error_count(),
			'error_samples' => $database->error_samples(),
			'warnings'      => $warnings,
		);
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
		return DD_Maintenance_Elementor_Compatibility::fix_elementor_dynamic_tags( $content );
	}

	/**
	 * Higieniza uma string individual de tag dinamica preservando aspas escapadas caso faca parte de JSON cru.
	 *
	 * @param string $content String contendo tag do Elementor.
	 * @return string
	 */
	public static function fix_elementor_dynamic_tags_string( string $content ): string {
		return DD_Maintenance_Elementor_Compatibility::fix_elementor_dynamic_tags_string( $content );
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
					$unserialized = unserialize( $data, array( 'allowed_classes' => false ) );
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
	 * Executa Search & Replace seguro e paginado sem ocultar falhas de escrita.
	 *
	 * @param string $from_url    URL de origem do backup.
	 * @param string $to_url      URL de destino atual.
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @param DD_Maintenance_Restore_Database_Adapter|null $database Adaptador.
	 * @return string[]
	 */
	private function perform_url_search_replace( string $from_url, string $to_url, string $dump_prefix, ?DD_Maintenance_Restore_Database_Adapter $database = null ): array {
		global $wpdb;
		$database = $database instanceof DD_Maintenance_Restore_Database_Adapter ? $database : new DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$warnings = array();
		$replace_map    = self::build_url_replacement_map( $from_url, $to_url );
		if ( ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $dump_prefix ) ) {
			return array( 'prefixo inválido para search/replace' );
		}
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
					if ( ! $database->execute(
						$database->prepare(
							"UPDATE `{$posts_table}` SET `post_content` = %s, `post_excerpt` = %s, `guid` = %s WHERE `ID` = %d",
							$content,
							$excerpt,
							$guid,
							(int) $prow['ID']
						)
					) ) {
						$warnings[] = 'Falha ao atualizar URLs e conteúdo de posts.';
					}
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
				if ( $fixed_val !== $orig_val && ! $database->execute( $database->prepare( "UPDATE `{$postmeta_table}` SET `meta_value` = %s WHERE `meta_id` = %d", $fixed_val, (int) $mrow['meta_id'] ) ) ) {
					$warnings[] = 'Falha ao atualizar URLs dos metadados de posts.';
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
				if ( $fixed_val !== $orig_val && ! $database->execute( $database->prepare( "UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_id` = %d", $fixed_val, (int) $orow['option_id'] ) ) ) {
					$warnings[] = 'Falha ao atualizar URLs das opções.';
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
					if ( ! is_string( $orig_val ) || '' === $orig_val ) {
						continue;
					}
					$fixed_val = self::recursive_search_replace( $replace_map, '', $orig_val );
					if ( $fixed_val !== $orig_val && ! $database->execute( $database->prepare( "UPDATE `{$termmeta_table}` SET `meta_value` = %s WHERE `meta_id` = %d", $fixed_val, (int) $trow['meta_id'] ) ) ) {
						$warnings[] = 'Falha ao atualizar URLs de termmeta.';
					}
				}
				if ( count( $tm_rows ) < 100 ) {
					break;
				}
			}
		}

		$usermeta_table = $dump_prefix . 'usermeta';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $usermeta_table ) ) && ! empty( $replace_map ) ) {
			$last_umeta_id = 0;
			while ( true ) {
				$user_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT `umeta_id`, `meta_value` FROM `{$usermeta_table}` WHERE `umeta_id` > %d AND (`meta_value` LIKE %s OR `meta_value` LIKE %s OR `meta_value` LIKE %s) ORDER BY `umeta_id` ASC LIMIT 100",
						$last_umeta_id,
						'%' . $wpdb->esc_like( $from_url ) . '%',
						'%' . $wpdb->esc_like( $from_escaped ) . '%',
						'%' . $wpdb->esc_like( $from_no_proto ) . '%'
					),
					ARRAY_A
				);
				if ( empty( $user_rows ) || ! is_array( $user_rows ) ) {
					break;
				}
				foreach ( $user_rows as $user_row ) {
					$last_umeta_id = (int) ( $user_row['umeta_id'] ?? $last_umeta_id );
					$original      = $user_row['meta_value'] ?? '';
					if ( ! is_string( $original ) ) {
						continue;
					}
					$fixed = self::recursive_search_replace( $replace_map, '', $original );
					if ( $fixed !== $original && ! $database->execute( $database->prepare( "UPDATE `{$usermeta_table}` SET `meta_value` = %s WHERE `umeta_id` = %d", $fixed, $last_umeta_id ) ) ) {
						$warnings[] = 'Falha ao atualizar URLs de usermeta.';
					}
				}
				if ( count( $user_rows ) < 100 ) {
					break;
				}
			}
		}
		$warnings = array_merge( $warnings, self::ensure_elementor_active_kit( $dump_prefix, $database ) );
		$warnings = array_merge( $warnings, self::rebuild_elementor_theme_builder_conditions( $dump_prefix, $database ) );
		$warnings = array_merge( $warnings, self::clear_elementor_cache( $dump_prefix, $database ) );
		return $warnings;
	}

	/**
	 * Verifica e repara o Elementor Kit Ativo (elementor_active_kit) no banco restaurado.
	 * Se o kit ativo estiver ausente, corrompido ou despublicado, restaura a referência correta
	 * para evitar que o Elementor reinicialize um kit em branco perdendo todas as fontes e cores.
	 *
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @return string[]
	 */
	public static function ensure_elementor_active_kit( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array {
		global $wpdb;
		$database = $database instanceof DD_Maintenance_Restore_Database_Adapter ? $database : new DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$warnings = array();
		if ( empty( $dump_prefix ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = (string) $wpdb->prefix;
		}
		if ( ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $dump_prefix ) ) {
			return array( 'O prefixo do Elementor é inválido.' );
		}

		$options_table  = $dump_prefix . 'options';
		$posts_table    = $dump_prefix . 'posts';
		$postmeta_table = $dump_prefix . 'postmeta';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_table ) ) ) {
			return $warnings;
		}

		$kit_id    = (int) $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'elementor_active_kit' LIMIT 1" );
		$valid_kit = false;
		if ( $kit_id > 0 ) {
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT `ID`, `post_status` FROM `{$posts_table}` WHERE `ID` = %d AND `post_type` = 'elementor_library' LIMIT 1", $kit_id ) );
			if ( $post ) {
				$valid_kit = true;
				if ( 'publish' !== $post->post_status && ! $database->execute( $database->prepare( "UPDATE `{$posts_table}` SET `post_status` = 'publish' WHERE `ID` = %d", $kit_id ) ) ) {
					$warnings[] = 'Falha ao publicar o kit ativo do Elementor.';
				}
			}
		}
		if ( ! $valid_kit ) {
			$found_kit_id = (int) $wpdb->get_var( "SELECT p.`ID` FROM `{$posts_table}` p INNER JOIN `{$postmeta_table}` pm ON p.`ID` = pm.`post_id` WHERE p.`post_type` = 'elementor_library' AND pm.`meta_key` = '_elementor_template_type' AND pm.`meta_value` = 'kit' ORDER BY p.`ID` DESC LIMIT 1" );
			if ( ! $found_kit_id ) {
				$found_kit_id = (int) $wpdb->get_var( "SELECT `ID` FROM `{$posts_table}` WHERE `post_type` = 'elementor_library' AND (`post_title` LIKE '%Kit%' OR `post_name` LIKE '%kit%') ORDER BY `ID` DESC LIMIT 1" );
			}
			if ( $found_kit_id > 0 ) {
				$mutations = array(
					'dados do kit' => $database->prepare( "UPDATE `{$posts_table}` SET `post_status` = 'publish' WHERE `ID` = %d", $found_kit_id ),
					'opção do kit' => $database->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_active_kit', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", (string) $found_kit_id, (string) $found_kit_id ),
				);
				foreach ( $mutations as $label => $sql ) {
					if ( ! $database->execute( $sql ) ) {
						$warnings[] = 'Falha ao atualizar ' . $label . ' do Elementor.';
					}
				}
				$has_type = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_id` FROM `{$postmeta_table}` WHERE `post_id` = %d AND `meta_key` = '_elementor_template_type' LIMIT 1", $found_kit_id ) );
				if ( ! $has_type && ! $database->execute( $database->prepare( "INSERT INTO `{$postmeta_table}` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, '_elementor_template_type', 'kit')", $found_kit_id ) ) ) {
					$warnings[] = 'Falha ao registrar o tipo do kit do Elementor.';
				}
				$has_mode = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_id` FROM `{$postmeta_table}` WHERE `post_id` = %d AND `meta_key` = '_elementor_edit_mode' LIMIT 1", $found_kit_id ) );
				if ( ! $has_mode && ! $database->execute( $database->prepare( "INSERT INTO `{$postmeta_table}` (`post_id`, `meta_key`, `meta_value`) VALUES (%d, '_elementor_edit_mode', 'builder')", $found_kit_id ) ) ) {
					$warnings[] = 'Falha ao registrar o modo de edição do kit do Elementor.';
				}
			}
		}
		return $warnings;
	}

	/**
	 * Reconstrói as condições globais de Header, Footer e Templates do Theme Builder do Elementor Pro / Pro Elements
	 * caso o array serializado na tabela options tenha sido perdido na restauração.
	 *
	 * @param string $dump_prefix Prefixo das tabelas.
	 * @return string[]
	 */
	public static function rebuild_elementor_theme_builder_conditions( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null ): array {
		global $wpdb;
		$database = $database instanceof DD_Maintenance_Restore_Database_Adapter ? $database : new DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$warnings = array();
		if ( empty( $dump_prefix ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = (string) $wpdb->prefix;
		}
		if ( ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $dump_prefix ) ) {
			return array( 'O prefixo das condições do Elementor é inválido.' );
		}
		$options_table  = $dump_prefix . 'options';
		$postmeta_table = $dump_prefix . 'postmeta';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_table ) ) ) {
			return $warnings;
		}
		$current_opt = $wpdb->get_var( "SELECT `option_value` FROM `{$options_table}` WHERE `option_name` = 'elementor_pro_theme_builder_conditions' LIMIT 1" );
		$current_val = is_string( $current_opt ) ? unserialize( $current_opt, array( 'allowed_classes' => false ) ) : null;
		if ( empty( $current_val ) || ! is_array( $current_val ) ) {
			$condition_rows = $wpdb->get_results( "SELECT pm.`post_id`, pm.`meta_value` as `conditions`, pt.`meta_value` as `template_type` FROM `{$postmeta_table}` pm LEFT JOIN `{$postmeta_table}` pt ON pm.`post_id` = pt.`post_id` AND pt.`meta_key` = '_elementor_template_type' WHERE pm.`meta_key` = '_elementor_conditions'", ARRAY_A );
			if ( ! empty( $condition_rows ) && is_array( $condition_rows ) ) {
				$rebuilt = array();
				foreach ( $condition_rows as $crow ) {
					$cond = unserialize( $crow['conditions'], array( 'allowed_classes' => false ) );
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
					if ( ! $database->execute( $database->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_pro_theme_builder_conditions', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $serialized, $serialized ) ) ) {
						$warnings[] = 'Falha ao reconstruir as condições do Theme Builder.';
					}
				}
			}
		}
		return $warnings;
	}

	/**
	 * Limpa todos os caches compilados de CSS e metadados do Elementor para forçar regeneração limpa com novas URLs.
	 *
	 * @param string $dump_prefix   Prefixo das tabelas.
	 * @param DD_Maintenance_Restore_Database_Adapter|null $database Adaptador de banco.
	 * @param array  $event_context Contexto operacional seguro.
	 * @return string[]
	 */
	public static function clear_elementor_cache( string $dump_prefix = '', ?DD_Maintenance_Restore_Database_Adapter $database = null, array $event_context = array() ): array {
		global $wpdb;
		$wpdb       = isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
		$database   = $database instanceof DD_Maintenance_Restore_Database_Adapter ? $database : new DD_Maintenance_Restore_Database_Adapter( $wpdb );
		$warnings   = array();
		$has_prefix = '' !== $dump_prefix;
		if ( ! $has_prefix && is_object( $wpdb ) && isset( $wpdb->prefix ) ) {
			$dump_prefix = (string) $wpdb->prefix;
			$has_prefix  = '' !== $dump_prefix;
		}
		if ( $has_prefix && ! DD_Maintenance_Restore_Database_Adapter::is_safe_identifier( $dump_prefix ) ) {
			$warnings[] = 'O prefixo dos caches do Elementor é inválido.';
			self::record_elementor_cache_failure( $warnings, $event_context );
			return $warnings;
		}
		$options_table  = $has_prefix ? $dump_prefix . 'options' : '';
		$postmeta_table = $has_prefix ? $dump_prefix . 'postmeta' : '';
		if ( $has_prefix && is_object( $wpdb ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $postmeta_table ) ) ) {
			if ( ! $database->execute( "DELETE FROM `{$postmeta_table}` WHERE `meta_key` IN ('_elementor_css', '_elementor_element_cache', '_elementor_inline_svg', '_elementor_page_assets')" ) ) {
				$warnings[] = 'Falha ao limpar metadados de cache do Elementor.';
			}
		}
		if ( $has_prefix && is_object( $wpdb ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_table ) ) ) {
			$queries = array(
				"DELETE FROM `{$options_table}` WHERE `option_name` LIKE '%_elementor_%' AND (`option_name` LIKE '%_transient_%' OR `option_name` LIKE '%_cache%')",
				"DELETE FROM `{$options_table}` WHERE `option_name` IN ('_elementor_global_css', '_elementor_assets_data', 'elementor_remote_info_library')",
			);
			$now = (string) time();
			$queries[] = $database->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_global_css_time', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $now, $now );
			$queries[] = $database->prepare( "INSERT INTO `{$options_table}` (`option_name`, `option_value`, `autoload`) VALUES ('elementor_css_version', %s, 'yes') ON DUPLICATE KEY UPDATE `option_value` = %s", $now, $now );
			foreach ( $queries as $sql ) {
				if ( ! $database->execute( $sql ) ) {
					$warnings[] = 'Falha ao atualizar caches persistidos do Elementor.';
				}
			}
		}

		$upload_dir_base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : ( defined( 'ABSPATH' ) ? ABSPATH . 'wp-content/uploads' : '' );
		$css_dir = $upload_dir_base . '/elementor/css';
		if ( is_dir( $css_dir ) ) {
			$files = glob( $css_dir . '/*.css' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && ! unlink( $file ) ) {
						$warnings[] = 'Falha ao remover arquivo CSS compilado do Elementor.';
					}
				}
			}
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				$cache_result = \Elementor\Plugin::$instance->files_manager->clear_cache();
				if ( false === $cache_result ) {
					$warnings[] = 'O Files Manager do Elementor recusou a limpeza de cache.';
				}
			} catch ( Throwable $e ) {
				$warnings[] = 'O Files Manager do Elementor não pôde limpar o cache.';
			}
		}
		self::record_elementor_cache_failure( $warnings, $event_context );
		return $warnings;

	}

	/**
	 * Registra falhas do cleanup do Elementor sem incluir SQL ou mensagens arbitrárias.
	 *
	 * @param string[] $warnings       Avisos internos conhecidos.
	 * @param array    $event_context  Contexto operacional seguro.
	 */
	private static function record_elementor_cache_failure( array $warnings, array $event_context ): void {
		if ( empty( $warnings ) || ! class_exists( 'DD_Maintenance' ) || ! method_exists( 'DD_Maintenance', 'record_event' ) ) {
			return;
		}
		DD_Maintenance::record_event(
			'restore',
			'elementor_cache_clear_failed',
			array(
				'step'           => $event_context['step'] ?? 'restore_finalize',
				'session_id'     => $event_context['session_id'] ?? '',
				'correlation_id' => $event_context['correlation_id'] ?? '',
				'status'         => 'failure',
				'failure_code'   => 'elementor_cache_clear_failed',
				'error_count'    => count( $warnings ),
				'warning_count'  => count( $warnings ),
			)
		);
	}
	/**
	 * Registra falhas conhecidas do loader MU sem incluir conteúdo arbitrário.
	 *
	 * @param string $event          Evento de falha.
	 * @param array  $event_context  Contexto operacional seguro.
	 */
	private static function record_mu_plugin_loader_failure( string $event, array $event_context = array() ): void {
		if ( ! class_exists( 'DD_Maintenance' ) || ! method_exists( 'DD_Maintenance', 'record_event' ) ) {
			return;
		}
		DD_Maintenance::record_event(
			'restore',
			$event,
			array(
				'step'           => $event_context['step'] ?? 'restore_init',
				'session_id'     => $event_context['session_id'] ?? '',
				'correlation_id' => $event_context['correlation_id'] ?? '',
				'status'         => 'failure',
				'failure_code'   => $event,
				'error_count'    => 1,
			)
		);
	}

	/**
	 * Cria um drop-in temporário em wp-content/mu-plugins/ para garantir que o DD Maintenance
	 * permaneça carregado pelo WordPress mesmo enquanto o banco de dados está sendo reconstruído.
	 *
	 * @param array $event_context Contexto operacional seguro.
	 */
	public static function create_mu_plugin_loader( array $event_context = array() ): bool {
		$loader = new DD_Maintenance_Restore_Mu_Loader();
		if ( $loader->create() ) {
			return true;
		}
		self::record_mu_plugin_loader_failure( 'mu_loader_create_failed', $event_context );
		return false;
	}

	/**
	 * Instala um drop-in permanente em mu-plugins para blindar o Elementor no site restaurado contra erros de PHP 8.0+.
	 */
	public static function install_permanent_elementor_shield(): void {
		DD_Maintenance_Elementor_Compatibility::install_permanent_elementor_shield();
	}

	/**
	 * Aplica correcao direta no arquivo do Elementor caso detecte a assinatura incompativel com PHP 8.2.
	 */
	public static function patch_elementor_php8_compatibility(): bool {
		return DD_Maintenance_Elementor_Compatibility::patch_elementor_php8_compatibility();
	}

	/**
	 * Remove o drop-in temporário do mu-plugins.
	 *
	 * @param array $event_context Contexto operacional seguro.
	 * @return bool
	 */
	public static function remove_mu_plugin_loader( array $event_context = array() ): bool {
		$loader = new DD_Maintenance_Restore_Mu_Loader();
		if ( $loader->remove() ) {
			return true;
		}
		self::record_mu_plugin_loader_failure( 'mu_loader_remove_failed', $event_context );
		return false;
	}
}
