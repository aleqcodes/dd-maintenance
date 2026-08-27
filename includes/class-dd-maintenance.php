<?php
/**
 * Classe principal do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance {

	/**
	 * Instância singleton.
	 *
	 * @var DD_Maintenance|null
	 */
	private static $instance = null;

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
		new DD_Maintenance_Settings();

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'dd_maintenance_daily_maintenance', array( $this, 'cron_full_maintenance' ) );

		// Compatibilidade com agendamentos anteriores do Backuper.
		add_action( 'backuper_daily_maintenance', array( $this, 'cron_full_maintenance' ) );
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
		if ( class_exists( 'DD_Maintenance_Restore' ) ) {
			add_filter( 'elementor/dynamic_tags/parse_tag_text', array( 'DD_Maintenance_Restore', 'fix_elementor_dynamic_tags' ), 999 );
			add_filter( 'the_content', array( 'DD_Maintenance_Restore', 'fix_elementor_dynamic_tags' ), 1 );
			add_filter( 'widget_text', array( 'DD_Maintenance_Restore', 'fix_elementor_dynamic_tags' ), 1 );
		}
	}

	/**
	 * Aplica migrações e atualizações de configuração entre versões e plugins anteriores.
	 */
	public function maybe_upgrade() {
		$version = get_option( 'dd_maintenance_version', '0' );

		// Migra configurações antigas do Backuper, se existirem.
		$legacy_backuper_settings = get_option( 'backuper_settings', null );
		$current_settings         = get_option( 'dd_maintenance_settings', null );

		if ( null === $current_settings && is_array( $legacy_backuper_settings ) ) {
			update_option( 'dd_maintenance_settings', $legacy_backuper_settings );
		}

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
		$settings = wp_parse_args(
			get_option( 'dd_maintenance_settings', array() ),
			array(
				'include_db'         => 1,
				'include_wpcontent'  => 1,
				'include_wpconfig'   => 1,
				'include_entire'     => 1,
				'keep_local'         => 1,
				'schedule_enabled'   => 0,
				'schedule_frequency' => 'daily',
				'schedule_time'      => '03:00',
				'retention_local'    => 5,
			)
		);
		update_option( 'dd_maintenance_settings', $settings );

		update_option( 'dd_maintenance_version', DD_MAINTENANCE_VERSION );
	}

	/**
	 * Pasta local onde os backups são armazenados.
	 *
	 * @return string
	 */
	public static function backup_dir() {
		$dir = WP_CONTENT_DIR . '/uploads/dd-maintenance';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '<?php // Silence is golden.' );
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
			@file_put_contents( $htaccess_file, $htaccess_content );
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
			@file_put_contents( $webconfig_file, $webconfig_content );
		}

		return $dir;
	}

	/**
	 * Pasta de logs persistentes em uploads.
	 *
	 * @return string
	 */
	public static function logs_dir(): string {
		$dir = self::backup_dir() . '/logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '<?php // Silence is golden.' );
		}

		return $dir;
	}

	/**
	 * Salva um log tanto no transient quanto em arquivo físico na pasta de uploads.
	 *
	 * @param array|string $log       Linhas do log ou texto.
	 * @param string       $status    'success' | 'failure' | 'info'.
	 * @param string       $base_name Identificador do backup (ex: site-2026-08-21-1430).
	 * @return string Caminho do arquivo de log criado.
	 */
	public static function save_log( $log, string $status = 'success', string $base_name = '' ): string {
		$lines = is_array( $log ) ? $log : explode( "\n", (string) $log );
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );

		set_transient( 'dd_maintenance_last_log', $lines, DAY_IN_SECONDS );
		set_transient( 'backuper_last_log', $lines, DAY_IN_SECONDS );

		$logs_dir = self::logs_dir();
		$status   = in_array( $status, array( 'success', 'failure', 'error', 'info' ), true ) ? $status : 'info';
		if ( 'error' === $status ) {
			$status = 'failure';
		}

		$date_stamp = current_time( 'Y-m-d-His' );
		if ( ! empty( $base_name ) ) {
			$clean_base = sanitize_file_name( $base_name );
			$filename   = sprintf( 'backup-%s-%s-%s.log', $clean_base, $status, $date_stamp );
		} else {
			$filename   = sprintf( 'backup-%s-%s.log', $status, $date_stamp );
		}

		$filepath = $logs_dir . '/' . $filename;
		$content  = implode( "\n", $lines ) . "\n";

		@file_put_contents( $filepath, $content );
		self::purge_old_log_files( 30 );

		return $filepath;
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
				@unlink( $files[ $i ] );
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
			return @unlink( $path );
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
		if ( ! is_array( $files ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $files as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) {
				$count++;
			}
		}

		delete_transient( 'dd_maintenance_last_log' );
		delete_transient( 'backuper_last_log' );

		return $count;
	}

	/**
	 * Ativa o plugin.
	 */
	public static function activate() {
		$dir = self::backup_dir();
		@file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );

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
		$settings = get_option( 'dd_maintenance_settings', array() );
		if ( empty( $settings ) ) {
			$settings = get_option( 'backuper_settings', array() );
		}

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
		$settings  = get_option( 'dd_maintenance_settings', array() );
		$retention = isset( $settings['retention_local'] ) ? (int) $settings['retention_local'] : 5;

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
		$active = get_option( 'dd_maintenance_background_job', array() );
		$now    = time();
		if ( is_array( $active ) && isset( $active['status'], $active['session_id'], $active['started_at'] ) && 'running' === $active['status'] ) {
			if ( ( $now - (int) $active['started_at'] ) < 3600 ) {
				$this->schedule_backup_continuation( $active['session_id'] );
				return;
			}
			$backup = new DD_Maintenance_Backup();
			$backup->cleanup_failed_session( $active['session_id'], __( 'Job agendado expirado por tempo limite.', 'dd-maintenance' ) );
		}
		$backup  = new DD_Maintenance_Backup();
		$session = $backup->init_session();
		if ( is_wp_error( $session ) ) {
			$log = array( '[ERRO] Backup: ' . $session->get_error_message() );
			set_transient( 'dd_maintenance_last_log', $log, DAY_IN_SECONDS );
			set_transient( 'backuper_last_log', $log, DAY_IN_SECONDS );
			return;
		}

		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$job       = array(
			'status'       => 'running',
			'phase'        => 'database',
			'session_id'   => $session['session_id'],
			'session_dir'  => $session['session_dir'],
			'folder'       => ( $site_slug ? $site_slug : 'site' ) . '/' . current_time( 'Y-m-d' ),
			'parts'        => array(),
			'upload_index' => 0,
			'total_size'   => 0,
			'started_at'   => time(),
			'log'          => array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) ),
		);
		update_option( 'dd_maintenance_background_job', $job, false );
		$this->schedule_backup_continuation( $session['session_id'] );
	}

	/**
	 * Executa um único lote da manutenção agendada e agenda a continuação.
	 *
	 * @param string $session_id ID da sessão.
	 */
	public function cron_backup_continue( $session_id ) {
		$job = get_option( 'dd_maintenance_background_job', array() );
		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) || $session_id !== ( $job['session_id'] ?? '' ) ) {
			return;
		}

		$backup = new DD_Maintenance_Backup();
		$result = true;

		switch ( $job['phase'] ) {
			case 'database':
				$result = $backup->dump_database_step( $session_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['completed'] ) ) {
					$job['phase'] = 'index';
					$job['log'][] = $result['log'];
				}
				break;

			case 'index':
				$result = $backup->index_files_step( $session_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['completed'] ) ) {
					$job['phase'] = 'zip';
					$job['log'][] = $result['log'];
				}
				break;

			case 'zip':
				$result = $backup->zip_batch_step( $session_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['completed'] ) ) {
					$job['phase'] = 'finalize';
					$job['log'][] = $result['log'];
				}
				break;

			case 'finalize':
				$result = $backup->finalize_and_split_step( $session_id );
				if ( ! is_wp_error( $result ) && ! empty( $result['completed'] ) ) {
					$job['phase']      = 'upload';
					$job['parts']      = $result['parts'];
					$job['total_size'] = $result['total_size'];
					$job['log'][]      = $result['log'];
				}
				break;

			case 'upload':
				$s3 = new DD_Maintenance_S3();
				if ( ! $s3->is_configured() ) {
					$result = new WP_Error( 's3_config', __( 'Configure as credenciais do S3 / DigitalOcean Spaces.', 'dd-maintenance' ) );
					break;
				}

				$index = (int) $job['upload_index'];
				if ( $index < count( $job['parts'] ) ) {
					$part   = $job['parts'][ $index ];
					$result = $s3->put_object( $job['folder'] . '/' . $part['name'], $part['file'] );
					if ( is_wp_error( $result ) ) {
						break;
					}
					$job['upload_index']++;
					$job['log'][] = sprintf(
						__( '[OK] Parte %1$d/%2$d enviada: %3$s', 'dd-maintenance' ),
						$job['upload_index'],
						count( $job['parts'] ),
						$part['name']
					);
				}
				if ( $job['upload_index'] >= count( $job['parts'] ) ) {
					$job['phase'] = 'retention';
				}
				break;

			case 'retention':
				$purged = $this->apply_retention_policy();
				$job['log'][] = sprintf( __( '[Retenção] %d backup(s) antigo(s) removido(s).', 'dd-maintenance' ), count( $purged ) );
				$backup->cleanup_session_step( $session_id );
				$job['phase'] = 'plugins';
				break;

			case 'plugins':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_plugins();
				if ( is_wp_error( $result ) ) {
					$job['log'][] = '[ERRO] Plugins: ' . $result->get_error_message();
					$result       = true;
				} else {
					$job['log'][] = '[OK] Plugins atualizados: ' . $result['updated'];
				}
				$job['phase'] = 'core';
				break;

			case 'core':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_core();
				if ( is_wp_error( $result ) ) {
					$job['log'][] = '[ERRO] Core: ' . $result->get_error_message();
				} else {
					$job['log'][] = '[Core] ' . $result['message'];
				}
				$job['log'][]     = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
				$job['phase']     = 'done';
				$job['status']    = 'completed';
				$job['finished_at'] = time();
				$result = true;
				break;
		}

		if ( is_wp_error( $result ) ) {
			$job['status']      = 'error';
			$job['finished_at'] = time();
			$job['log'][]       = '[ERRO] ' . $result->get_error_message();
			$job['log'][]       = '[AUTOLIMPEZA] Backup encerrado por erro. Arquivos residuais limpos.';
			$job['log'][]       = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			if ( ! empty( $session_id ) ) {
				$backup->cleanup_failed_session( $session_id, $result->get_error_message(), $job['log'] );
			} else {
				self::save_log( $job['log'], 'failure' );
			}
		} elseif ( 'completed' === $job['status'] ) {
			self::save_log( $job['log'], 'success', $job['base_name'] ?? '' );
		}
		update_option( 'dd_maintenance_background_job', $job, false );


		if ( 'running' === $job['status'] ) {
			$this->schedule_backup_continuation( $session_id );
		}
	}

	/**
	 * Agenda a próxima unidade de trabalho sem manter a requisição atual aberta.
	 *
	 * @param string $session_id ID da sessão.
	 */
	private function schedule_backup_continuation( $session_id ) {
		if ( ! wp_next_scheduled( 'dd_maintenance_backup_continue', array( $session_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'dd_maintenance_backup_continue', array( $session_id ) );
		}
	}

	/**
	 * Executa a manutenção completa:
	 * 1. Verificação de travas no wp-config (aviso caso DISALLOW_FILE_MODS esteja ativo).
	 * 2. Backup do site (com divisão em partes de 25MB).
	 * 3. Envio para o bucket S3 (DigitalOcean Spaces).
	 * 4. Aplicação da política de retenção local.
	 * 5. Atualização de todos os plugins.
	 * 6. Atualização do core do WordPress.
	 *
	 * @return array Log de execução.
	 */
	public function run_full() {
		$this->set_time_limit( 0 );

		$log = array( '[Início] ' . current_time( 'Y-m-d H:i:s' ) );

		// Verificação de travas no wp-config.php.
		$config_status = DD_Maintenance_Config::get_wp_config_status();
		$file_mods     = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		if ( true === $file_mods ) {
			$log[] = '[Aviso] DISALLOW_FILE_MODS está ATIVO no wp-config.php. Se as atualizações falharem, desative-o na aba "Travas wp-config.php".';
		}

		// 1. Backup.
		$backup = new DD_Maintenance_Backup();
		$result = $backup->run();

		if ( is_wp_error( $result ) ) {
			$log[] = '[ERRO] Backup: ' . $result->get_error_message();
			$log[] = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			return $log;
		}

		$parts       = isset( $result['parts'] ) ? $result['parts'] : array( array( 'file' => $result['file'], 'name' => $result['name'], 'size' => $result['size'], 'part' => 1 ) );
		$total_parts = count( $parts );
		$total_size  = isset( $result['total_size'] ) ? $result['total_size'] : $result['size'];

		$log[] = sprintf(
			/* translators: 1: Quantidade de partes, 2: Tamanho total */
			__( '[OK] Backup criado com sucesso: %1$d parte(s) de até 25MB (Total: %2$s)', 'dd-maintenance' ),
			$total_parts,
			size_format( $total_size )
		);

		// 2. Envio para o S3.
		$s3 = new DD_Maintenance_S3();

		if ( ! $s3->is_configured() ) {
			$log[] = '[ERRO] S3: ' . __( 'Configure as credenciais do S3 / DigitalOcean Spaces na aba de configurações.', 'dd-maintenance' );
			$log[] = '[Fim] ' . current_time( 'Y-m-d H:i:s' );
			return $log;
		}

		$site_slug = sanitize_title( get_bloginfo( 'name' ) );
		$site_slug = $site_slug ? $site_slug : 'site';
		$folder    = $site_slug . '/' . current_time( 'Y-m-d' );

		$log[] = '[OK] Pasta de destino no S3: ' . $folder;

		$upload_error = false;
		foreach ( $parts as $idx => $part ) {
			$key    = $folder . '/' . $part['name'];
			$upload = $s3->put_object( $key, $part['file'] );

			if ( is_wp_error( $upload ) ) {
				$log[] = sprintf(
					/* translators: 1: Índice da parte, 2: Total de partes, 3: Nome do arquivo, 4: Mensagem de erro */
					__( '[ERRO] Envio da parte %1$d/%2$d (%3$s): %4$s', 'dd-maintenance' ),
					$idx + 1,
					$total_parts,
					$part['name'],
					$upload->get_error_message()
				);
				$upload_error = true;
				break;
			} else {
				$log[] = sprintf(
					/* translators: 1: Índice da parte, 2: Total de partes, 3: Nome do arquivo, 4: Tamanho da parte */
					__( '[OK] Parte %1$d/%2$d enviada: %3$s (%4$s)', 'dd-maintenance' ),
					$idx + 1,
					$total_parts,
					$part['name'],
					size_format( $part['size'] )
				);
			}
		}

		if ( $upload_error ) {
			$log[] = '[Fim com Erro no S3] ' . current_time( 'Y-m-d H:i:s' );
			return $log;
		}

		$log[] = sprintf(
			/* translators: 1: Quantidade de partes, 2: Nome do bucket, 3: Pasta no bucket */
			__( '[OK] Todas as %1$d parte(s) enviadas para o bucket "%2$s" em "%3$s".', 'dd-maintenance' ),
			$total_parts,
			$s3->get_bucket(),
			$folder
		);

		// 3. Aplica política de retenção local de backups.
		$purged_backups = $this->apply_retention_policy();
		if ( ! empty( $purged_backups ) ) {
			$log[] = sprintf(
				/* translators: %s: Lista de backups removidos */
				__( '[Retenção] %d backup(s) antigo(s) removido(s) conforme a política de retenção.', 'dd-maintenance' ),
				count( $purged_backups )
			);
		}

		// 4. Plugins.
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

		// 5. Core.
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
			@set_time_limit( $seconds );
		}
	}
}
