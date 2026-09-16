# Baseline da refatoração — Fase 0

**Data da execução:** 2026-09-16T10:46:13-03:00  
**Branch:** `refactor/fase-0`  
**Commit de referência:** `0105dcb`  
**Mensagem:** `fix(shield): sincronizar regex flexivel e caminhos sem is_writable no drop-in mu-plugin`

## Status

A baseline executável do código próprio foi concluída. Depois da instalação das extensões PHP, o self-check completo do backup avançou até a validação de quantidade de volumes e falhou por uma expectativa inconsistente do próprio teste. Também não há WordPress core nem ambiente de staging neste checkout; esses itens precisam ser executados no ambiente de integração/staging antes do aceite final da Fase 0.

Nenhum arquivo de código-fonte foi alterado durante a Fase 0.

## Runtime registrado

| Item | Valor | Fonte/observação |
|---|---|---|
| Plugin | DD Maintenance 2.1.1 | `dd-maintenance.php:3-8` |
| PHP mínimo | 7.4 | Header do plugin |
| PHP executado | 8.2.33 | `PHP_VERSION` |
| WordPress mínimo | 5.4 | Header do plugin |
| WordPress instalado no checkout | não disponível | Nenhum `version.php` foi encontrado |
| `json` | disponível | Extensão carregada |
| `zip` / `ZipArchive` | disponível | Instalada e carregada |
| `mysqli` | disponível | Instalada e carregada |
| `curl` | disponível | Instalada e carregada |
| Composer | 2.7.1 | Instalado |
| PHPUnit | não localizado | Ainda não instalado/configurado |
| PHPStan | não localizado | Ainda não instalado/configurado |
| PHPCS | não localizado | Ainda não instalado/configurado |

O header declara `Requires at least: 5.4` e `Requires PHP: 7.4`. A versão efetiva do WordPress não pode ser registrada a partir deste repositório isolado.

## Estrutura auditada

Código próprio:

| Arquivo | Linhas |
|---|---:|
| `dd-maintenance.php` | 88 |
| `backuper.php` | 9 |
| `includes/class-dd-maintenance.php` | 879 |
| `includes/class-dd-maintenance-settings.php` | 4.043 |
| `includes/class-dd-maintenance-backup.php` | 1.208 |
| `includes/class-dd-maintenance-restore.php` | 2.691 |
| `includes/class-dd-maintenance-s3.php` | 987 |
| `includes/class-dd-maintenance-config.php` | 512 |
| `includes/class-dd-maintenance-updater.php` | 199 |
| `includes/class-backuper-*.php` | 50 no total |

Testes existentes:

| Arquivo | Linhas |
|---|---:|
| `tests/backup-batch-self-check.php` | 272 |
| `tests/test-chunked-upload-restore.php` | 84 |
| `tests/test-elementor-patch.php` | 59 |
| `tests/test-integrity-regressions.php` | 273 |
| `tests/test-local-backup-download.php` | 166 |
| `tests/test-render-page.php` | 160 |
| `tests/test-s3-remote-delete.php` | 146 |
| `tests/test-serialized-replace.php` | 144 |

O diretório `updraftplus/` é código de terceiro e está ignorado pelo `.gitignore`. Ele não foi modificado nem incluído em verificações de refatoração do código próprio.

## Contratos atuais

### Opções WordPress

| Opção | Uso atual |
|---|---|
| `dd_maintenance_settings` | Fonte principal de configuração |
| `backuper_settings` | Fonte legacy; lida como fallback e ainda atualizada junto com a opção atual |
| `dd_maintenance_version` | Versão usada nas migrações |
| `dd_maintenance_password_hash` | Hash de senha atual |
| `dd_gerenciador_updates_password_hash` | Hash legacy migrado para a opção atual |
| `dd_maintenance_background_job` | Estado do job de backup executado pelo cron |
| `siteurl` | URL atual do site usada durante restore |
| `home` | URL atual da home usada durante restore |
| `gmt_offset` | Offset usado no cálculo do próximo cron |

