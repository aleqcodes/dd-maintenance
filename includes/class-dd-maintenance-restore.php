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

			// Se for um arquivo do tipo part002 sem as outras partes.
			if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $single_file ), $m ) ) {
				$part_num = (int) $m[1];
				if ( $part_num > 1 ) {
					$this->delete_directory( $temp_dir );
					return new WP_Error(
						'restore_missing_other_parts',
						sprintf(
							/* translators: %s: Nome da parte */
							__( 'Você enviou apenas a parte %s. Em backups divididos em partes de 25MB, envie todas as partes juntas no campo de upload.', 'dd-maintenance' ),
							basename( $single_file )
						)
					);
				}
			}

			$result = $this->restore_archive( $single_file );
			$this->delete_directory( $temp_dir );
			return $result;
		}

		// Múltiplos arquivos enviados: junta as partes em um único .zip.
		$merged_zip = $temp_dir . '/merged_backup_' . time() . '.zip';
		$join_result = $this->join_part_files( $uploaded_files, $merged_zip );

		if ( is_wp_error( $join_result ) ) {
			$this->delete_directory( $temp_dir );
			return $join_result;
		}

		$result = $this->restore_archive( $merged_zip );

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

		// Se só tiver 1 parte (ex: part001 apenas), mas o arquivo completo era só ela.
		if ( 1 === count( $part_files ) && preg_match( '/\.part001\.zip$/i', $part_files[0] ) ) {
			// Junta ou executa direto
		}

		$temp_dir = $backup_dir . '/local-restore-' . time() . '-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'restore_temp_failed', __( 'Não foi possível criar pasta temporária para juntar as partes.', 'dd-maintenance' ) );
		}

		$merged_zip  = $temp_dir . '/merged_' . $base_name . '.zip';
		$join_result = $this->join_part_files( $part_files, $merged_zip );

		if ( is_wp_error( $join_result ) ) {
			$this->delete_directory( $temp_dir );
			return $join_result;
		}

		$result = $this->restore_archive( $merged_zip );

		// Remove o zip temporário unificado (as partes originais permanecem na pasta de backups).
		$this->delete_directory( $temp_dir );

		return $result;
	}

	/**
	 * Junta várias partes (.part001.zip, .part002.zip, ...) em um arquivo .zip unificado.
	 *
	 * @param array  $part_files  Lista de caminhos absolutos das partes.
	 * @param string $output_file Caminho do arquivo unificado de saída.
	 * @return true|WP_Error
	 */
	public function join_part_files( array $part_files, string $output_file ) {
		if ( empty( $part_files ) ) {
			return new WP_Error( 'join_empty_list', __( 'Nenhuma parte fornecida para junção.', 'dd-maintenance' ) );
		}

		// Ordena as partes numericamente pelo sufixo .partXXX.zip.
		usort(
			$part_files,
			function( $a, $b ) {
				$num_a = 0;
				$num_b = 0;
				if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $a ), $ma ) ) {
					$num_a = (int) $ma[1];
				}
				if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $b ), $mb ) ) {
					$num_b = (int) $mb[1];
				}
				return $num_a - $num_b;
			}
		);

		// Valida se a sequência começa em 1 e não tem partes faltando.
		$expected = 1;
		foreach ( $part_files as $file ) {
			if ( preg_match( '/\.part(\d+)\.zip$/i', basename( $file ), $m ) ) {
				$current = (int) $m[1];
				if ( $current !== $expected ) {
					return new WP_Error(
						'join_sequence_missing',
						sprintf(
							/* translators: 1: Parte esperada, 2: Parte encontrada */
							__( 'Sequência de partes incompleta: era esperada a parte %1$03d, mas foi encontrada a parte %2$03d.', 'dd-maintenance' ),
							$expected,
							$current
						)
					);
				}
				$expected++;
			}
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
		$this->set_time_and_memory_limits();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'restore_zip_missing', __( 'A extensão PHP ZipArchive não está disponível no servidor.', 'dd-maintenance' ) );
		}

		if ( ! file_exists( $zip_path ) || filesize( $zip_path ) <= 0 ) {
			return new WP_Error( 'restore_zip_invalid', __( 'Arquivo de backup inválido ou vazio.', 'dd-maintenance' ) );
		}

		$backup_dir  = DD_Maintenance::backup_dir();
		$extract_dir = $backup_dir . '/temp-restore-' . time() . '-' . wp_generate_password( 8, false );

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'restore_mkdir_failed', __( 'Não foi possível criar a pasta temporária de extração.', 'dd-maintenance' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$this->delete_directory( $extract_dir );
			return new WP_Error( 'restore_zip_open_failed', __( 'Falha ao abrir o arquivo .zip de backup.', 'dd-maintenance' ) );
		}

		$extracted = $zip->extractTo( $extract_dir );
		$zip->close();

		if ( ! $extracted ) {
			$this->delete_directory( $extract_dir );
			return new WP_Error( 'restore_extract_failed', __( 'Falha ao descompactar os arquivos do backup.', 'dd-maintenance' ) );
		}

		$log = array(
			'[Início da Restauração] ' . current_time( 'Y-m-d H:i:s' ),
			'[Arquivo] ' . basename( $zip_path ) . ' (' . size_format( filesize( $zip_path ) ) . ')',
		);

		// 1. Localiza e restaura o banco de dados (database.sql ou qualquer arquivo .sql).
		$sql_file = $this->find_sql_file( $extract_dir );
		$db_stats = null;

		if ( $sql_file ) {
			$log[] = '[Banco] Arquivo SQL detectado: ' . basename( $sql_file );
			$db_result = $this->restore_database( $sql_file );

			if ( is_wp_error( $db_result ) ) {
				$this->delete_directory( $extract_dir );
				return $db_result;
			}

			$db_stats = $db_result;
			$log[]    = sprintf(
				/* translators: 1: Quantidade de queries, 2: Tabelas afetadas */
				__( '[OK] Banco restaurado com sucesso: %1$d comandos executados (%2$d tabelas processadas).', 'dd-maintenance' ),
				$db_result['queries'],
				$db_result['tables']
			);
		} else {
			$log[] = '[Aviso] Nenhum arquivo .sql encontrado no backup (banco de dados não alterado).';
		}

		// 2. Restaura os arquivos (site/ ou wp-content/ ou wp-config.php).
		$files_result = $this->restore_files( $extract_dir );
		if ( is_wp_error( $files_result ) ) {
			$this->delete_directory( $extract_dir );
			return $files_result;
		}

		$log[] = sprintf(
			/* translators: %d: Quantidade de arquivos restaurados */
			__( '[OK] Arquivos restaurados com sucesso: %d arquivos copiados.', 'dd-maintenance' ),
			$files_result['copied']
		);

		// Limpa a pasta temporária de extração.
		$this->delete_directory( $extract_dir );

		$log[] = '[Fim da Restauração] ' . current_time( 'Y-m-d H:i:s' );

		return array(
			'success'  => true,
			'log'      => $log,
			'db_stats' => $db_stats,
			'files'    => $files_result['copied'],
		);
	}

	/**
	 * Localiza o arquivo SQL dentro da pasta extraída.
	 *
	 * @param string $extract_dir Pasta raiz da extração.
	 * @return string|null Caminho completo do arquivo SQL ou null.
	 */
	private function find_sql_file( string $extract_dir ): ?string {
		if ( file_exists( $extract_dir . '/database.sql' ) ) {
			return $extract_dir . '/database.sql';
		}

		$files = glob( $extract_dir . '/*.sql' );
		if ( ! empty( $files ) && is_file( $files[0] ) ) {
			return $files[0];
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
		$groups     = array();

		if ( empty( $files ) ) {
			return array();
		}

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
					);
				}

				$groups[ $base_name ]['parts'][ $part_num ] = array(
					'filename' => $filename,
					'path'     => $file,
					'size'     => $size,
					'part'     => $part_num,
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
								'filename' => $filename,
								'path'     => $file,
								'size'     => $size,
								'part'     => 1,
							),
						),
						'total_size'    => $size,
						'latest_mtime'  => $mtime,
					);
				}
			}
		}

		$backups = array();
		foreach ( $groups as $base => $data ) {
			$count = count( $data['parts'] );
			$mtime = $data['latest_mtime'];

			$backups[] = array(
				'identifier'     => $base,
				'display_name'   => $data['is_multipart'] ? sprintf( '%s (%d partes de 25MB)', $base, $count ) : $data['display_name'],
				'is_multipart'   => $data['is_multipart'],
				'total_parts'    => $count,
				'parts'          => $data['parts'],
				'size'           => $data['total_size'],
				'size_formatted' => size_format( $data['total_size'] ),
				'timestamp'      => $mtime,
				'date_formatted' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ), 'd/m/Y H:i:s' ),
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
