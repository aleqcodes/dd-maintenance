<?php
/**
 * Orquestra a manutenção agendada em etapas persistidas.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DD_Maintenance_Updater' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-updater.php';
}
if ( ! class_exists( 'DD_Maintenance_Backup_Workflow' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-backup-workflow.php';
}
if ( ! class_exists( 'DD_Maintenance_Cron_Job_Store' ) ) {
	require_once __DIR__ . '/class-dd-maintenance-cron-job-store.php';
}

class DD_Maintenance_Cron_Workflow {

	private $backup_workflow;
	private $job_store;
	private $retention_callback;

	/**
	 * @param DD_Maintenance_Backup_Workflow|null $backup_workflow Caso de uso de backup.
	 * @param DD_Maintenance_Cron_Job_Store|null $job_store Persistência do job.
	 * @param callable|null $retention_callback Política de retenção.
	 */
	public function __construct( $backup_workflow = null, $job_store = null, $retention_callback = null ) {
		$this->backup_workflow   = $backup_workflow instanceof DD_Maintenance_Backup_Workflow ? $backup_workflow : new DD_Maintenance_Backup_Workflow();
		$this->job_store          = $job_store instanceof DD_Maintenance_Cron_Job_Store ? $job_store : new DD_Maintenance_Cron_Job_Store();
		$this->retention_callback = is_callable( $retention_callback ) ? $retention_callback : null;
	}

	/**
	 * Inicia uma execução agendada e devolve o controle ao WP-Cron.
	 *
	 * @return void
	 */
	public function start(): void {
		$active = $this->job_store->get();
		$now    = time();
		$correlation_id = isset( $active['correlation_id'] ) ? (string) $active['correlation_id'] : '';
		$start_event = DD_Maintenance::record_event(
			'backup',
			'cron_started',
			array(
				'step'           => 'cron_start',
				'status'         => 'running',
				'session_id'     => $active['session_id'] ?? '',
				'correlation_id' => $correlation_id,
			)
		);
		$correlation_id = $start_event['correlation_id'];
		if ( isset( $active['status'], $active['session_id'], $active['started_at'] ) && 'running' === $active['status'] ) {
			if ( ( $now - (int) $active['started_at'] ) < 3600 ) {
				$active['correlation_id'] = $correlation_id;
				if ( ! $this->save_job( $active, $active['phase'] ?? 'resume' ) ) {
					return;
				}
				DD_Maintenance::record_event( 'backup', 'cron_resumed', array( 'step' => $active['phase'] ?? 'unknown', 'status' => 'running', 'session_id' => $active['session_id'], 'correlation_id' => $correlation_id ) );
				return;
			}
			$this->backup_workflow->cleanup_failed( $active['session_id'], __( 'Job agendado expirado por tempo limite.', 'dd-maintenance' ) );
			DD_Maintenance::record_event( 'backup', 'cron_expired', array( 'step' => $active['phase'] ?? 'unknown', 'status' => 'failure', 'session_id' => $active['session_id'], 'failure_code' => 'cron_timeout', 'error_count' => 1, 'correlation_id' => $correlation_id ) );
		}

		$session = $this->backup_workflow->step( 'init', '', $correlation_id );
		if ( is_wp_error( $session ) ) {
			$log = array( '[ERRO] Backup: ' . $session->get_error_message() );
			set_transient( 'dd_maintenance_last_log', $log, DAY_IN_SECONDS );
			set_transient( 'backuper_last_log', $log, DAY_IN_SECONDS );
			DD_Maintenance::record_event( 'backup', 'cron_failed', array( 'step' => 'init', 'status' => 'failure', 'failure_code' => $session->get_error_code(), 'error_count' => 1, 'correlation_id' => $correlation_id ) );
			return;
		}

		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$job       = array(
			'status'       => 'running',
			'phase'        => 'database',
			'session_id'   => $session['session_id'],
			'session_dir'  => $session['session_dir'],
			'base_name'    => $session['base_name'],
			'folder'       => ( $site_slug ? $site_slug : 'site' ) . '/' . current_time( 'Y-m-d' ),
			'parts'        => array(),
			'upload_index' => 0,
			'total_size'   => 0,
			'started_at'   => time(),
			'correlation_id'=> $correlation_id,
			'log'          => array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) ),
		);
		if ( ! $this->save_job( $job, 'database' ) ) {
			return;
		}
		DD_Maintenance::record_event( 'backup', 'cron_session_created', array( 'step' => 'database', 'status' => 'running', 'session_id' => $session['session_id'], 'correlation_id' => $correlation_id ) );
		$this->schedule_continuation( $session['session_id'] );
	}

	/**
	 * Executa exatamente uma etapa persistida.
	 *
	 * @param string $session_id Sessão.
	 * @return void
	 */
	public function continue( string $session_id ): void {
		$job = $this->job_store->get();
		if ( 'running' !== ( $job['status'] ?? '' ) || $session_id !== ( $job['session_id'] ?? '' ) ) {
			return;
		}
		$phase          = (string) ( $job['phase'] ?? 'unknown' );
		$started_at     = microtime( true );
		$correlation_id = (string) ( $job['correlation_id'] ?? '' );
		DD_Maintenance::record_event(
			'backup',
			'cron_step_started',
			array(
				'step'           => $phase,
				'status'         => 'running',
				'session_id'     => $session_id,
				'correlation_id' => $correlation_id,
			)
		);

		$result = true;
		switch ( $job['phase'] ) {
			case 'database':
				$result = $this->backup_workflow->step( 'database', $session_id );
				if ( ! is_wp_error( $result ) && $result->completed ) {
					$job['phase'] = 'index';
					$job['log'][] = $result->log;
				}
				break;
			case 'index':
				$result = $this->backup_workflow->step( 'index', $session_id );
				if ( ! is_wp_error( $result ) && $result->completed ) {
					$job['phase'] = 'zip';
					$job['log'][] = $result->log;
				}
				break;
			case 'zip':
				$result = $this->backup_workflow->step( 'zip', $session_id );
				if ( ! is_wp_error( $result ) && $result->completed ) {
					$job['phase'] = 'finalize';
					$job['log'][] = $result->log;
				}
				break;
			case 'finalize':
				$result = $this->backup_workflow->step( 'finalize', $session_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['completed'] ) ) {
					$job['phase']      = 'upload';
					$job['parts']      = $result['parts'];
					$job['total_size'] = $result['total_size'];
					$job['log'][]      = $result['log'];
				}
				break;
			case 'upload':
				$index = (int) $job['upload_index'];
				if ( $index < count( $job['parts'] ) ) {
					$part   = $job['parts'][ $index ];
					$result = $this->backup_workflow->upload_parts( array( $part ), $job['folder'], (int) $part['size'], $correlation_id );
					if ( $result->success ) {
						$job['upload_index']++;
						$job['log'] = array_merge( $job['log'], $result->logs );
					} else {
						$result = new WP_Error( 'cron_upload_failed', implode( ' ', $result->errors ) );
					}
				}
				if ( ! is_wp_error( $result ) && $job['upload_index'] >= count( $job['parts'] ) ) {
					$job['phase'] = 'retention';
				}
				break;
			case 'retention':
				$purged = is_callable( $this->retention_callback ) ? call_user_func( $this->retention_callback ) : array();
				$job['log'][] = sprintf( __( '[Retenção] %d backup(s) antigo(s) removido(s).', 'dd-maintenance' ), count( $purged ) );
				$this->backup_workflow->cleanup( $session_id );
				$job['phase'] = 'plugins';
				break;
			case 'plugins':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_plugins();
				$job['log'][] = is_wp_error( $result ) ? '[ERRO] Plugins: ' . $result->get_error_message() : '[OK] Plugins atualizados: ' . $result['updated'];
				$job['phase'] = 'core';
				$result = true;
				break;
			case 'core':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_core();
				$job['log'][]      = is_wp_error( $result ) ? '[ERRO] Core: ' . $result->get_error_message() : '[Core] ' . $result['message'];
				$job['log'][]      = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
				$job['phase']      = 'done';
				$job['status']     = 'completed';
				$job['finished_at'] = time();
				$result = true;
				break;
		}
		$event_status = is_wp_error( $result ) ? 'failure' : ( 'completed' === $job['status'] ? 'success' : 'running' );
		DD_Maintenance::record_event(
			'backup',
			'cron_step_finished',
			array(
				'step'            => $phase,
				'status'          => $event_status,
				'session_id'      => $session_id,
				'correlation_id'  => $correlation_id,
				'error_count'     => is_wp_error( $result ) ? 1 : 0,
				'failure_code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
				'bytes_processed' => (int) ( $job['total_size'] ?? 0 ),
				'duration_ms'      => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$job['status']      = 'error';
			$job['finished_at'] = time();
			$job['log'][]       = '[ERRO] ' . $result->get_error_message();
			$job['log'][]       = '[AUTOLIMPEZA] Backup encerrado por erro. Arquivos residuais limpos.';
			$job['log'][]       = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			$this->backup_workflow->cleanup_failed( $session_id, $result->get_error_message(), $job['log'] );
		} elseif ( 'completed' === $job['status'] ) {
			DD_Maintenance::save_log( $job['log'], 'success', $job['base_name'] ?? '' );
		}

		if ( ! $this->save_job( $job, $phase ) ) {
			return;
		}
		if ( 'running' === $job['status'] ) {
			$this->schedule_continuation( $session_id );
		}
	}

	/**
	 * Persiste o job e registra falha de checkpoint sem avançar o cron.
	 *
	 * @param array  $job  Estado do job.
	 * @param string $step Etapa em execução.
	 * @return bool
	 */
	private function save_job( array $job, string $step ): bool {
		if ( $this->job_store->save( $job ) ) {
			return true;
		}
		DD_Maintenance::record_event(
			'backup',
			'checkpoint_failed',
			array(
				'step'           => $step,
				'session_id'     => $job['session_id'] ?? '',
				'correlation_id' => $job['correlation_id'] ?? '',
				'status'         => 'failure',
				'failure_code'   => 'cron_job_save_failed',
				'error_count'    => 1,
			)
		);
		return false;
	}

	/**
	 * @param string $session_id Sessão.
	 * @return void
	 */
	private function schedule_continuation( string $session_id ): void {
		if ( ! wp_next_scheduled( 'dd_maintenance_backup_continue', array( $session_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'dd_maintenance_backup_continue', array( $session_id ) );
		}
	}
}
