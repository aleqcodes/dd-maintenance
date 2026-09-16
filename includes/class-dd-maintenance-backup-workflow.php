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
	 * @param string $correlation_id Correlação da operação chamadora.
	 * @return DD_Maintenance_Backup_Result|WP_Error
	 */
	public function create( string $correlation_id = '' ) {
		$started_at  = microtime( true );
		$start_event = DD_Maintenance::record_event( 'backup', 'operation_started', array( 'step' => 'init', 'status' => 'running', 'correlation_id' => $correlation_id ) );
		$correlation_id = $start_event['correlation_id'];
		$session        = $this->step( 'init', '' );
		if ( is_wp_error( $session ) ) {
			DD_Maintenance::record_event(
				'backup',
				'operation_failed',
				array(
					'step'          => 'init',
					'status'        => 'failure',
					'failure_code'  => $session->get_error_code(),
					'error_count'   => 1,
					'duration_ms'   => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'correlation_id' => $correlation_id,
				)
			);
			return $session;
		}

		$session_id = $session['session_id'];
		$result     = array();
		DD_Maintenance::record_event( 'backup', 'session_created', array( 'step' => 'init', 'session_id' => $session_id, 'status' => 'running', 'correlation_id' => $correlation_id ) );
		foreach ( array( 'database', 'index', 'zip', 'finalize' ) as $step ) {
			do {
				$result = $this->step( $step, $session_id );
				if ( is_wp_error( $result ) ) {
					$this->cleanup_failed( $session_id, $result->get_error_message() );
					DD_Maintenance::record_event(
						'backup',
						'operation_failed',
						array(
							'step'           => $step,
							'session_id'     => $session_id,
							'status'         => 'failure',
							'failure_code'   => $result->get_error_code(),
							'error_count'    => 1,
							'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
							'correlation_id' => $correlation_id,
						)
					);
					return $result;
				}
			} while ( ! $this->is_completed( $result ) );
		}

		$this->cleanup( $session_id );
		DD_Maintenance::record_event(
			'backup',
			'operation_finished',
			array(
				'step'           => 'finalize',
				'session_id'     => $session_id,
				'status'         => 'success',
				'progress'       => 100,
				'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'correlation_id' => $correlation_id,
			)
		);
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
	 * @param array  $parts          Partes com file, name e size.
	 * @param string $folder         Pasta remota.
	 * @param int    $total_size     Tamanho total.
	 * @param string $correlation_id Correlação da operação chamadora.
	 * @return DD_Maintenance_Storage_Upload_Result
	 */
	public function upload_parts( array $parts, string $folder, int $total_size = 0, string $correlation_id = '' ): DD_Maintenance_Storage_Upload_Result {
		$started_at  = microtime( true );
		$start_event = DD_Maintenance::record_event(
			'backup',
			'upload_started',
			array(
				'step'           => 'upload',
				'status'         => 'running',
				'bytes_processed' => 0,
				'correlation_id' => $correlation_id,
				'context'        => array( 'parts_total' => count( $parts ) ),
			)
		);
		$correlation_id = $start_event['correlation_id'];
		$result         = new DD_Maintenance_Storage_Upload_Result();
		$result->total  = count( $parts );
		$result->success = false;
		$result->total_size = $total_size;

		if ( ! $this->s3->is_configured() ) {
			$result->errors[] = __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' );
			DD_Maintenance::record_event(
				'backup',
				'upload_failed',
				array(
					'step'           => 'upload',
					'status'         => 'failure',
					'failure_code'   => 's3_not_configured',
					'error_count'    => 1,
					'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'correlation_id' => $correlation_id,
				)
			);
			return $result;
		}

		$processed_bytes = 0;
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
				DD_Maintenance::record_event(
					'backup',
					'upload_failed',
					array(
						'step'           => 'upload',
						'status'         => 'failure',
						'failure_code'   => $upload->get_error_code(),
						'error_count'    => 1,
						'bytes_processed'=> $processed_bytes,
						'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
						'correlation_id' => $correlation_id,
						'part_index'     => $index + 1,
					)
				);
				return $result;
			}

			$result->uploaded++;
			$processed_bytes += isset( $part['size'] ) ? (int) $part['size'] : 0;
			$result->logs[] = sprintf(
				/* translators: 1: Índice da parte, 2: Total de partes, 3: Nome */
				__( '[OK] Parte %1$d/%2$d enviada: %3$s', 'dd-maintenance' ),
				$result->uploaded,
				$result->total,
				$part['name']
			);
			DD_Maintenance::record_event(
				'backup',
				'upload_part_finished',
				array(
					'step'            => 'upload',
					'status'          => 'running',
					'bytes_processed' => $processed_bytes,
					'progress'        => $result->total > 0 ? (int) floor( $result->uploaded / $result->total * 100 ) : 100,
					'correlation_id'  => $correlation_id,
					'part_index'      => $index + 1,
				)
			);
		}

		$result->success = true;
		DD_Maintenance::record_event(
			'backup',
			'upload_finished',
			array(
				'step'            => 'upload',
				'status'          => 'success',
				'progress'        => 100,
				'bytes_processed' => $processed_bytes,
				'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'correlation_id'  => $correlation_id,
			)
		);
		return $result;
	}

	/**
	 * Executa a manutenção completa usando o mesmo fluxo de backup e upload.
	 *
	 * @param callable|null $retention_callback Política de retenção opcional.
	 * @return array Log de execução.
	 */
	public function run_full( $retention_callback = null ): array {
		$started_at     = microtime( true );
		$start_event    = DD_Maintenance::record_event( 'backup', 'full_operation_started', array( 'step' => 'init', 'status' => 'running' ) );
		$correlation_id = $start_event['correlation_id'];
		$log = array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) );

		$config_status = DD_Maintenance_Config::get_wp_config_status();
		$file_mods     = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		if ( true === $file_mods ) {
			$log[] = '[Aviso] DISALLOW_FILE_MODS está ATIVO no wp-config.php. Se as atualizações falharem, desative-o na aba "Travas wp-config.php".';
		}

		$backup_result = $this->create( $correlation_id );
		if ( is_wp_error( $backup_result ) ) {
			$log[] = '[ERRO] Backup: ' . $backup_result->get_error_message();
			$log[] = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			DD_Maintenance::record_event(
				'backup',
				'full_operation_failed',
				array(
					'step'           => 'backup',
					'status'         => 'failure',
					'failure_code'   => $backup_result->get_error_code(),
					'error_count'    => 1,
					'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'correlation_id' => $correlation_id,
				)
			);
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

		$upload_result = $this->upload_parts( $parts, $folder, (int) $total_size, $correlation_id );
		$log          = array_merge( $log, $upload_result->logs );
		if ( ! $upload_result->success ) {
			$log = array_merge( $log, array_map( static function ( $error ) { return '[ERRO] ' . $error; }, $upload_result->errors ) );
			$log[] = '[Fim com Erro no S3] ' . current_time( 'Y-m-d H:i:s' );
			DD_Maintenance::record_event(
				'backup',
				'full_operation_failed',
				array(
					'step'            => 'upload',
					'status'          => 'failure',
					'failure_code'    => 's3_upload_failed',
					'error_count'     => count( $upload_result->errors ),
					'bytes_processed' => (int) $total_size,
					'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
					'correlation_id'  => $correlation_id,
				)
			);
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
		$error_count = count( array_filter( $log, static function ( $line ) { return 0 === strpos( $line, '[ERRO]' ); } ) );
		$status = $error_count > 0 ? 'failure' : ( count( array_filter( $log, static function ( $line ) { return 0 === strpos( $line, '[Aviso]' ); } ) ) > 0 ? 'warning' : 'success' );
		DD_Maintenance::record_event(
			'backup',
			'full_operation_finished',
			array(
				'step'            => 'core',
				'status'          => $status,
				'progress'        => 100,
				'bytes_processed' => (int) $total_size,
				'error_count'     => $error_count,
				'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'correlation_id'  => $correlation_id,
			)
		);
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
