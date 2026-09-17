<?php
/**
 * Compatibilidade retroativa para nomes públicos do Backuper.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Legacy_Compatibility {

	/**
	 * A remoção dos contratos legados fica reservada para a próxima major.
	 */
	const REMOVAL_TARGET = '3.0.0';

	/**
	 * Opção agregada de telemetria de compatibilidade, sem dados do operador.
	 */
	const USAGE_OPTION = 'dd_maintenance_legacy_usage';

	/**
	 * @var bool
	 */
	private static $notice_registered = false;

	/**
	 * Retorna o inventário de aliases públicos legados.
	 *
	 * @return array<string, string>
	 */
	public static function aliases(): array {
		return array(
			'Backuper'               => 'DD_Maintenance',
			'Backuper_Backup'        => 'DD_Maintenance_Backup',
			'Backuper_S3'            => 'DD_Maintenance_S3',
			'Backuper_Updater'       => 'DD_Maintenance_Updater',
			'Backuper_Settings'      => 'DD_Maintenance_Settings',
			'DD_Gerenciador_Updates' => 'DD_Maintenance_Config',
		);
	}
	/**
	 * Retorna a tabela de migração dos contratos legados conhecidos.
	 *
	 * A tabela é estática e não inclui dados de operadores ou instalações.
	 *
	 * @return array<string, array<int|string, mixed>>
	 */
	public static function migration_table(): array {
		return array(
			'classes'    => self::aliases(),
			'hooks'      => array(
				'admin_post_backuper_save_settings'         => 'admin_post_dd_maintenance_save_settings',
				'admin_post_backuper_update_plugins'        => 'admin_post_dd_maintenance_update_plugins',
				'admin_post_backuper_update_core'           => 'admin_post_dd_maintenance_update_core',
				'admin_post_backuper_run_full'              => 'admin_post_dd_maintenance_run_full',
				'admin_post_backuper_run_backup'            => 'admin_post_dd_maintenance_run_backup',
				'admin_post_backuper_download_backup'       => 'admin_post_dd_maintenance_download_backup',
				'backuper_daily_maintenance'               => 'dd_maintenance_daily_maintenance',
			),
			'options'    => array(
				'backuper_settings'                    => 'dd_maintenance_settings',
				'dd_gerenciador_updates_password_hash' => 'dd_maintenance_password_hash',
			),
			'transients' => array(
				'backuper_last_log' => 'dd_maintenance_last_log',
				'backuper_notice'   => 'dd_maintenance_notice',
			),
			'wrappers'   => array(
				'backuper.php'               => 'dd-maintenance.php',
				'class-backuper.php'         => 'dd-maintenance.php',
				'class-backuper-backup.php'  => 'dd-maintenance.php',
				'class-backuper-s3.php'      => 'dd-maintenance.php',
				'class-backuper-settings.php' => 'dd-maintenance.php',
				'class-backuper-updater.php' => 'dd-maintenance.php',
			),
			'removal'    => array(
				'target_version' => self::REMOVAL_TARGET,
				'usage_option'   => self::USAGE_OPTION,
			),
		);
	}


	/**
	 * Registra os nomes públicos mantidos por compatibilidade.
	 */
	public static function register(): void {
		foreach ( self::aliases() as $legacy => $canonical ) {
			if ( ! class_exists( $legacy ) && class_exists( $canonical ) ) {
				class_alias( $canonical, $legacy );
			}
		}

	}

	/**
	 * Registra o carregamento explícito de um wrapper antigo.
	 *
	 * @param string $wrapper Nome do arquivo wrapper.
	 */
	public static function register_wrapper( string $wrapper ): void {
		self::record_usage( 'wrapper_' . $wrapper );
		self::register_deprecation_notice();

		if ( function_exists( '_deprecated_file' ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress deprecation API receives contract metadata, not browser output.
			_deprecated_file(
				$wrapper,
				self::REMOVAL_TARGET,
				'dd-maintenance.php',
				'Use o carregador principal do DD Maintenance.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Registra um hook legado apontando para o mesmo caso de uso canônico.
	 *
	 * @param string   $hook           Nome do hook legado.
	 * @param callable $callback       Callback canônico.
	 * @param int      $priority       Prioridade do hook.
	 * @param int      $accepted_args  Quantidade de argumentos aceitos.
	 */
	public static function register_legacy_hook( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action(
			$hook,
			function () use ( $hook, $callback ) {
				self::record_usage( 'hook_' . $hook );
				return call_user_func_array( $callback, func_get_args() );
			},
			$priority,
			$accepted_args
		);
	}

	/**
	 * Registra uso agregado de um contrato legado.
	 *
	 * Somente o nome estável do contrato e contadores são persistidos.
	 *
	 * @param string $contract Identificador interno do contrato.
	 */
	public static function record_usage( string $contract ): void {
		$key = strtolower( (string) preg_replace( '/[^a-z0-9_]+/i', '_', $contract ) );
		$key = trim( $key, '_' );
		if ( '' === $key ) {
			return;
		}

		$usage     = get_option( self::USAGE_OPTION, array() );
		$contracts = is_array( $usage['contracts'] ?? null ) ? $usage['contracts'] : array();
		$now       = time();
		$entry     = is_array( $contracts[ $key ] ?? null ) ? $contracts[ $key ] : array();
		$entry['count']      = (int) ( $entry['count'] ?? 0 ) + 1;
		$entry['first_seen'] = (int) ( $entry['first_seen'] ?? $now );
		$entry['last_seen']  = $now;
		$contracts[ $key ]    = $entry;

		update_option(
			self::USAGE_OPTION,
			array(
				'schema_version' => 1,
				'contracts'      => $contracts,
			),
			false
		);
		self::register_deprecation_notice();
	}

	/**
	 * Retorna os contadores agregados de compatibilidade.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function usage(): array {
		$usage = get_option( self::USAGE_OPTION, array() );
		return is_array( $usage['contracts'] ?? null ) ? $usage['contracts'] : array();
	}

	/**
	 * Registra o aviso administrativo uma única vez por processo.
	 */
	private static function register_deprecation_notice(): void {
		if ( self::$notice_registered || ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'render_deprecation_notice' ), 5 );
		self::$notice_registered = true;
	}

	/**
	 * Exibe o aviso antes da remoção dos contratos.
	 */
	public static function render_deprecation_notice(): void {
		if ( function_exists( 'is_admin' ) && ! is_admin() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( defined( 'DD_MAINTENANCE_VERSION' ) && version_compare( DD_MAINTENANCE_VERSION, self::REMOVAL_TARGET, '>=' ) ) {
			return;
		}

		$message = sprintf(
			__( 'Os aliases, hooks e wrappers Backuper estão obsoletos e serão removidos no DD Maintenance %s. Migre para os nomes dd_maintenance antes dessa versão.', 'dd-maintenance' ),
			self::REMOVAL_TARGET
		);
		$escaped = function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The message is escaped above and only renders the fixed notice shell.
		echo '<div class="notice notice-warning"><p>' . $escaped . '</p></div>';
	}
}
