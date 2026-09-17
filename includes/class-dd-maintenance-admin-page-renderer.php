<?php
/**
 * Renderização isolada da página administrativa.
 *
 * @package DD_Maintenance
 */

defined( 'ABSPATH' ) || exit;

class DD_Maintenance_Admin_Page_Renderer {

	/**
	 * Retorna a URL base da página administrativa.
	 *
	 * @param string $tab Aba opcional.
	 * @return string
	 */
	public function page_url( $tab = '' ): string {
		$url = admin_url( 'admin.php?page=dd-maintenance' );
		return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * Retorna a URL segura de download de backup.
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
	 * Aba 1: Visão Geral & Ações Rápidas.
	 */
	public function render_tab_general( $s3_configured, $s3, $config_status, $settings, $last_log, array $local_backups = array(), $next_cron = 0 ) {
		$file_mods    = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		$file_edit    = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_EDIT' );
		$backup_count = count( $local_backups );
		$total_bytes  = 0;
		foreach ( $local_backups as $b ) {
			$total_bytes += $b['size'];
		}
		?>
		<div class="dd-maint-style-display-grid-grid-template-columns-repeat-auto-f-e40afb">
			<!-- Card 1: Status do S3 -->
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-213afc">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-cloud dd-maint-style-color-2271b1-5dadfa"></span>
					<?php esc_html_e( 'Armazenamento S3 (Spaces)', 'dd-maintenance' ); ?>
				</h3>
				<?php if ( $s3_configured ) : ?>
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-yes-alt dd-maint-style-color-46b450-5c8216"></span> <strong><?php esc_html_e( 'Configurado e Pronto', 'dd-maintenance' ); ?></strong></p>
					<p class="dd-maint-style-margin-bottom-0-76084e"><code><?php echo esc_html( $s3->get_bucket() ); ?></code> (<?php echo esc_html( $s3->get_region() ); ?>)</p>
				<?php else : ?>
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-warning dd-maint-style-color-dba617-a100d6"></span> <strong><?php esc_html_e( 'Não configurado', 'dd-maintenance' ); ?></strong></p>
					<p><a href="<?php echo esc_url( $this->page_url( 's3' ) ); ?>" class="button button-small"><?php esc_html_e( 'Configurar credenciais', 'dd-maintenance' ); ?></a></p>
				<?php endif; ?>
			</div>

			<!-- Card 2: Status do wp-config.php -->
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-213afc">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-admin-settings dd-maint-style-color-2271b1-5dadfa"></span>
					<?php esc_html_e( 'Travas wp-config.php', 'dd-maintenance' ); ?>
				</h3>
				<p class="dd-maint-style-margin-4px-0-60946a">
					<strong>DISALLOW_FILE_MODS:</strong>
					<?php if ( true === $file_mods ) : ?>
						<span class="dd-maint-style-color-d63638-font-weight-600-5671c2"><?php esc_html_e( 'Bloqueado (true)', 'dd-maintenance' ); ?></span>
					<?php else : ?>
						<span class="dd-maint-style-color-46b450-font-weight-600-e9a241"><?php esc_html_e( 'Liberado (false)', 'dd-maintenance' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="dd-maint-style-margin-4px-0-60946a">
					<strong>DISALLOW_FILE_EDIT:</strong>
					<?php if ( true === $file_edit ) : ?>
						<span class="dd-maint-style-color-d63638-font-weight-600-5671c2"><?php esc_html_e( 'Bloqueado (true)', 'dd-maintenance' ); ?></span>
					<?php else : ?>
						<span class="dd-maint-style-color-46b450-font-weight-600-e9a241"><?php esc_html_e( 'Liberado (false)', 'dd-maintenance' ); ?></span>
					<?php endif; ?>
				</p>
				<p class="dd-maint-style-margin-top-8px-margin-bottom-0-f27a7b">
					<a href="<?php echo esc_url( $this->page_url( 'config' ) ); ?>" class="button button-small"><?php esc_html_e( 'Gerenciar com senha', 'dd-maintenance' ); ?></a>
				</p>
			</div>

			<!-- Card 3: Status da Automação & Retenção -->
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-213afc">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-backup dd-maint-style-color-2271b1-5dadfa"></span>
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
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-yes-alt dd-maint-style-color-46b450-5c8216"></span> <strong><?php echo esc_html( $freq_label ); ?></strong></p>
					<?php if ( $next_cron ) : ?>
						<p class="dd-maint-style-margin-4px-0-color-666-font-size-12px-015270"><?php printf( esc_html__( 'Próxima: %s', 'dd-maintenance' ), esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'd/m/Y H:i:s' ) ) ); ?></p>
					<?php endif; ?>
					<p class="dd-maint-style-margin-4px-0-color-666-font-size-12px-015270"><?php printf( esc_html__( 'Retenção: %s', 'dd-maintenance' ), $retention > 0 ? sprintf( esc_html__( 'últimos %s backups', 'dd-maintenance' ), esc_html( (string) $retention ) ) : esc_html__( 'Ilimitada', 'dd-maintenance' ) ); ?></p>
				<?php else : ?>
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-marker dd-maint-style-color-666-6a8c41"></span> <strong><?php esc_html_e( 'Desativada', 'dd-maintenance' ); ?></strong></p>
					<p><a href="<?php echo esc_url( $this->page_url( 'cron' ) ); ?>" class="button button-small"><?php esc_html_e( 'Configurar agendamento', 'dd-maintenance' ); ?></a></p>
				<?php endif; ?>
			</div>

			<!-- Card 4: Backups Locais no Servidor -->
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-213afc">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-database-import dd-maint-style-color-2271b1-5dadfa"></span>
					<?php esc_html_e( 'Backups Locais Salvos', 'dd-maintenance' ); ?>
				</h3>
				<?php if ( $backup_count > 0 ) : ?>
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-yes-alt dd-maint-style-color-46b450-5c8216"></span> <strong><?php printf( esc_html__( '%s pacote(s) disponível(is)', 'dd-maintenance' ), esc_html( (string) $backup_count ) ); ?></strong></p>
					<p class="dd-maint-style-margin-4px-0-color-666-font-size-12px-015270"><?php printf( esc_html__( 'Tamanho em disco: %s', 'dd-maintenance' ), esc_html( size_format( $total_bytes ) ) ); ?></p>
					<p class="dd-maint-style-margin-top-8px-margin-bottom-0-f27a7b">
						<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small button-primary">
							<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
							<?php esc_html_e( 'Baixar Backups', 'dd-maintenance' ); ?> &rarr;
						</a>
					</p>
				<?php else : ?>
					<p class="dd-maint-style-display-flex-align-items-center-gap-6px-ac86ac"><span class="dashicons dashicons-marker dd-maint-style-color-666-6a8c41"></span> <strong><?php esc_html_e( 'Nenhum backup local', 'dd-maintenance' ); ?></strong></p>
					<p class="dd-maint-style-margin-top-8px-margin-bottom-0-f27a7b"><a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small"><?php esc_html_e( 'Ver pasta de backups', 'dd-maintenance' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( true === $file_mods ) : ?>
			<div class="notice notice-warning inline dd-maint-style-margin-bottom-20px-7dde5e">
				<p>
					<strong><?php esc_html_e( 'Atenção:', 'dd-maintenance' ); ?></strong>
					<?php esc_html_e( 'A constante DISALLOW_FILE_MODS está ativa no seu wp-config.php. Atualizações de plugins e do core do WordPress podem ser bloqueadas pelo WordPress até que ela seja liberada.', 'dd-maintenance' ); ?>
					<a href="<?php echo esc_url( $this->page_url( 'config' ) ); ?>"><?php esc_html_e( 'Liberar temporariamente no Gerenciador', 'dd-maintenance' ); ?> &rarr;</a>
				</p>
			</div>
		<?php endif; ?>

		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-7486dd">
			<h2 class="dd-maint-style-margin-top-0-291b7b"><?php esc_html_e( 'Ações Manuais de Manutenção', 'dd-maintenance' ); ?></h2>
			<p><?php esc_html_e( 'Execute o ciclo completo ou dispare cada etapa de manutenção individualmente:', 'dd-maintenance' ); ?></p>

			<div class="dd-maintenance-actions">
				<!-- Botão 1: Executar Tudo -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_run_full">
					<?php wp_nonce_field( 'dd_maintenance_run_full' ); ?>
					<button type="submit" class="button button-primary" data-dd-action="run_full">
						<span class="dashicons dashicons-update"></span>
						<span class="btn-text"><?php esc_html_e( 'Executar Tudo (Backup → S3 → Plugins → Core)', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 2: Backup e Envio -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_run_backup">
					<?php wp_nonce_field( 'dd_maintenance_run_backup' ); ?>
					<button type="submit" class="button button-secondary" data-dd-action="run_backup">
						<span class="dashicons dashicons-cloud-upload"></span>
						<span class="btn-text"><?php esc_html_e( 'Backup e Envio ao S3', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 3: Atualizar Plugins -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_update_plugins">
					<?php wp_nonce_field( 'dd_maintenance_update_plugins' ); ?>
					<button type="submit" class="button button-secondary" data-dd-action="update_plugins">
						<span class="dashicons dashicons-admin-plugins"></span>
						<span class="btn-text"><?php esc_html_e( 'Atualizar Plugins', 'dd-maintenance' ); ?></span>
					</button>
				</form>

				<!-- Botão 4: Atualizar Core -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dd_maintenance_update_core">
					<?php wp_nonce_field( 'dd_maintenance_update_core' ); ?>
					<button type="submit" class="button button-secondary" data-dd-action="update_core">
						<span class="dashicons dashicons-wordpress"></span>
						<span class="btn-text"><?php esc_html_e( 'Atualizar Core WordPress', 'dd-maintenance' ); ?></span>
					</button>
				</form>
			</div>
		</div>

		<?php if ( ! empty( $local_backups ) ) : ?>
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-7486dd">
				<div class="dd-maint-style-display-flex-justify-content-space-between-align-801166">
					<h2 class="dd-maint-style-margin-0-display-flex-align-items-center-gap-8px-b271d5">
						<span class="dashicons dashicons-download dd-maint-style-color-2271b1-5dadfa"></span>
						<?php esc_html_e( 'Últimos Backups Locais (Downloads Rápidos)', 'dd-maintenance' ); ?>
					</h2>
					<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Ver todos os backups locais', 'dd-maintenance' ); ?> &rarr;
					</a>
				</div>
				<p class="dd-maint-style-margin-top-0-color-50575e-5e3ae0">
					<?php esc_html_e( 'Baixe os arquivos de backup gerados no servidor diretamente para seu computador:', 'dd-maintenance' ); ?>
				</p>

				<table class="widefat striped dd-maint-style-border-1px-solid-c3c4c7-791c44">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Backup & Volumes', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-width-140px-7a1ab9"><?php esc_html_e( 'Data', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-width-110px-80ffd2"><?php esc_html_e( 'Tamanho', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-min-width-210px-860edd"><?php esc_html_e( 'Download Imediato', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$recent_backups = array_slice( $local_backups, 0, 3 );
						foreach ( $recent_backups as $b ) :
						?>
							<tr>
								<td>
									<strong class="dd-maint-style-font-family-monospace-font-size-13px-179b58"><?php echo esc_html( $b['identifier'] ); ?></strong>
									<div class="dd-maint-style-margin-top-2px-b08169">
										<?php if ( ! empty( $b['is_multipart'] ) ) : ?>
											<span class="dd-maint-part-badge"><?php printf( esc_html__( '%s volumes', 'dd-maintenance' ), esc_html( (string) (int) $b['total_parts'] ) ); ?></span>
										<?php elseif ( ! empty( $b['parts'] ) ) : ?>
											<span class="dd-maint-part-badge"><?php esc_html_e( 'Volume Único (.zip)', 'dd-maintenance' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $b['has_sql'] ) ) : ?>
											<span class="dd-maint-sql-badge"><?php esc_html_e( 'Dump SQL (.sql)', 'dd-maintenance' ); ?></span>
										<?php endif; ?>
									</div>
								</td>
								<td class="dd-maint-style-font-size-12-5px-color-50575e-751583"><?php echo esc_html( $b['date_formatted'] ); ?></td>
								<td class="dd-maint-style-font-weight-600-font-size-12-5px-4f0b20"><?php echo esc_html( $b['size_formatted'] ); ?></td>
								<td>
									<div class="dd-maint-style-display-flex-flex-wrap-wrap-gap-4px-align-items--6583bc">
										<?php if ( ! empty( $b['is_multipart'] ) && count( $b['parts'] ) > 1 ) : ?>
											<button type="button" class="button button-primary button-small dd-maint-download-all-trigger" data-dd-download-parts="<?php echo esc_attr( wp_json_encode( wp_list_pluck( $b['parts'], 'filename' ) ) ); ?>">
												<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
												<?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?>
											</button>
											<?php foreach ( $b['parts'] as $p ) : ?>
												<a href="<?php echo esc_url( self::get_download_url( $p['filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $p['filename'] ); ?>" title="<?php echo esc_attr( $p['filename'] ); ?>">
													<?php printf( esc_html__( 'P%s (%s)', 'dd-maintenance' ), esc_html( (string) (int) $p['part'] ), esc_html( $p['size_formatted'] ) ); ?>
												</a>
											<?php endforeach; ?>
										<?php elseif ( ! empty( $b['parts'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $b['parts'][0]['filename'] ) ); ?>" class="button button-primary button-small" download="<?php echo esc_attr( $b['parts'][0]['filename'] ); ?>">
												<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
												<?php esc_html_e( 'Baixar Backup (.zip)', 'dd-maintenance' ); ?>
											</a>
										<?php endif; ?>

										<?php if ( ! empty( $b['has_sql'] ) && ! empty( $b['sql_filename'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $b['sql_filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $b['sql_filename'] ); ?>" title="<?php esc_attr_e( 'Baixar dump SQL do banco de dados', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-database dd-maint-style-font-size-12px-vertical-align-middle-f45765"></span>
												<?php esc_html_e( 'SQL', 'dd-maintenance' ); ?>
											</a>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_log ) && is_array( $last_log ) ) : ?>
			<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-368b1d">
				<div class="dd-maint-style-display-flex-justify-content-space-between-align-812972">
					<h2 class="dd-maint-style-margin-top-0-291b7b"><?php esc_html_e( 'Última Execução', 'dd-maintenance' ); ?></h2>
					<a href="<?php echo esc_url( $this->page_url( 'logs' ) ); ?>" class="button button-small"><?php esc_html_e( 'Ver logs completos', 'dd-maintenance' ); ?></a>
				</div>
				<pre class="dd-maint-style-background-f6f7f7-padding-12px-border-1px-solid--be1e89"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Aba 2: Gerenciador de wp-config.php e Senhas.
	 */
	public function render_tab_config( $status, $has_password ) {
		?>
		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-5658c0">
			<h2 class="dd-maint-style-margin-top-0-display-flex-align-items-center-gap-8981e5">
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

			<table class="widefat striped dd-maint-style-margin-20px-0-border-1px-solid-c3c4c7-1db2a8">
				<tbody>
					<tr>
						<th scope="row" class="dd-maint-style-width-240px-font-weight-600-a08b79"><?php esc_html_e( 'Arquivo detectado', 'dd-maintenance' ); ?></th>
						<td><code><?php echo esc_html( DD_Maintenance_Config::format_status_path( $status ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row" class="dd-maint-style-font-weight-600-0b87e9"><code>DISALLOW_FILE_MODS</code></th>
						<td>
							<?php
							$mods_val = DD_Maintenance_Config::get_status_value( $status, 'DISALLOW_FILE_MODS' );
							if ( true === $mods_val ) {
								echo '<span class="dd-maint-style-color-d63638-font-weight-bold-68da2c">' . esc_html__( 'true - BLOQUEADO (updates e arquivos travados)', 'dd-maintenance' ) . '</span>';
							} elseif ( false === $mods_val ) {
								echo '<span class="dd-maint-style-color-46b450-font-weight-bold-f5419f">' . esc_html__( 'false - LIBERADO (updates permitidos)', 'dd-maintenance' ) . '</span>';
							} else {
								echo '<span class="dd-maint-style-color-666-6a8c41">' . esc_html__( 'Não definido (padrão: liberado)', 'dd-maintenance' ) . '</span>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row" class="dd-maint-style-font-weight-600-0b87e9"><code>DISALLOW_FILE_EDIT</code></th>
						<td>
							<?php
							$edit_val = DD_Maintenance_Config::get_status_value( $status, 'DISALLOW_FILE_EDIT' );
							if ( true === $edit_val ) {
								echo '<span class="dd-maint-style-color-d63638-font-weight-bold-68da2c">' . esc_html__( 'true - BLOQUEADO (editor desativado)', 'dd-maintenance' ) . '</span>';
							} elseif ( false === $edit_val ) {
								echo '<span class="dd-maint-style-color-46b450-font-weight-bold-f5419f">' . esc_html__( 'false - LIBERADO (editor permitido)', 'dd-maintenance' ) . '</span>';
							} else {
								echo '<span class="dd-maint-style-color-666-6a8c41">' . esc_html__( 'Não definido (padrão: liberado)', 'dd-maintenance' ) . '</span>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! $has_password ) : ?>
				<div class="notice notice-info inline dd-maint-style-margin-bottom-20px-7dde5e">
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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dd-maint-style-margin-bottom-30px-5370cb">
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
								<select id="dd_maint_file_mods" name="dd_maint_file_mods" class="dd-maint-style-max-width-100-0697ec">
									<option value="true" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_MODS' ), true ); ?>><?php esc_html_e( 'true - BLOQUEAR updates, instalações e alterações de arquivos', 'dd-maintenance' ); ?></option>
									<option value="false" <?php selected( DD_Maintenance_Config::get_select_value( $status, 'DISALLOW_FILE_MODS' ), false ); ?>><?php esc_html_e( 'false - PERMITIR updates, instalações e alterações de arquivos', 'dd-maintenance' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dd_maint_file_edit"><code>DISALLOW_FILE_EDIT</code></label></th>
							<td>
								<select id="dd_maint_file_edit" name="dd_maint_file_edit" class="dd-maint-style-max-width-100-0697ec">
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
	public function render_tab_s3( $settings, $s3_configured, $s3, $split_size_mb = 0, $remote_backups = array() ) {
		$has_s3_error = is_wp_error( $remote_backups );
		?>
		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-1cf229">
			<h2 class="dd-maint-style-margin-top-0-display-flex-align-items-center-gap-8981e5">
				<span class="dashicons dashicons-cloud-upload"></span>
				<?php esc_html_e( 'Configurações do DigitalOcean Spaces (S3)', 'dd-maintenance' ); ?>
			</h2>

			<?php if ( ! $s3_configured ) : ?>
				<div class="notice notice-warning inline dd-maint-style-margin-bottom-16px-79a1c5">
					<p><strong><?php esc_html_e( 'S3 não configurado:', 'dd-maintenance' ); ?></strong> <?php esc_html_e( 'Informe as credenciais abaixo para que os backups possam ser enviados para a nuvem com segurança.', 'dd-maintenance' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-success inline dd-maint-style-margin-bottom-16px-79a1c5">
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
					<tr>
						<th scope="row"><label for="split_size_mb"><?php esc_html_e( 'Divisão de Volumes (Tamanho por Parte)', 'dd-maintenance' ); ?></label></th>
						<td>
							<?php $curr_split = (int) $split_size_mb; ?>
							<select id="split_size_mb" name="split_size_mb">
								<option value="25" <?php selected( $curr_split, 25 ); ?>><?php esc_html_e( '25 MB (Ultra leve / servidores restritivos)', 'dd-maintenance' ); ?></option>
								<option value="50" <?php selected( $curr_split, 50 ); ?>><?php esc_html_e( '50 MB', 'dd-maintenance' ); ?></option>
								<option value="100" <?php selected( $curr_split, 100 ); ?>><?php esc_html_e( '100 MB (Ideal para Cloudflare Free)', 'dd-maintenance' ); ?></option>
								<option value="200" <?php selected( $curr_split, 200 ); ?>><?php esc_html_e( '200 MB (Recomendado - Rápido)', 'dd-maintenance' ); ?></option>
								<option value="500" <?php selected( $curr_split, 500 ); ?>><?php esc_html_e( '500 MB (Arquivos grandes)', 'dd-maintenance' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Tamanhos maiores (200MB a 400MB) reduzem drasticamente o tempo total gerando menos arquivos.', 'dd-maintenance' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Salvar Configurações de Backup & S3', 'dd-maintenance' ), 'primary' ); ?>
			</form>

			<?php if ( $s3_configured ) : ?>
				<hr class="dd-maint-style-margin-24px-0-95e61b">

					<a href="<?php echo esc_url( $this->page_url( 's3' ) ); ?>" class="button button-small">
						<span class="dashicons dashicons-update dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
						<?php esc_html_e( 'Atualizar Lista do S3', 'dd-maintenance' ); ?>
					</a>
				</div>
				<p class="description dd-maint-style-margin-top-0-291b7b">
					<?php printf( esc_html__( 'Backups agrupados no bucket "%1$s" (região: %2$s):', 'dd-maintenance' ), esc_html( $s3->get_bucket() ), esc_html( $s3->get_region() ) ); ?>
				</p>

				<?php if ( $has_s3_error ) : ?>
					<div class="notice notice-warning inline dd-maint-style-margin-12px-0-41db5c">
						<p><?php printf( esc_html__( 'Não foi possível listar objetos do S3: %s', 'dd-maintenance' ), esc_html( $remote_backups->get_error_message() ) ); ?></p>
					</div>
				<?php elseif ( empty( $remote_backups ) ) : ?>
					<p class="dd-maint-style-color-666-font-style-italic-5f541e">
						<?php esc_html_e( 'Nenhum backup (.zip/.sql) encontrado no bucket S3 / Spaces.', 'dd-maintenance' ); ?>
					</p>
				<?php else : ?>
					<table class="widefat striped dd-maint-style-margin-top-10px-border-1px-solid-c3c4c7-9450dc">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Backup / Volumes', 'dd-maintenance' ); ?></th>
								<th scope="col" class="dd-maint-style-width-120px-9fe45c"><?php esc_html_e( 'Tamanho Total', 'dd-maintenance' ); ?></th>
								<th scope="col" class="dd-maint-style-width-180px-34f7b3"><?php esc_html_e( 'Data no S3 (GMT)', 'dd-maintenance' ); ?></th>
								<th scope="col" class="dd-maint-style-text-align-right-width-150px-9d3ff8"><?php esc_html_e( 'Ação', 'dd-maintenance' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $remote_backups as $backup ) : ?>
								<tr>
									<td>
										<strong class="dd-maint-style-font-family-monospace-font-size-12px-5dc977"><?php echo esc_html( $backup['display_name'] ); ?></strong>
										<?php if ( ! empty( $backup['folder'] ) ) : ?>
											<div><code class="dd-maint-style-font-size-11px-e48b05"><?php echo esc_html( $backup['folder'] ); ?>/</code></div>
										<?php endif; ?>
										<div class="dd-maint-style-display-flex-flex-wrap-wrap-gap-4px-align-items--1bd370">
											<span class="dd-maint-style-display-inline-block-padding-2px-6px-background--c3c091">
												<?php printf( esc_html__( '%d volume(s)', 'dd-maintenance' ), (int) $backup['total_parts'] ); ?>
											</span>
											<?php if ( ! empty( $backup['has_sql'] ) ) : ?>
												<span class="dd-maint-style-display-inline-block-padding-2px-6px-background--092f8d">
													<?php esc_html_e( 'Dump SQL', 'dd-maintenance' ); ?>
												</span>
											<?php endif; ?>
										</div>
										<?php if ( ! empty( $backup['parts'] ) || ! empty( $backup['has_sql'] ) ) : ?>
											<details class="dd-maint-style-margin-top-6px-font-size-11px-color-50575e-f4d163">
												<summary class="dd-maint-style-cursor-pointer-color-2271b1-159ccf"><?php esc_html_e( 'Ver arquivos deste backup', 'dd-maintenance' ); ?></summary>
												<ul class="dd-maint-style-margin-5px-0-0-16px-ae6033">
													<?php foreach ( $backup['parts'] as $part ) : ?>
														<li class="dd-maint-style-margin-2px-0-9d1682">
															<code><?php echo esc_html( $part['key'] ); ?></code>
															(<?php echo esc_html( $part['size_formatted'] ); ?>)
														</li>
													<?php endforeach; ?>
													<?php if ( ! empty( $backup['has_sql'] ) ) : ?>
														<li class="dd-maint-style-margin-2px-0-9d1682">
															<code><?php echo esc_html( $backup['sql_key'] ); ?></code>
															(<?php echo esc_html( $backup['sql_size_formatted'] ); ?>)
														</li>
													<?php endif; ?>
												</ul>
											</details>
										<?php endif; ?>
									</td>
									<td class="dd-maint-style-font-size-12px-font-weight-600-050002">
										<?php echo esc_html( $backup['size_formatted'] ); ?>
									</td>
									<td class="dd-maint-style-font-size-12px-color-50575e-223965">
										<?php echo esc_html( $backup['last_modified'] ); ?>
									</td>
									<td class="dd-maint-style-text-align-right-a527ba">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dd-confirm="<?php echo esc_attr( sprintf( __( 'Tem certeza que deseja excluir todos os arquivos do backup "%s" do S3 / Spaces?', 'dd-maintenance' ), $backup['identifier'] ) ); ?>" class="dd-maint-style-margin-0-1da9fa">
											<input type="hidden" name="action" value="dd_maintenance_delete_s3_backup">
											<input type="hidden" name="backup_identifier" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
											<input type="hidden" name="redirect_tab" value="s3">
											<?php wp_nonce_field( 'dd_maintenance_delete_s3_backup' ); ?>
											<button type="submit" class="button button-link-delete button-small dd-maint-style-color-b32d2e-text-decoration-none-990de9">
												<span class="dashicons dashicons-trash dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
												<?php esc_html_e( 'Excluir backup', 'dd-maintenance' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Aba 4: Agendamento & Automação (WP-Cron).
	 */
	public function render_tab_cron( $settings, $next_cron = 0, $chunk_size_mb = 0 ) {
		$current_freq      = isset( $settings['schedule_frequency'] ) ? $settings['schedule_frequency'] : 'daily';
		$current_time_val  = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '03:00';
		$current_retention = isset( $settings['retention_local'] ) ? (int) $settings['retention_local'] : 5;
		?>
		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-1cf229">
			<h2 class="dd-maint-style-margin-top-0-display-flex-align-items-center-gap-8981e5">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Agendamento Automático & Políticas de Retenção (WP-Cron)', 'dd-maintenance' ); ?>
			</h2>

			<p>
				<?php printf( esc_html__( 'Configure a rotina automática para executar periodicamente o fluxo completo de manutenção (backup completo com volumes de até %s MB, envio ao S3/Spaces, limpeza de retenção e atualizações de plugins e core).', 'dd-maintenance' ), esc_html( (string) $chunk_size_mb ) ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dd_maintenance_save_settings">
				<input type="hidden" name="active_tab" value="cron">
				<input type="hidden" name="s3_access_key" value="<?php echo esc_attr( $settings['s3_access_key'] ); ?>">
				<?php // O segredo nunca é transportado por formulário de cron. ?>
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
								<p class="dd-maint-style-margin-top-0-291b7b">
									<span class="dashicons dashicons-yes-alt dd-maint-style-color-46b450-vertical-align-middle-bd2f9d"></span>
									<strong class="dd-maint-style-color-46b450-5c8216"><?php esc_html_e( 'Agendamento Ativo', 'dd-maintenance' ); ?></strong>
								</p>
								<p>
									<strong><?php esc_html_e( 'Próxima Execução Prevista:', 'dd-maintenance' ); ?></strong>
									<code><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'd/m/Y H:i:s' ) ); ?></code>
								</p>
							<?php else : ?>
								<p class="dd-maint-style-color-666-margin-top-0-98bc7f">
									<span class="dashicons dashicons-no-alt dd-maint-style-color-d63638-vertical-align-middle-f9ba26"></span>
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
	public function render_tab_logs( $last_log, array $saved_logs = array(), $last_event = array(), array $event_summary = array() ) {
		?>
		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-5658c0">
			<div class="dd-maint-style-display-flex-justify-content-space-between-align-bcbb84">
				<h2 class="dd-maint-style-margin-0-display-flex-align-items-center-gap-8px-b271d5">
					<span class="dashicons dashicons-media-text"></span>
					<?php esc_html_e( 'Log da Última Execução', 'dd-maintenance' ); ?>
				</h2>

				<?php if ( ! empty( $last_log ) || ! empty( $saved_logs ) || ! empty( $last_event ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dd_maintenance_clear_log">
						<?php wp_nonce_field( 'dd_maintenance_clear_log' ); ?>
						<?php submit_button( __( 'Limpar Todos os Logs', 'dd-maintenance' ), 'secondary button-small', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $event_summary ) ) : ?>
				<?php
				$summary_status = (string) ( $event_summary['status'] ?? 'info' );
				$summary_class  = 'success' === $summary_status ? 'success' : ( 'warning' === $summary_status ? 'warning' : ( in_array( $summary_status, array( 'failure', 'persistence_failure' ), true ) ? 'error' : '' ) );
				$summary_label  = 'success' === $summary_status ? __( 'Sucesso', 'dd-maintenance' ) : ( 'warning' === $summary_status ? __( 'Sucesso com avisos', 'dd-maintenance' ) : ( 'persistence_failure' === $summary_status ? __( 'Falha de persistência', 'dd-maintenance' ) : ( 'failure' === $summary_status ? __( 'Falha', 'dd-maintenance' ) : __( 'Informação', 'dd-maintenance' ) ) ) );
				?>
				<p class="dd-maint-style-margin-0-0-12px-color-50575e-ad976f">
					<strong><?php esc_html_e( 'Resumo operacional:', 'dd-maintenance' ); ?></strong>
					<span class="dd-maint-badge <?php echo esc_attr( $summary_class ); ?>"><?php echo esc_html( $summary_label ); ?></span>
					<code><?php echo esc_html( $event_summary['text'] ?? '' ); ?></code>
				</p>
			<?php elseif ( ! empty( $last_event ) ) : ?>
				<p class="dd-maint-style-margin-0-0-12px-color-50575e-ad976f">
					<strong><?php esc_html_e( 'Último evento:', 'dd-maintenance' ); ?></strong>
					<code><?php echo esc_html( DD_Maintenance_Observability::format( $last_event ) ); ?></code>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $last_log ) && is_array( $last_log ) ) : ?>
				<pre class="dd-maint-style-background-1d2327-color-f0f0f1-padding-16px-bord-c00564"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre>
			<?php else : ?>
				<p class="dd-maint-style-color-666-font-style-italic-5f541e">
					<?php esc_html_e( 'Nenhum log registrado na sessão atual.', 'dd-maintenance' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-1cf229">
			<h2 class="dd-maint-style-margin-top-0-margin-bottom-16px-display-flex-ali-b2d039">
				<span class="dashicons dashicons-archive"></span>
				<?php esc_html_e( 'Histórico de Logs Salvos (Uploads)', 'dd-maintenance' ); ?>
			</h2>

			<?php if ( ! empty( $saved_logs ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Data do Backup / Log', 'dd-maintenance' ); ?></th>
							<th><?php esc_html_e( 'Arquivo', 'dd-maintenance' ); ?></th>
							<th><?php esc_html_e( 'Status', 'dd-maintenance' ); ?></th>
							<th><?php esc_html_e( 'Tamanho', 'dd-maintenance' ); ?></th>
							<th class="dd-maint-style-text-align-right-a527ba"><?php esc_html_e( 'Ações', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $saved_logs as $log_item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $log_item['date_formatted'] ); ?></strong></td>
								<td><code><?php echo esc_html( $log_item['filename'] ); ?></code></td>
								<td>
									<?php if ( 'success' === $log_item['status'] ) : ?>
										<span class="dd-maint-badge success"><?php esc_html_e( 'Sucesso', 'dd-maintenance' ); ?></span>
									<?php elseif ( 'warning' === $log_item['status'] ) : ?>
										<span class="dd-maint-badge warning"><?php esc_html_e( 'Sucesso com avisos', 'dd-maintenance' ); ?></span>
									<?php elseif ( 'failure' === $log_item['status'] ) : ?>
										<span class="dd-maint-badge error"><?php esc_html_e( 'Falha / Erro', 'dd-maintenance' ); ?></span>
									<?php else : ?>
										<span class="dd-maint-badge"><?php esc_html_e( 'Info', 'dd-maintenance' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $log_item['size_formatted'] ); ?></td>
								<td class="dd-maint-style-text-align-right-display-flex-gap-6px-justify-co-7268be">
									<button type="button" class="button button-small dd-view-log-btn" data-log-filename="<?php echo esc_attr( $log_item['filename'] ); ?>">
										<span class="dashicons dashicons-visibility dd-maint-style-font-size-14px-vertical-align-middle-line-height-29c3c7"></span>
										<?php esc_html_e( 'Ver Log', 'dd-maintenance' ); ?>
									</button>

									<?php
									$download_url = add_query_arg(
										array(
											'action'       => 'dd_maintenance_download_log',
											'log_filename' => $log_item['filename'],
											'_wpnonce'     => wp_create_nonce( 'dd_maintenance_download_log' ),
										),
										admin_url( 'admin-post.php' )
									);
									?>
									<a href="<?php echo esc_url( $download_url ); ?>" class="button button-small">
										<span class="dashicons dashicons-download dd-maint-style-font-size-14px-vertical-align-middle-line-height-29c3c7"></span>
										<?php esc_html_e( 'Baixar', 'dd-maintenance' ); ?>
									</a>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dd-maint-style-display-inline-margin-0-165435">
										<input type="hidden" name="action" value="dd_maintenance_delete_log">
										<input type="hidden" name="log_filename" value="<?php echo esc_attr( $log_item['filename'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_delete_log' ); ?>
										<button type="submit" class="button button-small button-link-delete" data-dd-confirm-click="<?php esc_attr_e( 'Excluir este log permanentemente?', 'dd-maintenance' ); ?>">
											<?php esc_html_e( 'Excluir', 'dd-maintenance' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Modal para visualização de log individual -->
				<div id="dd-maint-log-viewer-modal" class="dd-maint-modal-backdrop dd-maint-style-display-none-z-index-100001-position-fixed-top-0-84d2b7">
					<div class="dd-maint-modal-dialog dd-maint-style-background-fff-border-radius-6px-width-80-max-wi-d438d8">
						<div class="dd-maint-modal-header dd-maint-style-padding-16px-20px-border-bottom-1px-solid-ddd-di-181c93">
							<h3 id="dd-maint-log-viewer-title" class="dd-maint-style-margin-0-font-size-16px-f36234">Log</h3>
							<button type="button" id="dd-maint-log-viewer-close" class="button button-small">&times;</button>
						</div>
						<div class="dd-maint-modal-body dd-maint-style-padding-20px-flex-1-overflow-auto-background-1d2-75dcd3">
							<pre id="dd-maint-log-viewer-content" class="dd-maint-style-color-f0f0f1-margin-0-font-family-monospace-font-8a6b87"></pre>
						</div>
					</div>
				</div>


			<?php else : ?>
				<p class="dd-maint-style-color-666-font-style-italic-margin-0-a70e5c">
					<?php esc_html_e( 'Nenhum histórico de log salvo no servidor.', 'dd-maintenance' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Aba: Backups Locais & Restauração.
	 */
	public function render_tab_restore( $has_password, array $local_backups = array(), $max_upload = '', $s3 = null, $s3_configured = false ) {
		$total_bytes = 0;
		foreach ( $local_backups as $b ) {
			$total_bytes += $b['size'];
		}
		?>
		<div class="dd-maint-style-background-fff-border-1px-solid-ccd0d4-border-ra-830b2a">
			<div class="dd-maint-style-display-flex-justify-content-space-between-align-c674fa">
				<h2 class="dd-maint-style-margin-0-display-flex-align-items-center-gap-8px-b271d5">
					<span class="dashicons dashicons-database-import dd-maint-style-color-2271b1-5dadfa"></span>
					<?php esc_html_e( 'Backups Locais Armazenados no Servidor', 'dd-maintenance' ); ?>
				</h2>
				<?php if ( ! empty( $local_backups ) ) : ?>
					<span class="dd-maint-style-font-size-12px-color-50575e-background-f0f0f1-pa-719fa0">
						<strong><?php echo esc_html( count( $local_backups ) ); ?></strong> <?php esc_html_e( 'pacote(s) de backup', 'dd-maintenance' ); ?> &bull; <strong><?php echo esc_html( size_format( $total_bytes ) ); ?></strong> <?php esc_html_e( 'em disco', 'dd-maintenance' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<p class="dd-maint-style-margin-top-0-291b7b">
				<?php esc_html_e( 'Baixe os arquivos de backup diretamente para seu computador ou restaure o site a qualquer momento. Os arquivos ficam salvos com segurança em', 'dd-maintenance' ); ?> <code>wp-content/uploads/dd-maintenance/</code>.
			</p>

			<!-- Tabela de Backups Locais -->
			<?php if ( empty( $local_backups ) ) : ?>
				<div class="notice notice-info inline dd-maint-style-margin-16px-0-598bcd">
					<p class="dd-maint-style-margin-4px-0-60946a">
						<span class="dashicons dashicons-info dd-maint-style-color-72aee6-vertical-align-middle-8f9b66"></span>
						<?php esc_html_e( 'Nenhum arquivo de backup local encontrado na pasta do servidor. Execute um backup na aba "Visão Geral & Ações" para gerar novos arquivos.', 'dd-maintenance' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped dd-maint-style-margin-top-12px-border-1px-solid-c3c4c7-bd7524">
					<thead>
						<tr>
							<th scope="col" class="dd-maint-style-min-width-220px-1576af"><?php esc_html_e( 'Identificação do Backup & Volumes', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-width-140px-7a1ab9"><?php esc_html_e( 'Data de Criação', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-width-110px-80ffd2"><?php esc_html_e( 'Tamanho Total', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-min-width-210px-860edd"><?php esc_html_e( 'Downloads', 'dd-maintenance' ); ?></th>
							<th scope="col" class="dd-maint-style-text-align-right-min-width-180px-31267b"><?php esc_html_e( 'Ações', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_backups as $backup ) : ?>
							<tr>
								<td>
									<div class="dd-maint-style-font-weight-600-font-family-monospace-font-size--6ede09">
										<?php echo esc_html( $backup['identifier'] ); ?>
									</div>
									<div class="dd-maint-style-display-flex-flex-wrap-wrap-gap-4px-align-items--6583bc">
										<?php if ( ! empty( $backup['is_multipart'] ) ) : ?>
											<span class="dd-maint-part-badge">
												<?php printf( esc_html__( '%s volumes / partes', 'dd-maintenance' ), esc_html( (string) (int) $backup['total_parts'] ) ); ?>
											</span>
										<?php elseif ( ! empty( $backup['parts'] ) ) : ?>
											<span class="dd-maint-part-badge">
												<?php esc_html_e( 'Volume Único (.zip)', 'dd-maintenance' ); ?>
											</span>
										<?php endif; ?>

										<?php if ( ! empty( $backup['has_sql'] ) ) : ?>
											<span class="dd-maint-sql-badge">
												<?php esc_html_e( 'Dump SQL (.sql)', 'dd-maintenance' ); ?>
											</span>
										<?php endif; ?>
									</div>

									<?php if ( ! empty( $backup['is_multipart'] ) && count( $backup['parts'] ) > 1 ) : ?>
										<details class="dd-maint-style-margin-top-6px-font-size-11px-color-50575e-f4d163">
											<summary class="dd-maint-style-cursor-pointer-color-2271b1-159ccf"><?php esc_html_e( 'Ver lista de volumes individuais', 'dd-maintenance' ); ?></summary>
											<ul class="dd-maint-style-margin-4px-0-0-14px-padding-0-list-style-disc-d66f4b">
												<?php foreach ( $backup['parts'] as $p ) : ?>
													<li class="dd-maint-style-margin-2px-0-9d1682">
														<code><?php echo esc_html( $p['filename'] ); ?></code> (<?php echo esc_html( $p['size_formatted'] ); ?>)
													</li>
												<?php endforeach; ?>
											</ul>
										</details>
									<?php endif; ?>
								</td>
								<td class="dd-maint-style-font-size-12-5px-color-50575e-751583">
									<?php echo esc_html( $backup['date_formatted'] ); ?>
								</td>
								<td class="dd-maint-style-font-weight-600-font-size-12-5px-4f0b20">
									<?php echo esc_html( $backup['size_formatted'] ); ?>
								</td>
								<td>
									<div class="dd-maint-style-display-flex-flex-direction-column-gap-6px-align-916be7">
										<?php if ( ! empty( $backup['is_multipart'] ) && count( $backup['parts'] ) > 1 ) : ?>
											<button type="button" class="button button-primary button-small dd-maint-download-all-trigger" data-dd-download-parts="<?php echo esc_attr( wp_json_encode( wp_list_pluck( $backup['parts'], 'filename' ) ) ); ?>" title="<?php esc_attr_e( 'Inicia o download de todos os volumes em lotes de 5 no navegador', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
												<?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?>
											</button>

											<div class="dd-maint-style-display-flex-flex-wrap-wrap-gap-4px-5c6531">
												<?php foreach ( $backup['parts'] as $p ) : ?>
													<a href="<?php echo esc_url( self::get_download_url( $p['filename'] ) ); ?>" class="button button-secondary button-small" title="<?php echo esc_attr( $p['filename'] ); ?>" download="<?php echo esc_attr( $p['filename'] ); ?>">
														<span class="dashicons dashicons-media-archive dd-maint-style-font-size-12px-vertical-align-middle-f45765"></span>
														<?php printf( esc_html__( 'Parte %s (%s)', 'dd-maintenance' ), esc_html( (string) (int) $p['part'] ), esc_html( $p['size_formatted'] ) ); ?>
													</a>
												<?php endforeach; ?>
											</div>
										<?php elseif ( ! empty( $backup['parts'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $backup['parts'][0]['filename'] ) ); ?>" class="button button-primary button-small" download="<?php echo esc_attr( $backup['parts'][0]['filename'] ); ?>">
												<span class="dashicons dashicons-download dd-maint-style-font-size-13px-vertical-align-middle-line-height-8cdfa1"></span>
												<?php esc_html_e( 'Baixar Backup (.zip)', 'dd-maintenance' ); ?>
											</a>
										<?php endif; ?>

										<?php if ( ! empty( $backup['has_sql'] ) && ! empty( $backup['sql_filename'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $backup['sql_filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $backup['sql_filename'] ); ?>" title="<?php esc_attr_e( 'Baixar dump SQL do banco de dados', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-database dd-maint-style-font-size-12px-vertical-align-middle-f45765"></span>
												<?php printf( esc_html__( 'Baixar Dump SQL (%s)', 'dd-maintenance' ), esc_html( $backup['sql_size_formatted'] ) ); ?>
											</a>
										<?php endif; ?>
									</div>
								</td>
								<td class="dd-maint-style-text-align-right-a527ba">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dd-confirm="<?php echo esc_attr( __( 'Tem certeza que deseja restaurar este backup? Os arquivos e banco de dados atuais serão substituídos!', 'dd-maintenance' ) ); ?>" class="dd-maint-style-display-inline-block-margin-right-6px-15b26a">
										<input type="hidden" name="action" value="dd_maintenance_restore_local">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_restore_local' ); ?>
										<?php if ( $has_password ) : ?>
											<input type="password" name="restore_password" placeholder="<?php esc_attr_e( 'Senha', 'dd-maintenance' ); ?>" required autocomplete="current-password" class="dd-maint-style-width-105px-height-30px-font-size-12px-ce55b4">
										<?php endif; ?>
										<label class="dd-maint-style-display-block-margin-8px-0-font-size-12px-ebcd62">
											<input type="checkbox" name="apply_elementor_compatibility" value="1">
											<?php esc_html_e( 'Aplicar compatibilidade Elementor somente se o arquivo conhecido for reconhecido', 'dd-maintenance' ); ?>
										</label>
										<button type="submit" class="button button-primary button-small" title="<?php esc_attr_e( 'Restaura os arquivos e banco deste backup', 'dd-maintenance' ); ?>">
											<span class="dashicons dashicons-backup dd-maint-style-vertical-align-middle-font-size-14px-width-14px--f0d581"></span>
										</button>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-dd-confirm="<?php echo esc_attr( __( 'Tem certeza que deseja excluir este arquivo de backup local?', 'dd-maintenance' ) ); ?>" class="dd-maint-style-display-inline-block-44c26a">
										<input type="hidden" name="action" value="dd_maintenance_delete_backup">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_delete_backup' ); ?>
										<?php if ( $s3_configured ) : ?>
											<label title="<?php esc_attr_e( 'Marque para apagar também os arquivos deste backup no DigitalOcean Spaces / S3', 'dd-maintenance' ); ?>" class="dd-maint-style-font-size-11px-color-50575e-margin-right-6px-dis-36c7fe">
												<input type="checkbox" name="delete_remote" value="1" class="dd-maint-style-margin-0-1da9fa">
												<span class="dashicons dashicons-cloud dd-maint-style-font-size-13px-width-13px-height-13px-color-2271-df0d9f"></span>
												<?php esc_html_e( '+ S3', 'dd-maintenance' ); ?>
											</label>
										<?php endif; ?>
										<button type="submit" class="button button-link-delete button-small dd-maint-style-color-b32d2e-text-decoration-none-990de9">
											<?php esc_html_e( 'Excluir', 'dd-maintenance' ); ?>
										</button>
									</form>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr class="dd-maint-style-margin-30px-0-66f287">

			<!-- Opção 2: Upload de Arquivo .ZIP -->
			<h3 class="dd-maint-style-margin-top-24px-display-flex-align-items-center--47a341">
				<span class="dashicons dashicons-upload dd-maint-style-color-2271b1-5dadfa"></span>
				<?php esc_html_e( 'Fazer Upload de Arquivo .ZIP Externo para Restaurar', 'dd-maintenance' ); ?>
			</h3>
			<p>
				<?php esc_html_e( 'Se você possui arquivos de backup baixados no seu computador, pode enviá-los abaixo para restaurar o site:', 'dd-maintenance' ); ?>
			</p>

			<div class="notice notice-warning inline dd-maint-style-margin-bottom-16px-79a1c5">
				<p>
					<strong><?php esc_html_e( 'Atenção:', 'dd-maintenance' ); ?></strong>
					<?php esc_html_e( 'A restauração sobrescreverá os arquivos do site e as tabelas existentes no banco de dados com as versões contidas no arquivo de backup. Recomendamos gerar um backup atual antes de restaurar.', 'dd-maintenance' ); ?>
				</p>
			</div>

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
						<th scope="row"><?php esc_html_e( 'Compatibilidade Elementor', 'dd-maintenance' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="apply_elementor_compatibility" value="1">
								<?php esc_html_e( 'Aplicar somente se o arquivo conhecido for reconhecido', 'dd-maintenance' ); ?>
							</label>
						</td>
					</tr>
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
		</div>
		<?php
	}
}
