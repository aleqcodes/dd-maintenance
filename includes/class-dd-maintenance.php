<?php
/**
 * Classe principal do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-dd-maintenance-observability.php';
require_once __DIR__ . '/class-dd-maintenance-legacy-compatibility.php';
require_once __DIR__ . '/class-dd-maintenance-settings-repository.php';
require_once __DIR__ . '/class-dd-maintenance-cron-job-store.php';
require_once __DIR__ . '/class-dd-maintenance-elementor-compatibility.php';



class DD_Maintenance {

	/**
	 * Instância singleton.
	 *
	 * @var DD_Maintenance|null
	 */
	private static $instance = null;
	/**
	 * Repositório de configurações.
	 *
	 * @var DD_Maintenance_Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Persistência do job agendado.
	 *
	 * @var DD_Maintenance_Cron_Job_Store
	 */
	private $cron_job_store;
	/**
	 * Caso de uso de backup.
	 *
	 * @var DD_Maintenance_Backup_Workflow
	 */
	private $backup_workflow;


	/**
	 * Caso de uso do cron.
	 *
	 * @var DD_Maintenance_Cron_Workflow
	 */
	private $cron_workflow;


	/**
	 * Retorna a instância única.
	 *
	 * @return DD_Maintenance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construtor.
	 */
	private function __construct() {
		require_once __DIR__ . '/class-dd-maintenance-backup-workflow.php';
		require_once __DIR__ . '/class-dd-maintenance-restore-workflow.php';
		require_once __DIR__ . '/class-dd-maintenance-cron-workflow.php';
		$this->settings_repository = new DD_Maintenance_Settings_Repository();
		$this->cron_job_store      = new DD_Maintenance_Cron_Job_Store();
		$this->backup_workflow  = new DD_Maintenance_Backup_Workflow();
		$this->cron_workflow    = new DD_Maintenance_Cron_Workflow( $this->backup_workflow, $this->cron_job_store, array( $this, 'apply_retention_policy' ) );
		self::migrate_legacy_settings();
		new DD_Maintenance_Settings();

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'dd_maintenance_daily_maintenance', array( $this, 'cron_full_maintenance' ) );

		// Compatibilidade com agendamentos anteriores do Backuper.
		DD_Maintenance_Legacy_Compatibility::register_legacy_hook( 'backuper_daily_maintenance', array( $this, 'cron_full_maintenance' ) );
		add_action( 'dd_maintenance_backup_continue', array( $this, 'cron_backup_continue' ), 10, 1 );
		add_action( 'plugins_loaded', array( $this, 'register_elementor_compatibility' ), 1 );
	}

	/**
	 * Registra intervalos personalizados no WP-Cron (diário, 7 dias, 15 dias e 30 dias).
	 *
	 * @param array $schedules Lista de intervalos existentes.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['dd_daily'] = array(
			'interval' => 86400,
			'display'  => __( 'Diário (a cada 24 horas)', 'dd-maintenance' ),
		);
		$schedules['dd_weekly'] = array(
			'interval' => 604800,
			'display'  => __( 'Semanal (a cada 7 dias)', 'dd-maintenance' ),
		);
		$schedules['dd_biweekly'] = array(
			'interval' => 1296000,
			'display'  => __( 'Quinzenal (a cada 15 dias)', 'dd-maintenance' ),
		);
		$schedules['dd_monthly'] = array(
			'interval' => 2592000,
			'display'  => __( 'Mensal (a cada 30 dias)', 'dd-maintenance' ),
		);
		return $schedules;
	}

	/**
	 * Garante compatibilidade de tags dinâmicas do Elementor com PHP 8.0+.
	 */
	public function register_elementor_compatibility() {
		if ( class_exists( 'DD_Maintenance_Elementor_Compatibility' ) ) {
			add_filter( 'elementor/dynamic_tags/parse_tag_text', array( 'DD_Maintenance_Elementor_Compatibility', 'fix_elementor_dynamic_tags' ), 1 );
			add_filter( 'elementor/dynamic_tags/parse_tag_text', array( 'DD_Maintenance_Elementor_Compatibility', 'fix_elementor_dynamic_tags' ), 999 );
			add_filter( 'the_content', array( 'DD_Maintenance_Elementor_Compatibility', 'fix_elementor_dynamic_tags' ), 1 );
			add_filter( 'widget_text', array( 'DD_Maintenance_Elementor_Compatibility', 'fix_elementor_dynamic_tags' ), 1 );
			add_filter( 'get_post_metadata', array( $this, 'filter_elementor_post_metadata' ), 10, 4 );
		}
	}

	/**
	 * Intercepta _elementor_data e _elementor_page_settings para blindar contra tags dinamicas malformadas.
	 */
	public function filter_elementor_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( ! in_array( $meta_key, array( '_elementor_data', '_elementor_page_settings', '_elementor_controls_usage' ), true ) ) {
			return $value;
		}
		static $in_filter = false;
		if ( $in_filter ) {
			return $value;
		}
		$in_filter = true;
		$meta      = get_post_meta( $object_id, $meta_key, true );
		$in_filter = false;

		if ( is_string( $meta ) && false !== strpos( $meta, '[elementor-tag' ) ) {
			$fixed = DD_Maintenance_Elementor_Compatibility::fix_elementor_dynamic_tags( $meta );
			return $single ? $fixed : array( $fixed );
		}
		return $value;
	}

	/**
	 * Migra a configuração legada antes de qualquer fluxo poder consumi-la.
	 */
	private static function migrate_legacy_settings(): void {
		$legacy_settings  = get_option( 'backuper_settings', null );
		$current_settings = get_option( 'dd_maintenance_settings', null );

		if ( is_array( $legacy_settings ) ) {
			DD_Maintenance_Legacy_Compatibility::record_usage( 'option_backuper_settings' );
		}
		if ( null === $current_settings && is_array( $legacy_settings ) ) {
			update_option( 'dd_maintenance_settings', $legacy_settings, false );
		}
	}

	/**
	 * Aplica migrações e atualizações de configuração entre versões e plugins anteriores.
	 */
	public function maybe_upgrade() {

		$version = get_option( 'dd_maintenance_version', '0' );

		// Migra hash de senha do Gerenciador de Updates DD antigo, se existir.
		$legacy_hash = get_option( 'dd_gerenciador_updates_password_hash', '' );
		$new_hash    = get_option( 'dd_maintenance_password_hash', '' );
		if ( '' === $new_hash && is_string( $legacy_hash ) && '' !== $legacy_hash ) {
			update_option( 'dd_maintenance_password_hash', $legacy_hash, false );
		}

		if ( version_compare( $version, DD_MAINTENANCE_VERSION, '>=' ) ) {
			return;
		}

		// Garante configurações padrão.
		$settings = $this->settings_repository->get();
		$this->settings_repository->save( $settings );

		update_option( 'dd_maintenance_version', DD_MAINTENANCE_VERSION );
	}

	/**
	 * Pasta local onde os backups são armazenados.
	 *
	 * @return string
	 */
	public static function backup_dir() {
		$dir = WP_CONTENT_DIR . '/uploads/dd-maintenance';
		if ( is_link( $dir ) ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Não foi possível criar a pasta de backups.' );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			if ( ! self::write_file_fully( $index_file, '<?php // Silence is golden.' ) ) {
				throw new RuntimeException( 'Não foi possível criar a proteção index.php dos backups.' );
			}
		}

		// Proteção .htaccess para servidores Apache e LiteSpeed (bloqueia download direto de .zip e .sql).
		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = "# DD Maintenance - Bloqueio de Acesso Publico a Backups\n"
				. "<IfModule !authz_core_module>\n"
				. "Order deny,allow\n"
				. "Deny from all\n"
				. "</IfModule>\n"
				. "<IfModule authz_core_module>\n"
				. "Require all denied\n"
				. "</IfModule>\n";
			if ( ! self::write_file_fully( $htaccess_file, $htaccess_content ) ) {
				throw new RuntimeException( 'Não foi possível criar a proteção .htaccess dos backups.' );
			}
		}

		// Proteção web.config para servidores IIS.
		$webconfig_file = $dir . '/web.config';
		if ( ! file_exists( $webconfig_file ) ) {
			$webconfig_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n"
				. "  <system.webServer>\n"
				. "    <authorization>\n"
				. "      <deny users=\"*\" />\n"
				. "    </authorization>\n"
				. "  </system.webServer>\n"
				. "</configuration>\n";
			if ( ! self::write_file_fully( $webconfig_file, $webconfig_content ) ) {
				throw new RuntimeException( 'Não foi possível criar a proteção web.config dos backups.' );
			}
		}

		return $dir;
	}
	/**
	 * Indica se novos backups, uploads e restores estão bloqueados para rollback.
	 *
	 * @return bool
	 */
	public static function operations_disabled(): bool {
		return defined( 'DD_MAINTENANCE_DISABLE_OPERATIONS' ) && true === DD_MAINTENANCE_DISABLE_OPERATIONS;
	}


	public static function logs_dir(): string {
		$dir = self::backup_dir() . '/logs';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Não foi possível criar a pasta de logs.' );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			if ( ! self::write_file_fully( $index_file, '<?php // Silence is golden.' ) ) {
				throw new RuntimeException( 'Não foi possível criar a proteção index.php dos logs.' );
			}
		}

		return $dir;
	}
	/**
	 * Escreve conteúdo completo e só confirma sucesso após todos os bytes.
	 *
	 * @param string $path    Arquivo de destino.
	 * @param string $content Conteúdo.
	 * @param int    $flags   Flags de file_put_contents().
	 * @return bool
	 */
	private static function write_file_fully( string $path, string $content, int $flags = 0 ): bool {
		$written = file_put_contents( $path, $content, $flags );
		if ( false !== $written && strlen( $content ) === (int) $written ) {
			return true;
		}
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
		return false;
	}
	/**
	 * Registra um evento operacional estruturado e sem dados sensíveis.
	 *
	 * @param string $operation Operação principal.
	 * @param string $event     Nome do evento.
	 * @param array  $context   Metadados operacionais.
	 * @return array
	 */
	public static function record_event( string $operation, string $event, array $context = array() ): array {
		$payload = DD_Maintenance_Observability::make_event( $operation, $event, $context );
		$payload['persistence_status'] = 'persisted';
		try {
			$line    = DD_Maintenance_Observability::encode( $payload ) . "\n";
			$path    = self::logs_dir() . '/events-' . gmdate( 'Y-m-d' ) . '.jsonl';
			$written = file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
			if ( false === $written || (int) $written !== strlen( $line ) ) {
				throw new RuntimeException( 'event_write_failed' );
			}
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( 'dd_maintenance_last_event_error' );
			}
		} catch ( Throwable $e ) {
			$payload['persistence_status'] = 'created_not_persisted';
			if ( function_exists( 'set_transient' ) ) {
				set_transient(
					'dd_maintenance_last_event_error',
					array(
						'code'           => 'event_write_failed',
						'event_id'       => $payload['event_id'],
						'operation'      => $payload['operation'],
						'event'          => $payload['event'],
						'correlation_id' => $payload['correlation_id'],
						'step'           => $payload['step'],
					),
					DAY_IN_SECONDS
				);
			}
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'dd_maintenance_last_event', $payload, DAY_IN_SECONDS );
		}
		return $payload;
	}

	/**
	 * Retorna o último evento estruturado disponível.
	 *
	 * @return array
	 */
	public static function get_last_event(): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$event = get_transient( 'dd_maintenance_last_event' );
		return is_array( $event ) ? $event : array();
	}


	/**
	 * Salva um log tanto no transient quanto em arquivo físico na pasta de uploads.
	 *
	 * @param array|string $log       Linhas do log ou texto.
	 * @param string       $status    'success' | 'warning' | 'failure' | 'info'.
	 * @param string       $base_name Identificador do backup (ex: site-2026-08-21-1430).
	 * @return string Caminho criado ou string vazia se a gravação falhar.
	 */
	public static function save_log( $log, string $status = 'success', string $base_name = '' ): string {
		$lines = is_array( $log ) ? $log : explode( "\n", (string) $log );
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );

		set_transient( 'dd_maintenance_last_log', $lines, DAY_IN_SECONDS );
		set_transient( 'backuper_last_log', $lines, DAY_IN_SECONDS );

		$status     = in_array( $status, array( 'success', 'warning', 'failure', 'error', 'info' ), true ) ? $status : 'info';
		$has_error  = false;
		$has_warning = false;
		foreach ( $lines as $line ) {
			$has_error   = $has_error || 0 === strpos( $line, '[ERRO]' );
			$has_warning = $has_warning || 0 === strpos( $line, '[Aviso]' );
		}
		if ( 'error' === $status || ( 'success' === $status && $has_error ) ) {
			$status = 'failure';
		} elseif ( 'success' === $status && $has_warning ) {
			$status = 'warning';
		}

		$write_ok = false;
		$filepath = '';
		try {
			$logs_dir = self::logs_dir();
			$date_stamp = current_time( 'Y-m-d-His' );
			if ( ! empty( $base_name ) ) {
				$clean_base = sanitize_file_name( $base_name );
				$filename   = sprintf( 'backup-%s-%s-%s.log', $clean_base, $status, $date_stamp );
			} else {
				$filename = sprintf( 'backup-%s-%s.log', $status, $date_stamp );
			}

			$filepath = $logs_dir . '/' . $filename;
			$content  = implode( "\n", $lines ) . "\n";
			$written  = file_put_contents( $filepath, $content );
			$write_ok = false !== $written && (int) $written === strlen( $content );
		} catch ( Throwable $e ) {
			$write_ok = false;
		}
		try {
			self::purge_old_log_files( 30 );
		} catch ( Throwable $e ) {
			// Falha de retenção não deve ocultar o resultado da gravação do log.
		}
		self::record_event(
			'backup',
			'log_saved',
			array(
				'step'          => 'finalize',
				'status'        => $write_ok ? $status : 'failure',
				'error_count'   => count( array_filter( $lines, static function ( $line ) { return 0 === strpos( $line, '[ERRO]' ); } ) ),
				'failure_code'  => $write_ok ? '' : 'log_write_failed',
				'base_name'     => $base_name,
			)
		);

		return $write_ok ? $filepath : '';
	}

	/**
	 * Remove arquivos de log antigos mantendo apenas os N mais recentes.
	 *
	 * @param int $keep Quantidade de logs a manter.
	 */
	public static function purge_old_log_files( int $keep = 30 ): void {
		$dir   = self::logs_dir();
		$files = glob( $dir . '/backup-*.log' );
		if ( ! is_array( $files ) || count( $files ) <= $keep ) {
			return;
		}

		usort(
			$files,
			function( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		$total = count( $files );
		for ( $i = $keep; $i < $total; $i++ ) {
			if ( is_file( $files[ $i ] ) ) {
				unlink( $files[ $i ] );
			}
		}
	}

	/**
	 * Retorna a lista de logs salvos na pasta de uploads.
	 *
	 * @return array
	 */
	public static function get_saved_logs(): array {
		$dir   = self::logs_dir();
		$files = glob( $dir . '/backup-*.log' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$logs = array();
		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}
			$name   = basename( $file );
			$mtime  = (int) filemtime( $file );
			$size   = (int) filesize( $file );
			$status = 'info';

			if ( strpos( $name, '-success-' ) !== false ) {
				$status = 'success';
			} elseif ( strpos( $name, '-warning-' ) !== false ) {
				$status = 'warning';
			} elseif ( strpos( $name, '-failure-' ) !== false || strpos( $name, '-error-' ) !== false ) {
				$status = 'failure';
			}

			$logs[] = array(
				'filename'       => $name,
				'path'           => $file,
				'status'         => $status,
				'size'           => $size,
				'size_formatted' => size_format( $size ),
				'mtime'          => $mtime,
				'date_formatted' => function_exists( 'get_date_from_gmt' )
					? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ), 'd/m/Y H:i:s' )
					: date( 'd/m/Y H:i:s', $mtime ),
			);
		}

		usort(
			$logs,
			function( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);

		return $logs;
	}

	/**
	 * Retorna o conteúdo de um log salvo específico.
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string|WP_Error
	 */
	public static function get_log_content( string $filename ) {
		$filename = sanitize_file_name( $filename );
		$dir      = self::logs_dir();
		$path     = $dir . '/' . $filename;

		if ( empty( $filename ) || ! is_file( $path ) ) {
			return new WP_Error( 'log_not_found', __( 'Arquivo de log não encontrado.', 'dd-maintenance' ) );
		}

		return (string) file_get_contents( $path );
	}

	/**
	 * Exclui um arquivo de log salvo.
	 *
	 * @param string $filename Nome do arquivo.
	 * @return bool
	 */
	public static function delete_saved_log( string $filename ): bool {
		$filename = sanitize_file_name( $filename );
		$dir      = self::logs_dir();
		$path     = $dir . '/' . $filename;

		if ( ! empty( $filename ) && is_file( $path ) ) {
			return unlink( $path );
		}

		return false;
	}

	/**
	 * Limpa todos os logs salvos da pasta de uploads.
	 *
	 * @return int Quantidade de logs removidos.
	 */
	public static function clear_all_saved_logs(): int {
		$dir   = self::logs_dir();
		$files = glob( $dir . '/backup-*.log' );
		$files = is_array( $files ) ? $files : array();

		$count = 0;
		foreach ( $files as $file ) {
			if ( is_file( $file ) && unlink( $file ) ) {
				$count++;
			}
		}

		delete_transient( 'dd_maintenance_last_log' );
		delete_transient( 'backuper_last_log' );
		$event_files = glob( $dir . '/events-*.jsonl' );
		foreach ( is_array( $event_files ) ? $event_files : array() as $event_file ) {
			if ( is_file( $event_file ) ) {
				unlink( $event_file );
			}
		}
		delete_transient( 'dd_maintenance_last_event' );

		return $count;
	}

	/**
	 * Ativa o plugin.
	 */
	public static function activate() {

		self::maybe_schedule_cron();
	}

	/**
	 * Desativa o plugin.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'dd_maintenance_daily_maintenance' );
		wp_clear_scheduled_hook( 'backuper_daily_maintenance' );
		wp_clear_scheduled_hook( 'dd_maintenance_backup_continue' );
	}

	/**
	 * Calcula o próximo timestamp GMT para o horário e dia configurados.
	 *
	 * @param string $time_str Horário no formato HH:MM (ex.: "03:00").
	 * @return int Timestamp GMT.
	 */
	public static function calculate_next_run_timestamp( string $time_str = '03:00' ): int {
		$time_str = trim( $time_str );
		if ( ! preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time_str ) ) {
			$time_str = '03:00';
		}

		list( $hours, $minutes ) = explode( ':', $time_str );
		$hours   = (int) $hours;
		$minutes = (int) $minutes;

		// Pega timestamp atual na timezone configurada no WordPress.
		$current_local_time = current_time( 'timestamp' );
		$today_target       = strtotime( sprintf( '%s %02d:%02d:00', date( 'Y-m-d', $current_local_time ), $hours, $minutes ) );

		if ( $today_target <= $current_local_time ) {
			$next_local_target = $today_target + DAY_IN_SECONDS;
		} else {
			$next_local_target = $today_target;
		}

		// Converte para GMT timestamp.
		$gmt_offset = (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;
		return (int) ( $next_local_target - $gmt_offset );
	}

	/**
	 * Agenda (ou remove) o cron de manutenção conforme frequência e horário configurados.
	 */
	public static function maybe_schedule_cron() {
		$settings = ( new DD_Maintenance_Settings_Repository() )->get();

		// Limpa agendamentos antigos para reagendar com nova frequência/horário.
		wp_clear_scheduled_hook( 'dd_maintenance_daily_maintenance' );
		wp_clear_scheduled_hook( 'backuper_daily_maintenance' );

		if ( ! empty( $settings['schedule_enabled'] ) ) {
			$freq_setting = isset( $settings['schedule_frequency'] ) ? $settings['schedule_frequency'] : 'daily';
			$time_setting = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '03:00';

			$recurrence = 'dd_daily';
			switch ( $freq_setting ) {
				case 'weekly':
				case '7':
					$recurrence = 'dd_weekly';
					break;
				case 'biweekly':
				case '15':
					$recurrence = 'dd_biweekly';
					break;
				case 'monthly':
				case '30':
					$recurrence = 'dd_monthly';
					break;
				case 'daily':
				default:
					$recurrence = 'dd_daily';
					break;
			}

			$first_run = self::calculate_next_run_timestamp( $time_setting );
			wp_schedule_event( $first_run, $recurrence, 'dd_maintenance_daily_maintenance' );
		}
	}

	/**
	 * Aplica a política de retenção excluindo backups locais antigos que excedam o limite configurado.
	 *
	 * @return array Lista de backups locais removidos.
	 */
	public function apply_retention_policy(): array {
		$settings  = $this->settings_repository->get();
		$retention = (int) $settings['retention_local'];

		// 0 significa retenção ilimitada (não apaga backups).
		if ( $retention <= 0 ) {
			return array();
		}

		$backups = DD_Maintenance_Restore::get_local_backups();
		$total   = count( $backups );

		if ( $total <= $retention ) {
			return array();
		}

		$deleted = array();
		// Mantém os primeiros $retention e apaga o restante (já estão ordenados do mais recente para o mais antigo).
		for ( $i = $retention; $i < $total; $i++ ) {
			$item = $backups[ $i ];
			if ( DD_Maintenance_Restore::delete_local_backup( $item['identifier'] ) ) {
				$deleted[] = $item['display_name'];
			}
		}
		return $deleted;
	}

	/**
	 * Inicia a manutenção agendada e devolve o controle ao WP-Cron imediatamente.
	 * Cada evento seguinte executa apenas um lote persistido.
	 */
	public function cron_full_maintenance() {
		if ( self::operations_disabled() ) {
			self::record_event(
				'backup',
				'cron_rejected',
				array(
					'step'         => 'init',
					'status'       => 'failure',
					'failure_code' => 'operations_disabled',
					'error_count'  => 1,
				)
			);
			return;
		}
		$this->cron_workflow->start();
	}

	/**
	 * Executa um único lote da manutenção agendada e agenda a continuação.
	 *
	 * @param string $session_id ID da sessão.
	 */
	public function cron_backup_continue( $session_id ) {
		if ( self::operations_disabled() ) {
			self::record_event(
				'backup',
				'cron_rejected',
				array(
					'step'         => 'continue',
					'session_id'   => (string) $session_id,
					'status'       => 'failure',
					'failure_code' => 'operations_disabled',
					'error_count'  => 1,
				)
			);
			return;
		}
		$this->cron_workflow->continue( (string) $session_id );
	}

	/**
	 * Executa a manutenção completa:
	 * 1. Verificação de travas no wp-config (aviso caso DISALLOW_FILE_MODS esteja ativo).
	 * 2. Backup do site (com divisão em volumes configuráveis).
	 * 3. Envio para o bucket S3 (DigitalOcean Spaces).
	 * 4. Aplicação da política de retenção local.
	 * 5. Atualização de todos os plugins.
	 * 6. Atualização do core do WordPress.
	 *
	 * @return array Log de execução.
	 */
	public function run_full() {
		if ( self::operations_disabled() ) {
			self::record_event(
				'backup',
				'operation_rejected',
				array(
					'step'         => 'init',
					'status'       => 'failure',
					'failure_code' => 'operations_disabled',
					'error_count'  => 1,
				)
			);
			return array( '[ERRO] Backups e restores novos estão desabilitados para rollback.' );
		}
		return $this->backup_workflow->run_full( array( $this, 'apply_retention_policy' ) );
	}

	/**
	 * Define uma notificação de administrador (transient).
	 *
	 * @param string $message Mensagem.
	 * @param string $type    success | error | warning | info.
	 */
	public function set_notice( $message, $type = 'info' ) {
		set_transient( 'dd_maintenance_notice', array( 'message' => $message, 'type' => $type ), 60 );
		set_transient( 'backuper_notice', array( 'message' => $message, 'type' => $type ), 60 );
	}

	/**
	 * Tenta aumentar o tempo máximo de execução.
	 *
	 * @param int $seconds Segundos.
	 */
	private function set_time_limit( $seconds = 0 ) {
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( $seconds );
		}
	}
}
