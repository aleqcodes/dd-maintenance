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
	public function render_tab_general( $s3_configured, $s3, $config_status, $settings, $last_log ) {
		$file_mods     = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_MODS' );
		$file_edit     = DD_Maintenance_Config::get_status_value( $config_status, 'DISALLOW_FILE_EDIT' );
		$local_backups = DD_Maintenance_Restore::get_local_backups();
		$backup_count  = count( $local_backups );
		$total_bytes   = 0;
		foreach ( $local_backups as $b ) {
			$total_bytes += $b['size'];
		}

		$next_cron = wp_next_scheduled( 'dd_maintenance_daily_maintenance' );
		if ( ! $next_cron ) {
			$next_cron = wp_next_scheduled( 'backuper_daily_maintenance' );
		}
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:24px;">
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

			<!-- Card 4: Backups Locais no Servidor -->
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,0.04);">
				<h3 class="dd-maintenance-card-title">
					<span class="dashicons dashicons-database-import" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Backups Locais Salvos', 'dd-maintenance' ); ?>
				</h3>
				<?php if ( $backup_count > 0 ) : ?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <strong><?php printf( esc_html__( '%d pacote(s) disponível(is)', 'dd-maintenance' ), $backup_count ); ?></strong></p>
					<p style="margin:4px 0;color:#666;font-size:12px;"><?php printf( esc_html__( 'Tamanho em disco: %s', 'dd-maintenance' ), esc_html( size_format( $total_bytes ) ) ); ?></p>
					<p style="margin-top:8px;margin-bottom:0;">
						<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small button-primary">
							<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
							<?php esc_html_e( 'Baixar Backups', 'dd-maintenance' ); ?> &rarr;
						</a>
					</p>
				<?php else : ?>
					<p style="display:flex;align-items:center;gap:6px;"><span class="dashicons dashicons-marker" style="color:#666;"></span> <strong><?php esc_html_e( 'Nenhum backup local', 'dd-maintenance' ); ?></strong></p>
					<p style="margin-top:8px;margin-bottom:0;"><a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small"><?php esc_html_e( 'Ver pasta de backups', 'dd-maintenance' ); ?></a></p>
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
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:24px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
					<h2 style="margin:0;display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-download" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Últimos Backups Locais (Downloads Rápidos)', 'dd-maintenance' ); ?>
					</h2>
					<a href="<?php echo esc_url( $this->page_url( 'restore' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Ver todos os backups locais', 'dd-maintenance' ); ?> &rarr;
					</a>
				</div>
				<p style="margin-top:0;color:#50575e;">
					<?php esc_html_e( 'Baixe os arquivos de backup gerados no servidor diretamente para seu computador:', 'dd-maintenance' ); ?>
				</p>

				<table class="widefat striped" style="border:1px solid #c3c4c7;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Backup & Volumes', 'dd-maintenance' ); ?></th>
							<th scope="col" style="width:140px;"><?php esc_html_e( 'Data', 'dd-maintenance' ); ?></th>
							<th scope="col" style="width:110px;"><?php esc_html_e( 'Tamanho', 'dd-maintenance' ); ?></th>
							<th scope="col" style="min-width:210px;"><?php esc_html_e( 'Download Imediato', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$recent_backups = array_slice( $local_backups, 0, 3 );
						foreach ( $recent_backups as $b ) :
						?>
							<tr>
								<td>
									<strong style="font-family:monospace;font-size:13px;"><?php echo esc_html( $b['identifier'] ); ?></strong>
									<div style="margin-top:2px;">
										<?php if ( ! empty( $b['is_multipart'] ) ) : ?>
											<span class="dd-maint-part-badge"><?php printf( esc_html__( '%d volumes', 'dd-maintenance' ), $b['total_parts'] ); ?></span>
										<?php elseif ( ! empty( $b['parts'] ) ) : ?>
											<span class="dd-maint-part-badge"><?php esc_html_e( 'Volume Único (.zip)', 'dd-maintenance' ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $b['has_sql'] ) ) : ?>
											<span class="dd-maint-sql-badge"><?php esc_html_e( 'Dump SQL (.sql)', 'dd-maintenance' ); ?></span>
										<?php endif; ?>
									</div>
								</td>
								<td style="font-size:12.5px;color:#50575e;"><?php echo esc_html( $b['date_formatted'] ); ?></td>
								<td style="font-weight:600;font-size:12.5px;"><?php echo esc_html( $b['size_formatted'] ); ?></td>
								<td>
									<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
										<?php if ( ! empty( $b['is_multipart'] ) && count( $b['parts'] ) > 1 ) : ?>
											<button type="button" class="button button-primary button-small" onclick="ddMaintDownloadAll(<?php echo esc_attr( wp_json_encode( wp_list_pluck( $b['parts'], 'filename' ) ) ); ?>, this);">
												<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
												<?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?>
											</button>
											<?php foreach ( $b['parts'] as $p ) : ?>
												<a href="<?php echo esc_url( self::get_download_url( $p['filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $p['filename'] ); ?>" title="<?php echo esc_attr( $p['filename'] ); ?>">
													<?php printf( esc_html__( 'P%d (%s)', 'dd-maintenance' ), $p['part'], esc_html( $p['size_formatted'] ) ); ?>
												</a>
											<?php endforeach; ?>
										<?php elseif ( ! empty( $b['parts'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $b['parts'][0]['filename'] ) ); ?>" class="button button-primary button-small" download="<?php echo esc_attr( $b['parts'][0]['filename'] ); ?>">
												<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
												<?php esc_html_e( 'Baixar Backup (.zip)', 'dd-maintenance' ); ?>
											</a>
										<?php endif; ?>

										<?php if ( ! empty( $b['has_sql'] ) && ! empty( $b['sql_filename'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $b['sql_filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $b['sql_filename'] ); ?>" title="<?php esc_attr_e( 'Baixar dump SQL do banco de dados', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-database" style="font-size:12px;vertical-align:middle;"></span>
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
	public function render_tab_config( $status, $has_password ) {
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
	public function render_tab_s3( $settings, $s3_configured, $s3 ) {
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
					<tr>
						<th scope="row"><label for="split_size_mb"><?php esc_html_e( 'Divisão de Volumes (Tamanho por Parte)', 'dd-maintenance' ); ?></label></th>
						<td>
							<?php $curr_split = ( new DD_Maintenance_Settings_Repository() )->get_split_size_mb( $settings ); ?>
							<select id="split_size_mb" name="split_size_mb">
								<option value="25" <?php selected( $curr_split, 25 ); ?>><?php esc_html_e( '25 MB (Ultra leve / servidores restritivos)', 'dd-maintenance' ); ?></option>
								<option value="50" <?php selected( $curr_split, 50 ); ?>><?php esc_html_e( '50 MB', 'dd-maintenance' ); ?></option>
								<option value="100" <?php selected( $curr_split, 100 ); ?>><?php esc_html_e( '100 MB (Ideal para Cloudflare Free)', 'dd-maintenance' ); ?></option>
								<option value="200" <?php selected( $curr_split, 200 ); ?>><?php esc_html_e( '200 MB (Recomendado - Rápido)', 'dd-maintenance' ); ?></option>
								<option value="400" <?php selected( $curr_split, 400 ); ?>><?php esc_html_e( '400 MB (Padrão UpdraftPlus - Ultra Rápido)', 'dd-maintenance' ); ?></option>
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
				<hr style="margin:24px 0;">

				<?php
				$site_slug      = sanitize_title( get_bloginfo( 'name' ) );
				$site_slug      = $site_slug ? $site_slug : 'site';
				$remote_backups = $s3->get_remote_backups( $site_slug );
				$has_s3_error   = is_wp_error( $remote_backups );
				?>

				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
					<h3 style="margin:0;display:flex;align-items:center;gap:8px;">
						<span class="dashicons dashicons-cloud" style="color:#2271b1;"></span>
						<?php esc_html_e( 'Backups Armazenados no Bucket S3 / Spaces', 'dd-maintenance' ); ?>
					</h3>
					<a href="<?php echo esc_url( $this->page_url( 's3' ) ); ?>" class="button button-small">
						<span class="dashicons dashicons-update" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
						<?php esc_html_e( 'Atualizar Lista do S3', 'dd-maintenance' ); ?>
					</a>
				</div>
				<p class="description" style="margin-top:0;">
					<?php printf( esc_html__( 'Backups agrupados no bucket "%1$s" (região: %2$s):', 'dd-maintenance' ), esc_html( $s3->get_bucket() ), esc_html( $s3->get_region() ) ); ?>
				</p>

				<?php if ( $has_s3_error ) : ?>
					<div class="notice notice-warning inline" style="margin:12px 0;">
						<p><?php printf( esc_html__( 'Não foi possível listar objetos do S3: %s', 'dd-maintenance' ), esc_html( $remote_backups->get_error_message() ) ); ?></p>
					</div>
				<?php elseif ( empty( $remote_backups ) ) : ?>
					<p style="color:#666;font-style:italic;">
						<?php esc_html_e( 'Nenhum backup (.zip/.sql) encontrado no bucket S3 / Spaces.', 'dd-maintenance' ); ?>
					</p>
				<?php else : ?>
					<table class="widefat striped" style="margin-top:10px;border:1px solid #c3c4c7;">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Backup / Volumes', 'dd-maintenance' ); ?></th>
								<th scope="col" style="width:120px;"><?php esc_html_e( 'Tamanho Total', 'dd-maintenance' ); ?></th>
								<th scope="col" style="width:180px;"><?php esc_html_e( 'Data no S3 (GMT)', 'dd-maintenance' ); ?></th>
								<th scope="col" style="text-align:right;width:150px;"><?php esc_html_e( 'Ação', 'dd-maintenance' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $remote_backups as $backup ) : ?>
								<tr>
									<td>
										<strong style="font-family:monospace;font-size:12px;"><?php echo esc_html( $backup['display_name'] ); ?></strong>
										<?php if ( ! empty( $backup['folder'] ) ) : ?>
											<div><code style="font-size:11px;"><?php echo esc_html( $backup['folder'] ); ?>/</code></div>
										<?php endif; ?>
										<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-top:4px;">
											<span style="display:inline-block;padding:2px 6px;background:#e7f3ff;color:#135e96;border-radius:3px;font-size:11px;">
												<?php printf( esc_html__( '%d volume(s)', 'dd-maintenance' ), (int) $backup['total_parts'] ); ?>
											</span>
											<?php if ( ! empty( $backup['has_sql'] ) ) : ?>
												<span style="display:inline-block;padding:2px 6px;background:#f0f0f1;color:#50575e;border-radius:3px;font-size:11px;">
													<?php esc_html_e( 'Dump SQL', 'dd-maintenance' ); ?>
												</span>
											<?php endif; ?>
										</div>
										<?php if ( ! empty( $backup['parts'] ) || ! empty( $backup['has_sql'] ) ) : ?>
											<details style="margin-top:6px;font-size:11px;color:#50575e;">
												<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Ver arquivos deste backup', 'dd-maintenance' ); ?></summary>
												<ul style="margin:5px 0 0 16px;">
													<?php foreach ( $backup['parts'] as $part ) : ?>
														<li style="margin:2px 0;">
															<code><?php echo esc_html( $part['key'] ); ?></code>
															(<?php echo esc_html( $part['size_formatted'] ); ?>)
														</li>
													<?php endforeach; ?>
													<?php if ( ! empty( $backup['has_sql'] ) ) : ?>
														<li style="margin:2px 0;">
															<code><?php echo esc_html( $backup['sql_key'] ); ?></code>
															(<?php echo esc_html( $backup['sql_size_formatted'] ); ?>)
														</li>
													<?php endif; ?>
												</ul>
											</details>
										<?php endif; ?>
									</td>
									<td style="font-size:12px;font-weight:600;">
										<?php echo esc_html( $backup['size_formatted'] ); ?>
									</td>
									<td style="font-size:12px;color:#50575e;">
										<?php echo esc_html( $backup['last_modified'] ); ?>
									</td>
									<td style="text-align:right;">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Tem certeza que deseja excluir todos os arquivos do backup "%s" do S3 / Spaces?', 'dd-maintenance' ), $backup['identifier'] ) ); ?>');">
											<input type="hidden" name="action" value="dd_maintenance_delete_s3_backup">
											<input type="hidden" name="backup_identifier" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
											<input type="hidden" name="redirect_tab" value="s3">
											<?php wp_nonce_field( 'dd_maintenance_delete_s3_backup' ); ?>
											<button type="submit" class="button button-link-delete button-small" style="color:#b32d2e;text-decoration:none;">
												<span class="dashicons dashicons-trash" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
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
	public function render_tab_cron( $settings ) {
		$next_cron = wp_next_scheduled( 'dd_maintenance_daily_maintenance' );
		if ( ! $next_cron ) {
			$next_cron = wp_next_scheduled( 'backuper_daily_maintenance' );
		}

		$current_freq      = isset( $settings['schedule_frequency'] ) ? $settings['schedule_frequency'] : 'daily';
		$current_time_val  = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '03:00';
		$current_retention = isset( $settings['retention_local'] ) ? (int) $settings['retention_local'] : 5;
		$chunk_size_mb     = ( new DD_Maintenance_Settings_Repository() )->get_split_size_mb( $settings );
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;">
			<h2 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Agendamento Automático & Políticas de Retenção (WP-Cron)', 'dd-maintenance' ); ?>
			</h2>

			<p>
				<?php printf( esc_html__( 'Configure a rotina automática para executar periodicamente o fluxo completo de manutenção (backup completo com volumes de até %d MB, envio ao S3/Spaces, limpeza de retenção e atualizações de plugins e core).', 'dd-maintenance' ), $chunk_size_mb ); ?>
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
	public function render_tab_logs( $last_log ) {
		$saved_logs = DD_Maintenance::get_saved_logs();
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;margin-bottom:24px;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
				<h2 style="margin:0;display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-media-text"></span>
					<?php esc_html_e( 'Log da Última Execução', 'dd-maintenance' ); ?>
				</h2>

				<?php if ( ! empty( $last_log ) || ! empty( $saved_logs ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dd_maintenance_clear_log">
						<?php wp_nonce_field( 'dd_maintenance_clear_log' ); ?>
						<?php submit_button( __( 'Limpar Todos os Logs', 'dd-maintenance' ), 'secondary button-small', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $last_log ) && is_array( $last_log ) ) : ?>
				<pre style="background:#1d2327;color:#f0f0f1;padding:16px;border-radius:4px;overflow:auto;max-height:350px;font-family:monospace;font-size:13px;line-height:1.6;"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre>
			<?php else : ?>
				<p style="color:#666;font-style:italic;">
					<?php esc_html_e( 'Nenhum log registrado na sessão atual.', 'dd-maintenance' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:860px;">
			<h2 style="margin-top:0;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
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
							<th style="text-align:right;"><?php esc_html_e( 'Ações', 'dd-maintenance' ); ?></th>
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
									<?php elseif ( 'failure' === $log_item['status'] ) : ?>
										<span class="dd-maint-badge error"><?php esc_html_e( 'Falha / Erro', 'dd-maintenance' ); ?></span>
									<?php else : ?>
										<span class="dd-maint-badge"><?php esc_html_e( 'Info', 'dd-maintenance' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $log_item['size_formatted'] ); ?></td>
								<td style="text-align:right;display:flex;gap:6px;justify-content:flex-end;align-items:center;">
									<button type="button" class="button button-small dd-view-log-btn" data-log-filename="<?php echo esc_attr( $log_item['filename'] ); ?>">
										<span class="dashicons dashicons-visibility" style="font-size:14px;vertical-align:middle;line-height:1.4;"></span>
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
										<span class="dashicons dashicons-download" style="font-size:14px;vertical-align:middle;line-height:1.4;"></span>
										<?php esc_html_e( 'Baixar', 'dd-maintenance' ); ?>
									</a>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin:0;">
										<input type="hidden" name="action" value="dd_maintenance_delete_log">
										<input type="hidden" name="log_filename" value="<?php echo esc_attr( $log_item['filename'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_delete_log' ); ?>
										<button type="submit" class="button button-small button-link-delete" onclick="return confirm('Excluir este log permanentemente?');">
											<?php esc_html_e( 'Excluir', 'dd-maintenance' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Modal para visualização de log individual -->
				<div id="dd-maint-log-viewer-modal" class="dd-maint-modal-backdrop" style="display:none;z-index:100001;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;">
					<div class="dd-maint-modal-dialog" style="background:#fff;border-radius:6px;width:80%;max-width:800px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.3);">
						<div class="dd-maint-modal-header" style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
							<h3 id="dd-maint-log-viewer-title" style="margin:0;font-size:16px;">Log</h3>
							<button type="button" id="dd-maint-log-viewer-close" class="button button-small">&times;</button>
						</div>
						<div class="dd-maint-modal-body" style="padding:20px;flex:1;overflow:auto;background:#1d2327;">
							<pre id="dd-maint-log-viewer-content" style="color:#f0f0f1;margin:0;font-family:monospace;font-size:13px;line-height:1.6;white-space:pre-wrap;"></pre>
						</div>
					</div>
				</div>

				<script>
				(function() {
					var modal = document.getElementById('dd-maint-log-viewer-modal');
					var title = document.getElementById('dd-maint-log-viewer-title');
					var content = document.getElementById('dd-maint-log-viewer-content');
					var closeBtn = document.getElementById('dd-maint-log-viewer-close');
					var ajaxUrl = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var nonce = <?php echo json_encode( wp_create_nonce( 'dd_maint_ajax_nonce' ) ); ?>;

					if (closeBtn) {
						closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
					}
					if (modal) {
						modal.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });
					}

					document.querySelectorAll('.dd-view-log-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var fn = btn.getAttribute('data-log-filename');
							title.innerText = 'Log: ' + fn;
							content.innerText = 'Carregando log...';
							modal.style.display = 'flex';

							var fd = new FormData();
							fd.append('action', 'dd_maintenance_ajax_action');
							fd.append('step', 'get_log_content');
							fd.append('log_filename', fn);
							fd.append('nonce', nonce);

							fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
							.then(function(r) { return r.json(); })
							.then(function(res) {
								if (res && res.success) {
									content.innerText = res.data.content || 'Log vazio.';
								} else {
									content.innerText = 'Erro ao carregar log: ' + (res.data ? res.data.message : 'Desconhecido');
								}
							})
							.catch(function(err) { content.innerText = 'Erro de conexão: ' + err; });
						});
					});
				})();
				</script>
			<?php else : ?>
				<p style="color:#666;font-style:italic;margin:0;">
					<?php esc_html_e( 'Nenhum histórico de log salvo no servidor.', 'dd-maintenance' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Aba: Backups Locais & Restauração.
	 */
	public function render_tab_restore( $has_password ) {
		$local_backups = DD_Maintenance_Restore::get_local_backups();
		$max_upload    = size_format( wp_max_upload_size() );
		$total_bytes   = 0;
		foreach ( $local_backups as $b ) {
			$total_bytes += $b['size'];
		}
		$s3            = new DD_Maintenance_S3();
		$s3_configured = $s3->is_configured();
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;max-width:960px;margin-bottom:24px;">
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
				<h2 style="margin:0;display:flex;align-items:center;gap:8px;">
					<span class="dashicons dashicons-database-import" style="color:#2271b1;"></span>
					<?php esc_html_e( 'Backups Locais Armazenados no Servidor', 'dd-maintenance' ); ?>
				</h2>
				<?php if ( ! empty( $local_backups ) ) : ?>
					<span style="font-size:12px;color:#50575e;background:#f0f0f1;padding:4px 10px;border-radius:12px;">
						<strong><?php echo esc_html( count( $local_backups ) ); ?></strong> <?php esc_html_e( 'pacote(s) de backup', 'dd-maintenance' ); ?> &bull; <strong><?php echo esc_html( size_format( $total_bytes ) ); ?></strong> <?php esc_html_e( 'em disco', 'dd-maintenance' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<p style="margin-top:0;">
				<?php esc_html_e( 'Baixe os arquivos de backup diretamente para seu computador ou restaure o site a qualquer momento. Os arquivos ficam salvos com segurança em', 'dd-maintenance' ); ?> <code>wp-content/uploads/dd-maintenance/</code>.
			</p>

			<!-- Tabela de Backups Locais -->
			<?php if ( empty( $local_backups ) ) : ?>
				<div class="notice notice-info inline" style="margin:16px 0;">
					<p style="margin:4px 0;">
						<span class="dashicons dashicons-info" style="color:#72aee6;vertical-align:middle;"></span>
						<?php esc_html_e( 'Nenhum arquivo de backup local encontrado na pasta do servidor. Execute um backup na aba "Visão Geral & Ações" para gerar novos arquivos.', 'dd-maintenance' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:12px;border:1px solid #c3c4c7;">
					<thead>
						<tr>
							<th scope="col" style="min-width:220px;"><?php esc_html_e( 'Identificação do Backup & Volumes', 'dd-maintenance' ); ?></th>
							<th scope="col" style="width:140px;"><?php esc_html_e( 'Data de Criação', 'dd-maintenance' ); ?></th>
							<th scope="col" style="width:110px;"><?php esc_html_e( 'Tamanho Total', 'dd-maintenance' ); ?></th>
							<th scope="col" style="min-width:210px;"><?php esc_html_e( 'Downloads', 'dd-maintenance' ); ?></th>
							<th scope="col" style="text-align:right;min-width:180px;"><?php esc_html_e( 'Ações', 'dd-maintenance' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_backups as $backup ) : ?>
							<tr>
								<td>
									<div style="font-weight:600;font-family:monospace;font-size:13px;color:#1d2327;margin-bottom:4px;">
										<?php echo esc_html( $backup['identifier'] ); ?>
									</div>
									<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
										<?php if ( ! empty( $backup['is_multipart'] ) ) : ?>
											<span class="dd-maint-part-badge">
												<?php printf( esc_html__( '%d volumes / partes', 'dd-maintenance' ), $backup['total_parts'] ); ?>
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
										<details style="margin-top:6px;font-size:11px;color:#50575e;">
											<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Ver lista de volumes individuais', 'dd-maintenance' ); ?></summary>
											<ul style="margin:4px 0 0 14px;padding:0;list-style:disc;">
												<?php foreach ( $backup['parts'] as $p ) : ?>
													<li style="margin:2px 0;">
														<code><?php echo esc_html( $p['filename'] ); ?></code> (<?php echo esc_html( $p['size_formatted'] ); ?>)
													</li>
												<?php endforeach; ?>
											</ul>
										</details>
									<?php endif; ?>
								</td>
								<td style="font-size:12.5px;color:#50575e;">
									<?php echo esc_html( $backup['date_formatted'] ); ?>
								</td>
								<td style="font-weight:600;font-size:12.5px;">
									<?php echo esc_html( $backup['size_formatted'] ); ?>
								</td>
								<td>
									<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
										<?php if ( ! empty( $backup['is_multipart'] ) && count( $backup['parts'] ) > 1 ) : ?>
											<button type="button" class="button button-primary button-small" onclick="ddMaintDownloadAll(<?php echo esc_attr( wp_json_encode( wp_list_pluck( $backup['parts'], 'filename' ) ) ); ?>, this);" title="<?php esc_attr_e( 'Inicia o download de todos os volumes em lotes de 5 no navegador', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
												<?php esc_html_e( 'Baixar Todos os Volumes', 'dd-maintenance' ); ?>
											</button>

											<div style="display:flex;flex-wrap:wrap;gap:4px;">
												<?php foreach ( $backup['parts'] as $p ) : ?>
													<a href="<?php echo esc_url( self::get_download_url( $p['filename'] ) ); ?>" class="button button-secondary button-small" title="<?php echo esc_attr( $p['filename'] ); ?>" download="<?php echo esc_attr( $p['filename'] ); ?>">
														<span class="dashicons dashicons-media-archive" style="font-size:12px;vertical-align:middle;"></span>
														<?php printf( esc_html__( 'Parte %d (%s)', 'dd-maintenance' ), $p['part'], esc_html( $p['size_formatted'] ) ); ?>
													</a>
												<?php endforeach; ?>
											</div>
										<?php elseif ( ! empty( $backup['parts'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $backup['parts'][0]['filename'] ) ); ?>" class="button button-primary button-small" download="<?php echo esc_attr( $backup['parts'][0]['filename'] ); ?>">
												<span class="dashicons dashicons-download" style="font-size:13px;vertical-align:middle;line-height:1.4;"></span>
												<?php esc_html_e( 'Baixar Backup (.zip)', 'dd-maintenance' ); ?>
											</a>
										<?php endif; ?>

										<?php if ( ! empty( $backup['has_sql'] ) && ! empty( $backup['sql_filename'] ) ) : ?>
											<a href="<?php echo esc_url( self::get_download_url( $backup['sql_filename'] ) ); ?>" class="button button-secondary button-small" download="<?php echo esc_attr( $backup['sql_filename'] ); ?>" title="<?php esc_attr_e( 'Baixar dump SQL do banco de dados', 'dd-maintenance' ); ?>">
												<span class="dashicons dashicons-database" style="font-size:12px;vertical-align:middle;"></span>
												<?php printf( esc_html__( 'Baixar Dump SQL (%s)', 'dd-maintenance' ), esc_html( $backup['sql_size_formatted'] ) ); ?>
											</a>
										<?php endif; ?>
									</div>
								</td>
								<td style="text-align:right;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza que deseja restaurar este backup? Os arquivos e banco de dados atuais serão substituídos!', 'dd-maintenance' ) ); ?>');">
										<input type="hidden" name="action" value="dd_maintenance_restore_local">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_restore_local' ); ?>
										<?php if ( $has_password ) : ?>
											<input type="password" name="restore_password" placeholder="<?php esc_attr_e( 'Senha', 'dd-maintenance' ); ?>" style="width:105px;height:30px;font-size:12px;" required autocomplete="current-password">
										<?php endif; ?>
										<label style="display:block;margin:8px 0;font-size:12px;">
											<input type="checkbox" name="apply_elementor_compatibility" value="1">
											<?php esc_html_e( 'Aplicar compatibilidade Elementor somente se o arquivo conhecido for reconhecido', 'dd-maintenance' ); ?>
										</label>
										<button type="submit" class="button button-primary button-small" title="<?php esc_attr_e( 'Restaura os arquivos e banco deste backup', 'dd-maintenance' ); ?>">
											<span class="dashicons dashicons-backup" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
											<?php esc_html_e( 'Restaurar', 'dd-maintenance' ); ?>
										</button>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza que deseja excluir este arquivo de backup local?', 'dd-maintenance' ) ); ?>');">
										<input type="hidden" name="action" value="dd_maintenance_delete_backup">
										<input type="hidden" name="backup_filename" value="<?php echo esc_attr( $backup['identifier'] ); ?>">
										<?php wp_nonce_field( 'dd_maintenance_delete_backup' ); ?>
										<?php if ( $s3_configured ) : ?>
											<label style="font-size:11px;color:#50575e;margin-right:6px;display:inline-flex;align-items:center;gap:3px;cursor:pointer;" title="<?php esc_attr_e( 'Marque para apagar também os arquivos deste backup no DigitalOcean Spaces / S3', 'dd-maintenance' ); ?>">
												<input type="checkbox" name="delete_remote" value="1" style="margin:0;">
												<span class="dashicons dashicons-cloud" style="font-size:13px;width:13px;height:13px;color:#2271b1;"></span>
												<?php esc_html_e( '+ S3', 'dd-maintenance' ); ?>
											</label>
										<?php endif; ?>
										<button type="submit" class="button button-link-delete button-small" style="color:#b32d2e;text-decoration:none;">
											<?php esc_html_e( 'Excluir', 'dd-maintenance' ); ?>
										</button>
									</form>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr style="margin:30px 0;">

			<!-- Opção 2: Upload de Arquivo .ZIP -->
			<h3 style="margin-top:24px;display:flex;align-items:center;gap:6px;">
				<span class="dashicons dashicons-upload" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Fazer Upload de Arquivo .ZIP Externo para Restaurar', 'dd-maintenance' ); ?>
			</h3>
			<p>
				<?php esc_html_e( 'Se você possui arquivos de backup baixados no seu computador, pode enviá-los abaixo para restaurar o site:', 'dd-maintenance' ); ?>
			</p>

			<div class="notice notice-warning inline" style="margin-bottom:16px;">
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
