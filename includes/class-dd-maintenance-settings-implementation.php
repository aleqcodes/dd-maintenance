<?php
/**
 * Página de configurações e ações de administração do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-dd-maintenance-settings-repository.php';
require_once __DIR__ . '/class-dd-maintenance-file-security.php';
require_once __DIR__ . '/class-dd-maintenance-backup-workflow.php';
require_once __DIR__ . '/class-dd-maintenance-restore-workflow.php';
require_once __DIR__ . '/class-dd-maintenance-admin-page-renderer.php';
require_once __DIR__ . '/class-dd-maintenance-admin-page-data.php';
require_once __DIR__ . '/class-dd-maintenance-admin-action-controller.php';
require_once __DIR__ . '/class-dd-maintenance-backup-action-controller.php';
require_once __DIR__ . '/class-dd-maintenance-restore-action-controller.php';
require_once __DIR__ . '/class-dd-maintenance-admin-request.php';
require_once __DIR__ . '/class-dd-maintenance-request.php';


class DD_Maintenance_Settings_Implementation {
	private const VALID_TABS = array( 'general', 'config', 's3', 'cron', 'restore', 'logs' );
	/**
	 * Repositório das configurações persistidas.
	 *
	 * @var DD_Maintenance_Settings_Repository
	 */
	private $settings_repository;
	/**
	 * Caso de uso de backup.
	 *
	 * @var DD_Maintenance_Backup_Workflow
	 */
	private $backup_workflow;

	/**
	 * Caso de uso de restauração.
	 *
	 * @var DD_Maintenance_Restore_Workflow
	 */
	private $restore_workflow;
	/**
	 * Renderer da página administrativa.
	 *
	 * @var DD_Maintenance_Admin_Page_Renderer
	 */
	private $page_renderer;
	/**
	 * Controller das ações administrativas.
	 *
	 * @var DD_Maintenance_Admin_Action_Controller
	 */
	private $action_controller;
	/**
	 * Controller das ações específicas de backup.
	 *
	 * @var DD_Maintenance_Backup_Action_Controller
	 */
	private $backup_action_controller;
	/**
	 * Controller das ações específicas de restauração.
	 *
	 * @var DD_Maintenance_Restore_Action_Controller
	 */
	private $restore_action_controller;

	/** @var DD_Maintenance_Admin_Page_Data */
	private $page_data;


	/**
	 * Construtor da implementação interna.
	 *
	 * Os hooks da fronteira WordPress são registrados exclusivamente pela
	 * fachada DD_Maintenance_Settings, depois da composição dos handlers que
	 * aplicam autorização e normalização de entrada.
	 */
	public function __construct() {
		$this->settings_repository       = new DD_Maintenance_Settings_Repository();
		$this->backup_workflow           = new DD_Maintenance_Backup_Workflow();
		$this->restore_workflow          = new DD_Maintenance_Restore_Workflow();
		$this->page_renderer             = new DD_Maintenance_Admin_Page_Renderer();
		$this->page_data                 = new DD_Maintenance_Admin_Page_Data( $this->settings_repository, $this->backup_workflow->storage_service() );
		$this->action_controller         = new DD_Maintenance_Admin_Action_Controller( $this );
		$this->backup_action_controller  = new DD_Maintenance_Backup_Action_Controller( $this );
		$this->restore_action_controller = new DD_Maintenance_Restore_Action_Controller( $this );
	}

	/**
	 * Redireciona links antigos de backuper e gerenciador-de-updates-dd para a nova página.
	 */
	public function handle_legacy_redirects( ?DD_Maintenance_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Request ? $request : DD_Maintenance_Request::from_globals();
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = $request->get_key( 'page' );
		if ( in_array( $page, array( 'backuper', 'gerenciador-de-updates-dd' ), true ) ) {
			$tab = 'gerenciador-de-updates-dd' === $page ? '&tab=config' : '';
			wp_safe_redirect( admin_url( 'admin.php?page=dd-maintenance' . $tab ) );
			exit;
		}
	}

	/**
	 * Retorna a URL base da página de administração.
	 *
	 * @param string $tab Aba opcional.
	 * @return string
	 */
	public function page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=dd-maintenance' );
		if ( '' === (string) $tab ) {
			return $url;
		}
		$tab = sanitize_key( (string) $tab );
		if ( 'backups' === $tab ) {
			$tab = 'restore';
		}
		if ( ! in_array( $tab, self::VALID_TABS, true ) ) {
			$tab = 'general';
		}
		return add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Registra o menu de administração do DD Maintenance.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DD Maintenance', 'dd-maintenance' ),
			__( 'DD Maintenance', 'dd-maintenance' ),
			'manage_options',
			'dd-maintenance',
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			79
		);

		// Atalho em Configurações também para conveniência.
		add_options_page(
			__( 'DD Maintenance', 'dd-maintenance' ),
			__( 'DD Maintenance', 'dd-maintenance' ),
			'manage_options',
			'dd-maintenance-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renderiza a página principal com abas.
	 */
	public function render_page( ?DD_Maintenance_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Request ? $request : DD_Maintenance_Request::from_globals();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'dd-maintenance' ) );
		}

		$current_tab = $request->get_key( 'tab', 'general' );
		if ( 'backups' === $current_tab ) {
			$current_tab = 'restore';
		}
		$valid_tabs  = self::VALID_TABS;
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'general';
		}

		$page_data = $this->page_data->for_tab( $current_tab );
		$settings  = $page_data['settings'];
		if ( function_exists( 'wp_enqueue_style' ) ) {
			$asset_version = defined( 'DD_MAINTENANCE_VERSION' ) ? DD_MAINTENANCE_VERSION : '1.0.0';
			wp_enqueue_style( 'dd-maintenance-admin', plugins_url( '../assets/css/dd-maintenance-admin.css', __FILE__ ), array(), $asset_version );
			wp_enqueue_script( 'dd-maintenance-admin', plugins_url( '../assets/js/dd-maintenance-admin.js', __FILE__ ), array(), $asset_version, true );
			if ( function_exists( 'wp_localize_script' ) ) {
				wp_localize_script(
					'dd-maintenance-admin',
					'DDMaintenanceAdmin',
					array(
						'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
						'nonce'           => wp_create_nonce( 'dd_maint_ajax_nonce' ),
						'downloadBaseUrl' => admin_url( 'admin-post.php' ),
						'downloadNonce'   => wp_create_nonce( 'dd_maintenance_download_backup' ),
					)
				);
			}
		}

		?>
		<div class="wrap dd-maintenance-wrap">
			<h1>
				<span class="dashicons dashicons-shield-alt"></span>
				<?php esc_html_e( 'DD Maintenance', 'dd-maintenance' ); ?>
				<span class="dd-maint-style-font-size-13px-font-weight-normal-color-666-marg-778af7">v<?php echo esc_html( DD_MAINTENANCE_VERSION ); ?></span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Painel unificado de manutenção do WordPress: gerenciamento seguro de travas no wp-config.php, backups completos com envio ao S3 (DigitalOcean Spaces) e atualizações automáticas.', 'dd-maintenance' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix dd-maint-style-margin-bottom-20px-b75fad">
				<a href="<?php echo esc_url( $this->page_url( 'general' ) ); ?>" class="nav-tab <?php echo 'general' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-dashboard"></span>
					<?php esc_html_e( 'Visão Geral & Ações', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 'config' ) ); ?>" class="nav-tab <?php echo 'config' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Travas wp-config.php', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 's3' ) ); ?>" class="nav-tab <?php echo 's3' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-cloud-upload"></span>
					<?php esc_html_e( 'Backup & S3 Spaces', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 'cron' ) ); ?>" class="nav-tab <?php echo 'cron' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-clock"></span>
					<?php esc_html_e( 'Agendamento & Automação', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="nav-tab <?php echo 'restore' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-database-import"></span>
					<?php esc_html_e( 'Backups Locais & Restauração', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 'logs' ) ); ?>" class="nav-tab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-media-text"></span>
					<?php esc_html_e( 'Logs & Histórico', 'dd-maintenance' ); ?>
				</a>
			</nav>

			<?php
			switch ( $current_tab ) {
				case 'config':
					$this->page_renderer->render_tab_config( $page_data['config_status'], $page_data['has_password'] );
					break;

				case 's3':
					$this->page_renderer->render_tab_s3( $settings, $page_data['s3_configured'], $page_data['s3'], $page_data['split_size_mb'], $page_data['remote_backups'] );
					break;

				case 'cron':
					$this->page_renderer->render_tab_cron( $settings, $page_data['next_cron'], $page_data['split_size_mb'] );
					break;

				case 'restore':
					$this->page_renderer->render_tab_restore( $page_data['has_password'] ?? false, $page_data['local_backups'], $page_data['max_upload'], $page_data['s3'], $page_data['s3_configured'] );
					break;

				case 'logs':
					$this->page_renderer->render_tab_logs( $page_data['last_log'], $page_data['saved_logs'], $page_data['last_event'], $page_data['event_summary'] );
					break;

				case 'general':
				default:
					$this->page_renderer->render_tab_general( $page_data['s3_configured'], $page_data['s3'], $page_data['config_status'], $settings, $page_data['last_log'], $page_data['local_backups'], $page_data['next_cron'] );
					break;
			}
			?>
		<!-- Modal de Progresso em Tempo Real (0 a 100%) -->
		<div id="dd-maint-progress-modal" class="dd-maint-modal dd-maint-style-display-none-c8be1c">
			<div class="dd-maint-modal-backdrop"></div>
			<div class="dd-maint-modal-dialog">
				<div class="dd-maint-modal-header">
					<h3>
						<span id="dd-maint-modal-icon" class="dashicons dashicons-update dd-maint-spin dd-maint-style-color-2271b1-font-size-22px-width-22px-height-22-0a10bf"></span>
						<span id="dd-maint-modal-title-text"><?php esc_html_e( 'Processando...', 'dd-maintenance' ); ?></span>
					</h3>
					<span id="dd-maint-modal-percent" class="dd-maint-badge">0%</span>
				</div>

				<div class="dd-maint-modal-body">
					<div class="dd-maint-progress-container">
						<div id="dd-maint-progress-bar" class="dd-maint-progress-bar dd-maint-style-width-0-b05fd1"></div>
					</div>

					<p id="dd-maint-status-text" class="dd-maint-status-text"><?php esc_html_e( 'Iniciando operação...', 'dd-maintenance' ); ?></p>
					<!-- Painel de Downloads Imediatos (Exibido ao finalizar backup) -->
					<div id="dd-maint-downloads-container" class="dd-maint-download-box dd-maint-style-display-none-c8be1c">
						<div class="dd-maint-style-display-flex-justify-content-space-between-align-005261">
							<div class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac">
								<span class="dashicons dashicons-download dd-maint-style-color-46b450-font-size-18px-width-18px-height-18-64c686"></span>
								<strong class="dd-maint-style-font-size-13-5px-color-1d2327-945815"><?php esc_html_e( 'Baixar Arquivos de Backup Agora', 'dd-maintenance' ); ?></strong>
								<span id="dd-maint-downloads-summary-badge" class="dd-maint-part-badge dd-maint-style-display-none-c8be1c"></span>
							</div>
							<button type="button" id="dd-maint-download-all-btn" class="button button-primary button-small dd-maint-style-display-none-c8be1c">
								<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
								<span id="dd-maint-download-all-btn-text"><?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?></span>
							</button>
						</div>
						<div id="dd-maint-downloads-filter-wrap" class="dd-maint-style-display-none-margin-bottom-8px-4e9275">
							<input type="text" id="dd-maint-downloads-filter" placeholder="<?php esc_attr_e( 'Filtrar volumes (ex: part120, sql)...', 'dd-maintenance' ); ?>" class="dd-maint-style-width-100-font-size-12px-padding-4px-8px-border--813fa3">
						</div>
						<div id="dd-maint-downloads-list"></div>
					</div>

					<div class="dd-maint-console-container">
						<div class="dd-maint-console-header">
							<span><?php esc_html_e( 'Terminal de Logs em Tempo Real', 'dd-maintenance' ); ?></span>
						</div>
						<pre id="dd-maint-console-output" class="dd-maint-console"></pre>
					</div>
				</div>

				<div class="dd-maint-modal-footer dd-maint-style-display-flex-justify-content-space-between-align-812972">
					<div>
						<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" id="dd-maint-modal-view-backups-btn" class="button button-secondary dd-maint-style-display-none-c8be1c">
							<span class="dashicons dashicons-database-import dd-maint-style-vertical-align-middle-font-size-15px-width-15px--92034c"></span>
							<?php esc_html_e( 'Ver Todos os Backups Locais', 'dd-maintenance' ); ?>
						</a>
					</div>
					<div class="dd-maint-style-display-flex-gap-8px-b9bbe5">
						<button type="button" id="dd-maint-modal-dismiss-btn" class="button button-secondary dd-maint-style-display-none-c8be1c">
							<?php esc_html_e( 'Fechar', 'dd-maintenance' ); ?>
						</button>
						<button type="button" id="dd-maint-modal-close-btn" class="button button-primary dd-maint-style-display-none-c8be1c" data-dd-reload-click="1">
							<?php esc_html_e( 'Concluído (Atualizar Página)', 'dd-maintenance' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		</div>
		<?php
	}
	/**
	 * Handler: Restauração a partir de upload .zip.
	 */
	public function handle_restore_upload( ?DD_Maintenance_Restore_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Restore_Request ? $request : DD_Maintenance_Restore_Request::from_globals();
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e restores novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		if ( DD_Maintenance_Config::has_password() ) {
			$password = $request->post_secret( 'restore_password' );
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Senha incorreta. Restauração cancelada.', 'dd-maintenance' ), 'error' );
				wp_safe_redirect( $this->page_url( 'restore' ) );
				exit;
			}
		}

		$file_input = $request->file_input( 'backup_zip' );
		if ( empty( $file_input ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nenhum arquivo enviado.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}
		$apply_elementor_compatibility = $request->post_flag( 'apply_elementor_compatibility' );

		$result  = $this->restore_workflow->from_upload( $file_input, $apply_elementor_compatibility );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result->log() ) ) {
				set_transient( 'dd_maintenance_last_log', $result->log(), DAY_IN_SECONDS );
			}
			DD_Maintenance::instance()->set_notice( __( 'Backup restaurado com sucesso!', 'dd-maintenance' ), 'success' );
		}

		wp_safe_redirect( $this->page_url( 'restore' ) );
		exit;
	}

	/**
	 * Handler: Restauração a partir de arquivo local.
	 */
	public function handle_restore_local( ?DD_Maintenance_Restore_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Restore_Request ? $request : DD_Maintenance_Restore_Request::from_globals();
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e restores novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		if ( DD_Maintenance_Config::has_password() ) {
			$password = $request->post_secret( 'restore_password' );
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Senha incorreta. Restauração cancelada.', 'dd-maintenance' ), 'error' );
				wp_safe_redirect( $this->page_url( 'restore' ) );
				exit;
			}
		}

		$filename = sanitize_file_name( $request->post_text( 'backup_filename' ) );
		if ( empty( $filename ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nome de arquivo inválido.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}
		$apply_elementor_compatibility = $request->post_flag( 'apply_elementor_compatibility' );

		$result  = $this->restore_workflow->from_local( $filename, $apply_elementor_compatibility );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result->log() ) ) {
				set_transient( 'dd_maintenance_last_log', $result->log(), DAY_IN_SECONDS );
			}
			DD_Maintenance::instance()->set_notice( __( 'Backup local restaurado com sucesso!', 'dd-maintenance' ), 'success' );
		}

		wp_safe_redirect( $this->page_url( 'restore' ) );
		exit;
	}

	/**
	 * Handler: Exclusão de arquivo de backup local (e opcionalmente do S3 / Spaces).
	 */
	public function handle_delete_backup( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();

		$filename = sanitize_file_name( $request->post_text( 'backup_filename' ) );
		if ( empty( $filename ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nome de arquivo inválido.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		$deleted = DD_Maintenance_Restore::delete_local_backup( $filename );

		$remote_msg = '';
		if ( $request->post_flag( 'delete_remote' ) ) {
			$s3 = $this->backup_workflow->storage_service();
			if ( $s3->is_configured() ) {
				$remote_result = $s3->delete_backup_remote( $filename );
				if ( ! empty( $remote_result['deleted'] ) ) {
					$remote_msg = sprintf( __( ' e %d arquivo(s) removido(s) do S3 / Spaces', 'dd-maintenance' ), $remote_result['deleted'] );
				} elseif ( ! empty( $remote_result['errors'] ) ) {
					$remote_msg = ' (' . __( 'Aviso S3: ', 'dd-maintenance' ) . implode( '; ', $remote_result['errors'] ) . ')';
				} else {
					$remote_msg = ' (' . __( 'nenhum arquivo correspondente encontrado no S3', 'dd-maintenance' ) . ')';
				}
			}
		}

		if ( $deleted ) {
			DD_Maintenance::instance()->set_notice( __( 'Arquivo(s) de backup local excluído(s) com sucesso', 'dd-maintenance' ) . $remote_msg . '.', 'success' );
		} elseif ( ! empty( $remote_msg ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Backup local não encontrado, mas', 'dd-maintenance' ) . $remote_msg . '.', 'info' );
		} else {
			DD_Maintenance::instance()->set_notice( __( 'Arquivo não encontrado.', 'dd-maintenance' ), 'error' );
		}
		$redirect_tab = $request->post_key( 'redirect_tab', 'restore' );
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}

	/**
	 * Handler: Exclusão de todos os volumes de um backup agrupado no S3 / Spaces.
	 */
	public function handle_delete_s3_backup( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();

		$identifier = sanitize_file_name( $request->post_text( 'backup_identifier' ) );
		if ( empty( $identifier ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Identificador de backup S3 inválido.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$s3 = $this->backup_workflow->storage_service();
		if ( ! $s3->is_configured() ) {
			DD_Maintenance::instance()->set_notice( __( 'S3 / Spaces não configurado.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$result = $s3->delete_backup_remote( $identifier );
		if ( ! empty( $result['deleted'] ) ) {
			$message = sprintf(
				_n(
					'Backup "%1$s" excluído do S3 (%2$d arquivo).',
					'Backup "%1$s" excluído do S3 (%2$d arquivos).',
					(int) $result['deleted'],
					'dd-maintenance'
				),
				$identifier,
				(int) $result['deleted']
			);
			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . implode( ' ', $result['errors'] );
			}
			DD_Maintenance::instance()->set_notice( $message, 'success' );
		} elseif ( ! empty( $result['errors'] ) ) {
			DD_Maintenance::instance()->set_notice( implode( ' ', $result['errors'] ), 'error' );
		} else {
			DD_Maintenance::instance()->set_notice( __( 'Nenhum arquivo desse backup foi encontrado no S3.', 'dd-maintenance' ), 'warning' );
		}
		$redirect_tab = $request->post_key( 'redirect_tab', 's3' );
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}

	/**
	 * Handler: Exclusão direta de um objeto do bucket S3 / Spaces.
	 */
	public function handle_delete_s3_object( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();

		$object_key = $request->post_text( 'object_key' );
		if ( empty( $object_key ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Chave de objeto S3 inválida.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$s3 = $this->backup_workflow->storage_service();
		if ( ! $s3->is_configured() ) {
			DD_Maintenance::instance()->set_notice( __( 'S3 / Spaces não configurado.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$result = $s3->delete_object( $object_key );
		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro ao excluir arquivo no S3: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			DD_Maintenance::instance()->set_notice( sprintf( __( 'Arquivo "%s" excluído com sucesso do bucket S3 / Spaces.', 'dd-maintenance' ), esc_html( basename( $object_key ) ) ), 'success' );
		}

		$redirect_tab = $request->post_key( 'redirect_tab', 's3' );
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}
	/**
	 * Exibe a notificação flash de administrador.
	 */
	public function show_notice() {
		$persistence_error = get_transient( 'dd_maintenance_last_event_error' );
		if ( ! empty( $persistence_error ) ) {
			delete_transient( 'dd_maintenance_last_event_error' );
			$event_label = is_array( $persistence_error )
				? ( (string) ( $persistence_error['operation'] ?? 'operation' ) . '/' . (string) ( $persistence_error['event'] ?? 'event' ) )
				: 'operation/event';
			$correlation_id = is_array( $persistence_error ) ? (string) ( $persistence_error['correlation_id'] ?? '' ) : '';
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						__( 'Alerta de observabilidade: o evento %1$s foi criado, mas não pôde ser persistido no JSONL. Verifique a pasta de logs. Correlação: %2$s.', 'dd-maintenance' ),
						$event_label,
						$correlation_id ? $correlation_id : __( 'indisponível', 'dd-maintenance' )
					)
				)
			);
		}

		$notice = get_transient( 'dd_maintenance_notice' );
		if ( empty( $notice ) ) {
			$notice = get_transient( 'backuper_notice' );
			if ( ! empty( $notice ) && class_exists( 'DD_Maintenance_Legacy_Compatibility' ) ) {
				DD_Maintenance_Legacy_Compatibility::record_usage( 'transient_backuper_notice' );
			}
			delete_transient( 'backuper_notice' );
		}

		if ( empty( $notice ) ) {
			return;
		}

		delete_transient( 'dd_maintenance_notice' );

		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Handler: Salva configurações de S3, backup e agendamento.
	 */
	public function save_settings( ?DD_Maintenance_Settings_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Settings_Request ? $request : DD_Maintenance_Settings_Request::from_globals();
		$settings = $this->settings_repository->get();
		$do_detect = $request->post_flag( 'dd_maint_detect_region' );

		if ( $request->has_post( 's3_access_key' ) ) {
			$settings['s3_access_key'] = $request->post_text( 's3_access_key' );
		}
		if ( $request->has_post( 's3_secret_key' ) ) {
			$settings['s3_secret_key'] = $request->post_secret( 's3_secret_key' );
		}
		if ( $request->has_post( 's3_bucket' ) ) {
			$settings['s3_bucket'] = $request->post_text( 's3_bucket' );
		}
		if ( $request->has_post( 's3_region' ) ) {
			$settings['s3_region'] = $request->post_key( 's3_region' );
		}
		if ( $request->has_post( 's3_endpoint' ) ) {
			$settings['s3_endpoint'] = $request->post_text( 's3_endpoint' );
		}

		if ( $request->has_post( 'include_db' ) || $request->has_post( 'include_entire' ) ) {
			$settings['include_db']        = $request->post_flag( 'include_db' ) ? 1 : 0;
			$settings['include_wpcontent'] = $request->post_flag( 'include_wpcontent' ) ? 1 : 0;
			$settings['include_wpconfig']  = $request->post_flag( 'include_wpconfig' ) ? 1 : 0;
			$settings['include_entire']    = $request->post_flag( 'include_entire' ) ? 1 : 0;
			$settings['keep_local']        = $request->post_flag( 'keep_local' ) ? 1 : 0;
		}

		if ( $request->has_post( 'schedule_enabled' ) || ! $do_detect ) {
			$settings['schedule_enabled'] = $request->post_flag( 'schedule_enabled' ) ? 1 : 0;
		}
		if ( $request->has_post( 'schedule_frequency' ) ) {
			$settings['schedule_frequency'] = $request->post_key( 'schedule_frequency' );
		}
		if ( $request->has_post( 'schedule_time' ) ) {
			$settings['schedule_time'] = $request->post_text( 'schedule_time' );
		}
		if ( $request->has_post( 'split_size_mb' ) ) {
			$settings['split_size_mb'] = $request->post_int( 'split_size_mb' );
		}
		if ( $request->has_post( 'retention_local' ) ) {
			$settings['retention_local'] = $request->post_int( 'retention_local' );
		}

		$redirect_tab = $request->post_key( 'active_tab', 's3' );
		if ( ! $this->settings_repository->save( $settings ) ) {
			DD_Maintenance::record_event( 'settings', 'checkpoint_failed', array( 'step' => 'save_settings', 'session_id' => '', 'status' => 'failure', 'failure_code' => 'settings_save_failed', 'error_count' => 1 ) );
			DD_Maintenance::instance()->set_notice( __( 'Não foi possível persistir as configurações. Nenhuma alteração foi aplicada.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( $redirect_tab ) );
			exit;
		}
		if ( $do_detect ) {
			$s3 = $this->backup_workflow->storage_service();

			if ( empty( $s3->get_bucket() ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Informe o nome do bucket antes de detectar a região.', 'dd-maintenance' ), 'error' );
			} else {
				$detected = $s3->detect_region();

				if ( is_wp_error( $detected ) ) {
					DD_Maintenance::instance()->set_notice( $detected->get_error_message(), 'error' );
				} else {
					$settings['s3_region'] = $detected;
					if ( ! $this->settings_repository->save( $settings ) ) {
						DD_Maintenance::record_event( 'settings', 'checkpoint_failed', array( 'step' => 'save_region', 'session_id' => '', 'status' => 'failure', 'failure_code' => 'settings_save_failed', 'error_count' => 1 ) );
						DD_Maintenance::instance()->set_notice( __( 'A região foi detectada, mas não foi possível persistir a configuração.', 'dd-maintenance' ), 'error' );
					} else {
						DD_Maintenance::instance()->set_notice(
							sprintf(
								/* translators: %s: Região detectada */
								__( 'Bucket localizado na região %s! Região atualizada automaticamente.', 'dd-maintenance' ),
								$detected
							),
							'success'
						);
					}
				}
			}
		} else {
			if ( $settings['s3_access_key'] && 0 !== strpos( $settings['s3_access_key'], 'DO00' ) ) {
				DD_Maintenance::instance()->set_notice(
					__( 'Configurações salvas, mas a Access Key não parece ser uma chave de Spaces do DigitalOcean (geralmente começa com "DO00"). Confira se não trocou com o Secret Key.', 'dd-maintenance' ),
					'warning'
				);
			} else {
				DD_Maintenance::instance()->set_notice( __( 'Configurações salvas com sucesso.', 'dd-maintenance' ), 'success' );
			}
		}

		DD_Maintenance::maybe_schedule_cron();

		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}

	/**
	 * Handler: Tratamento de ações do wp-config e senhas.
	 */
	public function handle_config_action( ?DD_Maintenance_Settings_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Settings_Request ? $request : DD_Maintenance_Settings_Request::from_globals();
		$result = DD_Maintenance_Config::handle_post( $request );

		if ( is_array( $result ) && isset( $result['message'] ) ) {
			DD_Maintenance::instance()->set_notice( $result['message'], $result['type'] ?? 'info' );
		}

		wp_safe_redirect( $this->page_url( 'config' ) );
		exit;
	}

	/**
	 * Handler: backup + envio ao S3.
	 */
	public function handle_backup() {
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e backups novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'general' ) );
			exit;
		}

		$backup_result = $this->backup_workflow->create();
		if ( is_wp_error( $backup_result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro no backup: ', 'dd-maintenance' ) . $backup_result->get_error_message(), 'error' );
			wp_safe_redirect( $this->page_url( 'general' ) );
			exit;
		}

		$storage = $this->backup_workflow->storage_service();
		if ( ! $storage->is_configured() ) {
			DD_Maintenance::instance()->set_notice( __( 'Configure as credenciais do DigitalOcean Spaces antes de executar o envio.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$site_slug  = sanitize_title( get_bloginfo( 'name' ) );
		$folder     = ( $site_slug ? $site_slug : 'site' ) . '/' . current_time( 'Y-m-d' );
		$parts      = ! empty( $backup_result->parts() ) ? $backup_result->parts() : array( array( 'file' => $backup_result->file(), 'name' => $backup_result->name(), 'size' => $backup_result->size(), 'part' => 1 ) );
		$total_size = $backup_result->total_size() > 0 ? $backup_result->total_size() : $backup_result->size();

		$upload_result = $this->backup_workflow->upload_parts( $parts, $folder, (int) $total_size, $backup_result->correlation_id() );
		if ( ! $upload_result->is_success() ) {
			DD_Maintenance::instance()->set_notice( implode( ' ', $upload_result->errors() ), 'error' );
		} else {
			$chunk_size_mb = $backup_result->chunk_size_mb();
			DD_Maintenance::instance()->set_notice(
				sprintf(
					/* translators: 1: Quantidade de partes, 2: Tamanho máximo configurado, 3: Tamanho formatado, 4: Pasta no S3, 5: Nome do bucket */
					__( 'Backup gerado em %1$d parte(s) de até %2$d MB (Total: %3$s) e enviado com sucesso para "%4$s" no bucket "%5$s".', 'dd-maintenance' ),
					count( $parts ),
					$chunk_size_mb,
					size_format( $total_size ),
					$folder,
					$storage->get_bucket()
				),
				'success'
			);
		}

		wp_safe_redirect( $this->page_url( 'general' ) );
		exit;
	}

	/**
	 * Handler: atualizar plugins.
	 */
	public function handle_plugins() {

		$updater = new DD_Maintenance_Updater();
		$result  = $updater->update_plugins();

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro ao atualizar plugins: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			$message = sprintf(
				/* translators: %d: Quantidade de plugins atualizados */
				__( 'Plugins atualizados: %d.', 'dd-maintenance' ),
				$result['updated']
			);
			DD_Maintenance::instance()->set_notice( $message, 'success' );
		}

		wp_safe_redirect( $this->page_url( 'general' ) );
		exit;
	}

	/**
	 * Handler: atualizar core.
	 */
	public function handle_core() {

		$updater = new DD_Maintenance_Updater();
		$result  = $updater->update_core();

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro ao atualizar o core: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			DD_Maintenance::instance()->set_notice( $result['message'], $result['updated'] ? 'success' : 'info' );
		}

		wp_safe_redirect( $this->page_url( 'general' ) );
		exit;
	}

	/**
	 * Handler: fluxo completo.
	 */
	public function handle_full() {
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e backups novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'general' ) );
			exit;
		}

		$log = DD_Maintenance::instance()->run_full();
		set_transient( 'dd_maintenance_last_log', $log, DAY_IN_SECONDS );
		set_transient( 'backuper_last_log', $log, DAY_IN_SECONDS );

		$has_error = false;
		foreach ( $log as $line ) {
			if ( 0 === strpos( $line, '[ERRO]' ) ) {
				$has_error = true;
				break;
			}
		}

		DD_Maintenance::instance()->set_notice(
			$has_error ? __( 'Fluxo concluído com erros. Veja o log abaixo.', 'dd-maintenance' ) : __( 'Fluxo completo de manutenção concluído com sucesso!', 'dd-maintenance' ),
			$has_error ? 'warning' : 'success'
		);

		wp_safe_redirect( $this->page_url( 'general' ) );
		exit;
	}

	/**
	 * Handler: limpa o log salvo.
	 */
	public function handle_clear_log() {

		$count = DD_Maintenance::clear_all_saved_logs();
		DD_Maintenance::instance()->set_notice(
			sprintf( __( '%d log(s) removido(s) com sucesso.', 'dd-maintenance' ), $count ),
			'info'
		);

		wp_safe_redirect( $this->page_url( 'logs' ) );
		exit;
	}

	/**
	 * Handler: exclui um log salvo específico.
	 */
	public function handle_delete_log( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();
		$filename = sanitize_file_name( $request->post_text( 'log_filename' ) );
		if ( ! empty( $filename ) && DD_Maintenance::delete_saved_log( $filename ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Arquivo de log excluído com sucesso.', 'dd-maintenance' ), 'info' );
		} else {
			DD_Maintenance::instance()->set_notice( __( 'Não foi possível excluir o log.', 'dd-maintenance' ), 'error' );
		}

		wp_safe_redirect( $this->page_url( 'logs' ) );
		exit;
	}

	/**
	 * Handler: faz download de um arquivo de log salvo.
	 */
	public function handle_download_log( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();
		$filename = sanitize_file_name( $request->get_text( 'log_filename' ) );
		$content  = DD_Maintenance::get_log_content( $filename );
		if ( is_wp_error( $content ) ) {
			wp_die( esc_html( $content->get_error_message() ) );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Download content must remain byte-for-byte unchanged.
		echo $content;
		exit;
	}

	/**
	 * Handler: Faz download seguro de um arquivo de backup local (.zip ou .sql).
	 */
	public function handle_download_backup( ?DD_Maintenance_Artifact_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Artifact_Request ? $request : DD_Maintenance_Artifact_Request::from_globals();

		$filename = sanitize_file_name( $request->get_text( 'file' ) );
		if ( empty( $filename ) ) {
			wp_die( esc_html__( 'Nome de arquivo de backup inválido.', 'dd-maintenance' ), 400 );
		}

		// Garante que a extensão seja estritamente .zip ou .sql
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'zip', 'sql' ), true ) ) {
			wp_die( esc_html__( 'Tipo de arquivo não permitido para download.', 'dd-maintenance' ), 400 );
		}

		$backup_dir = wp_normalize_path( realpath( DD_Maintenance::backup_dir() ) );
		$filepath   = $backup_dir . '/' . $filename;
		$real_path  = file_exists( $filepath ) ? wp_normalize_path( realpath( $filepath ) ) : false;

		// Previne qualquer tentativa de Directory Traversal ou acesso fora da pasta de backups
		if ( empty( $real_path ) || 0 !== strpos( $real_path, $backup_dir . '/' ) || ! is_file( $real_path ) ) {
			wp_die( esc_html__( 'Arquivo de backup não encontrado no servidor.', 'dd-maintenance' ), 404 );
		}

		$filesize = (int) filesize( $real_path );
		$mime     = 'zip' === $ext ? 'application/zip' : 'text/plain; charset=utf-8';

		// Limpa qualquer buffer de saída ativo para transmissão limpa e segura
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Desativa limites de tempo para downloads de arquivos maiores
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 0 );
		}

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . (string) $filesize );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );

		// Transmite em blocos de 2MB para baixo consumo de memória
		$chunk_size = 2 * 1024 * 1024;
		$handle     = fopen( $real_path, 'rb' );
		if ( false !== $handle ) {
			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary download content must remain byte-for-byte unchanged.
				echo fread( $handle, $chunk_size );
				if ( function_exists( 'flush' ) ) {
					flush();
				}
			}
			fclose( $handle );
		} else {
			readfile( $real_path );
		}
		exit;
	}

	/**
	 * Retorna a URL segura de download para um arquivo de backup local.
	 *
	 * @param string $filename Nome do arquivo.
	 * @return string
	 */
	public static function get_download_url( string $filename ): string {
		return add_query_arg(
			array(
				'action'   => 'dd_maintenance_download_backup',
				'file'     => sanitize_file_name( $filename ),
				'_wpnonce' => wp_create_nonce( 'dd_maintenance_download_backup' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Handler AJAX: executa etapas de manutenção com progresso em tempo real.
	 */
	public function ajax_handle_action( ?DD_Maintenance_Backup_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Backup_Request ? $request : DD_Maintenance_Backup_Request::from_globals();
		if ( ! $this->backup_workflow instanceof DD_Maintenance_Backup_Workflow ) {
			$this->backup_workflow = new DD_Maintenance_Backup_Workflow();
		}
		$step           = $request->post_key( 'step' );
		$session_id     = sanitize_file_name( $request->post_text( 'session_id' ) );
		$correlation_id = $request->post_key( 'correlation_id' );
		if ( ! DD_Maintenance_Admin_Request::authorize_ajax( 'dd_maintenance_ajax_action' ) ) {
			$has_capability = is_user_logged_in() && current_user_can( 'manage_options' );
			DD_Maintenance::record_event( 'backup', 'ajax_rejected', array( 'step' => $step, 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $has_capability ? 'invalid_nonce' : 'insufficient_capability', 'error_count' => 1 ) );
			wp_send_json_error(
				array(
					'message' => $has_capability ? __( 'Sessão expirada ou nonce inválido. Recarregue a página.', 'dd-maintenance' ) : __( 'Sem permissão.', 'dd-maintenance' ),
					'correlation_id' => $correlation_id,
				)
			);
		}

		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '512M' );
			ini_set( 'max_execution_time', '3600' );
		}
		ignore_user_abort( true );
		$request_event = DD_Maintenance::record_event( 'backup', 'ajax_step_started', array( 'step' => $step, 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'running' ) );
		$correlation_id = $request_event['correlation_id'];
		if ( $this->operations_disabled() ) {
			DD_Maintenance::record_event( 'backup', 'ajax_rejected', array( 'step' => $step, 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'operations_disabled', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Uploads e backups novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
		}
		switch ( $step ) {
			case 'backup_init':
				$session = $this->backup_workflow->step( 'init', '', $correlation_id );

				if ( is_wp_error( $session ) ) {
					DD_Maintenance::record_event( 'backup', 'step_failed', array( 'step' => 'backup_init', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $session->get_error_code(), 'error_count' => 1 ) );
					wp_send_json_error( array( 'message' => $session->get_error_message() ) );
				}
				DD_Maintenance::record_event( 'backup', 'session_created', array( 'step' => 'backup_init', 'session_id' => $session['session_id'], 'correlation_id' => $correlation_id, 'status' => 'running' ) );
				wp_send_json_success(
					array(
						'session_id'     => $session['session_id'],
						'base_name'      => $session['base_name'],
						'chunk_size_mb'  => (int) round( $this->backup_workflow->backup_service()->get_chunk_size( $session ) / 1048576 ),
						'correlation_id' => $correlation_id,
					)
				);
				break;

			case 'backup_db':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$result     = $this->backup_workflow->step( 'database', $session_id );

				if ( is_wp_error( $result ) ) {
					$this->record_step_failure( 'backup', 'backup_db', $session_id, $result, $correlation_id );
					$this->backup_workflow->cleanup_failed( $session_id, $result->get_error_message() );
					wp_send_json_error( array( 'message' => $result->get_error_message(), 'correlation_id' => $correlation_id ) );
				}

				$this->record_backup_result( 'backup_db', $session_id, $result, $correlation_id );
				wp_send_json_success( array_merge( $result->to_array(), array( 'correlation_id' => $correlation_id ) ) );
				break;

			case 'backup_index':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$result     = $this->backup_workflow->step( 'index', $session_id );

				if ( is_wp_error( $result ) ) {
					$this->record_step_failure( 'backup', 'backup_index', $session_id, $result, $correlation_id );
					$this->backup_workflow->cleanup_failed( $session_id, $result->get_error_message() );
					wp_send_json_error( array( 'message' => $result->get_error_message(), 'correlation_id' => $correlation_id ) );
				}

				$this->record_backup_result( 'backup_index', $session_id, $result, $correlation_id );
				wp_send_json_success( array_merge( $result->to_array(), array( 'correlation_id' => $correlation_id ) ) );
				break;

			case 'backup_zip_batch':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$result     = $this->backup_workflow->step( 'zip', $session_id );

				if ( is_wp_error( $result ) ) {
					$this->record_step_failure( 'backup', 'backup_zip_batch', $session_id, $result, $correlation_id );
					$this->backup_workflow->cleanup_failed( $session_id, $result->get_error_message() );
					wp_send_json_error( array( 'message' => $result->get_error_message(), 'correlation_id' => $correlation_id ) );
				}

				$this->record_backup_result( 'backup_zip_batch', $session_id, $result, $correlation_id );
				wp_send_json_success( array_merge( $result->to_array(), array( 'correlation_id' => $correlation_id ) ) );
				break;

			case 'backup_finalize':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$result     = $this->backup_workflow->step( 'finalize', $session_id );

				if ( is_wp_error( $result ) ) {
					$this->record_step_failure( 'backup', 'backup_finalize', $session_id, $result, $correlation_id );
					$this->backup_workflow->cleanup_failed( $session_id, $result->get_error_message() );
					wp_send_json_error( array( 'message' => $result->get_error_message(), 'correlation_id' => $correlation_id ) );
				}

				$site_slug = sanitize_title( get_bloginfo( 'name' ) );
				$site_slug = $site_slug ? $site_slug : 'site';
				$folder    = $site_slug . '/' . current_time( 'Y-m-d' );

				$result['folder'] = $folder;
				$this->record_backup_result( 'backup_finalize', $session_id, $result, $correlation_id );
				wp_send_json_success( array_merge( $result, array( 'correlation_id' => $correlation_id ) ) );
				break;

			case 'backup_fail_cleanup':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$error_msg  = $request->post_text( 'error' );
				$log_raw    = $request->post_value( 'log', '' );
				$lines      = is_array( $log_raw ) ? $log_raw : explode( "\n", (string) $log_raw );
				$cleanup_result = $this->backup_workflow->cleanup_failed( $session_id, $error_msg, $lines );
				DD_Maintenance::record_event( 'backup', empty( $cleanup_result['errors'] ) ? 'cleanup_finished' : 'cleanup_failed', array( 'step' => 'backup_fail_cleanup', 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => empty( $cleanup_result['errors'] ) ? 'warning' : 'failure', 'failure_code' => empty( $cleanup_result['errors'] ) ? 'backup_aborted' : 'backup_cleanup_failed', 'error_count' => empty( $cleanup_result['errors'] ) ? 1 : count( $cleanup_result['errors'] ), 'cleanup' => $cleanup_result['errors'] ) );
				wp_send_json_success( array( 'cleaned' => empty( $cleanup_result['errors'] ), 'errors' => $cleanup_result['errors'], 'correlation_id' => $correlation_id ) );
				break;

			case 'backup_save_log':
				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				$status     = $request->post_key( 'status', 'success' );
				$base_name  = sanitize_file_name( $request->post_text( 'base_name' ) );
				$log_raw    = $request->post_value( 'log', '' );
				$lines      = is_array( $log_raw ) ? $log_raw : explode( "\n", (string) $log_raw );
				$log_path = DD_Maintenance::save_log( $lines, $status, $base_name );
				if ( '' === $log_path ) {
					$this->record_step_failure( 'backup', 'backup_save_log', $session_id, $log_path, $correlation_id );
					wp_send_json_error( array( 'message' => __( 'Não foi possível salvar o log do backup.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
				}
				DD_Maintenance::record_event( 'backup', 'step_finished', array( 'step' => 'backup_save_log', 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'success', 'progress' => 100 ) );
				wp_send_json_success( array( 'saved' => true, 'correlation_id' => $correlation_id ) );
				break;

			case 'get_log_content':
				$filename = sanitize_file_name( $request->post_text( 'log_filename' ) );
				$content  = DD_Maintenance::get_log_content( $filename );
				if ( is_wp_error( $content ) ) {
					wp_send_json_error( array( 'message' => $content->get_error_message() ) );
				}
				wp_send_json_success( array( 'content' => $content ) );
				break;

			case 's3_upload_part':
				if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
					set_time_limit( 0 );
				}
				if ( function_exists( 'ini_set' ) ) {
					ini_set( 'memory_limit', '512M' );
				}
				ignore_user_abort( true );


				$part_file   = $request->post_text( 'part_file' );
				$part_name   = sanitize_file_name( $request->post_text( 'part_name' ) );
				$part_size   = max( 0, $request->post_int( 'part_size' ) );
				$part_index  = max( 1, $request->post_int( 'part_index', 1 ) );
				$total_parts = max( 1, $request->post_int( 'total_parts', 1 ) );
				$folder      = $request->post_text( 'folder', 'site/' . current_time( 'Y-m-d' ) );

				$backup_dir = wp_normalize_path( realpath( DD_Maintenance::backup_dir() ) );
				$real_file  = $part_file ? realpath( $part_file ) : false;
				$real_file  = $real_file ? wp_normalize_path( $real_file ) : '';
				if ( empty( $real_file ) || 0 !== strpos( $real_file, $backup_dir . '/' ) || basename( $real_file ) !== $part_name ) {
					wp_send_json_error( array( 'message' => __( 'Arquivo da parte de backup não encontrado no servidor.', 'dd-maintenance' ) ) );
				}
				$part_file = $real_file;

				$upload_result = $this->backup_workflow->upload_parts(
					array(
						array(
							'file' => $part_file,
							'name' => $part_name,
							'size' => $part_size,
						),
					),
					$folder,
					$part_size,
					$correlation_id
				);

				if ( ! $upload_result->is_success() ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								__( 'Erro no envio da parte %1$d/%2$d: %3$s', 'dd-maintenance' ),
								$part_index,
								$total_parts,
								implode( ' ', $upload_result->errors() )
							),
						)
					);
				}

				wp_send_json_success(
					array(
						'log' => sprintf( __( '[OK] Parte %1$d/%2$d enviada para S3: %3$s (%4$s)', 'dd-maintenance' ), $part_index, $total_parts, $part_name, size_format( $part_size ? $part_size : filesize( $part_file ) ) ),
					)
				);
				break;

			case 'retention':
				$purged = DD_Maintenance::instance()->apply_retention_policy();
				$log    = ! empty( $purged )
					? sprintf( __( '[OK] Retenção: %d backup(s) antigo(s) removido(s).', 'dd-maintenance' ), count( $purged ) )
					: __( '[OK] Retenção verificada (nenhum backup antigo para expurgar).', 'dd-maintenance' );

				$session_id = sanitize_file_name( $request->post_text( 'session_id' ) );
				if ( $session_id ) {
					$this->backup_workflow->cleanup( $session_id );
				}

				wp_send_json_success( array( 'log' => $log ) );
				break;

			case 'plugins':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_plugins();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}

				$lines = array();
				if ( ! empty( $result['logs'] ) ) {
					foreach ( $result['logs'] as $line ) {
						$lines[] = '[Plugins] ' . $line;
					}
				}
				$lines[] = sprintf( __( '[OK] Total de plugins atualizados: %d', 'dd-maintenance' ), $result['updated'] );

				wp_send_json_success( array( 'log' => implode( "\n", $lines ) ) );
				break;

			case 'core':
				$updater = new DD_Maintenance_Updater();
				$result  = $updater->update_core();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}

				wp_send_json_success( array( 'log' => '[Core] ' . $result['message'] ) );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Etapa inválida.', 'dd-maintenance' ) ) );
				break;
		}
	}

	/**
	 * Handler AJAX: restauração com progresso.
	 */
	public function ajax_handle_restore( ?DD_Maintenance_Restore_Request $request = null ) {
		$request = $request instanceof DD_Maintenance_Restore_Request ? $request : DD_Maintenance_Restore_Request::from_globals();
		if ( ! $this->restore_workflow instanceof DD_Maintenance_Restore_Workflow ) {
			$this->restore_workflow = new DD_Maintenance_Restore_Workflow();
		}
		$mode                    = $request->post_key( 'mode' );
		$restore_session_id      = sanitize_file_name( $request->post_text( 'restore_session_id' ) );
		$restore_token           = $request->post_secret( 'restore_token' );
		$correlation_id          = $request->post_key( 'correlation_id' );
		$restore                 = $this->restore_workflow;
		$request_event           = DD_Maintenance::record_event( 'restore', 'ajax_step_started', array( 'step' => $mode, 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'running' ) );
		$has_valid_admin_session = DD_Maintenance_Admin_Request::authorize_ajax( 'dd_maintenance_ajax_restore' );

		$public_continuation_modes = array( 'restore_extract', 'restore_db', 'restore_files', 'restore_finalize', 'restore_fail_cleanup' );
		$has_valid_restore_token   = in_array( $mode, $public_continuation_modes, true )
			&& 0 === strpos( $restore_session_id, 'rst_' )
			&& $restore->verify_token( $restore_session_id, $restore_token );

		if ( ! $has_valid_admin_session && ! $has_valid_restore_token ) {
			DD_Maintenance::record_event( 'restore', 'ajax_rejected', array( 'step' => $mode, 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'authorization_failed', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Sessão expirada ou sem permissão.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
		}

		if ( $this->operations_disabled() ) {
			DD_Maintenance::record_event( 'restore', 'ajax_rejected', array( 'step' => $mode, 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'operations_disabled', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Uploads e restores novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
		}

		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'memory_limit', '512M' );
			ini_set( 'max_execution_time', '3600' );
		}
		ignore_user_abort( true );

		$initiating_modes = array( 'upload_init', 'restore_init', 'upload', 'local' );
		if ( in_array( $mode, $initiating_modes, true ) && DD_Maintenance_Config::has_password() ) {
			$password = $request->post_secret( 'restore_password' );
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				wp_send_json_error( array( 'message' => __( 'Senha de confirmação incorreta.', 'dd-maintenance' ) ) );
			}
		}

		if ( 'upload_init' === $mode ) {
			$upload_session_id = 'upload_restore_' . time() . '_' . wp_generate_password( 10, false );
			$backup_raw        = DD_Maintenance::backup_dir();
			$backup_dir        = wp_normalize_path( realpath( $backup_raw ) );
			$temp_dir          = $backup_dir . '/' . $upload_session_id;

			if ( is_link( $backup_raw ) || empty( $backup_dir ) || $backup_dir !== wp_normalize_path( $backup_raw ) || is_link( $temp_dir ) || ! wp_mkdir_p( $temp_dir ) || is_link( $temp_dir ) || false === realpath( $temp_dir ) ) {
				wp_send_json_error( array( 'message' => __( 'Não foi possível criar a pasta temporária de upload no servidor.', 'dd-maintenance' ) ) );
			}

			DD_Maintenance::record_event( 'restore', 'upload_session_created', array( 'step' => 'upload_init', 'session_id' => $upload_session_id, 'correlation_id' => $correlation_id, 'status' => 'running' ) );
			wp_send_json_success( array( 'upload_session_id' => $upload_session_id, 'correlation_id' => $correlation_id ) );
		} elseif ( 'upload_chunk' === $mode ) {
			$upload_session_id = sanitize_file_name( $request->post_text( 'upload_session_id' ) );
			if ( empty( $upload_session_id ) || 0 !== strpos( $upload_session_id, 'upload_restore_' ) ) {
				wp_send_json_error( array( 'message' => __( 'Identificador de sessão de upload inválido.', 'dd-maintenance' ) ) );
			}

			$backup_raw = DD_Maintenance::backup_dir();
			$backup_dir = wp_normalize_path( realpath( $backup_raw ) );
			$temp_dir   = $backup_dir . '/' . $upload_session_id;
			$real_temp  = file_exists( $temp_dir ) ? wp_normalize_path( realpath( $temp_dir ) ) : '';

			if ( is_link( $backup_raw ) || empty( $backup_dir ) || $backup_dir !== wp_normalize_path( $backup_raw ) || is_link( $temp_dir ) || empty( $real_temp ) || $real_temp !== wp_normalize_path( $temp_dir ) || 0 !== strpos( $real_temp, $backup_dir . '/' ) || ! is_dir( $real_temp ) ) {
				wp_send_json_error( array( 'message' => __( 'Pasta temporária de upload não encontrada no servidor.', 'dd-maintenance' ) ) );
			}

			$upload       = $request->file_input( 'file_chunk' );
			$tmp_name     = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
			$upload_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_OK !== $upload_error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
				$error_messages = array(
					UPLOAD_ERR_INI_SIZE   => __( 'o arquivo excedeu upload_max_filesize', 'dd-maintenance' ),
					UPLOAD_ERR_FORM_SIZE  => __( 'o arquivo excedeu o limite do formulário', 'dd-maintenance' ),
					UPLOAD_ERR_PARTIAL    => __( 'o upload foi recebido apenas parcialmente', 'dd-maintenance' ),
					UPLOAD_ERR_NO_FILE    => __( 'nenhum arquivo foi recebido', 'dd-maintenance' ),
					UPLOAD_ERR_NO_TMP_DIR => __( 'a pasta temporária do PHP não existe', 'dd-maintenance' ),
					UPLOAD_ERR_CANT_WRITE => __( 'o PHP não conseguiu gravar o arquivo temporário', 'dd-maintenance' ),
					UPLOAD_ERR_EXTENSION  => __( 'uma extensão do PHP interrompeu o upload', 'dd-maintenance' ),
				);
				$reason = $error_messages[ $upload_error ] ?? __( 'o arquivo temporário não foi reconhecido como upload HTTP', 'dd-maintenance' );
				wp_send_json_error(
					array(
						'message' => sprintf(
							__( 'Upload rejeitado pelo servidor: %s (código %d). Limites atuais: upload_max_filesize=%s, post_max_size=%s.', 'dd-maintenance' ),
							$reason,
							$upload_error,
							ini_get( 'upload_max_filesize' ),
							ini_get( 'post_max_size' )
						),
					)
				);
			}

			$posted_name = $request->post_text( 'file_name' );
			$upload_name = isset( $upload['name'] ) && is_string( $upload['name'] ) ? wp_unslash( $upload['name'] ) : '';
			$orig_name  = sanitize_file_name( '' !== $posted_name ? $posted_name : $upload_name );
			if ( '' === $orig_name ) {
				wp_send_json_error( array( 'message' => __( 'Nome de arquivo inválido nesta etapa do upload.', 'dd-maintenance' ) ) );
			}

			$chunk_index = max( 0, $request->post_int( 'chunk_index' ) );
			$chunk_total = max( 1, $request->post_int( 'chunk_total', 1 ) );
			$chunk_offset = max( 0, $request->post_int( 'chunk_offset' ) );
			$file_size   = max( 0, $request->post_int( 'file_size' ) );
			$dest_path   = $real_temp . '/' . $orig_name;
			$part_path   = $real_temp . '/.' . $orig_name . '.uploading';
			$dest_check  = DD_Maintenance_File_Security::safe_child_path( $real_temp, $orig_name, false );
			$part_check  = DD_Maintenance_File_Security::safe_child_path( $real_temp, '.' . $orig_name . '.uploading', false );
			if ( is_wp_error( $dest_check ) || is_wp_error( $part_check ) ) {
				wp_send_json_error( array( 'message' => __( 'Destino de trecho inseguro.', 'dd-maintenance' ) ) );
			}

			// Se a resposta final foi perdida, um retry do último trecho pode reaproveitar o arquivo concluído.
			if ( $chunk_index + 1 >= $chunk_total && ! is_link( $dest_path ) && is_file( $dest_path ) && ( 0 === $file_size || filesize( $dest_path ) === $file_size ) ) {
				wp_send_json_success(
					array(
						'filename'       => $orig_name,
						'chunk_index'    => $chunk_index,
						'complete'       => true,
						'received_size'  => (int) filesize( $dest_path ),
					)
				);
			}

			$input  = fopen( $tmp_name, 'rb' );
			$output = fopen( $part_path, 'c+b' );
			if ( ! $input || ! $output ) {
				if ( $input ) {
					fclose( $input );
				}
				if ( $output ) {
					fclose( $output );
				}
				wp_send_json_error( array( 'message' => __( 'Não foi possível abrir os arquivos temporários desta etapa.', 'dd-maintenance' ) ) );
			}

			if ( 0 !== fseek( $output, $chunk_offset, SEEK_SET ) ) {
				fclose( $input );
				fclose( $output );
				wp_send_json_error( array( 'message' => __( 'Não foi possível posicionar o trecho no arquivo temporário.', 'dd-maintenance' ) ) );
			}

			$copied = stream_copy_to_stream( $input, $output );
			fflush( $output );
			fclose( $input );
			fclose( $output );
			$tmp_size = (int) filesize( $tmp_name );
			if ( false === $copied || (int) $copied !== $tmp_size ) {
				wp_send_json_error( array( 'message' => __( 'O trecho recebido não pôde ser gravado integralmente no servidor.', 'dd-maintenance' ) ) );
			}

			$complete = $chunk_index + 1 >= $chunk_total;
			if ( $complete ) {
				$stored_size = (int) filesize( $part_path );
				if ( $file_size > 0 && $stored_size !== $file_size ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'Tamanho final inválido para %s: esperado %s, recebido %s.', 'dd-maintenance' ), $orig_name, size_format( $file_size ), size_format( $stored_size ) ) ) );
				}
				if ( is_link( $dest_path ) || ! rename( $part_path, $dest_path ) ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'Não foi possível concluir o arquivo %s no servidor.', 'dd-maintenance' ), $orig_name ) ) );
				}
			}

			wp_send_json_success(
				array(
					'filename'      => $orig_name,
					'chunk_index'   => $chunk_index,
					'complete'      => $complete,
					'received_size' => $complete ? (int) filesize( $dest_path ) : (int) filesize( $part_path ),
				)
			);
		} elseif ( 'restore_init' === $mode ) {
			$source     = $request->post_key( 'source', 'upload' );
			$backup_raw = DD_Maintenance::backup_dir();
			$backup_dir = wp_normalize_path( realpath( $backup_raw ) );
			$zip_paths  = array();
			$temp_dir   = '';

			if ( 'upload' === $source ) {
				$upload_session_id = sanitize_file_name( $request->post_text( 'upload_session_id' ) );
				if ( empty( $upload_session_id ) || 0 !== strpos( $upload_session_id, 'upload_restore_' ) ) {
					wp_send_json_error( array( 'message' => __( 'Identificador de upload inválido.', 'dd-maintenance' ) ) );
				}

				$temp_dir  = $backup_dir . '/' . $upload_session_id;
				$real_temp = file_exists( $temp_dir ) ? wp_normalize_path( realpath( $temp_dir ) ) : '';
				if ( is_link( $backup_raw ) || empty( $backup_dir ) || $backup_dir !== wp_normalize_path( $backup_raw ) || is_link( $temp_dir ) || empty( $real_temp ) || $real_temp !== wp_normalize_path( $temp_dir ) || 0 !== strpos( $real_temp, $backup_dir . '/' ) || ! is_dir( $real_temp ) ) {
					wp_send_json_error( array( 'message' => __( 'Pasta temporária de upload não encontrada.', 'dd-maintenance' ) ) );
				}

				$zip_paths = glob( $real_temp . '/*.zip' );
				if ( empty( $zip_paths ) ) {
					wp_send_json_error( array( 'message' => __( 'Nenhum arquivo .zip encontrado na pasta de upload.', 'dd-maintenance' ) ) );
				}
			} elseif ( 'local' === $source ) {
				$filename = sanitize_file_name( $request->post_text( 'backup_filename' ) );
				if ( empty( $filename ) ) {
					wp_send_json_error( array( 'message' => __( 'Identificador de backup local inválido.', 'dd-maintenance' ) ) );
				}

				$base_name = preg_replace( '/\.part\d+\.zip$/i', '', $filename );
				$base_name = preg_replace( '/\.zip$/i', '', $base_name );
				$zip_paths = glob( $backup_dir . '/' . $base_name . '.part*.zip' );

				if ( empty( $zip_paths ) && ! is_link( $backup_dir . '/' . $base_name . '.zip' ) && is_file( $backup_dir . '/' . $base_name . '.zip' ) ) {
					$zip_paths = array( $backup_dir . '/' . $base_name . '.zip' );
				}

				if ( empty( $zip_paths ) ) {
					wp_send_json_error( array( 'message' => __( 'Arquivo(s) de backup local não encontrado(s).', 'dd-maintenance' ) ) );
				}
			} else {
				wp_send_json_error( array( 'message' => __( 'Origem de restauração inválida.', 'dd-maintenance' ) ) );
			}

			$apply_elementor_compatibility = $request->post_flag( 'apply_elementor_compatibility' );
			$session = $restore->initialize( $zip_paths, $temp_dir, $apply_elementor_compatibility, $correlation_id );
			if ( is_wp_error( $session ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_init', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $session->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $session->get_error_message() ) );
			}

			DD_Maintenance::record_event( 'restore', 'session_created', array( 'step' => 'restore_init', 'session_id' => $session['session_id'], 'correlation_id' => $correlation_id, 'status' => 'running' ) );
			wp_send_json_success(
				array(
					'restore_session_id' => $session['session_id'],
					'restore_token'      => $session['restore_token'],
					'total_volumes'      => $session['total_volumes'],
					'correlation_id'     => $correlation_id,
				)
			);
		} elseif ( 'restore_extract' === $mode ) {
			$restore_session_id = sanitize_file_name( $request->post_text( 'restore_session_id' ) );
			$batch_limit        = max( 1, $request->post_int( 'batch_limit', 5 ) );

			$result = $restore->extract( $restore_session_id, $batch_limit );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_extract', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_extract', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_db' === $mode ) {
			$restore_session_id = sanitize_file_name( $request->post_text( 'restore_session_id' ) );

			$result = $restore->database( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_db', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_db', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_files' === $mode ) {
			$restore_session_id = sanitize_file_name( $request->post_text( 'restore_session_id' ) );

			$result = $restore->files( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_files', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_files', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_finalize' === $mode ) {
			$restore_session_id = sanitize_file_name( $request->post_text( 'restore_session_id' ) );

			$result = $restore->finalize( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_finalize', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$this->record_restore_result( 'restore_finalize', $restore_session_id, $result, $correlation_id );
			$result_data = is_array( $result ) ? $result : array();
			$result_log  = is_object( $result ) && method_exists( $result, 'log' ) ? $result->log() : ( $result_data['log'] ?? array() );
			$warnings    = is_object( $result ) && method_exists( $result, 'warnings' ) ? $result->warnings() : ( $result_data['warnings'] ?? array() );
			$log_str     = ! empty( $result_log ) && is_array( $result_log ) ? implode( "\n", $result_log ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'warnings' => $warnings, 'status' => ! empty( $warnings ) ? 'warning' : 'success', 'correlation_id' => $correlation_id ) );
		} elseif ( 'restore_fail_cleanup' === $mode ) {
			$restore_session_id = sanitize_file_name( $request->post_text( 'restore_session_id' ) );
			$cleanup_result = array( 'cleaned' => true, 'errors' => array() );
			if ( ! empty( $restore_session_id ) ) {
				$cleanup_result = $restore->cleanup_failed( $restore_session_id );
			}
			if ( empty( $cleanup_result['errors'] ) ) {
				DD_Maintenance::record_event(
					'restore',
					'cleanup_finished',
					array(
						'step'           => 'restore_fail_cleanup',
						'session_id'     => $restore_session_id,
						'correlation_id' => $correlation_id,
						'status'         => 'warning',
						'failure_code'   => 'restore_aborted',
						'error_count'    => 1,
					)
				);
			}
			wp_send_json_success( array( 'cleaned' => empty( $cleanup_result['errors'] ), 'errors' => $cleanup_result['errors'], 'correlation_id' => $correlation_id ) );
		} elseif ( 'upload' === $mode ) {
			if ( empty( $request->file_input( 'backup_zip' ) ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'upload', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'upload_missing', 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => __( 'Nenhum arquivo enviado.', 'dd-maintenance' ) ) );
			}

			$apply_elementor_compatibility = $request->post_flag( 'apply_elementor_compatibility' );
			$result = $restore->from_upload( $request->file_input( 'backup_zip' ), $apply_elementor_compatibility );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'upload', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'upload', '', $result, $correlation_id );
			$log_str = ! empty( $result->log() ) ? implode( "\n", $result->log() ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'correlation_id' => $correlation_id ) );
		} elseif ( 'local' === $mode ) {
			$filename = sanitize_file_name( $request->post_text( 'backup_filename' ) );
			if ( empty( $filename ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'local', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'backup_name_missing', 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => __( 'Nome de backup local inválido.', 'dd-maintenance' ) ) );
			}

			$apply_elementor_compatibility = $request->post_flag( 'apply_elementor_compatibility' );
			$result = $restore->from_local( $filename, $apply_elementor_compatibility );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'local', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'local', '', $result, $correlation_id );
			$log_str = ! empty( $result->log() ) ? implode( "\n", $result->log() ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'correlation_id' => $correlation_id ) );
		} else {
			DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => $mode, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'invalid_mode', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Modo de restauração inválido.', 'dd-maintenance' ) ) );
		}
	}
	private function record_restore_result( string $step, string $session_id, $result, string $correlation_id ): void {
		$data          = is_object( $result ) && method_exists( $result, 'to_array' ) ? $result->to_array() : (array) $result;
		$completed     = ! empty( $data['completed'] ) || 'restore_finalize' === $step;
		$error_count   = is_numeric( $data['errors'] ?? null ) ? (int) $data['errors'] : ( is_array( $data['errors'] ?? null ) ? count( $data['errors'] ) : 0 );
		$warning_count = is_array( $data['warnings'] ?? null ) ? count( $data['warnings'] ) : 0;
		$status = $completed ? ( $error_count > 0 || $warning_count > 0 ? 'warning' : 'success' ) : 'running';
		DD_Maintenance::record_event(
			'restore',
			$completed ? 'step_finished' : 'step_progress',
			array(
				'step'            => $step,
				'session_id'      => $session_id,
				'correlation_id'  => $correlation_id,
				'progress'         => isset( $data['percent'] ) ? (int) $data['percent'] : ( $completed ? 100 : null ),
				'bytes_processed'  => isset( $data['bytes_processed'] ) ? (int) $data['bytes_processed'] : 0,
				'error_count'      => $error_count + $warning_count,
				'warning_count'    => $warning_count,
				'failure_code'     => $error_count > 0 || $warning_count > 0 ? 'restore_sql_warnings' : '',
			)
		);
	}
	private function record_backup_result( string $step, string $session_id, $result, string $correlation_id ): void {
		$data      = is_object( $result ) && method_exists( $result, 'to_array' ) ? $result->to_array() : (array) $result;
		$completed = ! empty( $data['completed'] );
		DD_Maintenance::record_event(
			'backup',
			$completed ? 'step_finished' : 'step_progress',
			array(
				'step'            => $step,
				'session_id'      => $session_id,
				'correlation_id'  => $correlation_id,
				'status'          => $completed ? 'success' : 'running',
				'progress'        => isset( $data['percent'] ) ? (int) $data['percent'] : ( $completed ? 100 : null ),
				'bytes_processed' => isset( $data['bytes_processed'] ) ? (int) $data['bytes_processed'] : 0,
				'error_count'     => isset( $data['errors'] ) && is_array( $data['errors'] ) ? count( $data['errors'] ) : 0,
			)
		);
	}

	private function record_step_failure( string $operation, string $step, string $session_id, $error, string $correlation_id ): void {
		DD_Maintenance::record_event(
			$operation,
			'step_failed',
			array(
				'step'           => $step,
				'session_id'     => $session_id,
				'correlation_id' => $correlation_id,
				'status'         => 'failure',
				'failure_code'   => is_wp_error( $error ) ? $error->get_error_code() : 'operation_failed',
				'error_count'    => 1,
			)
		);
	}
	private function operations_disabled(): bool {
		if ( method_exists( 'DD_Maintenance', 'operations_disabled' ) ) {
			return DD_Maintenance::operations_disabled();
		}
		return defined( 'DD_MAINTENANCE_DISABLE_OPERATIONS' ) && true === DD_MAINTENANCE_DISABLE_OPERATIONS;
	}
}