Configuração observada em `dd_maintenance_settings`:

```text
s3_access_key     string
s3_secret_key     string sensível
s3_bucket         string
s3_region         string, default nyc3
s3_endpoint       string
include_db        bool/int, default 1
include_wpcontent bool/int, default 1
include_wpconfig  bool/int, default 1
include_entire    bool/int, default 1
keep_local        bool/int, default 1
split_size_mb     int, default 200, limitado entre 25 e 1000
schedule_enabled  bool/int, default 0
schedule_frequency daily|weekly|biweekly|monthly
schedule_time     HH:MM, default 03:00
retention_local   int, default 5; 0 significa ilimitado
```

Referências principais: `includes/class-dd-maintenance-settings.php:118-134`, `:3072-3129`, `includes/class-dd-maintenance.php:122-160`, `includes/class-dd-maintenance-backup.php:49-94`.

### Transients

| Transient | Uso |
|---|---|
| `dd_maintenance_last_log` | Último log exibido no painel |
| `backuper_last_log` | Fallback legacy do último log |
| `dd_maintenance_notice` | Aviso atual do painel |
| `backuper_notice` | Fallback legacy do aviso |

O código ainda grava e lê os pares atual/legacy. Referências: `includes/class-dd-maintenance.php:241-242`, `:400-401`, `:864-866`; `includes/class-dd-maintenance-settings.php:140-143`, `:3041-3053`, `:3321-3323`.

### Hooks de ciclo de vida e cron

- `register_activation_hook(__FILE__, ['DD_Maintenance', 'activate'])`
- `register_deactivation_hook(__FILE__, ['DD_Maintenance', 'deactivate'])`
- `cron_schedules`
- `admin_init`
- `plugins_loaded`
- `dd_maintenance_daily_maintenance`
- `backuper_daily_maintenance` (legacy)
- `dd_maintenance_backup_continue`, com `session_id` como argumento

Intervalos próprios:

```text
dd_daily     86400 segundos
dd_weekly    604800 segundos
dd_biweekly  1296000 segundos
dd_monthly   2592000 segundos
```

O estado do job de cron usa atualmente `status`, `phase`, `session_id`, `session_dir`, `folder`, `parts`, `upload_index`, `total_size`, `started_at` e `log`. Referências: `includes/class-dd-maintenance.php:538-572`, `:580-705`.

### Hooks administrativos

A classe `DD_Maintenance_Settings` registra:

```text
admin_menu
admin_init
admin_post_dd_maintenance_save_settings
admin_post_dd_maintenance_run_backup
admin_post_dd_maintenance_update_plugins
admin_post_dd_maintenance_update_core
admin_post_dd_maintenance_run_full
admin_post_dd_maintenance_config_action
admin_post_dd_maintenance_clear_log
admin_post_dd_maintenance_delete_log
admin_post_dd_maintenance_download_log
admin_post_dd_maintenance_restore_upload
admin_post_dd_maintenance_restore_local
admin_post_dd_maintenance_delete_backup
admin_post_dd_maintenance_download_backup
admin_post_dd_maintenance_delete_s3_object
admin_post_dd_maintenance_delete_s3_backup
wp_ajax_dd_maintenance_ajax_action
wp_ajax_dd_maintenance_ajax_restore
wp_ajax_nopriv_dd_maintenance_ajax_restore
admin_notices
```

Hooks legacy ainda suportados:

```text
admin_post_backuper_save_settings
admin_post_backuper_run_backup
admin_post_backuper_update_plugins
admin_post_backuper_update_core
admin_post_backuper_run_full
admin_post_backuper_download_backup
```

### AJAX

A ação geral é `dd_maintenance_ajax_action`. O pipeline de backup usa, entre outros, os passos:

```text
backup_init
backup_db
backup_index
backup_zip_batch
backup_finalize
backup_save_log
backup_fail_cleanup
retention
```

A ação de restore é `dd_maintenance_ajax_restore`. Modos observados:

