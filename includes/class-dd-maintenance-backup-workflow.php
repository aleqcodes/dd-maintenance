<?php
/**
 * Caso de uso compartilhado para criação e envio de backups.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Backup' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-backup.php';
}
if ( ! class_exists( 'DD_Maintenance_S3' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-s3.php';
}
if ( ! class_exists( 'DD_Maintenance_Backup_Result' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-backup-result.php';
}
if ( ! class_exists( 'DD_Maintenance_Storage_Upload_Result' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-storage-upload-result.php';
}
if ( ! class_exists( 'DD_Maintenance_Progress' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-progress.php';
}
if ( ! class_exists( 'DD_Maintenance_Config' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-config.php';
}
if ( ! class_exists( 'DD_Maintenance_Updater' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-updater.php';
}

class DD_Maintenance_Backup_Workflow {

	private $backup;
	private $s3;

	/**
	 * @param DD_Maintenance_Backup|null $backup Serviço de criação.
	 * @param DD_Maintenance_S3|null    $s3 Serviço de armazenamento.
	 */
	public function __construct( $backup = null, $s3 = null ) {
		$this->backup = $backup instanceof DD_Maintenance_Backup ? $backup : new DD_Maintenance_Backup();
		$this->s3     = $s3 instanceof DD_Maintenance_S3 ? $s3 : new DD_Maintenance_S3();
	}

	/**
	 * Executa criação incremental usando a mesma sequência do AJAX e do cron.
	 *
	 * @return DD_Maintenance_Backup_Result|WP_Error
	 */
	public function create() {
		$session = $this->step( 'init', '' );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$session_id = $session['session_id'];
		$result     = array();
		foreach ( array( 'database', 'index', 'zip', 'finalize' ) as $step ) {
			do {
				$result = $this->step( $step, $session_id );
				if ( is_wp_error( $result ) ) {
					$this->cleanup_failed( $session_id, $result->get_error_message() );
					return $result;
				}
			} while ( ! $this->is_completed( $result ) );
		}

		$this->cleanup( $session_id );
		return DD_Maintenance_Backup_Result::from_array( $result );
	}

	/**
	 * Executa uma etapa incremental do backup.
	 *
	 * @param string $step       Nome da etapa.
	 * @param string $session_id ID da sessão.
	 * @return array|DD_Maintenance_Progress|WP_Error
	 */
	public function step( string $step, string $session_id ) {
		switch ( $step ) {
			case 'init':
				return $this->backup->init_session();
			case 'database':
				return $this->normalize_progress( $this->backup->dump_database_step( $session_id ) );
			case 'index':
				return $this->normalize_progress( $this->backup->index_files_step( $session_id ) );
			case 'zip':
				return $this->normalize_progress( $this->backup->zip_batch_step( $session_id ) );
			case 'finalize':
				return $this->backup->finalize_and_split_step( $session_id );
			default:
				return new WP_Error( 'backup_step_invalid', __( 'Etapa de backup inválida.', 'dd-maintenance' ) );
		}
	}

	/**
	 * Normaliza progresso sem remover campos específicos de cada etapa.
	 *
	 * @param array|WP_Error $result Resultado legado.
	 * @return DD_Maintenance_Progress|WP_Error
	 */
	private function normalize_progress( $result ) {
		return is_wp_error( $result ) ? $result : DD_Maintenance_Progress::from_array( $result );
	}
	/**
	 * Verifica o marcador de conclusão dos resultados de etapas.
	 *
	 * @param array|DD_Maintenance_Progress $result Resultado da etapa.
	 * @return bool
	 */
	private function is_completed( $result ): bool {
		return is_object( $result ) ? ! empty( $result->completed ) : ! empty( $result['completed'] );
	}

	/**
	 * Envia partes usando o mesmo caso de uso para execução manual, AJAX e cron.
	 *
	 * @param array  $parts       Partes com file, name e size.
	 * @param string $folder      Pasta remota.
	 * @param int    $total_size  Tamanho total.
	 * @return DD_Maintenance_Storage_Upload_Result
	 */
	public function upload_parts( array $parts, string $folder, int $total_size = 0 ): DD_Maintenance_Storage_Upload_Result {
		$result            = new DD_Maintenance_Storage_Upload_Result();
		$result->total     = count( $parts );
		$result->success   = false;
		$result->total_size = $total_size;

		if ( ! $this->s3->is_configured() ) {
			$result->errors[] = __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' );
			return $result;
		}

		foreach ( $parts as $index => $part ) {
			$key    = $folder . '/' . $part['name'];
			$upload = $this->s3->put_object( $key, $part['file'] );
			if ( is_wp_error( $upload ) ) {
				$result->errors[] = sprintf(
					/* translators: 1: Índice da parte, 2: Total de partes, 3: Nome, 4: Erro */
					__( 'Erro no envio da parte %1$d/%2$d (%3$s): %4$s', 'dd-maintenance' ),
					$index + 1,
					$result->total,
					$part['name'],
					$upload->get_error_message()
				);
				return $result;
			}

			$result->uploaded++;
			$result->logs[] = sprintf(
				/* translators: 1: Índice da parte, 2: Total de partes, 3: Nome */
				__( '[OK] Parte %1$d/%2$d enviada: %3$s', 'dd-maintenance' ),
				$result->uploaded,
				$result->total,
				$part['name']
			);
		}

		$result->success = true;
		return $result;
	}

	/**
	 * Executa a manutenção completa usando o mesmo fluxo de backup e upload.
	 *
	 * @param callable|null $retention_callback Política de retenção opcional.
	 * @return array Log de execução.
	 */
	public function run_full( $retention_callback = null ): array {
		$log = array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) );

		$config_status = DD_Maintenance_Config::get_wp_config_status();
		$file_mods     = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		if ( true === $file_mods ) {
			$log[] = '[Aviso] DISALLOW_FILE_MODS está ATIVO no wp-config.php. Se as atualizações falharem, desative-o na aba "Travas wp-config.php".';
		}

		$backup_result = $this->create();
		if ( is_wp_error( $backup_result ) ) {
			$log[] = '[ERRO] Backup: ' . $backup_result->get_error_message();
			$log[] = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			return $log;
		}

		$parts         = ! empty( $backup_result->parts ) ? $backup_result->parts : array( array( 'file' => $backup_result->file, 'name' => $backup_result->name, 'size' => $backup_result->size, 'part' => 1 ) );
		$total_parts   = count( $parts );
		$total_size    = $backup_result->total_size > 0 ? $backup_result->total_size : $backup_result->size;
		$chunk_size_mb = (int) $backup_result->chunk_size_mb;
		$log[]         = sprintf(
			/* translators: 1: Quantidade de partes, 2: Tamanho máximo configurado, 3: Tamanho total */
			__( '[OK] Backup criado com sucesso: %1$d parte(s) de até %2$d MB (Total: %3$s)', 'dd-maintenance' ),
			$total_parts,
			$chunk_size_mb,
			size_format( $total_size )
		);

		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$folder    = ( $site_slug ? $site_slug : 'site' ) . '/' . current_time( 'Y-m-d' );
		$log[]     = '[OK] Pasta de destino no S3: ' . $folder;

		$upload_result = $this->upload_parts( $parts, $folder, (int) $total_size );
		$log          = array_merge( $log, $upload_result->logs );
		if ( ! $upload_result->success ) {
			$log = array_merge( $log, array_map( static function ( $error ) { return '[ERRO] ' . $error; }, $upload_result->errors ) );
			$log[] = '[Fim com Erro no S3] ' . current_time( 'Y-m-d H:i:s' );
			return $log;
		}

		$log[] = sprintf(
			/* translators: 1: Quantidade de partes, 2: Nome do bucket, 3: Pasta no bucket */
			__( '[OK] Todas as %1$d parte(s) enviadas para o bucket "%2$s" em "%3$s".', 'dd-maintenance' ),
			$total_parts,
			$this->s3->get_bucket(),
			$folder
		);

		if ( is_callable( $retention_callback ) ) {
			$purged_backups = call_user_func( $retention_callback );
			if ( ! empty( $purged_backups ) ) {
				$log[] = sprintf(
					/* translators: %d: Quantidade removida */
					__( '[Retenção] %d backup(s) antigo(s) removido(s) conforme a política de retenção.', 'dd-maintenance' ),
					count( $purged_backups )
				);
			}
		}

		$updater = new DD_Maintenance_Updater();
		$plugins = $updater->update_plugins();
		if ( is_wp_error( $plugins ) ) {
			$log[] = '[ERRO] Plugins: ' . $plugins->get_error_message();
		} else {
			foreach ( $plugins['logs'] as $line ) {
				$log[] = '[Plugins] ' . $line;
			}
			$log[] = '[OK] Plugins atualizados: ' . $plugins['updated'];
		}

		$core = $updater->update_core();
		if ( is_wp_error( $core ) ) {
			$log[] = '[ERRO] Core: ' . $core->get_error_message();
		} elseif ( $core['updated'] ) {
			$log[] = '[OK] ' . $core['message'];
		} else {
			$log[] = '[Core] ' . $core['message'];
		}

		$log[] = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
		return $log;
	}


	/**
	 * Remove uma sessão interrompida.
	 *
	 * @param string $session_id Sessão.
	 * @param string $message    Motivo.
	 * @param array  $log        Log acumulado.
	 * @return array
	 */
	public function cleanup_failed( string $session_id, string $message = '', array $log = array() ): array {
		return $this->backup->cleanup_failed_session( $session_id, $message, $log );
	}

	/**
	 * Libera uma sessão concluída.
	 *
	 * @param string $session_id Sessão.
	 * @return true|WP_Error
	 */
	public function cleanup( string $session_id ) {
		return $this->backup->cleanup_session_step( $session_id );
	}

	/**
	 * @return DD_Maintenance_Backup
	 */
	public function backup_service(): DD_Maintenance_Backup {
		return $this->backup;
	}

	/**
	 * @return DD_Maintenance_S3
	 */
	public function storage_service(): DD_Maintenance_S3 {
		return $this->s3;
	}
}
