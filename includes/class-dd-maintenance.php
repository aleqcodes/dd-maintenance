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

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'dd_maintenance_daily_maintenance', array( $this, 'cron_full_maintenance' ) );

		// Compatibilidade com agendamentos anteriores do Backuper.
		add_action( 'backuper_daily_maintenance', array( $this, 'cron_full_maintenance' ) );
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
				'include_db'        => 1,
				'include_wpcontent' => 1,
				'include_wpconfig'  => 1,
				'include_entire'    => 1,
				'keep_local'        => 1,
				'schedule_enabled'  => 0,
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

		return $dir;
	}

	/**
	 * Ativa o plugin.
	 */
	public static function activate() {
		$dir = self::backup_dir();
		@file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );

		$settings = get_option( 'dd_maintenance_settings', array() );
		if ( empty( $settings ) ) {
			$settings = get_option( 'backuper_settings', array() );
		}

		if ( ! empty( $settings['schedule_enabled'] ) && ! wp_next_scheduled( 'dd_maintenance_daily_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'dd_maintenance_daily_maintenance' );
		}
	}

	/**
	 * Desativa o plugin.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'dd_maintenance_daily_maintenance' );
		wp_clear_scheduled_hook( 'backuper_daily_maintenance' );
	}

	/**
	 * Agenda (ou remove) o cron de manutenção diária conforme as configurações.
	 */
	public static function maybe_schedule_cron() {
		$settings = get_option( 'dd_maintenance_settings', array() );
		if ( empty( $settings ) ) {
			$settings = get_option( 'backuper_settings', array() );
		}

		if ( ! empty( $settings['schedule_enabled'] ) ) {
			if ( ! wp_next_scheduled( 'dd_maintenance_daily_maintenance' ) ) {
				wp_schedule_event( time() + 60, 'daily', 'dd_maintenance_daily_maintenance' );
			}
		} else {
			wp_clear_scheduled_hook( 'dd_maintenance_daily_maintenance' );
			wp_clear_scheduled_hook( 'backuper_daily_maintenance' );
		}
	}

	/**
	 * Executa a manutenção completa via cron (backup -> S3 -> plugins -> core).
	 */
	public function cron_full_maintenance() {
		$log = $this->run_full();
		set_transient( 'dd_maintenance_last_log', $log, DAY_IN_SECONDS );
		set_transient( 'backuper_last_log', $log, DAY_IN_SECONDS );
	}

	/**
	 * Executa a manutenção completa:
	 * 1. Verificação de travas no wp-config (aviso caso DISALLOW_FILE_MODS esteja ativo).
	 * 2. Backup do site.
	 * 3. Envio para o bucket S3 (DigitalOcean Spaces).
	 * 4. Atualização de todos os plugins.
	 * 5. Atualização do core do WordPress.
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

		// 3. Plugins.
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

		// 4. Core.
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