```text
upload_init
upload_chunk
restore_init
restore_extract
restore_db
restore_files
restore_finalize
restore_fail_cleanup
```

A continuação pública do restore aceita somente os modos de continuação, uma sessão iniciada com prefixo `rst_` e um token válido. O handler valida nonce/capability para a sessão administrativa e usa token efêmero para continuar depois da troca do banco.

Referência principal: `includes/class-dd-maintenance-settings.php:3761-4042`.

### Sessão de backup

Diretório base:

```text
WP_CONTENT_DIR/uploads/dd-maintenance
```

Formato de sessão:

```text
session_bk_<timestamp>_<random>/
├── state.json
├── database.sql
├── manifest.jsonl
├── index-queue.jsonl
└── <volumes temporários>.volumeXXXXXX.zip
```

Identificador: `bk_<timestamp>_<random>`.

Campos essenciais registrados em `state.json`:

```text
session_id
session_dir
base_name
settings
db_file
manifest_file
index_queue_file
total_files
processed
db_initialized
db_completed
db_table_index
db_row_offset
db_schema_written
db_position
db_manifested
index_initialized
index_completed
index_queue_offset
index_queue_size
manifest_size
indexed_dirs
zip_manifest_offset
volume_index
large_file_offset
large_file_target
large_files
metadata_added
volume_count
volumes_completed
total_size
parts
created_at
```

O `state.json` de backup possui gravação temporária seguida de `rename()`. O leitor rejeita arquivo ausente ou JSON inválido. Referência: `includes/class-dd-maintenance-backup.php:37-141`.

### Sessão de restore

Upload temporário:

```text
WP_CONTENT_DIR/uploads/dd-maintenance/upload-temp-<timestamp>-<random>/
```

Sessão de upload AJAX:

```text
WP_CONTENT_DIR/uploads/dd-maintenance/upload_restore_<timestamp>_<random>/
```

Sessão de execução:

```text
WP_CONTENT_DIR/uploads/dd-maintenance/restore_exec_rst_<timestamp>_<random>/
├── state.json
└── arquivos extraídos
```

Campos essenciais do restore:

```text
session_id
extract_dir
temp_upload_dir
zip_paths
total_volumes
current_index
large_rebuilt
db_done
db_stats
target_siteurl
target_home
files_done
files_copied
auth_token_hash
auth_expires_at
log
created_at
```

O token original tem 48 caracteres. Somente o hash SHA-256 é persistido. A expiração inicial e a renovação durante uso são de 7.200 segundos. Referência: `includes/class-dd-maintenance-restore.php:347-450`.

### Arquivos de backup e índice

Nomes observados:

```text
<base>.zip
<base>.part001.zip
<base>.part002.zip
<base>.sql
<base>.part001.zip durante o upload
__dd_chunks__/manifest.json dentro de volumes com arquivos grandes
```

O índice usa JSON Lines:

```json
{"path":"/caminho/local","target":"site/caminho/relativo"}
```

`manifest.jsonl` contém arquivos a empacotar. `index-queue.jsonl` contém diretórios ainda não percorridos. O offset de cada arquivo é persistido em `state.json` para permitir retomada. Referências: `includes/class-dd-maintenance-backup.php:317-487`, `:489-629`.

### Compatibilidade legacy

A raiz mantém aliases para:

```text
Backuper
Backuper_Backup
Backuper_S3
Backuper_Updater
Backuper_Settings
DD_Gerenciador_Updates
```

Os aliases são registrados por `DD_Maintenance_Legacy_Compatibility`, na borda do
plugin, e os arquivos `class-backuper-*.php` apenas carregam o módulo canônico e
essa camada. O alvo de remoção é a próxima major version, depois do inventário
dos consumidores externos e da publicação de aviso de depreciação. Hooks, transients,
opções e wrappers ainda consumidos permanecem preservados até essa migração formal.

## Resultados da baseline

### Sintaxe PHP

Comando executado:

```bash
printf '%s\n' dd-maintenance.php backuper.php includes/*.php tests/*.php | xargs -n1 php -l
```

