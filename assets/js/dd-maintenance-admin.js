		(function() {
			var config          = window.DDMaintenanceAdmin || {};
			var ajaxUrl         = config.ajaxUrl || '';
			var nonce           = config.nonce || '';
			var downloadBaseUrl = config.downloadBaseUrl || '';
			var downloadNonce   = config.downloadNonce || '';
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

			document.querySelectorAll('.dd-maint-download-all-trigger').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var files = [];
					try {
						files = JSON.parse(btn.getAttribute('data-dd-download-parts') || '[]');
					} catch (e) {
						files = [];
					}
					downloadAllParts(files, btn);
				});
			});

			var logModal = document.getElementById('dd-maint-log-viewer-modal');
			var logTitle = document.getElementById('dd-maint-log-viewer-title');
			var logContent = document.getElementById('dd-maint-log-viewer-content');
			var logCloseBtn = document.getElementById('dd-maint-log-viewer-close');

			if (logCloseBtn) {
				logCloseBtn.addEventListener('click', function() {
					logModal.style.display = 'none';
				});
			}
			if (logModal) {
				logModal.addEventListener('click', function(e) {
					if (e.target === logModal) {
						logModal.style.display = 'none';
					}
				});
			}

			document.querySelectorAll('.dd-view-log-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var filename = btn.getAttribute('data-log-filename');
					if (!logModal || !logTitle || !logContent) {
						return;
					}
					logTitle.innerText = 'Log: ' + filename;
					logContent.innerText = 'Carregando log...';
					logModal.style.display = 'flex';

					var fd = new FormData();
					fd.append('action', 'dd_maintenance_ajax_action');
					fd.append('step', 'get_log_content');
					fd.append('log_filename', filename);
					fd.append('nonce', nonce);

					fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							if (res && res.success) {
								logContent.innerText = res.data.content || 'Log vazio.';
							} else {
								logContent.innerText = 'Erro ao carregar log: ' + (res.data ? res.data.message : 'Desconhecido');
							}
						})
						.catch(function(err) {
							logContent.innerText = 'Erro de conexão: ' + err;
						});
				});
			});

			document.querySelectorAll('[data-dd-confirm]').forEach(function(form) {
				form.addEventListener('submit', function(e) {
					if (!window.confirm(form.getAttribute('data-dd-confirm'))) {
						e.preventDefault();
					}
				});
			});

			document.querySelectorAll('[data-dd-confirm-click]').forEach(function(button) {
				button.addEventListener('click', function(e) {
					if (!window.confirm(button.getAttribute('data-dd-confirm-click'))) {
						e.preventDefault();
					}
				});
			});

			document.querySelectorAll('[data-dd-reload-click]').forEach(function(button) {
				button.addEventListener('click', function() {
					window.location.reload();
				});
			});

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
						rememberCorrelation(json.data);
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
									var finalStatus = finRes && finRes.status === 'warning' ? 'Backup restaurado com avisos!' : 'Backup restaurado com sucesso!';
									setProgress(100, finalStatus, (finRes.log ? finRes.log : '[OK] Restauração concluída.') + '\n[Fim] ' + new Date().toLocaleTimeString(), true);
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
