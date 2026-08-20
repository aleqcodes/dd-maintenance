<?php
/**
 * Página de configurações e ações de administração do DD Maintenance.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Settings {

	/**
	 * Construtor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_redirects' ) );

		// Handlers do admin-post.
		add_action( 'admin_post_dd_maintenance_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_dd_maintenance_run_backup', array( $this, 'handle_backup' ) );
		add_action( 'admin_post_dd_maintenance_update_plugins', array( $this, 'handle_plugins' ) );
		add_action( 'admin_post_dd_maintenance_update_core', array( $this, 'handle_core' ) );
		add_action( 'admin_post_dd_maintenance_run_full', array( $this, 'handle_full' ) );
		add_action( 'admin_post_dd_maintenance_config_action', array( $this, 'handle_config_action' ) );
		add_action( 'admin_post_dd_maintenance_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_dd_maintenance_restore_upload', array( $this, 'handle_restore_upload' ) );
		add_action( 'admin_post_dd_maintenance_restore_local', array( $this, 'handle_restore_local' ) );
		add_action( 'admin_post_dd_maintenance_delete_backup', array( $this, 'handle_delete_backup' ) );

		// Handlers AJAX para barra de progresso visual em tempo real.
		add_action( 'wp_ajax_dd_maintenance_ajax_action', array( $this, 'ajax_handle_action' ) );
		add_action( 'wp_ajax_dd_maintenance_ajax_restore', array( $this, 'ajax_handle_restore' ) );
		// Compatibilidade com ações legadas do Backuper.
		add_action( 'admin_post_backuper_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_backuper_run_backup', array( $this, 'handle_backup' ) );
		add_action( 'admin_post_backuper_update_plugins', array( $this, 'handle_plugins' ) );
		add_action( 'admin_post_backuper_update_core', array( $this, 'handle_core' ) );
		add_action( 'admin_post_backuper_run_full', array( $this, 'handle_full' ) );

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
		$valid_tabs  = array( 'general', 'config', 's3', 'cron', 'restore', 'logs' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'general';
		}

		$settings = wp_parse_args(
			get_option( 'dd_maintenance_settings', array() ),
			array(
				's3_access_key'     => '',
				's3_secret_key'     => '',
				's3_bucket'         => '',
				's3_region'         => 'nyc3',
				's3_endpoint'       => '',
				'include_db'        => 1,
				'include_wpcontent' => 1,
				'include_wpconfig'  => 1,
				'include_entire'    => 1,
				'keep_local'        => 1,
				'schedule_enabled'  => 0,
			)
		);

		$s3            = new DD_Maintenance_S3();
		$s3_configured = $s3->is_configured();
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
					position: fixed;
					top: 0;
					left: 0;
					width: 100vw;
					height: 100vh;
					z-index: 999999;
					display: flex;
					align-items: center;
					justify-content: center;
				}
				.dd-maint-modal-backdrop {
					position: absolute;
					top: 0;
					left: 0;
					width: 100%;
					height: 100%;
					background: rgba(18, 23, 28, 0.75);
					backdrop-filter: blur(3px);
				}
				.dd-maint-modal-dialog {
					position: relative;
					background: #ffffff;
					width: 92%;
					max-width: 650px;
					border-radius: 8px;
					box-shadow: 0 15px 35px rgba(0,0,0,0.35);
					overflow: hidden;
					z-index: 1;
					animation: ddMaintFadeIn 0.25s ease-out;
				}
				@keyframes ddMaintFadeIn {
					from { opacity: 0; transform: translateY(-15px); }
					to { opacity: 1; transform: translateY(0); }
				}
				.dd-maint-modal-header {
					display: flex;
					align-items: center;
					justify-content: space-between;
					padding: 16px 20px;
					border-bottom: 1px solid #dcdcde;
					background: #f6f7f7;
				}
				.dd-maint-modal-header h3 {
					margin: 0;
					display: flex;
					align-items: center;
					gap: 8px;
					font-size: 15px;
					font-weight: 600;
				}
				.dd-maint-badge {
					background: #2271b1;
					color: #ffffff;
					font-weight: 700;
					font-size: 13px;
					padding: 3px 10px;
					border-radius: 12px;
					letter-spacing: 0.5px;
				}
				.dd-maint-badge.success {
					background: #46b450;
				}
				.dd-maint-badge.error {
					background: #d63638;
				}
				.dd-maint-modal-body {
					padding: 20px;
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
					max-height: 180px;
					overflow-y: auto;
					white-space: pre-wrap;
					word-break: break-all;
				}
				.dd-maint-modal-footer {
					padding: 12px 20px;
					background: #f6f7f7;
					border-top: 1px solid #dcdcde;
					display: flex;
					justify-content: flex-end;
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
					<?php esc_html_e( 'Restaurar Backup', 'dd-maintenance' ); ?>
				</a>
				<a href="<?php echo esc_url( $this->page_url( 'logs' ) ); ?>" class="nav-tab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-media-text"></span>
					<?php esc_html_e( 'Logs & Histórico', 'dd-maintenance' ); ?>
				</a>
			</nav>

			<?php
			switch ( $current_tab ) {
				case 'config':
					$this->render_tab_config( $config_status, $has_password );
					break;

				case 's3':
					$this->render_tab_s3( $settings, $s3_configured, $s3 );
					break;

				case 'cron':
					$this->render_tab_cron( $settings );
					break;

				case 'restore':
					$this->render_tab_restore( $has_password );
					break;

				case 'logs':
					$this->render_tab_logs( $last_log );
					break;

				case 'general':
				default:
					$this->render_tab_general( $s3_configured, $s3, $config_status, $settings, $last_log );
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

					<div class="dd-maint-console-container">
						<div class="dd-maint-console-header">
							<span><?php esc_html_e( 'Terminal de Logs em Tempo Real', 'dd-maintenance' ); ?></span>
						</div>
						<pre id="dd-maint-console-output" class="dd-maint-console"></pre>
					</div>
				</div>

				<div class="dd-maint-modal-footer">
					<button type="button" id="dd-maint-modal-close-btn" class="button button-primary" style="display:none;" onclick="location.reload();">
						<?php esc_html_e( 'Concluído (Atualizar Página)', 'dd-maintenance' ); ?>
					</button>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var ajaxUrl = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo json_encode( wp_create_nonce( 'dd_maint_ajax_nonce' ) ); ?>;

			var modal      = document.getElementById('dd-maint-progress-modal');
			var icon       = document.getElementById('dd-maint-modal-icon');
			var titleText  = document.getElementById('dd-maint-modal-title-text');
			var percentEl  = document.getElementById('dd-maint-modal-percent');
			var barEl      = document.getElementById('dd-maint-progress-bar');
			var statusText = document.getElementById('dd-maint-status-text');
			var consoleOut = document.getElementById('dd-maint-console-output');
			var closeBtn   = document.getElementById('dd-maint-modal-close-btn');

			function openModal(title) {
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

				if (isSuccess) {
					percentEl.className = 'dd-maint-badge success';
					barEl.className     = 'dd-maint-progress-bar success';
					icon.className       = 'dashicons dashicons-yes-alt';
					icon.style.color     = '#46b450';
					closeBtn.style.display = 'inline-block';
				} else if (isError) {
					percentEl.className = 'dd-maint-badge error';
					barEl.className     = 'dd-maint-progress-bar error';
					icon.className       = 'dashicons dashicons-no-alt';
					icon.style.color     = '#d63638';
					closeBtn.style.display = 'inline-block';
				}
			}

			function sendAjax(action, data, onSuccess, onError) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
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
				.then(function(r) { return r.json(); })
				.then(function(json) {
					if (json && json.success) {
						if (onSuccess) onSuccess(json.data);
					} else {
						var err = (json && json.data && json.data.message) ? json.data.message : (json && json.data) ? json.data : 'Erro desconhecido';
						if (onError) onError(err, json);
					}
				})
				.catch(function(err) {
					if (onError) onError('Erro de conexão ou resposta inválida: ' + err);
				});
			}

			// Intercepta formulários de Ações Rápidas (Painel Geral)
			document.querySelectorAll('.dd-maintenance-actions form').forEach(function(form) {
				form.addEventListener('submit', function(e) {
					e.preventDefault();
					var act = form.querySelector('input[name="action"]').value;

					if (act === 'dd_maintenance_run_full') {
						runFullSequence();
					} else if (act === 'dd_maintenance_run_backup') {
						runBackupSequence();
					} else if (act === 'dd_maintenance_update_plugins') {
						runPluginsUpdate();
					} else if (act === 'dd_maintenance_update_core') {
						runCoreUpdate();
					}
				});
			});

			function runFullSequence() {
				openModal('Manutenção Completa (Backup → S3 → Plugins → Core)');
				setProgress(5, 'Passo 1/5: Gerando dump SQL e arquivos compactados (partes de 25MB)...', '[Início] ' + new Date().toLocaleTimeString());

				sendAjax('dd_maintenance_ajax_action', { step: 'backup' }, function(d) {
					setProgress(35, 'Passo 2/5: Enviando partes para o S3 / Spaces...', d.log);

					sendAjax('dd_maintenance_ajax_action', { step: 's3', parts_data: JSON.stringify(d.parts_data), folder: d.folder }, function(d2) {
						setProgress(70, 'Passo 3/5: Aplicando política de retenção local...', d2.log);

						sendAjax('dd_maintenance_ajax_action', { step: 'retention' }, function(d3) {
							setProgress(80, 'Passo 4/5: Atualizando plugins com versões pendentes...', d3.log);

							sendAjax('dd_maintenance_ajax_action', { step: 'plugins' }, function(d4) {
								setProgress(90, 'Passo 5/5: Verificando e atualizando Core do WordPress...', d4.log);

								sendAjax('dd_maintenance_ajax_action', { step: 'core' }, function(d5) {
									setProgress(100, 'Manutenção completa concluída com sucesso!', d5.log + '\n[Fim] ' + new Date().toLocaleTimeString(), true);
								}, function(err) {
									setProgress(90, 'Erro na atualização do Core', '[ERRO] ' + err, false, true);
								});

							}, function(err) {
								setProgress(80, 'Erro na atualização de plugins', '[ERRO] ' + err, false, true);
							});

						}, function(err) {
							setProgress(70, 'Erro na retenção', '[ERRO] ' + err, false, true);
						});

					}, function(err) {
						setProgress(35, 'Erro no envio ao S3', '[ERRO] ' + err, false, true);
					});

				}, function(err) {
					setProgress(5, 'Erro na criação do backup', '[ERRO] ' + err, false, true);
				});
			}

			function runBackupSequence() {
				openModal('Backup & Envio para S3 / Spaces');
				setProgress(10, 'Passo 1/3: Criando backup (SQL + Arquivos em partes de 25MB)...', '[Início] ' + new Date().toLocaleTimeString());

				sendAjax('dd_maintenance_ajax_action', { step: 'backup' }, function(d) {
					setProgress(50, 'Passo 2/3: Enviando partes para o bucket S3 / Spaces...', d.log);

					sendAjax('dd_maintenance_ajax_action', { step: 's3', parts_data: JSON.stringify(d.parts_data), folder: d.folder }, function(d2) {
						setProgress(85, 'Passo 3/3: Aplicando política de retenção...', d2.log);

						sendAjax('dd_maintenance_ajax_action', { step: 'retention' }, function(d3) {
							setProgress(100, 'Backup e envio ao S3 concluídos com sucesso!', d3.log + '\n[OK] Concluído com sucesso.', true);
						}, function(err) {
							setProgress(85, 'Erro na retenção', '[ERRO] ' + err, false, true);
						});

					}, function(err) {
						setProgress(50, 'Erro no envio ao S3', '[ERRO] ' + err, false, true);
					});

				}, function(err) {
					setProgress(10, 'Erro no backup', '[ERRO] ' + err, false, true);
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

			// Intercepta formulários de Restauração (Upload e Local)
			document.querySelectorAll('form[action*="admin-post.php"]').forEach(function(f) {
				var actInput = f.querySelector('input[name="action"]');
				if (!actInput) return;
				var actVal = actInput.value;

				if (actVal === 'dd_maintenance_restore_upload') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						var fileInput = f.querySelector('input[name="backup_zip[]"]') || f.querySelector('input[name="backup_zip"]');
						if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
							alert('Selecione pelo menos um arquivo .zip');
							return;
						}

						openModal('Restauração de Backup (Upload)');
						setProgress(5, 'Enviando arquivo(s) para o servidor...', '[Início] ' + new Date().toLocaleTimeString() + '\n[Upload] ' + fileInput.files.length + ' arquivo(s) selecionado(s)...');

						var fd = new FormData(f);
						fd.append('action', 'dd_maintenance_ajax_restore');
						fd.append('mode', 'upload');
						fd.append('nonce', nonce);

						var xhr = new XMLHttpRequest();
						xhr.open('POST', ajaxUrl, true);
						xhr.withCredentials = true;

						xhr.upload.onprogress = function(pe) {
							if (pe.lengthComputable) {
								var uploadPct = Math.round((pe.loaded / pe.total) * 50);
								setProgress(uploadPct, 'Enviando arquivo(s) para o servidor (' + Math.round((pe.loaded / pe.total) * 100) + '% enviado)...');
							}
						};

						xhr.onload = function() {
							if (xhr.status >= 200 && xhr.status < 300) {
								try {
									var res = JSON.parse(xhr.responseText);
									if (res && res.success) {
										setProgress(100, 'Backup restaurado com sucesso!', res.data.log || '[OK] Restauração concluída.', true);
									} else {
										var errMsg = (res && res.data && res.data.message) ? res.data.message : (res && res.data) ? res.data : 'Erro na restauração.';
										setProgress(50, 'Erro na restauração', '[ERRO] ' + errMsg, false, true);
									}
								} catch (err) {
									setProgress(50, 'Erro ao processar resposta', '[ERRO] Resposta inválida do servidor: ' + xhr.responseText.substr(0, 200), false, true);
								}
							} else {
								setProgress(50, 'Erro HTTP ' + xhr.status, '[ERRO] Falha na requisição ao servidor.', false, true);
							}
						};

						xhr.onerror = function() {
							setProgress(50, 'Erro de conexão', '[ERRO] Falha de conexão ao enviar arquivos.', false, true);
						};

						xhr.send(fd);
					});
				} else if (actVal === 'dd_maintenance_restore_local') {
					f.addEventListener('submit', function(e) {
						e.preventDefault();
						var fnInput = f.querySelector('input[name="backup_filename"]');
						var pwdInput = f.querySelector('input[name="restore_password"]');
						var filename = fnInput ? fnInput.value : '';
						var pwd = pwdInput ? pwdInput.value : '';

						openModal('Restauração de Backup Local');
						setProgress(10, 'Reconstruindo e extraindo backup local...', '[Início] ' + new Date().toLocaleTimeString() + '\n[Arquivo] ' + filename);

						sendAjax('dd_maintenance_ajax_restore', { mode: 'local', backup_filename: filename, restore_password: pwd }, function(d) {
							setProgress(100, 'Backup local restaurado com sucesso!', d.log || '[OK] Restauração concluída.', true);
						}, function(err) {
							setProgress(40, 'Erro na restauração', '[ERRO] ' + err, false, true);
						});
					});
				}
			});
		})();
		</script>
		</div>
		<?php
	}
	/**
	 * Aba 1: Visão Geral & Ações Rápidas.
	 */
	private function render_tab_general( $s3_configured, $s3, $config_status, $settings, $last_log ) {
		$file_mods = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		$file_edit = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_EDIT' );
		$next_cron = wp_next_scheduled( 'dd_maintenance_daily_maintenance' );
		if ( ! $next_cron ) {
			$next_cron = wp_next_scheduled( 'backuper_daily_maintenance' );
		}
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:24px;">
			<!-- Card 1: Status do S3 -->
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,0.04);">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-cloud" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Armazenamento S3 (Spaces)', 'dd-maintenance' ); ?>
				</h3>
				<?php if ( $s3_configured ) : ?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <strong><?php esc_html_e( 'Configurado e Pronto', 'dd-maintenance' ); ?></strong></p>
					<p style="margin-bottom:0;"><code><?php echo esc_html( $s3->get_bucket() ); ?></code> (<?php echo esc_html( $s3->get_region() ); ?>)</p>
				<?php else : ?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-warning" style="color:#dba617;"></span> <strong><?php esc_html_e( 'Não configurado', 'dd-maintenance' ); ?></strong></p>
					<p><a href="<?php echo esc_url( $this->page_url( 's3' ) ); ?>" class="button button-small"><?php esc_html_e( 'Configurar credenciais', 'dd-maintenance' ); ?></a></p>
				<?php endif; ?>
			</div>

			<!-- Card 2: Status do wp-config.php -->
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,0.04);">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-admin-settings" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Travas wp-config.php', 'dd-maintenance' ); ?>
				</h3>
				<p style="margin:4px 0;">
					<strong>DISALLOW_FILE_MODS:</strong>
					<?php if ( true === $file_mods ) : ?>
						<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'Bloqueado (true)', 'dd-maintenance' ); ?></span>
					<?php else : ?>
						<span style="color:#46b450;font-weight:600;"><?php esc_html_e( 'Liberado (false)', 'dd-maintenance' ); ?></span>
					<?php endif; ?>
				</p>
				<p style="margin:4px 0;">
					<strong>DISALLOW_FILE_EDIT:</strong>
					<?php if ( true === $file_edit ) : ?>
						<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'Bloqueado (true)', 'dd-maintenance' ); ?></span>
					<?php else : ?>
						<span style="color:#46b450;font-weight:600;"><?php esc_html_e( 'Liberado (false)', 'dd-maintenance' ); ?></span>
					<?php endif; ?>
				</p>
				<p style="margin-top:8px;margin-bottom:0;">
					<a href="<?php echo esc_url( $this->page_url( 'config' ) ); ?>" class="button button-small"><?php esc_html_e( 'Gerenciar com senha', 'dd-maintenance' ); ?></a>
				</p>
			</div>

			<!-- Card 3: Status da Automação & Retenção -->
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,0.04);">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-backup" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Automação & Retenção', 'dd-maintenance' ); ?>
				</h3>
				<?php if ( ! empty( $settings['schedule_enabled'] ) ) : ?>
					<?php
					$freq_labels = array(
						'daily'    => __( 'Diária (24h)', 'dd-maintenance' ),
						'weekly'   => __( 'Semanal (7 dias)', 'dd-maintenance' ),
						'biweekly' => __( 'Quinzenal (15 dias)', 'dd-maintenance' ),
						'monthly'  => __( 'Mensal (30 dias)', 'dd-maintenance' ),
					);
					$freq_key   = isset( $settings['schedule_frequency'] ) ? $settings['schedule_frequency'] : 'daily';
					$freq_label = isset( $freq_labels[ $freq_key ] ) ? $freq_labels[ $freq_key ] : __( 'Ativada', 'dd-maintenance' );
					$retention  = isset( $settings['retention_local'] ) ? (int) $settings['retention_local'] : 5;
					?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <strong><?php echo esc_html( $freq_label ); ?></strong></p>
					<?php if ( $next_cron ) : ?>
						<p style="margin:4px 0;color:#666;font-size:12px;"><?php printf( esc_html__( 'Próxima: %s', 'dd-maintenance' ), esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'd/m/Y H:i:s' ) ) ); ?></p>
					<?php endif; ?>
					<p style="margin:4px 0;color:#666;font-size:12px;"><?php printf( esc_html__( 'Retenção: %s', 'dd-maintenance' ), $retention > 0 ? sprintf( esc_html__( 'últimos %d backups', 'dd-maintenance' ), $retention ) : esc_html__( 'Ilimitada', 'dd-maintenance' ) ); ?></p>
				<?php else : ?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-marker" style="color:#666;"></span> <strong><?php esc_html_e( 'Desativada', 'dd-maintenance' ); ?></strong></p>
					<p><a href="<?php echo esc_url( $this->page_url( 'cron' ) ); ?>" class="button button-small"><?php esc_html_e( 'Configurar agendamento', 'dd-maintenance' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( true === $file_mods ) : ?>
			<div class="notice notice-warning inline" style="margin-bottom:20px;">
				<p>
					<strong><?php esc_html_e( 'Atenção:', 'dd-maintenance' ); ?></strong>
					<?php esc_html_e( 'A constante DISALLOW_FILE_MODS está ativa no seu wp-config.php. Atualizações de plugins e do core do WordPress podem ser bloqueadas pelo WordPress até que ela seja liberada.', 'dd-maintenance' ); ?>
					<a href="<?php echo esc_url( $this->page_url( 'config' ) ); ?>"><?php esc_html_e( 'Liberar temporariamente no Gerenciador', 'dd-maintenance' ); ?> &rarr;</a>
				</p>
			</div>
		<?php endif; ?>

		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Ações Manuais de Manutenção', 'dd-maintenance' ); ?></h2>
			<p><?php esc_html_e( 'Execute o ciclo completo ou dispare cada etapa de manutenção individualmente:', 'dd-maintenance' ); ?></p>

			<div class="dd-maintenance-actions">
				<!-- Botão 1: Executar Tudo -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_run_full">
					<?php wp_nonce_field( 'dd_maintenance_run_full' ); ?>
					<button type="submit" class="button button-primary">
						<span class="dashicons dashicons-update"></span>
						<span class="btn-text"><?php esc_html_e( 'Executar Tudo (Backup → S3 → Plugins → Core)', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 2: Backup e Envio -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_run_backup">
					<?php wp_nonce_field( 'dd_maintenance_run_backup' ); ?>
					<button type="submit" class="button button-secondary">
						<span class="dashicons dashicons-cloud-upload"></span>
						<span class="btn-text"><?php esc_html_e( 'Backup e Envio ao S3', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 3: Atualizar Plugins -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_update_plugins">
					<?php wp_nonce_field( 'dd_maintenance_update_plugins' ); ?>
					<button type="submit" class="button button-secondary">
						<span class="dashicons dashicons-admin-plugins"></span>
						<span class="btn-text"><?php esc_html_e( 'Atualizar Plugins', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 4: Atualizar Core -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_update_core">
					<?php wp_nonce_field( 'dd_maintenance_update_core' ); ?>
					<button type="submit" class="button button-secondary">
						<span class="dashicons dashicons-wordpress"></span>
						<span class="btn-text"><?php esc_html_e( 'Atualizar Core WordPress', 'dd-maintenance' ); ?></span>
					</button>
				</form>
			</div>
		</div>

		<?php if ( ! empty( $last_log ) && is_array( $last_log ) ) : ?>
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;">
				<div style="display:flex;justify-content:space-between;align-items:center;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Última Execução', 'dd-maintenance' ); ?></h2>
					<a href="<?php echo esc_url( $this->page_url( 'logs' ) ); ?>" class="button button-small"><?php esc_html_e( 'Ver logs completos', 'dd-maintenance' ); ?></a>
				</div>
				<pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;border-radius:3px;overflow:auto;max-height:220px;font-size:12px;line-height:1.5;"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Aba 2: Gerenciador de wp-config.php e Senhas.
	 */
	private function render_tab_config( $status, $has_password ) {
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;margin-bottom:24px;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-shield"></span>
				<?php esc_html_e( 'Controle de Travas no wp-config.php', 'dd-maintenance' ); ?>
			</h2>

			<p>
				<?php esc_html_e( 'Altere com segurança as diretivas de bloqueio de edição e atualizações diretamente no arquivo de configuração do WordPress, com proteção por senha e backup automático.', 'dd-maintenance' ); ?>
			</p>

			<ul>
				<li><code>define( 'DISALLOW_FILE_MODS', true/false );</code> &mdash; <?php esc_html_e( 'true bloqueia atualizações e instalações de plugins/temas/core; false libera.', 'dd-maintenance' ); ?></li>
				<li><code>define( 'DISALLOW_FILE_EDIT', true/false );</code> &mdash; <?php esc_html_e( 'true bloqueia o editor de arquivos de temas/plugins no painel; false libera.', 'dd-maintenance' ); ?></li>
			</ul>

			<table class="widefat striped" style="margin: 20px 0; border: 1px solid #c3c4c7;">
				<tbody>
					<tr>
						<th scope="row" style="width:240px;font-weight:600;"><?php esc_html_e( 'Arquivo detectado', 'dd-maintenance' ); ?></th>
						<td><code><?php echo esc_html( DD_Maintenance_Config::format_status_path( $status ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row" style="font-weight:600;"><code>DISALLOW_FILE_MODS</code></th>
						<td>
							<?php
							$mods_val = DD_Maintenance_Config::get_status_value( $status, 'DISALLOW_FILE_MODS' );
							if ( true === $mods_val ) {
								echo '<span style="color:#d63638;font-weight:bold;">' . esc_html__( 'true - BLOQUEADO (updates e arquivos travados)', 'dd-maintenance' ) . '</span>';
							} elseif ( false === $mods_val ) {
								echo '<span style="color:#46b450;font-weight:bold;">' . esc_html__( 'false - LIBERADO (updates permitidos)', 'dd-maintenance' ) . '</span>';
							} else {
								echo '<span style="color:#666;">' . esc_html__( 'Não definido (padrão: liberado)', 'dd-maintenance' ) . '</span>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row" style="font-weight:600;"><code>DISALLOW_FILE_EDIT</code></th>
						<td>
							<?php
							$edit_val = DD_Maintenance_Config::get_status_value( $status, 'DISALLOW_FILE_EDIT' );
							if ( true === $edit_val ) {
								echo '<span style="color:#d63638;font-weight:bold;">' . esc_html__( 'true - BLOQUEADO (editor desativado)', 'dd-maintenance' ) . '</span>';
							} elseif ( false === $edit_val ) {
								echo '<span style="color:#46b450;font-weight:bold;">' . esc_html__( 'false - LIBERADO (editor permitido)', 'dd-maintenance' ) . '</span>';
							} else {
								echo '<span style="color:#666;">' . esc_html__( 'Não definido (padrão: liberado)', 'dd-maintenance' ) . '</span>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! $has_password ) : ?>
				<div class="notice notice-info inline" style="margin-bottom:20px;">
					<p><strong><?php esc_html_e( 'Criar senha de proteção:', 'dd-maintenance' ); ?></strong> <?php esc_html_e( 'Antes de modificar o wp-config.php, defina uma senha de segurança para proteger estas operações.', 'dd-maintenance' ); ?></p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_config_action">
					<input type="hidden" name="dd_maintenance_config_action" value="set_password">
					<?php wp_nonce_field( DD_Maintenance_Config::NONCE_ACTION_SET_PASSWORD ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dd_maint_new_password"><?php esc_html_e( 'Nova senha', 'dd-maintenance' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="dd_maint_new_password" name="dd_maint_new_password" autocomplete="new-password" required minlength="6">
								<p class="description"><?php esc_html_e( 'Mínimo de 6 caracteres.', 'dd-maintenance' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_confirm_password"><?php esc_html_e( 'Confirmar senha', 'dd-maintenance' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="dd_maint_confirm_password" name="dd_maint_confirm_password" autocomplete="new-password" required minlength="6">
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Salvar e Criar Senha', 'dd-maintenance' ), 'primary' ); ?>
				</form>
			<?php else : ?>
				<h3><?php esc_html_e( 'Alterar Opções no wp-config.php', 'dd-maintenance' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Nota: "true" bloqueia as alterações/editor, e "false" permite.', 'dd-maintenance' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:30px;">
					<input type="hidden" name="action" value="dd_maintenance_config_action">
					<input type="hidden" name="dd_maintenance_config_action" value="save_config">
					<?php wp_nonce_field( DD_Maintenance_Config::NONCE_ACTION_SAVE_CONFIG ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dd_maint_password"><?php esc_html_e( 'Senha de confirmação', 'dd-maintenance' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="dd_maint_password" name="dd_maint_password" autocomplete="current-password" required>
								<p class="description"><?php esc_html_e( 'Digite sua senha do DD Maintenance para autorizar a modificação do wp-config.php.', 'dd-maintenance' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_file_mods"><code>DISALLOW_FILE_MODS</code></label></th>
							<td>
								<select id="dd_maint_file_mods" name="dd_maint_file_mods" style="max-width:100%;">
									<option value="true" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_MODS' ), true ); ?>><?php esc_html_e( 'true - BLOQUEAR updates, instalações e alterações de arquivos', 'dd-maintenance' ); ?></option>
									<option value="false" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_MODS' ), false ); ?>><?php esc_html_e( 'false - PERMITIR updates, instalações e alterações de arquivos', 'dd-maintenance' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_file_edit"><code>DISALLOW_FILE_EDIT</code></label></th>
							<td>
								<select id="dd_maint_file_edit" name="dd_maint_file_edit" style="max-width:100%;">
									<option value="true" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_EDIT' ), true ); ?>><?php esc_html_e( 'true - BLOQUEAR editor de arquivos no painel', 'dd-maintenance' ); ?></option>
									<option value="false" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_EDIT' ), false ); ?>><?php esc_html_e( 'false - PERMITIR editor de arquivos no painel', 'dd-maintenance' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Salvar Alterações no wp-config.php', 'dd-maintenance' ), 'primary' ); ?>
				</form>

				<hr>

				<h3><?php esc_html_e( 'Trocar Senha de Proteção', 'dd-maintenance' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_config_action">
					<input type="hidden" name="dd_maintenance_config_action" value="set_password">
					<?php wp_nonce_field( DD_Maintenance_Config::NONCE_ACTION_SET_PASSWORD ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dd_maint_current_password"><?php esc_html_e( 'Senha atual', 'dd-maintenance' ); ?></label></th>
							<td><input type="password" class="regular-text" id="dd_maint_current_password" name="dd_maint_current_password" autocomplete="current-password" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_new_password"><?php esc_html_e( 'Nova senha', 'dd-maintenance' ); ?></label></th>
							<td><input type="password" class="regular-text" id="dd_maint_new_password" name="dd_maint_new_password" autocomplete="new-password" required minlength="6"></td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_confirm_password"><?php esc_html_e( 'Confirmar nova senha', 'dd-maintenance' ); ?></label></th>
							<td><input type="password" class="regular-text" id="dd_maint_confirm_password" name="dd_maint_confirm_password" autocomplete="new-password" required minlength="6"></td>
						</tr>
					</table>

					<?php submit_button( __( 'Trocar Senha', 'dd-maintenance' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Aba 3: S3 / DigitalOcean Spaces & Opções de Backup.
	 */
	private function render_tab_s3( $settings, $s3_configured, $s3 ) {
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-cloud-upload"></span>
				<?php esc_html_e( 'Configurações do DigitalOcean Spaces (S3)', 'dd-maintenance' ); ?>
			</h2>

			<?php if ( ! $s3_configured ) : ?>
				<div class="notice notice-warning inline" style="margin-bottom:16px;">
					<p><strong><?php esc_html_e( 'S3 não configurado:', 'dd-maintenance' ); ?></strong> <?php esc_html_e( 'Informe as credenciais abaixo para que os backups possam ser enviados para a nuvem com segurança.', 'dd-maintenance' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline" style="margin-bottom:16px;">
					<p><strong><?php esc_html_e( 'S3 configurado com sucesso!', 'dd-maintenance' ); ?></strong> <?php esc_html_e( 'Os backups são enviados para o bucket:', 'dd-maintenance' ); ?> <code><?php echo esc_html( $s3->get_bucket() ); ?></code> (<?php echo esc_html( $s3->get_region() ); ?>).</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dd_maintenance_save_settings">
				<?php wp_nonce_field( 'dd_maintenance_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="s3_access_key"><?php esc_html_e( 'Access Key', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="s3_access_key" name="s3_access_key" value="<?php echo esc_attr( $settings['s3_access_key'] ); ?>" autocomplete="off">
							<p class="description">
								<?php esc_html_e( 'Chave de Spaces do DigitalOcean (começa com "DO00"). Em DigitalOcean → Spaces → Access Keys → Create Access Key.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_secret_key"><?php esc_html_e( 'Secret Key', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="s3_secret_key" name="s3_secret_key" value="<?php echo esc_attr( $settings['s3_secret_key'] ); ?>" autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_bucket"><?php esc_html_e( 'Nome do Bucket', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="s3_bucket" name="s3_bucket" value="<?php echo esc_attr( $settings['s3_bucket'] ); ?>">
							<p class="description">
								<?php esc_html_e( 'Criado em DigitalOcean → Spaces → Create a new Space.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_region"><?php esc_html_e( 'Região', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="s3_region" name="s3_region" value="<?php echo esc_attr( $settings['s3_region'] ); ?>">
							<p class="description">
								<?php esc_html_e( 'Ex.: nyc3, ams3, sfo3, fra1, sgp1, lon1... (mesma região do seu Space).', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_endpoint"><?php esc_html_e( 'Endpoint Customizado (opcional)', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="s3_endpoint" name="s3_endpoint" value="<?php echo esc_attr( $settings['s3_endpoint'] ); ?>" placeholder="https://bucket.region.digitaloceanspaces.com">
							<p class="description">
								<?php esc_html_e( 'Deixe vazio para usar a URL padrão automática.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Detectar Região Automaticamente', 'dd-maintenance' ), 'secondary', 'dd_maint_detect_region', false ); ?>
				</p>

				<hr>

				<h3><?php esc_html_e( 'Opções do Arquivo de Backup', 'dd-maintenance' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Conteúdo incluído', 'dd-maintenance' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="include_db" value="1" <?php checked( ! empty( $settings['include_db'] ), true ); ?>> <strong><?php esc_html_e( 'Banco de dados (dump SQL completo)', 'dd-maintenance' ); ?></strong></label><br><br>
								<label><input type="checkbox" name="include_entire" value="1" <?php checked( ! empty( $settings['include_entire'] ), true ); ?>> <strong><?php esc_html_e( 'Site inteiro (todos os arquivos do WordPress)', 'dd-maintenance' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Se "Site inteiro" estiver marcado, inclui wp-config, wp-content e todos os arquivos do core.', 'dd-maintenance' ); ?></p><br>
								<label><input type="checkbox" name="include_wpcontent" value="1" <?php checked( ! empty( $settings['include_wpcontent'] ), true ); ?>> <?php esc_html_e( 'Pasta wp-content (plugins, temas e uploads)', 'dd-maintenance' ); ?></label><br>
								<label><input type="checkbox" name="include_wpconfig" value="1" <?php checked( ! empty( $settings['include_wpconfig'] ), true ); ?>> <?php esc_html_e( 'Arquivo wp-config.php', 'dd-maintenance' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cópia Local', 'dd-maintenance' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="keep_local" value="1" <?php checked( ! empty( $settings['keep_local'] ), true ); ?>>
								<?php esc_html_e( 'Manter cópia local do arquivo gerado em wp-content/uploads/dd-maintenance/', 'dd-maintenance' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Salvar Configurações de Backup & S3', 'dd-maintenance' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Aba 4: Agendamento & Automação (WP-Cron).
	 */
	private function render_tab_cron( $settings ) {
		$next_cron = wp_next_scheduled( 'dd_maintenance_daily_maintenance' );
		if ( ! $next_cron ) {
			$next_cron = wp_next_scheduled( 'backuper_daily_maintenance' );
		}

		$current_freq      = isset( $settings['schedule_frequency'] ) ? $settings['schedule_frequency'] : 'daily';
		$current_time_val  = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '03:00';
		$current_retention = isset( $settings['retention_local'] ) ? (int) $settings['retention_local'] : 5;
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Agendamento Automático & Políticas de Retenção (WP-Cron)', 'dd-maintenance' ); ?>
			</h2>

			<p>
				<?php esc_html_e( 'Configure a rotina automática para executar periodicamente o fluxo completo de manutenção (backup completo com partes de 25MB, envio ao S3/Spaces, limpeza de retenção e atualizações de plugins e core).', 'dd-maintenance' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dd_maintenance_save_settings">
				<input type="hidden" name="active_tab" value="cron">
				<input type="hidden" name="s3_access_key" value="<?php echo esc_attr( $settings['s3_access_key'] ); ?>">
				<input type="hidden" name="s3_secret_key" value="<?php echo esc_attr( $settings['s3_secret_key'] ); ?>">
				<input type="hidden" name="s3_bucket" value="<?php echo esc_attr( $settings['s3_bucket'] ); ?>">
				<input type="hidden" name="s3_region" value="<?php echo esc_attr( $settings['s3_region'] ); ?>">
				<input type="hidden" name="s3_endpoint" value="<?php echo esc_attr( $settings['s3_endpoint'] ); ?>">
				<input type="hidden" name="include_db" value="<?php echo ! empty( $settings['include_db'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="include_entire" value="<?php echo ! empty( $settings['include_entire'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="include_wpcontent" value="<?php echo ! empty( $settings['include_wpcontent'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="include_wpconfig" value="<?php echo ! empty( $settings['include_wpconfig'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="keep_local" value="<?php echo ! empty( $settings['keep_local'] ) ? '1' : '0'; ?>">
				<?php wp_nonce_field( 'dd_maintenance_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ativar Agendamento', 'dd-maintenance' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="schedule_enabled" value="1" <?php checked( ! empty( $settings['schedule_enabled'] ), true ); ?>>
								<strong><?php esc_html_e( 'Ativar execução periódica automática (WP-Cron)', 'dd-maintenance' ); ?></strong>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="schedule_frequency"><?php esc_html_e( 'Frequência do Backup', 'dd-maintenance' ); ?></label></th>
						<td>
							<select id="schedule_frequency" name="schedule_frequency">
								<option value="daily" <?php selected( $current_freq, 'daily' ); ?>><?php esc_html_e( 'Diário (a cada 24 horas)', 'dd-maintenance' ); ?></option>
								<option value="weekly" <?php selected( $current_freq, 'weekly' ); ?>><?php esc_html_e( 'Semanal (a cada 7 dias)', 'dd-maintenance' ); ?></option>
								<option value="biweekly" <?php selected( $current_freq, 'biweekly' ); ?>><?php esc_html_e( 'Quinzenal (a cada 15 dias)', 'dd-maintenance' ); ?></option>
								<option value="monthly" <?php selected( $current_freq, 'monthly' ); ?>><?php esc_html_e( 'Mensal (a cada 30 dias)', 'dd-maintenance' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Escolha o intervalo desejado para rodar a rotina automática.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="schedule_time"><?php esc_html_e( 'Horário de Execução', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="time" id="schedule_time" name="schedule_time" value="<?php echo esc_attr( $current_time_val ); ?>" required>
							<p class="description">
								<?php esc_html_e( 'Horário de início preferencial (no fuso horário local configurado no WordPress). Recomendado: madrugada (ex: 03:00).', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="retention_local"><?php esc_html_e( 'Política de Retenção Local', 'dd-maintenance' ); ?></label></th>
						<td>
							<select id="retention_local" name="retention_local">
								<option value="0" <?php selected( $current_retention, 0 ); ?>><?php esc_html_e( 'Ilimitado (nunca excluir backups locais)', 'dd-maintenance' ); ?></option>
								<option value="3" <?php selected( $current_retention, 3 ); ?>><?php esc_html_e( 'Manter os 3 backups mais recentes', 'dd-maintenance' ); ?></option>
								<option value="5" <?php selected( $current_retention, 5 ); ?>><?php esc_html_e( 'Manter os 5 backups mais recentes (Recomendado)', 'dd-maintenance' ); ?></option>
								<option value="7" <?php selected( $current_retention, 7 ); ?>><?php esc_html_e( 'Manter os 7 backups mais recentes', 'dd-maintenance' ); ?></option>
								<option value="10" <?php selected( $current_retention, 10 ); ?>><?php esc_html_e( 'Manter os 10 backups mais recentes', 'dd-maintenance' ); ?></option>
								<option value="15" <?php selected( $current_retention, 15 ); ?>><?php esc_html_e( 'Manter os 15 backups mais recentes', 'dd-maintenance' ); ?></option>
								<option value="30" <?php selected( $current_retention, 30 ); ?>><?php esc_html_e( 'Manter os 30 backups mais recentes', 'dd-maintenance' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Backups locais mais antigos que ultrapassarem este limite serão excluídos automaticamente após novas rotinas para economizar espaço em disco no servidor.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Status Atual do Cron', 'dd-maintenance' ); ?></th>
						<td>
							<?php if ( ! empty( $settings['schedule_enabled'] ) && $next_cron ) : ?>
								<p style="margin-top:0;">
									<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span>
									<strong style="color:#46b450;"><?php esc_html_e( 'Agendamento Ativo', 'dd-maintenance' ); ?></strong>
								</p>
								<p>
									<strong><?php esc_html_e( 'Próxima Execução Prevista:', 'dd-maintenance' ); ?></strong>
									<code><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'd/m/Y H:i:s' ) ); ?></code>
								</p>
							<?php else : ?>
								<p style="color:#666;margin-top:0;">
									<span class="dashicons dashicons-no-alt" style="color:#d63638;vertical-align:middle;"></span>
									<?php esc_html_e( 'Nenhuma rotina automática agendada no momento.', 'dd-maintenance' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Salvar Configurações de Agendamento e Retenção', 'dd-maintenance' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Aba 5: Logs & Histórico.
	 */
	private function render_tab_logs( $last_log ) {
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
				<h2 style="margin:0;display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-media-text"></span>
					<?php esc_html_e( 'Log da Última Execução', 'dd-maintenance' ); ?>
				</h2>

				<?php if ( ! empty( $last_log ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dd_maintenance_clear_log">
						<?php wp_nonce_field( 'dd_maintenance_clear_log' ); ?>
						<?php submit_button( __( 'Limpar Log', 'dd-maintenance' ), 'secondary button-small', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $last_log ) && is_array( $last_log ) ) : ?>
				<pre style="background:#1d2327;color:#f0f0f1;padding:16px;border-radius:4px;overflow:auto;max-height:450px;font-family:monospace;font-size:13px;line-height:1.6;"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre>
			<?php else : ?>
				<p style="color:#666;font-style:italic;">
					<?php esc_html_e( 'Nenhum log registrado ainda. Execute uma manutenção para ver o histórico.', 'dd-maintenance' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Aba 5: Restaurar Backup (Restablecer).
	 */
	private function render_tab_restore( $has_password ) {
		$local_backups = DD_Maintenance_Restore::get_local_backups();
		$max_upload    = size_format( wp_max_upload_size() );
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;margin-bottom:24px;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-database-import"></span>
				<?php esc_html_e( 'Restaurar Backup do Site', 'dd-maintenance' ); ?>
			</h2>

			<p>
				<?php esc_html_e( 'Restaure o site completo a partir de um arquivo .zip de backup contendo o banco de dados (database.sql) e os arquivos do site (pasta site/ ou wp-content/).', 'dd-maintenance' ); ?>
			</p>

			<div class="notice notice-warning inline" style="margin-bottom:20px;">
				<p>
					<strong><?php esc_html_e( 'Atenção:', 'dd-maintenance' ); ?></strong>
					<?php esc_html_e( 'A restauração sobrescreverá os arquivos do site e as tabelas existentes no banco de dados com as versões contidas no arquivo de backup. Recomendamos gerar um backup atual antes de restaurar.', 'dd-maintenance' ); ?>
				</p>
			</div>

			<!-- Opção 1: Upload de Arquivo .ZIP -->
			<h3 style="margin-top:24px;"><?php esc_html_e( 'Opção 1: Fazer Upload de Arquivo .ZIP', 'dd-maintenance' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="dd_maintenance_restore_upload">
				<?php wp_nonce_field( 'dd_maintenance_restore_upload' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="backup_zip"><?php esc_html_e( 'Arquivo(s) .zip do Backup', 'dd-maintenance' ); ?></label></th>
						<td>
							<input type="file" id="backup_zip" name="backup_zip[]" multiple accept=".zip" required>
							<p class="description">
								<?php esc_html_e( 'Suporta arquivo único (.zip) ou backups divididos em partes (.part001.zip, .part002.zip...). Para restaurar backups divididos, selecione todas as partes juntas.', 'dd-maintenance' ); ?>
								<br>
								<?php printf( esc_html__( 'Tamanho máximo de upload do servidor: %s por arquivo.', 'dd-maintenance' ), esc_html( $max_upload ) ); ?>
							</p>
						</td>
					</tr>

					<?php if ( $has_password ) : ?>
						<tr>
							<th scope="row"><label for="restore_upload_password"><?php esc_html_e( 'Senha do DD Maintenance', 'dd-maintenance' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="restore_upload_password" name="restore_password" required autocomplete="current-password">
								<p class="description"><?php esc_html_e( 'Digite a senha de proteção configurada.', 'dd-maintenance' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmação', 'dd-maintenance' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="confirm_restore" value="1" required>
								<strong><?php esc_html_e( 'Confirmo que desejo restaurar este backup e substituir os dados atuais.', 'dd-maintenance' ); ?></strong>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Fazer Upload e Restaurar Agora', 'dd-maintenance' ), 'primary' ); ?>
			</form>

			<hr style="margin:30px 0;">

			<!-- Opção 2: Backups Locais Disponíveis -->
			<h3><?php esc_html_e( 'Opção 2: Restaurar a partir de Backup Local', 'dd-maintenance' ); ?></h3>

			<?php if ( empty( $local_backups ) ) : ?>
				<p style="color:#666;font-style:italic;">
					<?php esc_html_e( 'Nenhum arquivo de backup encontrado na pasta local (wp-content/uploads/dd-maintenance/).', 'dd-maintenance' ); ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Selecione um dos backups locais armazenados no servidor para restaurar:', 'dd-maintenance' ); ?></p>

				<table class="widefat striped" style="margin-top:12px;border:1px solid #c3c4c7;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Identificação do Backup', 'dd-maintenance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Data de Criação', 'dd-maintenance' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tamanho Total', 'dd-maintenance' ); ?></th>
							<th scope="col" style="text-align:right;"><?php esc_html_e( 'Ações', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_backups as $backup ) : ?>
							<tr>
								<td style="font-weight:600;font-family:monospace;">
									<?php echo esc_html( $backup['display_name'] ); ?>
								</td>
								<td><?php echo esc_html( $backup['date_formatted'] ); ?></td>
								<td><?php echo esc_html( $backup['size_formatted'] ); ?></td>
								<td style="text-align:right;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza que deseja restaurar este backup? Os arquivos e banco de dados atuais serão substituídos!', 'dd-maintenance' ) ); ?>');">
										<input type="hidden" name="action" value="dd_maintenance_restore_local">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_restore_local' ); ?>
										<?php if ( $has_password ) : ?>
											<input type="password" name="restore_password" placeholder="<?php esc_attr_e( 'Senha', 'dd-maintenance' ); ?>" style="width:110px;height:30px;font-size:12px;" required autocomplete="current-password">
										<?php endif; ?>
										<button type="submit" class="button button-primary button-small">
											<span class="dashicons dashicons-backup" style="vertical-align:middle;font-size:15px;width:15px;height:15px;"></span>
											<?php esc_html_e( 'Restaurar', 'dd-maintenance' ); ?>
										</button>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza que deseja excluir este arquivo de backup local?', 'dd-maintenance' ) ); ?>');">
										<input type="hidden" name="action" value="dd_maintenance_delete_backup">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_delete_backup' ); ?>
										<button type="submit" class="button button-link-delete button-small" style="color:#b32d2e;text-decoration:none;">
											<?php esc_html_e( 'Excluir', 'dd-maintenance' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
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

		$restore = new DD_Maintenance_Restore();
		$result  = $restore->restore_from_upload( $_FILES['backup_zip'] );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result['log'] ) ) {
				set_transient( 'dd_maintenance_last_log', $result['log'], DAY_IN_SECONDS );
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

		$restore = new DD_Maintenance_Restore();
		$result  = $restore->restore_from_local_file( $filename );

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro na restauração: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
		} else {
			if ( ! empty( $result['log'] ) ) {
				set_transient( 'dd_maintenance_last_log', $result['log'], DAY_IN_SECONDS );
			}
			DD_Maintenance::instance()->set_notice( __( 'Backup local restaurado com sucesso!', 'dd-maintenance' ), 'success' );
		}

		wp_safe_redirect( $this->page_url( 'restore' ) );
		exit;
	}

	/**
	 * Handler: Exclusão de arquivo de backup local.
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

		if ( $deleted ) {
			DD_Maintenance::instance()->set_notice( __( 'Arquivo(s) de backup excluído(s) com sucesso.', 'dd-maintenance' ), 'success' );
		} else {
			DD_Maintenance::instance()->set_notice( __( 'Arquivo não encontrado.', 'dd-maintenance' ), 'error' );
		}
		wp_safe_redirect( $this->page_url( 'restore' ) );
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
		check_admin_referer( 'dd_maintenance_save_settings' );

		$settings = wp_parse_args(
			get_option( 'dd_maintenance_settings', array() ),
			get_option( 'backuper_settings', array() )
		);

		$do_detect = ! empty( $_POST['dd_maint_detect_region'] );

		if ( isset( $_POST['s3_access_key'] ) ) {
			$settings['s3_access_key'] = sanitize_text_field( wp_unslash( $_POST['s3_access_key'] ) );
		}
		if ( isset( $_POST['s3_secret_key'] ) ) {
			$settings['s3_secret_key'] = sanitize_text_field( wp_unslash( $_POST['s3_secret_key'] ) );
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

		if ( isset( $_POST['retention_local'] ) ) {
			$settings['retention_local'] = max( 0, (int) $_POST['retention_local'] );
		}

		update_option( 'dd_maintenance_settings', $settings );
		update_option( 'backuper_settings', $settings );

		$redirect_tab = isset( $_POST['active_tab'] ) ? sanitize_key( wp_unslash( $_POST['active_tab'] ) ) : 's3';
		if ( $do_detect ) {
			$s3 = new DD_Maintenance_S3();

			if ( empty( $s3->get_bucket() ) ) {
				DD_Maintenance::instance()->set_notice( __( 'Informe o nome do bucket antes de detectar a região.', 'dd-maintenance' ), 'error' );
			} else {
				$detected = $s3->detect_region();

				if ( is_wp_error( $detected ) ) {
					DD_Maintenance::instance()->set_notice( $detected->get_error_message(), 'error' );
				} else {
					$settings['s3_region'] = $detected;
					update_option( 'dd_maintenance_settings', $settings );
					update_option( 'backuper_settings', $settings );
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

		$backup = new DD_Maintenance_Backup();
		$result = $backup->run();

		if ( is_wp_error( $result ) ) {
			DD_Maintenance::instance()->set_notice( __( 'Erro no backup: ', 'dd-maintenance' ) . $result->get_error_message(), 'error' );
			wp_safe_redirect( $this->page_url( 'general' ) );
			exit;
		}

		$s3 = new DD_Maintenance_S3();

		if ( ! $s3->is_configured() ) {
			DD_Maintenance::instance()->set_notice( __( 'Configure as credenciais do DigitalOcean Spaces antes de executar o envio.', 'dd-maintenance' ), 'error' );
			wp_safe_redirect( $this->page_url( 's3' ) );
			exit;
		}

		$site_slug   = sanitize_title( get_bloginfo( 'name' ) );
		$site_slug   = $site_slug ? $site_slug : 'site';
		$folder      = $site_slug . '/' . current_time( 'Y-m-d' );
		$parts       = isset( $result['parts'] ) ? $result['parts'] : array( array( 'file' => $result['file'], 'name' => $result['name'], 'size' => $result['size'], 'part' => 1 ) );
		$total_parts = count( $parts );
		$total_size  = isset( $result['total_size'] ) ? $result['total_size'] : $result['size'];

		$upload_error = false;
		foreach ( $parts as $idx => $part ) {
			$key    = $folder . '/' . $part['name'];
			$upload = $s3->put_object( $key, $part['file'] );

			if ( is_wp_error( $upload ) ) {
				DD_Maintenance::instance()->set_notice(
					sprintf(
						/* translators: 1: Índice da parte, 2: Total de partes, 3: Mensagem de erro */
						__( 'Erro no envio da parte %1$d/%2$d ao S3: %3$s', 'dd-maintenance' ),
						$idx + 1,
						$total_parts,
						$upload->get_error_message()
					),
					'error'
				);
				$upload_error = true;
				break;
			}
		}

		if ( ! $upload_error ) {
			DD_Maintenance::instance()->set_notice(
				sprintf(
					/* translators: 1: Quantidade de partes, 2: Tamanho formatado, 3: Pasta no S3, 4: Nome do bucket */
					__( 'Backup gerado em %1$d parte(s) de até 25MB (Total: %2$s) e enviado com sucesso para "%3$s" no bucket "%4$s".', 'dd-maintenance' ),
					$total_parts,
					size_format( $total_size ),
					$folder,
					$s3->get_bucket()
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

		delete_transient( 'dd_maintenance_last_log' );
		delete_transient( 'backuper_last_log' );

		DD_Maintenance::instance()->set_notice( __( 'Log limpo com sucesso.', 'dd-maintenance' ), 'info' );

		wp_safe_redirect( $this->page_url( 'logs' ) );
		exit;
	}

	/**
	 * Handler AJAX: executa etapas de manutenção com progresso em tempo real.
	 */
	public function ajax_handle_action() {
		check_ajax_referer( 'dd_maint_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'dd-maintenance' ) ) );
		}

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		switch ( $step ) {
			case 'backup':
				$backup = new DD_Maintenance_Backup();
				$result = $backup->run();

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}

				$parts       = isset( $result['parts'] ) ? $result['parts'] : array( array( 'file' => $result['file'], 'name' => $result['name'], 'size' => $result['size'], 'part' => 1 ) );
				$total_parts = count( $parts );
				$total_size  = isset( $result['total_size'] ) ? $result['total_size'] : $result['size'];

				$site_slug = sanitize_title( get_bloginfo( 'name' ) );
				$site_slug = $site_slug ? $site_slug : 'site';
				$folder    = $site_slug . '/' . current_time( 'Y-m-d' );

				wp_send_json_success(
					array(
						'log'        => sprintf( __( '[OK] Backup criado: %1$d parte(s) de até 25MB (Total: %2$s)', 'dd-maintenance' ), $total_parts, size_format( $total_size ) ),
						'parts_data' => $parts,
						'folder'     => $folder,
					)
				);
				break;

			case 's3':
				$s3 = new DD_Maintenance_S3();
				if ( ! $s3->is_configured() ) {
					wp_send_json_error( array( 'message' => __( 'S3 / Spaces não configurado.', 'dd-maintenance' ) ) );
				}

				$folder     = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'site/' . current_time( 'Y-m-d' );
				$parts_json = isset( $_POST['parts_data'] ) ? wp_unslash( $_POST['parts_data'] ) : '';
				$parts      = json_decode( $parts_json, true );

				if ( empty( $parts ) || ! is_array( $parts ) ) {
					wp_send_json_error( array( 'message' => __( 'Dados de partes do backup ausentes.', 'dd-maintenance' ) ) );
				}

				$logs        = array();
				$total_parts = count( $parts );

				foreach ( $parts as $idx => $part ) {
					$key    = $folder . '/' . sanitize_file_name( $part['name'] );
					$upload = $s3->put_object( $key, $part['file'] );

					if ( is_wp_error( $upload ) ) {
						wp_send_json_error(
							array(
								'message' => sprintf( __( 'Erro no envio da parte %1$d/%2$d: %3$s', 'dd-maintenance' ), $idx + 1, $total_parts, $upload->get_error_message() ),
							)
						);
					}

					$logs[] = sprintf( __( '[OK] Parte %1$d/%2$d enviada para S3: %3$s (%4$s)', 'dd-maintenance' ), $idx + 1, $total_parts, $part['name'], size_format( $part['size'] ) );
				}

				wp_send_json_success(
					array(
						'log' => implode( "\n", $logs ),
					)
				);
				break;

			case 'retention':
				$purged = DD_Maintenance::instance()->apply_retention_policy();
				$log    = ! empty( $purged )
					? sprintf( __( '[OK] Retenção: %d backup(s) antigo(s) removido(s).', 'dd-maintenance' ), count( $purged ) )
					: __( '[OK] Retenção verificada (nenhum backup antigo para expurgar).', 'dd-maintenance' );

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
		check_ajax_referer( 'dd_maint_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissão.', 'dd-maintenance' ) ) );
		}

		if ( DD_Maintenance_Config::has_password() ) {
			$password = isset( $_POST['restore_password'] ) ? trim( (string) wp_unslash( $_POST['restore_password'] ) ) : '';
			if ( ! DD_Maintenance_Config::verify_password( $password ) ) {
				wp_send_json_error( array( 'message' => __( 'Senha de confirmação incorreta.', 'dd-maintenance' ) ) );
			}
		}

		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$restore = new DD_Maintenance_Restore();

		if ( 'upload' === $mode ) {
			if ( empty( $_FILES['backup_zip'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhum arquivo enviado.', 'dd-maintenance' ) ) );
			}

			$result = $restore->restore_from_upload( $_FILES['backup_zip'] );
		} elseif ( 'local' === $mode ) {
			$filename = isset( $_POST['backup_filename'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_filename'] ) ) : '';
			if ( empty( $filename ) ) {
				wp_send_json_error( array( 'message' => __( 'Nome de backup local inválido.', 'dd-maintenance' ) ) );
			}

			$result = $restore->restore_from_local_file( $filename );
		} else {
			wp_send_json_error( array( 'message' => __( 'Modo de restauração inválido.', 'dd-maintenance' ) ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$log_str = ! empty( $result['log'] ) ? implode( "\n", $result['log'] ) : __( '[OK] Restauração concluída com sucesso.', 'dd-maintenance' );
		wp_send_json_success( array( 'log' => $log_str ) );
	}
}