Resultado: **PASS**. Todos os arquivos PHP próprios e de teste reportaram `No syntax errors detected`.

### Testes focados

Todos foram executados com assertions ativas:

```bash
php -d zend.assertions=1 -d assert.exception=1 <teste>
```

| Teste | Resultado |
|---|---|
| `tests/test-serialized-replace.php` | PASS |
| `tests/test-integrity-regressions.php` | PASS |
| `tests/test-local-backup-download.php` | PASS |
| `tests/test-s3-remote-delete.php` | FALHA no ambiente padrão; PASS com fallback HTTP simulado |
| `tests/test-elementor-patch.php` | PASS |
| `tests/test-render-page.php` | PASS |

O teste S3 falha com cURL habilitado em `tests/test-s3-remote-delete.php:143` porque o teste simula `wp_remote_request()`, enquanto `put_object()` usa o caminho real de cURL em `includes/class-dd-maintenance-s3.php:827-874`. Ao desabilitar apenas as funções cURL no processo de teste, o fallback HTTP simulado passa. O teste precisa ser tornado hermético e separado entre transporte cURL e transporte WordPress na fase de qualidade.
### Self-check de backup

Comando:

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/backup-batch-self-check.php
```

Resultado: **FALHA DE BASELINE**.

Falha observada:

```text
AssertionError: assert(count($result['parts']) >= 4)
tests/backup-batch-self-check.php:203
```

O self-check agora encontrou `ZipArchive`, `mysqli` e `curl` e executou o pipeline de backup até a finalização. A falha ocorre porque a sessão usa `split_size_mb` padrão de 200 MB (`includes/class-dd-maintenance-backup.php:57`), enquanto o teste exige no mínimo quatro partes (`tests/backup-batch-self-check.php:203`). A constante legada `CHUNK_SIZE` continua em 25 MB (`includes/class-dd-maintenance-backup.php:17`), mas o runtime usa o limite configurável de 25–1000 MB (`:1189-1196`).

Este resultado confirma uma inconsistência existente entre teste, constante e configuração; não deve ser corrigido alterando o teste para mascarar o contrato. A correção deve ser tratada na fase de alinhamento de contratos, depois de definir oficialmente o limite de volumes.

## Critérios de aceite da Fase 0

| Critério | Estado | Evidência |
|---|---|---|
| Branch exclusiva | PASS | `refactor/fase-0` |
| Contratos listados | PASS | Este documento |
| Forma reproduzível de testes | PASS | Comandos acima, assertions ativas |
| Sintaxe do plugin verificada | PASS | `php -l` em todos os PHP próprios/testes |
| Testes focados executáveis | FALHA DE BASELINE | 5 testes passam com cURL habilitado; o teste S3 precisa de mock do transporte cURL |
| Self-check ZIP/banco completo | FALHA DE BASELINE | Extensões disponíveis; falha em `tests/backup-batch-self-check.php:203` por expectativa de pelo menos 4 partes |
| CI declara extensões obrigatórias | PENDENTE | CI ainda será criada na Fase 7 |
| Backup de staging para rollback | PENDENTE | Não há ambiente WordPress/staging neste checkout |
| Cópia das sessões de staging | PENDENTE | Depende do ambiente de staging |

## Bloqueios antes da próxima fase

Antes de considerar a Fase 0 totalmente aceita:

1. resolver a inconsistência de volume entre `split_size_mb`, `CHUNK_SIZE` e `tests/backup-batch-self-check.php:203`;
2. tornar o teste S3 hermético ou executá-lo com um transporte injetável;
3. registrar a versão efetiva do WordPress no ambiente de integração/staging;
4. criar um backup de staging real;
5. copiar as sessões e artefatos necessários para rollback;
6. registrar o resultado de uma restauração de staging sem alterar produção.

A Fase 1 pode ser desenvolvida localmente porque a baseline sintática e os testes focados estão disponíveis, mas não deve ser aprovada para rollout sem repetir as verificações dependentes do ambiente.
