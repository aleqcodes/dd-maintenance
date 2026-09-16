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
require_once __DIR__ . '/class-dd-maintenance-admin-action-controller.php';
require_once __DIR__ . '/class-dd-maintenance-backup-action-controller.php';
require_once __DIR__ . '/class-dd-maintenance-restore-action-controller.php';


class DD_Maintenance_Settings {
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


	/**
	 * Construtor.
	 */
	public function __construct() {
		$this->settings_repository = new DD_Maintenance_Settings_Repository();
		$this->backup_workflow      = new DD_Maintenance_Backup_Workflow();
		$this->restore_workflow     = new DD_Maintenance_Restore_Workflow();
		$this->page_renderer        = new DD_Maintenance_Admin_Page_Renderer();
		$this->action_controller    = new DD_Maintenance_Admin_Action_Controller( $this );
		$this->backup_action_controller  = new DD_Maintenance_Backup_Action_Controller( $this );
		$this->restore_action_controller = new DD_Maintenance_Restore_Action_Controller( $this );
		$this->action_controller->register();
		$this->backup_action_controller->register();
		$this->restore_action_controller->register();
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_redirects' ) );

		add_action( 'admin_notices', array( $this, 'show_notice' ) );
	}

	/**
	 * Redireciona links antigos de backuper e gerenciador-de-updates-dd para a nova página.
	 */
	public function handle_legacy_redirects() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'backuper', 'gerenciador-de-updates-dd' ), true ) ) {
			$tab = 'gerenciador-de-updates-dd' === $_GET['page'] ? '&tab=config' : '';
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
		if ( $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}
		return $url;
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
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'dd-maintenance' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( 'backups' === $current_tab ) {
			$current_tab = 'restore';
		}
		$valid_tabs  = array( 'general', 'config', 's3', 'cron', 'restore', 'logs' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'general';
		}

		$settings = $this->settings_repository->get();

		$s3            = new DD_Maintenance_S3();
		$s3_configured = $s3->is_configured();
		// Nunca reidrata o segredo salvo na estrutura usada pela renderização HTML.
		$settings['s3_secret_key'] = '';
		$config_status = DD_Maintenance_Config::get_wp_config_status();
		$has_password  = DD_Maintenance_Config::has_password();
		$last_log      = get_transient( 'dd_maintenance_last_log' );
		if ( empty( $last_log ) ) {
			$last_log = get_transient( 'backuper_last_log' );
		}

		?>
		<div class="wrap dd-maintenance-wrap">
			<style>
				.dd-maintenance-wrap h1 {
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.dd-maintenance-wrap h1 .dashicons {
					font-size: 30px;
					width: 30px;
					height: 30px;
					line-height: 1;
					color: #2271b1;
					display: inline-flex;
					align-items: center;
					justify-content: center;
				}
				.dd-maintenance-wrap .nav-tab {
					display: inline-flex;
					align-items: center;
					gap: 6px;
				}
				.dd-maintenance-wrap .nav-tab .dashicons {
					font-size: 18px;
					width: 18px;
					height: 18px;
					line-height: 1;
					margin: 0;
					display: inline-flex;
					align-items: center;
					justify-content: center;
				}
				.dd-maintenance-actions {
					display: flex;
					flex-wrap: wrap;
					gap: 12px;
					align-items: center;
					margin-top: 16px;
				}
				.dd-maintenance-actions form {
					margin: 0;
					padding: 0;
					display: inline-block;
				}
				.dd-maintenance-actions .button {
					display: inline-flex !important;
					align-items: center !important;
					justify-content: center !important;
					gap: 8px !important;
					height: 42px !important;
					min-height: 42px !important;
					line-height: 1 !important;
					padding: 0 16px !important;
					font-size: 13px !important;
					white-space: nowrap !important;
					box-sizing: border-box !important;
					cursor: pointer;
				}
				.dd-maintenance-actions .button-primary {
					font-size: 13.5px !important;
					font-weight: 600 !important;
					padding: 0 20px !important;
				}
				.dd-maintenance-actions .button .dashicons {
					font-size: 18px !important;
					width: 18px !important;
					height: 18px !important;
					line-height: 18px !important;
					margin: 0 !important;
					padding: 0 !important;
					display: inline-flex !important;
					align-items: center !important;
					justify-content: center !important;
					flex-shrink: 0;
					vertical-align: middle !important;
				}
				.dd-maintenance-actions .button span.btn-text {
					line-height: 1 !important;
					display: inline-block !important;
				}
				.dd-maintenance-card-title {
					display: flex;
					align-items: center;
					gap: 6px;
					margin-top: 0;
				}
				.dd-maintenance-card-title .dashicons {
					font-size: 20px;
					width: 20px;
					height: 20px;
					line-height: 1;
					display: inline-flex;
					align-items: center;
					justify-content: center;
				}
				.dd-maintenance-wrap h2 {
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.dd-maintenance-wrap h2 .dashicons {
					font-size: 22px;
					width: 22px;
					height: 22px;
					line-height: 1;
					display: inline-flex;
					align-items: center;
					justify-content: center;
					color: #2271b1;
					color: #2271b1;
				}
				/* Modal de Progresso em Tempo Real */
				.dd-maint-modal {
					position: fixed !important;
					top: 0 !important;
					left: 0 !important;
					width: 100vw !important;
					height: 100vh !important;
					z-index: 9999999 !important;
					display: flex;
					align-items: center;
					justify-content: center;
				}
				.dd-maint-modal-backdrop {
					position: absolute !important;
					top: 0 !important;
					left: 0 !important;
					width: 100% !important;
					height: 100% !important;
					background: rgba(18, 23, 28, 0.75) !important;
					backdrop-filter: blur(3px) !important;
				}
				.dd-maint-modal-dialog {
					position: relative !important;
					background: #ffffff !important;
					width: 92% !important;
					max-width: 680px !important;
					max-height: 88vh !important;
					display: flex !important;
					flex-direction: column !important;
					border-radius: 8px !important;
					box-shadow: 0 15px 35px rgba(0,0,0,0.35) !important;
					overflow: hidden !important;
					z-index: 10 !important;
					animation: ddMaintFadeIn 0.25s ease-out;
				}
				.dd-maint-download-box {
					background: #f0f6fc;
					border: 1px solid #c8d7e1;
					border-left: 4px solid #46b450;
					border-radius: 4px;
					padding: 12px 14px;
					margin-bottom: 14px;
				}
				#dd-maint-downloads-list {
					max-height: 220px !important;
					overflow-y: auto !important;
					padding-right: 4px !important;
					display: flex !important;
					flex-direction: column !important;
					gap: 5px !important;
				}
				#dd-maint-downloads-list::-webkit-scrollbar {
					width: 6px;
				}
				#dd-maint-downloads-list::-webkit-scrollbar-track {
					background: #e7eef4;
					border-radius: 3px;
				}
				#dd-maint-downloads-list::-webkit-scrollbar-thumb {
					background: #90a4ae;
					border-radius: 3px;
				}
				#dd-maint-downloads-list::-webkit-scrollbar-thumb:hover {
					background: #607d8b;
				}
				.dd-maint-download-item {
					display: flex;
					align-items: center;
					justify-content: space-between;
					background: #ffffff;
					border: 1px solid #dcdcde;
					border-radius: 4px;
					padding: 6px 10px;
					gap: 8px;
					transition: border-color 0.15s ease, box-shadow 0.15s ease;
				}
				.dd-maint-download-item:hover {
					border-color: #2271b1;
					box-shadow: 0 1px 3px rgba(0,0,0,0.08);
				}
				.dd-maint-download-name {
					font-family: Consolas, Monaco, monospace;
					font-size: 11.5px;
					font-weight: 600;
					color: #1d2327;
					word-break: break-all;
				}
				.dd-maint-part-badge {
					display: inline-block;
					background: #e7f5ea;
					color: #1a7e37;
					font-size: 11px;
					font-weight: 600;
					padding: 2px 8px;
					border-radius: 10px;
					border: 1px solid #b4e2be;
					white-space: nowrap;
				}
				.dd-maint-sql-badge {
					display: inline-block;
					background: #f0f6fc;
					color: #0969da;
					font-size: 11px;
					font-weight: 600;
					padding: 2px 8px;
					border-radius: 10px;
					border: 1px solid #c8d7e1;
					white-space: nowrap;
				}
				@keyframes ddMaintFadeIn {
					from { opacity: 0; transform: translateY(-15px); }
					to { opacity: 1; transform: translateY(0); }
				}
				.dd-maint-modal-header {
					display: flex !important;
					align-items: center !important;
					justify-content: space-between !important;
					padding: 14px 20px !important;
					background: #f6f7f7 !important;
					box-sizing: border-box !important;
					flex-shrink: 0 !important;
				}
				.dd-maint-modal-header h3 {
					margin: 0 !important;
					padding: 0 !important;
					display: flex !important;
					align-items: center !important;
					gap: 8px !important;
					font-size: 15px !important;
					font-weight: 600 !important;
					color: #1d2327 !important;
					line-height: 1.4 !important;
				}
				.dd-maint-badge {
					display: inline-block !important;
					background: #2271b1 !important;
					color: #ffffff !important;
					font-size: 13px !important;
					padding: 3px 10px !important;
					border-radius: 12px !important;
					letter-spacing: 0.5px !important;
					line-height: 1.4 !important;
					flex-shrink: 0 !important;
					box-sizing: border-box !important;
				}
				.dd-maint-badge.success {
					background: #46b450 !important;
				}
				.dd-maint-badge.error {
					background: #d63638 !important;
				}
				.dd-maint-badge.warning {
					background: #dba617 !important;
				}
				.dd-maint-modal-body {
					padding: 16px 20px !important;
					overflow-y: auto !important;
					flex: 1 1 auto !important;
					max-height: calc(88vh - 125px) !important;
					box-sizing: border-box !important;
				}
				.dd-maint-modal-body::-webkit-scrollbar {
					width: 7px;
				}
				.dd-maint-modal-body::-webkit-scrollbar-track {
					background: #f0f0f1;
				}
				.dd-maint-modal-body::-webkit-scrollbar-thumb {
					background: #c3c4c7;
					border-radius: 4px;
				}
				.dd-maint-modal-body::-webkit-scrollbar-thumb:hover {
					background: #a7aaad;
				}
				.dd-maint-modal-footer {
					padding: 12px 20px !important;
					background: #f6f7f7 !important;
					border-top: 1px solid #dcdcde !important;
					display: flex !important;
					justify-content: space-between !important;
					align-items: center !important;
					flex-shrink: 0 !important;
					box-sizing: border-box !important;
				}
				.dd-maint-progress-container {
					width: 100%;
					height: 18px;
					background: #e0e0e0;
					border-radius: 9px;
					overflow: hidden;
					margin-bottom: 12px;
					box-shadow: inset 0 1px 2px rgba(0,0,0,0.12);
				}
				.dd-maint-progress-bar {
					height: 100%;
					width: 0%;
					background-color: #2271b1;
					background-image: linear-gradient(45deg, rgba(255,255,255,0.2) 25%, transparent 25%, transparent 50%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0.2) 75%, transparent 75%, transparent);
					background-size: 30px 30px;
					border-radius: 9px;
					transition: width 0.35s ease;
					animation: ddMaintProgressStripes 1s linear infinite;
				}
				.dd-maint-progress-bar.success {
					background-color: #46b450;
				}
				.dd-maint-progress-bar.warning {
					background-color: #dba617;
				}
				.dd-maint-progress-bar.error {
					background-color: #d63638;
				}
				@keyframes ddMaintProgressStripes {
					0% { background-position: 0 0; }
					100% { background-position: 30px 0; }
				}
				.dd-maint-status-text {
					font-size: 13.5px;
					font-weight: 500;
					color: #1d2327;
					margin: 0 0 14px 0;
					min-height: 20px;
				}
				.dd-maint-console-container {
					background: #1d2327;
					border-radius: 5px;
					border: 1px solid #0c0d0e;
					overflow: hidden;
				}
				.dd-maint-console-header {
					background: #2c3338;
					color: #8c8f94;
					padding: 6px 12px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.dd-maint-console {
					margin: 0;
					padding: 12px;
					background: transparent;
					color: #72aee6;
					font-family: Consolas, Monaco, monospace;
					font-size: 12px;
					line-height: 1.55;
					max-height: 160px;
					overflow-y: auto;
					white-space: pre-wrap;
					word-break: break-all;
				}
				.dd-maint-spin {
					animation: ddMaintSpin 1.2s linear infinite;
				}
				@keyframes ddMaintSpin {
					100% { transform: rotate(360deg); }
				}
			</style>
			<h1>
				<span class="dashicons dashicons-shield-alt"></span>
				<?php esc_html_e( 'DD Maintenance', 'dd-maintenance' ); ?>
				<span style="font-size:13px;font-weight:normal;color:#666;margin-left:10px;">v<?php echo esc_html( DD_MAINTENANCE_VERSION ); ?></span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Painel unificado de manutenção do WordPress: gerenciamento seguro de travas no wp-config.php, backups completos com envio ao S3 (DigitalOcean Spaces) e atualizações automáticas.', 'dd-maintenance' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
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
					$this->page_renderer->render_tab_config( $config_status, $has_password );
					break;

				case 's3':
					$this->page_renderer->render_tab_s3( $settings, $s3_configured, $s3 );
					break;

				case 'cron':
					$this->page_renderer->render_tab_cron( $settings );
					break;

				case 'restore':
					$this->page_renderer->render_tab_restore( $has_password );
					break;

				case 'logs':
					$this->page_renderer->render_tab_logs( $last_log );
					break;

				case 'general':
				default:
					$this->page_renderer->render_tab_general( $s3_configured, $s3, $config_status, $settings, $last_log );
					break;
			}
			?>
		<!-- Modal de Progresso em Tempo Real (0 a 100%) -->
		<div id="dd-maint-progress-modal" class="dd-maint-modal" style="display:none;">
			<div class="dd-maint-modal-backdrop"></div>
			<div class="dd-maint-modal-dialog">
				<div class="dd-maint-modal-header">
					<h3>
						<span id="dd-maint-modal-icon" class="dashicons dashicons-update dd-maint-spin" style="color:#2271b1;font-size:22px;width:22px;height:22px;vertical-align:middle;"></span>
						<span id="dd-maint-modal-title-text"><?php esc_html_e( 'Processando...', 'dd-maintenance' ); ?></span>
					</h3>
					<span id="dd-maint-modal-percent" class="dd-maint-badge">0%</span>
				</div>

				<div class="dd-maint-modal-body">
					<div class="dd-maint-progress-container">
						<div id="dd-maint-progress-bar" class="dd-maint-progress-bar" style="width:0%;"></div>
					</div>

					<p id="dd-maint-status-text" class="dd-maint-status-text"><?php esc_html_e( 'Iniciando operação...', 'dd-maintenance' ); ?></p>
					<!-- Painel de Downloads Imediatos (Exibido ao finalizar backup) -->
					<div id="dd-maint-downloads-container" class="dd-maint-download-box" style="display:none;">
						<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
							<div style="display:flex;align-items:center;gap:6px;">
								<span class="dashicons dashicons-download" style="color:#46b450;font-size:18px;width:18px;height:18px;"></span>
								<strong style="font-size:13.5px;color:#1d2327;"><?php esc_html_e( 'Baixar Arquivos de Backup Agora', 'dd-maintenance' ); ?></strong>
								<span id="dd-maint-downloads-summary-badge" class="dd-maint-part-badge" style="display:none;"></span>
							</div>
							<button type="button" id="dd-maint-download-all-btn" class="button button-primary button-small" style="display:none;">
								<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
								<span id="dd-maint-download-all-btn-text"><?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?></span>
							</button>
						</div>
						<div id="dd-maint-downloads-filter-wrap" style="display:none;margin-bottom:8px;">
							<input type="text" id="dd-maint-downloads-filter" placeholder="<?php esc_attr_e( 'Filtrar volumes (ex: part120, sql)...', 'dd-maintenance' ); ?>" style="width:100%;font-size:12px;padding:4px 8px;border-radius:4px;border:1px solid #ccd0d4;">
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

				<div class="dd-maint-modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
					<div>
						<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" id="dd-maint-modal-view-backups-btn" class="button button-secondary" style="display:none;">
							<span class="dashicons dashicons-database-import" style="vertical-align:middle;font-size:15px;width:15px;height:15px;"></span>
							<?php esc_html_e( 'Ver Todos os Backups Locais', 'dd-maintenance' ); ?>
						</a>
					</div>
					<div style="display:flex;gap:8px;">
						<button type="button" id="dd-maint-modal-dismiss-btn" class="button button-secondary" style="display:none;">
							<?php esc_html_e( 'Fechar', 'dd-maintenance' ); ?>
						</button>
						<button type="button" id="dd-maint-modal-close-btn" class="button button-primary" style="display:none;" onclick="location.reload();">
							<?php esc_html_e( 'Concluído (Atualizar Página)', 'dd-maintenance' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var ajaxUrl         = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce           = <?php echo json_encode( wp_create_nonce( 'dd_maint_ajax_nonce' ) ); ?>;
			var downloadBaseUrl = <?php echo json_encode( admin_url( 'admin-post.php' ) ); ?>;
			var downloadNonce   = <?php echo json_encode( wp_create_nonce( 'dd_maintenance_download_backup' ) ); ?>;
			var correlationId   = '';

			var modal              = document.getElementById('dd-maint-progress-modal');
			var icon               = document.getElementById('dd-maint-modal-icon');
			var titleText          = document.getElementById('dd-maint-modal-title-text');
			var percentEl          = document.getElementById('dd-maint-modal-percent');
			var barEl              = document.getElementById('dd-maint-progress-bar');
			var statusText         = document.getElementById('dd-maint-status-text');
			var consoleOut         = document.getElementById('dd-maint-console-output');
			var closeBtn           = document.getElementById('dd-maint-modal-close-btn');
			var dismissBtn         = document.getElementById('dd-maint-modal-dismiss-btn');
			var downloadsContainer = document.getElementById('dd-maint-downloads-container');
			var downloadsList      = document.getElementById('dd-maint-downloads-list');
			var downloadAllBtn     = document.getElementById('dd-maint-download-all-btn');
			var viewBackupsBtn     = document.getElementById('dd-maint-modal-view-backups-btn');

			if (dismissBtn) {
				dismissBtn.addEventListener('click', function() {
					modal.style.display = 'none';
				});
			}

			function getBackupDownloadUrl(filename) {
				return downloadBaseUrl + '?action=dd_maintenance_download_backup&file=' + encodeURIComponent(filename) + '&_wpnonce=' + encodeURIComponent(downloadNonce);
			}

			function triggerDownload(filename) {
				var url = getBackupDownloadUrl(filename);
				var a   = document.createElement('a');
				a.href  = url;
				a.download = filename;
				a.style.display = 'none';
				document.body.appendChild(a);
				a.click();
				setTimeout(function() {
					if (a.parentNode) {
						a.parentNode.removeChild(a);
					}
				}, 1000);
			}

			var isBatchDownloading = false;

			function downloadAllParts(files, btnEl) {
				if (!files || !files.length) return;
				if (isBatchDownloading) {
					alert('Um download em lotes já está em andamento no navegador. Aguarde a conclusão.');
					return;
				}

				var total        = files.length;
				var batchSize    = 5;
				var totalBatches = Math.ceil(total / batchSize);
				var originalBtnHtml = btnEl ? btnEl.innerHTML : '';
				var allBtnText   = document.getElementById('dd-maint-download-all-btn-text');
				var modalBtn     = document.getElementById('dd-maint-download-all-btn');

				isBatchDownloading = true;
				if (btnEl) btnEl.disabled = true;
				if (modalBtn) modalBtn.disabled = true;

				function updateStatus(batchNum, startIdx, endIdx) {
					var text = 'Baixando lote ' + batchNum + '/' + totalBatches + ' (' + (startIdx + 1) + '-' + endIdx + ' de ' + total + ')...';
					if (btnEl) {
						btnEl.innerHTML = '<span class="dashicons dashicons-update dd-maint-spin" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span> ' + text;
					}
					if (allBtnText) {
						allBtnText.innerText = text;
					}
					if (consoleOut) {
						consoleOut.innerText += (consoleOut.innerText ? '\n' : '') + '[Download Lote ' + batchNum + '/' + totalBatches + '] Disparando volumes ' + (startIdx + 1) + ' até ' + endIdx + ' de ' + total + '...';
						consoleOut.scrollTop = consoleOut.scrollHeight;
					}
				}

				function processBatch(batchIndex) {
					if (batchIndex >= totalBatches) {
						isBatchDownloading = false;
						var doneText = 'Todos os ' + total + ' volumes enviados para download!';
						if (btnEl) {
							btnEl.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span> ' + doneText;
							setTimeout(function() {
								btnEl.disabled = false;
								btnEl.innerHTML = originalBtnHtml;
							}, 4000);
						}
						if (modalBtn) {
							modalBtn.disabled = false;
						}
						if (allBtnText) {
							allBtnText.innerText = 'Baixar Todos os ' + total + ' Volumes';
						}
						if (consoleOut) {
							consoleOut.innerText += (consoleOut.innerText ? '\n' : '') + '[Download OK] Todos os ' + total + ' volumes foram enviados com sucesso em lotes de ' + batchSize + '!';
							consoleOut.scrollTop = consoleOut.scrollHeight;
						}
						return;
					}

					var start = batchIndex * batchSize;
					var end   = Math.min(start + batchSize, total);
					var batchFiles = files.slice(start, end);
					var batchNum   = batchIndex + 1;

					updateStatus(batchNum, start, end);

					// Dispara cada um dos 5 arquivos do lote com intervalo de 700ms entre eles
					batchFiles.forEach(function(file, i) {
						setTimeout(function() {
							triggerDownload(file);
						}, i * 700);
					});

					// Aguarda o término do lote atual (5 * 700ms = 3.5s) mais 3.0s de pausa calculada antes do próximo lote
					var batchDuration = (batchFiles.length * 700) + 3000;
					setTimeout(function() {
						processBatch(batchIndex + 1);
					}, batchDuration);
				}

				processBatch(0);
			}
			window.ddMaintDownloadAll = downloadAllParts;

			function renderModalDownloads(finalData) {
				if (!finalData || !downloadsContainer || !downloadsList) return;
				var parts       = finalData.parts || [];
				var hasSql      = !!finalData.has_sql;
				var sqlFile     = finalData.sql_filename || '';
				var sqlSize     = finalData.sql_size_formatted || '';
				var totalSize   = finalData.total_size || 0;
				var summaryBadge = document.getElementById('dd-maint-downloads-summary-badge');
				var filterWrap  = document.getElementById('dd-maint-downloads-filter-wrap');
				var filterInput = document.getElementById('dd-maint-downloads-filter');
				var allBtnText  = document.getElementById('dd-maint-download-all-btn-text');

				if (parts.length === 0 && !hasSql) return;

				downloadsList.innerHTML = '';
				var fileNamesToDownload = [];

				if (summaryBadge) {
					var totalSizeFormatted = finalData.total_size ? (Math.round((finalData.total_size / 1024 / 1024) * 10) / 10 + ' MB') : '';
					summaryBadge.innerText = parts.length > 1 ? (parts.length + ' volumes' + (totalSizeFormatted ? ' &bull; ' + totalSizeFormatted : '')) : '1 arquivo';
					summaryBadge.style.display = 'inline-block';
				}

				if (filterWrap) {
					filterWrap.style.display = parts.length > 8 ? 'block' : 'none';
					if (filterInput) {
						filterInput.value = '';
						filterInput.oninput = function() {
							var q = this.value.toLowerCase().trim();
							var items = downloadsList.querySelectorAll('.dd-maint-download-item');
							items.forEach(function(el) {
								var name = el.getAttribute('data-filename') || '';
								el.style.display = (q === '' || name.toLowerCase().indexOf(q) !== -1) ? 'flex' : 'none';
							});
						};
					}
				}

				parts.forEach(function(p, idx) {
					fileNamesToDownload.push(p.name);
					var item = document.createElement('div');
					item.className = 'dd-maint-download-item';
					item.setAttribute('data-filename', p.name);

					var left = document.createElement('div');
					left.style.display = 'flex';
					left.style.alignItems = 'center';
					left.style.gap = '6px';
					left.style.minWidth = '0';
					left.style.overflow = 'hidden';

					var iconEl = document.createElement('span');
					iconEl.className = 'dashicons dashicons-media-archive';
					iconEl.style.color = '#2271b1';
					iconEl.style.fontSize = '16px';
					iconEl.style.width = '16px';
					iconEl.style.height = '16px';
					iconEl.style.flexShrink = '0';

					var nameSpan = document.createElement('div');
					nameSpan.className = 'dd-maint-download-name';
					nameSpan.title = p.name;
					nameSpan.innerText = p.name;

					var sizeSpan = document.createElement('span');
					sizeSpan.className = 'dd-maint-part-badge';
					sizeSpan.innerText = p.size_formatted || (Math.round(((p.size || 0) / 1024 / 1024) * 10) / 10 + ' MB');

					left.appendChild(iconEl);
					left.appendChild(nameSpan);
					left.appendChild(sizeSpan);

					var btn = document.createElement('a');
					btn.href = getBackupDownloadUrl(p.name);
					btn.className = 'button button-primary button-small';
					btn.style.flexShrink = '0';
					btn.setAttribute('download', p.name);
					btn.innerHTML = '<span class="dashicons dashicons-download" style="font-size:12px;vertical-align:middle;line-height:1.4;"></span> Baixar';

					item.appendChild(left);
					item.appendChild(btn);
					downloadsList.appendChild(item);
				});

				if (hasSql && sqlFile) {
					var itemSql = document.createElement('div');
					itemSql.className = 'dd-maint-download-item';
					itemSql.setAttribute('data-filename', sqlFile);

					var leftSql = document.createElement('div');
					leftSql.style.display = 'flex';
					leftSql.style.alignItems = 'center';
					leftSql.style.gap = '6px';
					leftSql.style.minWidth = '0';
					leftSql.style.overflow = 'hidden';

					var iconSql = document.createElement('span');
					iconSql.className = 'dashicons dashicons-database';
					iconSql.style.color = '#0969da';
					iconSql.style.fontSize = '16px';
					iconSql.style.width = '16px';
					iconSql.style.height = '16px';
					iconSql.style.flexShrink = '0';

					var nameSpanSql = document.createElement('div');
					nameSpanSql.className = 'dd-maint-download-name';
					nameSpanSql.title = sqlFile;
					nameSpanSql.innerText = sqlFile;

					var sizeSpanSql = document.createElement('span');
					sizeSpanSql.className = 'dd-maint-sql-badge';
					sizeSpanSql.innerText = sqlSize || 'Dump SQL';

					leftSql.appendChild(iconSql);
					leftSql.appendChild(nameSpanSql);
					leftSql.appendChild(sizeSpanSql);

					var btnSql = document.createElement('a');
					btnSql.href = getBackupDownloadUrl(sqlFile);
					btnSql.className = 'button button-secondary button-small';
					btnSql.style.flexShrink = '0';
					btnSql.setAttribute('download', sqlFile);
					btnSql.innerHTML = '<span class="dashicons dashicons-download" style="font-size:12px;vertical-align:middle;line-height:1.4;"></span> Baixar SQL';

					itemSql.appendChild(leftSql);
					itemSql.appendChild(btnSql);
					downloadsList.appendChild(itemSql);
				}

				if (parts.length > 1) {
					downloadAllBtn.style.display = 'inline-block';
					if (allBtnText) {
						allBtnText.innerText = 'Baixar Todos os ' + parts.length + ' Volumes';
					}
					downloadAllBtn.onclick = function() {
						downloadAllParts(fileNamesToDownload, downloadAllBtn);
					};
				} else {
					downloadAllBtn.style.display = 'none';
				}

				downloadsContainer.style.display = 'block';
				if (viewBackupsBtn) {
					viewBackupsBtn.style.display = 'inline-block';
				}

				if (consoleOut) {
					consoleOut.innerText += '\n[Download] ' + (parts.length > 1 ? parts.length + ' volumes' : 'Arquivo') + ' prontos para download imediato acima!';
					consoleOut.scrollTop = consoleOut.scrollHeight;
				}
			}

			function openModal(title) {
				correlationId = '';
				titleText.innerText = title || 'Processando...';
				percentEl.innerText = '0%';
				percentEl.className = 'dd-maint-badge';
				barEl.style.width   = '0%';
				barEl.className     = 'dd-maint-progress-bar';
				statusText.innerText = 'Iniciando operação...';
				consoleOut.innerText = '';
				icon.className       = 'dashicons dashicons-update dd-maint-spin';
				icon.style.color     = '#2271b1';
				closeBtn.style.display = 'none';
				if (dismissBtn) dismissBtn.style.display = 'none';
				if (downloadsContainer) downloadsContainer.style.display = 'none';
				if (downloadsList) downloadsList.innerHTML = '';
				if (downloadAllBtn) downloadAllBtn.style.display = 'none';
				if (viewBackupsBtn) viewBackupsBtn.style.display = 'none';
				modal.style.display    = 'flex';
			}

			function setProgress(pct, status, logLine, isSuccess, isError) {
				pct = Math.min(100, Math.max(0, Math.round(pct)));
				percentEl.innerText = pct + '%';
				barEl.style.width   = pct + '%';

				if (status) statusText.innerText = status;
				if (logLine) {
					consoleOut.innerText += (consoleOut.innerText ? '\n' : '') + logLine;
					consoleOut.scrollTop = consoleOut.scrollHeight;
				}

				var hasWarning = isSuccess && logLine && logLine.indexOf('[Aviso]') !== -1;
				if (hasWarning) {
					percentEl.className = 'dd-maint-badge warning';
					barEl.className     = 'dd-maint-progress-bar warning';
					icon.className      = 'dashicons dashicons-warning';
					icon.style.color    = '#dba617';
					closeBtn.style.display = 'inline-block';
					if (dismissBtn) dismissBtn.style.display = 'inline-block';
				} else if (isSuccess) {
					percentEl.className = 'dd-maint-badge success';
					barEl.className     = 'dd-maint-progress-bar success';
					icon.className      = 'dashicons dashicons-yes-alt';
					icon.style.color    = '#46b450';
					closeBtn.style.display = 'inline-block';
					if (dismissBtn) dismissBtn.style.display = 'inline-block';
				} else if (isError) {
					percentEl.className = 'dd-maint-badge error';
					barEl.className     = 'dd-maint-progress-bar error';
					icon.className      = 'dashicons dashicons-no-alt';
					icon.style.color    = '#d63638';
					closeBtn.style.display = 'inline-block';
					if (dismissBtn) dismissBtn.style.display = 'inline-block';
				}
			}

			function rememberCorrelation(data) {
				if (data && data.correlation_id) {
					correlationId = String(data.correlation_id);
				}
			}

			function sendAjax(action, data, onSuccess, onError) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				if (!data.hasOwnProperty('correlation_id') && correlationId) {
					fd.append('correlation_id', correlationId);
				}
				for (var k in data) {
					if (data.hasOwnProperty(k)) {
						fd.append(k, data[k]);
					}
				}

				fetch(ajaxUrl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				})
				.then(function(r) {
					return r.text().then(function(text) {
						return { ok: r.ok, status: r.status, text: text };
					});
				})
				.then(function(res) {
					var json = null;
					try {
						json = JSON.parse(res.text);
					} catch (e) {
						json = null;
					}
					if (json && json.success) {
						rememberCorrelation(json.data);
						if (onSuccess) onSuccess(json.data);
					} else if (json && !json.success) {
						var errMsg = (json.data && json.data.message) ? json.data.message : (json.data ? json.data : 'Erro retornado pelo servidor.');
						if (onError) onError(errMsg, json);
					} else {
						// Se o servidor retornou HTML em vez de JSON (ex: Timeout 504, 500, etc.)
						var cleanErr = extractCleanError(res.text, res.status);
						if (onError) onError(cleanErr);
					}
				})
				.catch(function(err) {
					if (onError) onError('Erro de conexão: ' + err);
				});
			}

			function extractCleanError(rawText, status) {
				if (!rawText) return 'Erro HTTP ' + (status || 'desconhecido') + ': resposta vazia do servidor.';
				var titleMatch = rawText.match(/<title[^>]*>([^<]+)<\/title>/i);
				if (titleMatch && titleMatch[1]) {
					return 'Erro do servidor (HTTP ' + (status || '500') + '): ' + titleMatch[1].trim();
				}
				var bodyMatch = rawText.match(/<p[^>]*>([^<]+)<\/p>/i);
				if (bodyMatch && bodyMatch[1]) {
					return bodyMatch[1].trim();
				}
				var stripped = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
				return stripped.length > 200 ? stripped.substring(0, 200) + '...' : (stripped || 'Erro HTTP ' + status);
			}
			function runFullSequence() {
				openModal('Manutenção Completa (Backup → S3 → Plugins → Core)');
				executeBackupPipeline(function(finalBackupData) {
					setProgress(92, 'Passo 4/5: Atualizando plugins com versões pendentes...', '[Plugins] Verificando plugins...');

					sendAjax('dd_maintenance_ajax_action', { step: 'plugins' }, function(dPlugins) {
						setProgress(96, 'Passo 5/5: Verificando e atualizando Core do WordPress...', dPlugins.log);

						sendAjax('dd_maintenance_ajax_action', { step: 'core' }, function(dCore) {
							setProgress(100, 'Manutenção completa concluída com sucesso!', dCore.log + '\n[Fim] ' + new Date().toLocaleTimeString(), true);
							renderModalDownloads(finalBackupData);
						}, function(err) {
							setProgress(96, 'Erro na atualização do Core', '[ERRO] ' + err, false, true);
							renderModalDownloads(finalBackupData);
						});

					}, function(err) {
						setProgress(92, 'Erro na atualização de plugins', '[ERRO] ' + err, false, true);
						renderModalDownloads(finalBackupData);
					});

				}, function(err) {
					// Erro já tratado dentro do pipeline
				});
			}

			function runBackupSequence() {
				openModal('Backup & Envio para S3 / Spaces (Volumes configuráveis)');
				executeBackupPipeline(function(finalBackupData) {
					setProgress(100, 'Backup concluído com sucesso!', '[OK] Todas as etapas foram finalizadas com sucesso.\n[Fim] ' + new Date().toLocaleTimeString(), true);
					renderModalDownloads(finalBackupData);
				}, function(err) {
					// Erro já tratado dentro do pipeline
				});
			}

			function handlePipelineError(sessionId, baseName, errMsg, onError) {
				sendAjax('dd_maintenance_ajax_action', {
					step: 'backup_fail_cleanup',
					session_id: sessionId || '',
					base_name: baseName || '',
					error: errMsg || '',
					log: consoleOut.innerText || ''
				}, function() {
					if (onError) onError(errMsg);
				}, function() {
					if (onError) onError(errMsg);
				});
			}

			function handlePipelineSuccess(sessionId, baseName, finalData, onSuccess) {
				sendAjax('dd_maintenance_ajax_action', {
					step: 'backup_save_log',
					session_id: sessionId || '',
					base_name: baseName || '',
					status: 'success',
					log: consoleOut.innerText || ''
				}, function() {
					if (onSuccess) onSuccess(finalData);
				}, function() {
					if (onSuccess) onSuccess(finalData);
				});
			}

			function executeBackupPipeline(onSuccess, onError) {
				var currentSessionId = '';
				var currentBaseName = '';
				var chunkSizeMb = 25;

				setProgress(3, 'Passo 1: Inicializando sessão de backup...', '[Início] ' + new Date().toLocaleTimeString());

				sendAjax('dd_maintenance_ajax_action', { step: 'backup_init' }, function(initData) {
					currentSessionId = initData.session_id;
					currentBaseName  = initData.base_name || '';
					chunkSizeMb      = parseInt(initData.chunk_size_mb, 10) || 25;
					setProgress(8, 'Passo 2: Gerando dump SQL do banco de dados em lotes...', '[Sessão] ' + currentSessionId);

					loopDatabaseBatches(currentSessionId, function() {
						setProgress(18, 'Passo 3: Catalogando arquivos do site em lotes...');

						loopIndexBatches(currentSessionId, function(indexData) {
							var totalFiles = indexData.total_files || 0;
							setProgress(25, 'Passo 4: Montando volumes ZIP de até ' + chunkSizeMb + ' MB sem compressão (0/' + totalFiles + ')...', indexData.log);

							loopZipBatches(currentSessionId, totalFiles, function() {
								setProgress(65, 'Passo 5: Finalizando volumes de até ' + chunkSizeMb + ' MB...', '[Lotes] Todos os arquivos foram distribuídos.');

								loopFinalizeBatches(currentSessionId, function(finalData) {
									var parts = finalData.parts || [];
									var folder = finalData.folder || 'site';
									setProgress(70, 'Passo 6: Enviando ' + parts.length + ' parte(s) para o S3 / Spaces...', finalData.log);

									uploadS3PartsSequentially(parts, folder, 0, function() {
										setProgress(90, 'Passo 7: Aplicando política de retenção...', '[S3] Todas as partes foram enviadas com sucesso.');

										sendAjax('dd_maintenance_ajax_action', { step: 'retention', session_id: currentSessionId }, function(retData) {
											if (retData.log) {
												consoleOut.innerText += '\n' + retData.log;
												consoleOut.scrollTop = consoleOut.scrollHeight;
											}
											handlePipelineSuccess(currentSessionId, currentBaseName, finalData, onSuccess);
										}, function(err) {
											setProgress(90, 'Aviso na retenção', '[Aviso] ' + err);
											handlePipelineSuccess(currentSessionId, currentBaseName, finalData, onSuccess);
										});
									}, function(uploadErr) {
										setProgress(70, 'Erro no envio de partes ao S3', '[ERRO] ' + uploadErr, false, true);
										handlePipelineError(currentSessionId, currentBaseName, uploadErr, onError);
									});
								}, function(err) {
									setProgress(65, 'Erro ao finalizar backup', '[ERRO] ' + err, false, true);
									handlePipelineError(currentSessionId, currentBaseName, err, onError);
								});
							}, function(batchErr) {
								setProgress(35, 'Erro ao montar os volumes configurados', '[ERRO] ' + batchErr, false, true);
								handlePipelineError(currentSessionId, currentBaseName, batchErr, onError);
							});
						}, function(err) {
							setProgress(18, 'Erro ao indexar arquivos', '[ERRO] ' + err, false, true);
							handlePipelineError(currentSessionId, currentBaseName, err, onError);
						});
					}, function(err) {
						setProgress(8, 'Erro no dump SQL', '[ERRO] ' + err, false, true);
						handlePipelineError(currentSessionId, currentBaseName, err, onError);
					});
				}, function(err) {
					setProgress(3, 'Erro ao iniciar sessão', '[ERRO] ' + err, false, true);
					handlePipelineError('', '', err, onError);
				});
			}
			function loopDatabaseBatches(sessionId, onDone, onBatchError) {
				sendAjax('dd_maintenance_ajax_action', { step: 'backup_db', session_id: sessionId }, function(res) {
					var pct = 8 + Math.round(((res.percent || 0) / 100) * 10);
					setProgress(pct, 'Passo 2: Gerando dump SQL (' + (res.percent || 0) + '%)...', res.log);
					if (res.completed) {
						if (onDone) onDone(res);
					} else {
						setTimeout(function() { loopDatabaseBatches(sessionId, onDone, onBatchError); }, 50);
					}
				}, onBatchError);
			}

			function loopIndexBatches(sessionId, onDone, onBatchError) {
				sendAjax('dd_maintenance_ajax_action', { step: 'backup_index', session_id: sessionId }, function(res) {
					setProgress(18, 'Passo 3: Catalogando arquivos (' + (res.total_files || 0) + ' encontrados)...', res.log);
					if (res.completed) {
						if (onDone) onDone(res);
					} else {
						setTimeout(function() { loopIndexBatches(sessionId, onDone, onBatchError); }, 50);
					}
				}, onBatchError);
			}

			function loopFinalizeBatches(sessionId, onDone, onBatchError) {
				sendAjax('dd_maintenance_ajax_action', { step: 'backup_finalize', session_id: sessionId }, function(res) {
					var pct = 65 + Math.round(((res.percent || 0) / 100) * 5);
					setProgress(pct, 'Passo 5: Finalizando volumes configurados...', res.log);
					if (res.completed) {
						if (onDone) onDone(res);
					} else {
						setTimeout(function() { loopFinalizeBatches(sessionId, onDone, onBatchError); }, 50);
					}
				}, onBatchError);
			}

			function loopZipBatches(sessionId, totalFiles, onDone, onBatchError, attempt) {
				attempt = attempt || 0;
				sendAjax('dd_maintenance_ajax_action', { step: 'backup_zip_batch', session_id: sessionId }, function(res) {
					var processed = typeof res.processed === 'number' ? res.processed : 0;
					var rawPct = typeof res.percent === 'number' ? res.percent : (totalFiles ? processed / totalFiles * 100 : 100);
					var pct = 25 + Math.round((rawPct / 100) * 40); // escala de 25% a 65%

					setProgress(pct, 'Passo 4: Montando volumes sem compressão (' + processed + ' / ' + totalFiles + ')...', res.log);

					if (res.completed) {
						if (onDone) onDone();
					} else {
						loopZipBatches(sessionId, totalFiles, onDone, onBatchError, 0);
					}
				}, function(err) {
					if (attempt < 2) {
						setProgress(35, 'Servidor ocupado; retomando o mesmo lote...', '[Lotes] Tentativa ' + (attempt + 2) + '/3 após: ' + err);
						setTimeout(function() {
							loopZipBatches(sessionId, totalFiles, onDone, onBatchError, attempt + 1);
						}, Math.pow(2, attempt) * 1000);
					} else if (onBatchError) {
						onBatchError(err);
					}
				});
			}

			function uploadS3PartsSequentially(parts, folder, index, onAllUploaded, onPartError, attempt) {
				if (!parts || parts.length === 0 || index >= parts.length) {
					if (onAllUploaded) onAllUploaded();
					return;
				}
				attempt = attempt || 0;

				var part = parts[index];
				var totalParts = parts.length;
				var currentNum = index + 1;
				var progressPct = 70 + Math.round((currentNum / totalParts) * 20); // escala de 70% a 90%

				setProgress(progressPct, 'Enviando parte ' + currentNum + ' de ' + totalParts + ' para o S3 (' + part.name + ')...');

				sendAjax('dd_maintenance_ajax_action', {
					step: 's3_upload_part',
					part_file: part.file,
					part_name: part.name,
					part_size: part.size,
					part_index: currentNum,
					total_parts: totalParts,
					folder: folder
				}, function(res) {
					if (res.log) {
						consoleOut.innerText += '\n' + res.log;
						consoleOut.scrollTop = consoleOut.scrollHeight;
					}

					uploadS3PartsSequentially(parts, folder, index + 1, onAllUploaded, onPartError, 0);
				}, function(err) {
					if (attempt < 4) {
						var delaySec = Math.min(15, Math.pow(2, attempt + 1));
						setProgress(progressPct, 'Tentando novamente a parte ' + currentNum + ' de ' + totalParts + ' em ' + delaySec + 's...', '[S3] Tentativa ' + (attempt + 2) + '/5 após falha: ' + err);
						setTimeout(function() {
							uploadS3PartsSequentially(parts, folder, index, onAllUploaded, onPartError, attempt + 1);
						}, delaySec * 1000);
					} else if (onPartError) {
						onPartError('Falha ao enviar parte ' + currentNum + '/' + totalParts + ' após 5 tentativas: ' + err);
					}
				});
			}

			function runPluginsUpdate() {
				openModal('Atualização de Plugins');
				setProgress(20, 'Buscando atualizações de plugins...', '[Início] ' + new Date().toLocaleTimeString());

				sendAjax('dd_maintenance_ajax_action', { step: 'plugins' }, function(d) {
					setProgress(100, 'Plugins atualizados com sucesso!', d.log, true);
				}, function(err) {
					setProgress(50, 'Erro ao atualizar plugins', '[ERRO] ' + err, false, true);
				});
			}

			function runCoreUpdate() {
				openModal('Atualização do Core do WordPress');
				setProgress(20, 'Verificando versão e atualizando core...', '[Início] ' + new Date().toLocaleTimeString());

				sendAjax('dd_maintenance_ajax_action', { step: 'core' }, function(d) {
					setProgress(100, 'Core do WordPress atualizado com sucesso!', d.log, true);
				}, function(err) {
					setProgress(50, 'Erro ao atualizar Core', '[ERRO] ' + err, false, true);
				});
			}

			// Mantém operações longas fora de uma única requisição ao admin-post.php.
			document.querySelectorAll('form[action*="admin-post.php"]').forEach(function(f) {
				var actInput = f.querySelector('input[name="action"]');
				if (!actInput) return;
				var actVal = actInput.value;

				if (actVal === 'dd_maintenance_run_full') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						runFullSequence();
					});
				} else if (actVal === 'dd_maintenance_run_backup') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						runBackupSequence();
					});
				} else if (actVal === 'dd_maintenance_update_plugins') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						runPluginsUpdate();
					});
				} else if (actVal === 'dd_maintenance_update_core') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						runCoreUpdate();
					});
				} else if (actVal === 'dd_maintenance_restore_upload') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						var fileInput = f.querySelector('input[name="backup_zip[]"]') || f.querySelector('input[name="backup_zip"]');
						if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
							alert('Selecione pelo menos um arquivo .zip de backup');
							return;
						}

						var files = Array.prototype.slice.call(fileInput.files);
						var totalFiles = files.length;
						var pwdInput = f.querySelector('input[name="restore_password"]');
						var elementorInput = f.querySelector('input[name="apply_elementor_compatibility"]');
						var pwd = pwdInput ? pwdInput.value : '';
						var applyElementor = elementorInput ? elementorInput.checked : false;

						openModal('Restauração de Backup (Upload)');
						setProgress(2, 'Inicializando sessão de upload...', '[Início] ' + new Date().toLocaleTimeString() + '\n[Upload] ' + totalFiles + ' arquivo(s) selecionado(s)...');

						// Passo 1: Inicializa sessão de upload no servidor
						sendAjax('dd_maintenance_ajax_restore', {
							mode: 'upload_init',
							total_files: totalFiles,
							restore_password: pwd
						}, function(initData) {
							var uploadSessionId = initData.upload_session_id;
							setProgress(4, 'Iniciando envio sequencial dos ' + totalFiles + ' arquivo(s)...', '[Sessão] Upload temporário ID: ' + uploadSessionId);

							// Passo 2: Divide cada volume em trechos de 20MB por requisição para respeitar limites do servidor.
							uploadFilesSequentially(files, uploadSessionId, pwd, 0, function() {
								setProgress(60, 'Todos os ' + totalFiles + ' arquivos enviados! Iniciando extração dos volumes...', '[Upload] Concluído o envio dos ' + totalFiles + ' arquivos.');

								// Passo 3: Executa o pipeline granular de extração com progresso em tempo real
								executeRestorePipeline({
									source: 'upload',
									upload_session_id: uploadSessionId,
									restore_password: pwd,
									apply_elementor_compatibility: applyElementor
								}, 60, function() {
									// Concluído com sucesso
								}, function(err) {
									// Erro já tratado no pipeline
								});

							}, function(uploadErr) {
								setProgress(50, 'Erro no upload de arquivos', '[ERRO] ' + uploadErr, false, true);
							});

						}, function(initErr) {
							setProgress(5, 'Erro ao inicializar sessão', '[ERRO] ' + initErr, false, true);
						});
					});
				} else if (actVal === 'dd_maintenance_restore_local') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						var fnInput = f.querySelector('input[name="backup_filename"]');
						var pwdInput = f.querySelector('input[name="restore_password"]');
						var elementorInput = f.querySelector('input[name="apply_elementor_compatibility"]');
						var filename = fnInput ? fnInput.value : '';
						var pwd = pwdInput ? pwdInput.value : '';
						var applyElementor = elementorInput ? elementorInput.checked : false;
						openModal('Restauração de Backup Local');
						setProgress(5, 'Iniciando restauração do backup local...', '[Início] ' + new Date().toLocaleTimeString() + '\n[Arquivo] ' + filename);

						executeRestorePipeline({
							source: 'local',
							backup_filename: filename,
							restore_password: pwd,
							apply_elementor_compatibility: applyElementor
						}, 5, function() {
							// Concluído com sucesso
						}, function(err) {
							// Erro já tratado no pipeline
						});
					});
				}
			});

			function uploadFilesSequentially(files, sessionId, password, index, onComplete, onError, attempt) {
				if (!files || index >= files.length) {
					if (onComplete) onComplete();
					return;
				}

				var file       = files[index];
				var total      = files.length;
				var currentNum = index + 1;
				var basePct    = 4 + Math.round((index / total) * 56);
				var chunkSize  = 20 * 1024 * 1024;
				var chunkTotal = Math.max(1, Math.ceil(file.size / chunkSize));

				setProgress(basePct, 'Enviando arquivo ' + currentNum + ' de ' + total + ' em ' + chunkTotal + ' trecho(s) (' + file.name + ')...');

				function sendChunk(chunkIndex, chunkAttempt) {
					var chunkStart = chunkIndex * chunkSize;
					var chunkEnd   = Math.min(file.size, chunkStart + chunkSize);
					var chunk       = file.slice(chunkStart, chunkEnd);
					var fd          = new FormData();
					fd.append('action', 'dd_maintenance_ajax_restore');
					fd.append('mode', 'upload_chunk');
					fd.append('upload_session_id', sessionId);
					fd.append('file_index', currentNum);
					fd.append('total_files', total);
					fd.append('file_name', file.name);
					fd.append('file_size', String(file.size));
					fd.append('chunk_index', String(chunkIndex));
					fd.append('chunk_total', String(chunkTotal));
					fd.append('chunk_offset', String(chunkStart));
					fd.append('restore_password', password);
					fd.append('file_chunk', chunk, file.name);
					fd.append('nonce', nonce);
					if (correlationId) {
						fd.append('correlation_id', correlationId);
					}

					var xhr = new XMLHttpRequest();
					xhr.open('POST', ajaxUrl, true);
					xhr.withCredentials = true;

					xhr.upload.onprogress = function(pe) {
						if (pe.lengthComputable) {
							var uploaded       = Math.min(file.size, chunkStart + pe.loaded);
							var fileProgress   = file.size > 0 ? uploaded / file.size : 1;
							var currentPct     = 4 + Math.round(((index + fileProgress) / total) * 56);
							var uploadedMb     = Math.round((uploaded / 1024 / 1024) * 10) / 10;
							var totalMb        = Math.round((file.size / 1024 / 1024) * 10) / 10;
							setProgress(currentPct, 'Enviando arquivo ' + currentNum + '/' + total + ' (' + file.name + ' - ' + uploadedMb + '/' + totalMb + ' MB)...');
						}
					};

					function retryOrFail(message) {
						if (chunkAttempt < 2) {
							setTimeout(function() {
								sendChunk(chunkIndex, chunkAttempt + 1);
							}, 1500);
						} else if (onError) {
							onError(message);
						}
					}

					xhr.onload = function() {
						if (xhr.status >= 200 && xhr.status < 300) {
							try {
								var res = JSON.parse(xhr.responseText);
								rememberCorrelation(res.data);
								if (res && res.success) {
									if (chunkIndex + 1 < chunkTotal) {
										sendChunk(chunkIndex + 1, 0);
										return;
									}

									if (consoleOut) {
										var sizeStr = file.size > 1048576 ? Math.round(file.size / 1048576 * 10) / 10 + ' MB' : Math.round(file.size / 1024) + ' KB';
										consoleOut.innerText += (consoleOut.innerText ? '\n' : '') + '[Upload ' + currentNum + '/' + total + '] ' + file.name + ' (' + sizeStr + ')';
										consoleOut.scrollTop = consoleOut.scrollHeight;
									}
									uploadFilesSequentially(files, sessionId, password, index + 1, onComplete, onError, 0);
								} else {
									retryOrFail((res && res.data && res.data.message) ? res.data.message : 'Erro ao enviar trecho de ' + file.name);
								}
							} catch (e) {
								retryOrFail('Resposta inválida do servidor ao enviar ' + file.name);
							}
						} else {
							retryOrFail('Erro HTTP ' + xhr.status + ' ao enviar ' + file.name);
						}
					};

					xhr.onerror = function() {
						retryOrFail('Falha de conexão ao enviar trecho de ' + file.name);
					};

					xhr.send(fd);
				}

				sendChunk(0, attempt || 0);
			}

			function executeRestorePipeline(sourceParams, startPct, onDone, onError) {
				startPct = typeof startPct === 'number' ? startPct : 5;
				var currentRestoreSessionId = '';
				var currentRestoreToken     = '';
				var totalVolumes            = 1;
				var initData = { mode: 'restore_init' };
				for (var k in sourceParams) {
					if (sourceParams.hasOwnProperty(k)) {
						initData[k] = sourceParams[k];
					}
				}

				setProgress(startPct, 'Passo 1/4: Inicializando árvore de restauração no servidor...', '[Restauração] Preparando árvore de extração...');

				sendAjax('dd_maintenance_ajax_restore', initData, function(initRes) {
					currentRestoreSessionId = initRes.restore_session_id;
					currentRestoreToken     = initRes.restore_token;
					totalVolumes            = initRes.total_volumes || 1;

					var extractSpan = (startPct >= 50) ? 26 : 60;
					var filesPct    = startPct + extractSpan + 2;
					var dbPct       = startPct + extractSpan + 7;

					setProgress(startPct, 'Passo 1/4: Extraindo ' + totalVolumes + ' volume(s) de backup (0%)...', '[Sessão] ' + currentRestoreSessionId + ' (' + totalVolumes + ' volume(s))');

					loopRestoreExtractBatches(currentRestoreSessionId, currentRestoreToken, totalVolumes, startPct, extractSpan, sourceParams.restore_password || '', function() {
						setProgress(filesPct, 'Passo 2/4: Restaurando arquivos do site na raiz (0%)...', '[Arquivos] Copiando temas, plugins e biblioteca de mídia...');

						loopRestoreFilesBatches(currentRestoreSessionId, currentRestoreToken, filesPct, 4, sourceParams.restore_password || '', function(filesRes) {
							if (filesRes.log) {
								consoleOut.innerText += '\n' + filesRes.log;
								consoleOut.scrollTop = consoleOut.scrollHeight;
							}
							setProgress(dbPct, 'Passo 3/4: Restaurando banco de dados SQL (0%)...', '[Banco] Executando comandos do dump SQL...');

							loopRestoreDbBatches(currentRestoreSessionId, currentRestoreToken, dbPct, 7, sourceParams.restore_password || '', function(dbRes) {
								if (dbRes.log) {
									consoleOut.innerText += '\n' + dbRes.log;
									consoleOut.scrollTop = consoleOut.scrollHeight;
								}
								setProgress(98, 'Passo 4/4: Finalizando restauração e limpando temporários...', '[Finalização] Limpando pastas temporárias...');

								sendAjax('dd_maintenance_ajax_restore', {
									mode: 'restore_finalize',
									restore_session_id: currentRestoreSessionId,
									restore_token: currentRestoreToken,
									restore_password: sourceParams.restore_password || ''
								}, function(finRes) {
									setProgress(100, 'Backup restaurado com sucesso!', (finRes.log ? finRes.log : '[OK] Restauração concluída.') + '\n[Fim] ' + new Date().toLocaleTimeString(), true);
									if (onDone) onDone(finRes);
								}, function(errFin) {
									setProgress(98, 'Erro ao finalizar restauração', '[ERRO] ' + errFin, false, true);
									if (onError) onError(errFin);
								});

							}, function(errDb) {
								setProgress(dbPct, 'Erro no banco de dados', '[ERRO] ' + errDb, false, true);
								handleRestoreError(currentRestoreSessionId, currentRestoreToken, errDb, onError);
							});

						}, function(errFiles) {
							setProgress(filesPct, 'Erro ao restaurar arquivos', '[ERRO] ' + errFiles, false, true);
							handleRestoreError(currentRestoreSessionId, currentRestoreToken, errFiles, onError);
						});

					}, function(errExt) {
						setProgress(startPct + 5, 'Erro na extração de volumes', '[ERRO] ' + errExt, false, true);
						handleRestoreError(currentRestoreSessionId, currentRestoreToken, errExt, onError);
					});

				}, function(errInit) {
					setProgress(startPct, 'Erro ao inicializar restauração', '[ERRO] ' + errInit, false, true);
					if (onError) onError(errInit);
				});
			}

			function loopRestoreExtractBatches(sessionId, restoreToken, totalVolumes, startPct, extractSpan, password, onDone, onError, attempt) {
				attempt = attempt || 0;

				sendAjax('dd_maintenance_ajax_restore', {
					mode: 'restore_extract',
					restore_session_id: sessionId,
					restore_token: restoreToken,
					batch_limit: 10,
					restore_password: password || ''
				}, function(res) {
					var currentIdx = res.current_index || 0;
					var total      = res.total_volumes || totalVolumes;
					var pctStep    = Math.round((currentIdx / total) * extractSpan);
					var currentPct = Math.min(startPct + extractSpan, startPct + pctStep);
					var pctText    = Math.round((currentIdx / total) * 100);

					setProgress(currentPct, 'Passo 1/4: Extraindo volume ' + currentIdx + ' de ' + total + ' (' + pctText + '%)...', res.log);

					if (res.completed) {
						if (onDone) onDone(res);
					} else {
						setTimeout(function() {
							loopRestoreExtractBatches(sessionId, restoreToken, total, startPct, extractSpan, password, onDone, onError, 0);
						}, 40);
					}
				}, function(err) {
					if (attempt < 2) {
						setProgress(startPct, 'Tentando retomar lote de extração...', '[Aviso] Retentando após: ' + err);
						setTimeout(function() {
							loopRestoreExtractBatches(sessionId, restoreToken, totalVolumes, startPct, extractSpan, password, onDone, onError, attempt + 1);
						}, 1500);
					} else if (onError) {
						onError(err);
					}
				});
			}


			function loopRestoreDbBatches(sessionId, restoreToken, startPct, dbSpan, password, onDone, onError, attempt) {
				attempt = attempt || 0;

				sendAjax('dd_maintenance_ajax_restore', {
					mode: 'restore_db',
					restore_session_id: sessionId,
					restore_token: restoreToken,
					restore_password: password || ''
				}, function(res) {
					if (!res.has_sql || res.completed) {
						if (res.log) {
							consoleOut.innerText += '\n' + res.log;
							consoleOut.scrollTop = consoleOut.scrollHeight;
						}
						if (onDone) onDone(res);
					} else {
						var sqlPct      = res.percent || 0;
						var currentPct  = startPct + Math.round((sqlPct / 100) * dbSpan);
						var queriesText = res.queries ? ' (' + res.queries.toLocaleString() + ' comandos)' : '';
						setProgress(currentPct, 'Passo 2/4: Restaurando banco de dados SQL (' + sqlPct + '%' + queriesText + ')...', res.log);

						setTimeout(function() {
							loopRestoreDbBatches(sessionId, restoreToken, startPct, dbSpan, password, onDone, onError, 0);
						}, 40);
					}
				}, function(err) {
					if (attempt < 2) {
						setProgress(startPct, 'Tentando retomar lote do banco SQL...', '[Aviso] Retentando SQL após: ' + err);
						setTimeout(function() {
							loopRestoreDbBatches(sessionId, restoreToken, startPct, dbSpan, password, onDone, onError, attempt + 1);
						}, 1500);
					} else if (onError) {
						onError(err);
					}
				});
			}

			function loopRestoreFilesBatches(sessionId, restoreToken, startPct, filesSpan, password, onDone, onError, attempt) {
				attempt = attempt || 0;

				sendAjax('dd_maintenance_ajax_restore', {
					mode: 'restore_files',
					restore_session_id: sessionId,
					restore_token: restoreToken,
					restore_password: password || ''
				}, function(res) {
					if (res.completed) {
						if (res.log) {
							consoleOut.innerText += '\n' + res.log;
							consoleOut.scrollTop = consoleOut.scrollHeight;
						}
						if (onDone) onDone(res);
					} else {
						var filePct    = res.percent || 0;
						var currentPct = startPct + Math.round((filePct / 100) * filesSpan);
						var filesText  = res.copied ? ' (' + res.copied.toLocaleString() + '/' + (res.total || 0).toLocaleString() + ' arquivos)' : '';
						setProgress(currentPct, 'Passo 3/4: Restaurando arquivos do site na raiz (' + filePct + '%' + filesText + ')...', res.log);

						setTimeout(function() {
							loopRestoreFilesBatches(sessionId, restoreToken, startPct, filesSpan, password, onDone, onError, 0);
						}, 30);
					}
				}, function(err) {
					if (attempt < 2) {
						setProgress(startPct, 'Tentando retomar cópia de arquivos...', '[Aviso] Retentando arquivos após: ' + err);
						setTimeout(function() {
							loopRestoreFilesBatches(sessionId, restoreToken, startPct, filesSpan, password, onDone, onError, attempt + 1);
						}, 1500);
					} else if (onError) {
						onError(err);
					}
				});
			}
			function handleRestoreError(sessionId, restoreToken, errMsg, onError) {
				sendAjax('dd_maintenance_ajax_restore', {
					mode: 'restore_fail_cleanup',
					restore_session_id: sessionId || '',
					restore_token: restoreToken || ''
				}, function() {
					if (onError) onError(errMsg);
				}, function() {
					if (onError) onError(errMsg);
				});
			}
		})();
		</script>
		</div>
		<?php
	}
	/**
	 * Handler: Restauração a partir de upload .zip.
	 */
	public function handle_restore_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		check_admin_referer( 'dd_maintenance_restore_upload' );
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e restores novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		if ( DD_Maintenance_Config::has_password() ) {
			$password = isset( $_POST['restore_password'] ) ? trim( (string) wp_unslash( $_POST['restore_password'] ) ) : '';
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Senha incorreta. Restauração cancelada.', 'dd-maintenance' ), 'error' );
				wp_safe_redirect( $this->page_url( 'restore' ) );
				exit;
			}
		}

		if ( empty( $_FILES['backup_zip'] ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nenhum arquivo enviado.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}
		$apply_elementor_compatibility = ! empty( $_POST['apply_elementor_compatibility'] );

		$result  = $this->restore_workflow->from_upload( $_FILES['backup_zip'], $apply_elementor_compatibility );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result->log ) ) {
				set_transient( 'dd_maintenance_last_log', $result->log, DAY_IN_SECONDS );
			}
			DD_Maintenance::instance()->set_notice( __( 'Backup restaurado com sucesso!', 'dd-maintenance' ), 'success' );
		}

		wp_safe_redirect( $this->page_url( 'restore' ) );
		exit;
	}

	/**
	 * Handler: Restauração a partir de arquivo local.
	 */
	public function handle_restore_local() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		check_admin_referer( 'dd_maintenance_restore_local' );
		if ( $this->operations_disabled() ) {
			DD_Maintenance::instance()->set_notice( __( 'Uploads e restores novos estão temporariamente desabilitados para rollback.', 'dd-maintenance' ), 'warning' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		if ( DD_Maintenance_Config::has_password() ) {
			$password = isset( $_POST['restore_password'] ) ? trim( (string) wp_unslash( $_POST['restore_password'] ) ) : '';
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Senha incorreta. Restauração cancelada.', 'dd-maintenance' ), 'error' );
				wp_safe_redirect( $this->page_url( 'restore' ) );
				exit;
			}
		}

		$filename = isset( $_POST['backup_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_filename'] ) ) : '';
		if ( empty( $filename ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nome de arquivo inválido.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}
		$apply_elementor_compatibility = ! empty( $_POST['apply_elementor_compatibility'] );

		$result  = $this->restore_workflow->from_local( $filename, $apply_elementor_compatibility );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result->log ) ) {
				set_transient( 'dd_maintenance_last_log', $result->log, DAY_IN_SECONDS );
			}
			DD_Maintenance::instance()->set_notice( __( 'Backup local restaurado com sucesso!', 'dd-maintenance' ), 'success' );
		}

		wp_safe_redirect( $this->page_url( 'restore' ) );
		exit;
	}

	/**
	 * Handler: Exclusão de arquivo de backup local (e opcionalmente do S3 / Spaces).
	 */
	public function handle_delete_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		check_admin_referer( 'dd_maintenance_delete_backup' );

		$filename = isset( $_POST['backup_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_filename'] ) ) : '';
		if ( empty( $filename ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Nome de arquivo inválido.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 'restore' ) );
			exit;
		}

		$deleted = DD_Maintenance_Restore::delete_local_backup( $filename );

		$remote_msg = '';
		if ( ! empty( $_POST['delete_remote'] ) ) {
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

		$redirect_tab = isset( $_POST['redirect_tab'] ) ? sanitize_key( wp_unslash( $_POST['redirect_tab'] ) ) : 'restore';
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}

	/**
	 * Handler: Exclusão de todos os volumes de um backup agrupado no S3 / Spaces.
	 */
	public function handle_delete_s3_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		check_admin_referer( 'dd_maintenance_delete_s3_backup' );

		$identifier = isset( $_POST['backup_identifier'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_identifier'] ) ) : '';
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

		$redirect_tab = isset( $_POST['redirect_tab'] ) ? sanitize_key( wp_unslash( $_POST['redirect_tab'] ) ) : 's3';
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}

	/**
	 * Handler: Exclusão direta de um objeto do bucket S3 / Spaces.
	 */
	public function handle_delete_s3_object() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		check_admin_referer( 'dd_maintenance_delete_s3_object' );

		$object_key = isset( $_POST['object_key'] ) ? sanitize_text_field( wp_unslash( $_POST['object_key'] ) ) : '';
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

		$redirect_tab = isset( $_POST['redirect_tab'] ) ? sanitize_key( wp_unslash( $_POST['redirect_tab'] ) ) : 's3';
		wp_safe_redirect( $this->page_url( $redirect_tab ) );
		exit;
	}
	/**
	 * Exibe a notificação flash de administrador.
	 */
	public function show_notice() {
		$notice = get_transient( 'dd_maintenance_notice' );
		if ( empty( $notice ) ) {
			$notice = get_transient( 'backuper_notice' );
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
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
		$settings = $this->settings_repository->get();

		$do_detect = ! empty( $_POST['dd_maint_detect_region'] );

		if ( isset( $_POST['s3_access_key'] ) ) {
			$settings['s3_access_key'] = sanitize_text_field( wp_unslash( $_POST['s3_access_key'] ) );
		}
		$secret_key = isset( $_POST['s3_secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['s3_secret_key'] ) ) ) : '';
		if ( '' !== $secret_key ) {
			$settings['s3_secret_key'] = $secret_key;
		}
		if ( isset( $_POST['s3_bucket'] ) ) {
			$settings['s3_bucket'] = sanitize_text_field( wp_unslash( $_POST['s3_bucket'] ) );
		}
		if ( isset( $_POST['s3_region'] ) ) {
			$settings['s3_region'] = sanitize_text_field( wp_unslash( $_POST['s3_region'] ) );
		}
		if ( isset( $_POST['s3_endpoint'] ) ) {
			$settings['s3_endpoint'] = sanitize_text_field( wp_unslash( $_POST['s3_endpoint'] ) );
		}

		if ( isset( $_POST['include_db'] ) || isset( $_POST['include_entire'] ) ) {
			$settings['include_db']        = empty( $_POST['include_db'] ) ? 0 : 1;
			$settings['include_wpcontent'] = empty( $_POST['include_wpcontent'] ) ? 0 : 1;
			$settings['include_wpconfig']  = empty( $_POST['include_wpconfig'] ) ? 0 : 1;
			$settings['include_entire']    = empty( $_POST['include_entire'] ) ? 0 : 1;
			$settings['keep_local']        = empty( $_POST['keep_local'] ) ? 0 : 1;
		}

		if ( isset( $_POST['schedule_enabled'] ) || ! $do_detect ) {
			$settings['schedule_enabled'] = empty( $_POST['schedule_enabled'] ) ? 0 : 1;
		}

		if ( isset( $_POST['schedule_frequency'] ) ) {
			$freq = sanitize_key( wp_unslash( $_POST['schedule_frequency'] ) );
			if ( in_array( $freq, array( 'daily', 'weekly', 'biweekly', 'monthly' ), true ) ) {
				$settings['schedule_frequency'] = $freq;
			}
		}

		if ( isset( $_POST['schedule_time'] ) ) {
			$time_str = trim( sanitize_text_field( wp_unslash( $_POST['schedule_time'] ) ) );
			if ( preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time_str ) ) {
				$settings['schedule_time'] = $time_str;
			}
		}
		if ( isset( $_POST['split_size_mb'] ) ) {
			$settings['split_size_mb'] = $this->settings_repository->normalize_split_size_mb( wp_unslash( $_POST['split_size_mb'] ) );
		}

		if ( isset( $_POST['retention_local'] ) ) {
			$settings['retention_local'] = max( 0, (int) $_POST['retention_local'] );
		}

		$this->settings_repository->save( $settings );

		$redirect_tab = isset( $_POST['active_tab'] ) ? sanitize_key( wp_unslash( $_POST['active_tab'] ) ) : 's3';
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
					$this->settings_repository->save( $settings );
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
	public function handle_config_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

		$result = DD_Maintenance_Config::handle_post();

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
		check_admin_referer( 'dd_maintenance_run_backup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
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
		$parts      = ! empty( $backup_result->parts ) ? $backup_result->parts : array( array( 'file' => $backup_result->file, 'name' => $backup_result->name, 'size' => $backup_result->size, 'part' => 1 ) );
		$total_size = $backup_result->total_size > 0 ? $backup_result->total_size : $backup_result->size;

		$upload_result = $this->backup_workflow->upload_parts( $parts, $folder, (int) $total_size, '' );
		if ( ! $upload_result->success ) {
			DD_Maintenance::instance()->set_notice( implode( ' ', $upload_result->errors ), 'error' );
		} else {
			$chunk_size_mb = (int) $backup_result->chunk_size_mb;
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
		check_admin_referer( 'dd_maintenance_update_plugins' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

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
		check_admin_referer( 'dd_maintenance_update_core' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

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
		check_admin_referer( 'dd_maintenance_run_full' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}
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
		check_admin_referer( 'dd_maintenance_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

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
	public function handle_delete_log() {
		check_admin_referer( 'dd_maintenance_delete_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

		$filename = isset( $_POST['log_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['log_filename'] ) ) : '';
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
	public function handle_download_log() {
		check_admin_referer( 'dd_maintenance_download_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'dd-maintenance' ) );
		}

		$filename = isset( $_GET['log_filename'] ) ? sanitize_file_name( wp_unslash( $_GET['log_filename'] ) ) : '';
		$content  = DD_Maintenance::get_log_content( $filename );

		if ( is_wp_error( $content ) ) {
			wp_die( esc_html( $content->get_error_message() ) );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content;
		exit;
	}

	/**
	 * Handler: Faz download seguro de um arquivo de backup local (.zip ou .sql).
	 */
	public function handle_download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão para baixar arquivos de backup.', 'dd-maintenance' ), 403 );
		}

		check_admin_referer( 'dd_maintenance_download_backup' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
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
	public function ajax_handle_action() {
		if ( ! $this->backup_workflow instanceof DD_Maintenance_Backup_Workflow ) {
			$this->backup_workflow = new DD_Maintenance_Backup_Workflow();
		}
		$step           = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$session_id     = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
		$correlation_id = isset( $_POST['correlation_id'] ) ? sanitize_key( wp_unslash( $_POST['correlation_id'] ) ) : '';
		if ( ! check_ajax_referer( 'dd_maint_ajax_nonce', 'nonce', false ) ) {
			DD_Maintenance::record_event( 'backup', 'ajax_rejected', array( 'step' => $step, 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'invalid_nonce', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Sessão expirada ou nonce inválido. Recarregue a página.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			DD_Maintenance::record_event( 'backup', 'ajax_rejected', array( 'step' => $step, 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'insufficient_capability', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'dd-maintenance' ), 'correlation_id' => $correlation_id ) );
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
				$session = $this->backup_workflow->step( 'init', '' );

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
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
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
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
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
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
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
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
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
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
				$error_msg  = isset( $_POST['error'] ) ? sanitize_text_field( wp_unslash( $_POST['error'] ) ) : '';
				$log_raw    = isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
				$lines      = is_array( $log_raw ) ? $log_raw : explode( "\n", (string) $log_raw );
				$this->backup_workflow->cleanup_failed( $session_id, $error_msg, $lines );
				DD_Maintenance::record_event( 'backup', 'cleanup_finished', array( 'step' => 'backup_fail_cleanup', 'session_id' => $session_id, 'correlation_id' => $correlation_id, 'status' => 'warning', 'failure_code' => 'backup_aborted', 'error_count' => 1 ) );
				wp_send_json_success( array( 'cleaned' => true, 'correlation_id' => $correlation_id ) );
				break;

			case 'backup_save_log':
				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
				$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'success';
				$base_name  = isset( $_POST['base_name'] ) ? sanitize_file_name( wp_unslash( $_POST['base_name'] ) ) : '';
				$log_raw    = isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
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
				$filename = isset( $_POST['log_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['log_filename'] ) ) : '';
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


				$part_file   = isset( $_POST['part_file'] ) ? sanitize_text_field( wp_unslash( $_POST['part_file'] ) ) : '';
				$part_name   = isset( $_POST['part_name'] ) ? sanitize_file_name( wp_unslash( $_POST['part_name'] ) ) : '';
				$part_size   = isset( $_POST['part_size'] ) ? (int) $_POST['part_size'] : 0;
				$part_index  = isset( $_POST['part_index'] ) ? (int) $_POST['part_index'] : 1;
				$total_parts = isset( $_POST['total_parts'] ) ? (int) $_POST['total_parts'] : 1;
				$folder      = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'site/' . current_time( 'Y-m-d' );

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

				if ( ! $upload_result->success ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								__( 'Erro no envio da parte %1$d/%2$d: %3$s', 'dd-maintenance' ),
								$part_index,
								$total_parts,
								implode( ' ', $upload_result->errors )
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

				$session_id = isset( $_POST['session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['session_id'] ) ) : '';
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
	public function ajax_handle_restore() {
		if ( ! $this->restore_workflow instanceof DD_Maintenance_Restore_Workflow ) {
			$this->restore_workflow = new DD_Maintenance_Restore_Workflow();
		}
		$mode                    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$restore_session_id      = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';
		$restore_token           = isset( $_POST['restore_token'] ) ? trim( (string) wp_unslash( $_POST['restore_token'] ) ) : '';
		$correlation_id          = isset( $_POST['correlation_id'] ) ? sanitize_key( wp_unslash( $_POST['correlation_id'] ) ) : '';
		$restore                 = $this->restore_workflow;
		$request_event           = DD_Maintenance::record_event( 'restore', 'ajax_step_started', array( 'step' => $mode, 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'running' ) );
		$correlation_id          = $request_event['correlation_id'];
		$has_valid_admin_session = is_user_logged_in() && current_user_can( 'manage_options' ) && check_ajax_referer( 'dd_maint_ajax_nonce', 'nonce', false );

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
			$password = isset( $_POST['restore_password'] ) ? trim( (string) wp_unslash( $_POST['restore_password'] ) ) : '';
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
			$upload_session_id = isset( $_POST['upload_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['upload_session_id'] ) ) : '';
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

			$upload       = isset( $_FILES['file_chunk'] ) && is_array( $_FILES['file_chunk'] ) ? $_FILES['file_chunk'] : array();
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

			$posted_name = isset( $_POST['file_name'] ) && is_string( $_POST['file_name'] ) ? wp_unslash( $_POST['file_name'] ) : '';
			$upload_name = isset( $upload['name'] ) && is_string( $upload['name'] ) ? wp_unslash( $upload['name'] ) : '';
			$orig_name  = sanitize_file_name( '' !== $posted_name ? $posted_name : $upload_name );
			if ( '' === $orig_name ) {
				wp_send_json_error( array( 'message' => __( 'Nome de arquivo inválido nesta etapa do upload.', 'dd-maintenance' ) ) );
			}

			$chunk_index = isset( $_POST['chunk_index'] ) ? max( 0, (int) $_POST['chunk_index'] ) : 0;
			$chunk_total = isset( $_POST['chunk_total'] ) ? max( 1, (int) $_POST['chunk_total'] ) : 1;
			$chunk_offset = isset( $_POST['chunk_offset'] ) ? max( 0, (int) $_POST['chunk_offset'] ) : 0;
			$file_size   = isset( $_POST['file_size'] ) ? max( 0, (int) $_POST['file_size'] ) : 0;
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
			$source     = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'upload';
			$backup_raw = DD_Maintenance::backup_dir();
			$backup_dir = wp_normalize_path( realpath( $backup_raw ) );
			$zip_paths  = array();
			$temp_dir   = '';

			if ( 'upload' === $source ) {
				$upload_session_id = isset( $_POST['upload_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['upload_session_id'] ) ) : '';
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
				$filename = isset( $_POST['backup_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_filename'] ) ) : '';
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

			$apply_elementor_compatibility = ! empty( $_POST['apply_elementor_compatibility'] );
			$session = $restore->initialize( $zip_paths, $temp_dir, $apply_elementor_compatibility );
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
			$restore_session_id = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';
			$batch_limit        = isset( $_POST['batch_limit'] ) ? max( 1, (int) $_POST['batch_limit'] ) : 5;

			$result = $restore->extract( $restore_session_id, $batch_limit );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_extract', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_extract', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_db' === $mode ) {
			$restore_session_id = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';

			$result = $restore->database( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_db', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_db', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_files' === $mode ) {
			$restore_session_id = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';

			$result = $restore->files( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_files', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'restore_files', $restore_session_id, $result, $correlation_id );
			wp_send_json_success( $result );
		} elseif ( 'restore_finalize' === $mode ) {
			$restore_session_id = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';

			$result = $restore->finalize( $restore_session_id );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'restore_finalize', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$this->record_restore_result( 'restore_finalize', $restore_session_id, $result, $correlation_id );
			$log_str = ! empty( $result->log ) ? implode( "\n", $result->log ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'correlation_id' => $correlation_id ) );
		} elseif ( 'restore_fail_cleanup' === $mode ) {
			$restore_session_id = isset( $_POST['restore_session_id'] ) ? sanitize_file_name( wp_unslash( $_POST['restore_session_id'] ) ) : '';
			if ( ! empty( $restore_session_id ) ) {
				$restore->cleanup_failed( $restore_session_id );
			}
			DD_Maintenance::record_event( 'restore', 'cleanup_finished', array( 'step' => 'restore_fail_cleanup', 'session_id' => $restore_session_id, 'correlation_id' => $correlation_id, 'status' => 'warning', 'failure_code' => 'restore_aborted', 'error_count' => 1 ) );
			wp_send_json_success( array( 'cleaned' => true, 'correlation_id' => $correlation_id ) );
		} elseif ( 'upload' === $mode ) {
			if ( empty( $_FILES['backup_zip'] ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'upload', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'upload_missing', 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => __( 'Nenhum arquivo enviado.', 'dd-maintenance' ) ) );
			}

			$apply_elementor_compatibility = ! empty( $_POST['apply_elementor_compatibility'] );
			$result = $restore->from_upload( $_FILES['backup_zip'], $apply_elementor_compatibility );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'upload', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'upload', '', $result, $correlation_id );
			$log_str = ! empty( $result->log ) ? implode( "\n", $result->log ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'correlation_id' => $correlation_id ) );
		} elseif ( 'local' === $mode ) {
			$filename = isset( $_POST['backup_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_filename'] ) ) : '';
			if ( empty( $filename ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'local', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'backup_name_missing', 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => __( 'Nome de backup local inválido.', 'dd-maintenance' ) ) );
			}

			$apply_elementor_compatibility = ! empty( $_POST['apply_elementor_compatibility'] );
			$result = $restore->from_local( $filename, $apply_elementor_compatibility );
			if ( is_wp_error( $result ) ) {
				DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => 'local', 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => $result->get_error_code(), 'error_count' => 1 ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$this->record_restore_result( 'local', '', $result, $correlation_id );
			$log_str = ! empty( $result->log ) ? implode( "\n", $result->log ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
			wp_send_json_success( array( 'log' => $log_str, 'correlation_id' => $correlation_id ) );
		} else {
			DD_Maintenance::record_event( 'restore', 'step_failed', array( 'step' => $mode, 'correlation_id' => $correlation_id, 'status' => 'failure', 'failure_code' => 'invalid_mode', 'error_count' => 1 ) );
			wp_send_json_error( array( 'message' => __( 'Modo de restauração inválido.', 'dd-maintenance' ) ) );
		}
	}
	private function record_restore_result( string $step, string $session_id, $result, string $correlation_id ): void {
		$data      = is_object( $result ) && method_exists( $result, 'to_array' ) ? $result->to_array() : (array) $result;
		$completed = ! empty( $data['completed'] ) || 'restore_finalize' === $step;
		DD_Maintenance::record_event(
			'restore',
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
		return defined( 'DD_MAINTENANCE_DISABLE_OPERATIONS' ) && true === DD_MAINTENANCE_DISABLE_OPERATIONS;
	}
}
