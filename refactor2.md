# Plano de refatoração 2 — confiabilidade, testes e Clean Code

## Objetivo

Levar o DD Maintenance do estado atual — workflows compartilhados, segurança de paths e observabilidade inicial — para um estado confiável para rollout controlado.

Este plano não substitui `refactor.md`. Ele trata os problemas encontrados na auditoria seguinte:

- testes incompatíveis com o contrato atual;
- suíte PHPUnit e PHPCS não executáveis no ambiente local;
- checkpoints e escritas críticas sem validação completa;
- estado final de restore de arquivos não persistido;
- monólitos administrativos e de restauração;
- queries de pós-restore sem resultado explícito;
- análise estática permissiva;
- ausência de evidência executada de staging, canário e rollback.

## Regras de execução

1. Corrigir primeiro confiabilidade e testes; reorganizar classes depois.
2. Cada mudança deve ter uma responsabilidade principal.
3. Não fazer reformatagem global junto com mudança funcional.
4. Preservar opções, hooks, aliases e formatos de sessão até existir migração explícita.
5. Não remover compatibilidade `Backuper*` antes do canário e do inventário de consumidores externos.
6. Nenhum erro de I/O, banco, transporte ou checkpoint pode virar sucesso silencioso.
7. Não registrar tokens, chaves, cookies, Authorization ou conteúdo SQL.
8. Não executar código de terceiros nem modificar arquivos externos sem decisão explícita, checksum e rollback.
9. Toda etapa incremental precisa ser idempotente ou falhar de forma determinística.
10. Cada fase deve deixar o checkout executável antes de iniciar a seguinte.

## Estado inicial auditado

### Evidências positivas

- `includes/class-dd-maintenance-file-security.php` centraliza validação de paths, symlinks, extração ZIP e cópia confinada.
- `includes/class-dd-maintenance-session-store.php` grava estado com arquivo temporário, checksum, `0600` e `rename()` atômico.
- `includes/class-dd-maintenance-s3.php` usa streaming via cURL e limita o fallback materializado em memória.
- `includes/class-dd-maintenance-observability.php` define schema, correlação e redaction.
- Os smoke tests de renderização, Elementor, upload dividido, volumes, backups locais e replace serializado passaram no ambiente atual.

### Bloqueios conhecidos

- `phpstan.neon` usa nível 1 e `reportUnmatchedIgnoredErrors: false`.
- O workflow `.github/workflows/ci.yml` existe localmente, mas a publicação remota precisa ser confirmada antes de considerar CI como proteção de merge.
- Não há evidência neste checkout de restore real em staging, canário ou rollback ensaiado.

## Fase 0 — Baseline reproduzível e contratos

### Passos

1. Instalar localmente as extensões exigidas por PHPUnit e PHPCS: `dom`, `mbstring`, `xmlwriter` e `SimpleXML`.
2. Confirmar `curl`, `mysqli` e `ZipArchive` no mesmo ambiente.
3. Executar com assertions ativas:
   - `composer test`;
   - `composer phpstan`;
   - `composer phpcs`;
   - todos os scripts legados individualmente.
4. Publicar `.github/workflows/ci.yml` com escopo de credencial que permita workflows.
5. Fazer a CI falhar explicitamente quando faltar extensão obrigatória ou assertions.
6. Guardar os resultados como artefatos de CI, incluindo `tests/artifacts/`.
7. Registrar os contratos de sessão de backup, sessão de restore, job cron, eventos e resultados.
8. Definir oficialmente o contrato do tamanho de volume configurável entre 25 e 1000 MB.

### Contratos registrados

- **Volumes:** mínimo `25 MB`, máximo `1000 MB`, padrão efetivo `200 MB`.
- **Presets da UI:** `25`, `50`, `100`, `200`, `400` e `500 MB`; o limite de `1000 MB` permanece aceito pelo repositório/API.
- **Sessão de backup:** `wp-content/uploads/dd-maintenance/session_<id>/state.json`.
- **Sessão de restore:** `wp-content/uploads/dd-maintenance/restore_exec_<id>/state.json`.
- **Job cron:** opção persistida `dd_maintenance_background_job`, com `session_id`, `phase`, `status`, `started_at`, `correlation_id` e log.
- **Eventos:** `logs/events-YYYY-MM-DD.jsonl`, com `schema_version`, `event_id`, timestamp, operação, evento, correlação, sessão, etapa, status, progresso, bytes, duração, erros e código de falha.
- **Resultados:** erros operacionais usam `WP_Error`; resultados de restore e upload usam os objetos explícitos existentes na camada de workflow.

### Registro da execução local

- PHP `8.2.33` com `dom`, `mbstring`, `xmlwriter`, `SimpleXML`, `curl`, `mysqli`, `zip` e `ZipArchive`.
- `composer.lock` ajustado para `doctrine/instantiator 1.5.0` e plataforma PHP `7.4`, compatível com a matriz suportada.
- `phpunit.xml` corrigido para remover o atributo `cacheDirectory`, inválido no PHPUnit 9.6.
- PHPUnit executa `30` testes: `1` é pulado por ausência de `WP_CORE_DIR`.
- PHPStan passa sem erros no nível configurado.
- PHPCS executa, mas falha por violações existentes de documentação, nonce, escaping, formatação e operações de filesystem.
- Smoke tests passam para volumes, upload dividido, renderização, Elementor, backups locais, replace serializado e transportes S3.
- A cobertura de segurança de arquivos equivalente foi migrada para `tests/Unit/FileSecurityTest.php`; o smoke duplicado foi removido.

### Preservação de artefatos

- O workflow cria `.ci-artifacts/`.
- PHPUnit, PHPStan e PHPCS gravam seus outputs nessa pasta com `tee` e `pipefail`.
- O workflow publica `.ci-artifacts/` e `tests/artifacts/` mesmo quando a execução falha.

### Critérios de aceite

- Um comando único executa a suíte completa.
- PHPUnit, PHPCS e PHPStan executam sem erro de ambiente.
- CI remoto executa testes e análise estática em todas as versões suportadas.
- O teste de volumes não depende de um número artificial de partes.
- Os scripts legados restantes têm saída e código de retorno determinísticos.

## Fase 1 — Corrigir testes incompatíveis e transportes

### Passos

1. Remover a definição incompleta de `DD_Maintenance` em `tests/test-integrity-regressions.php` ou fornecer um double compatível com o contrato atual.
2. Carregar `DD_Maintenance_Observability` nos cenários que exercitam `ajax_handle_restore()`.
3. Separar o teste S3 em doubles explícitos para:
   - caminho cURL;
   - caminho `wp_remote_request()`;
   - falha transitória;
   - resposta HTTP inválida.
4. Não desabilitar funções globais por hacks de processo para forçar um transporte.
5. Migrar scripts com `assert()` para PHPUnit quando já existir cobertura equivalente.
6. Manter smoke tests apenas para fluxos de processo que realmente precisam de isolamento.
7. Fazer os doubles falharem quando receberem um contrato inesperado.

### Critérios de aceite

- `tests/test-integrity-regressions.php` passa com a implementação atual.
- `tests/test-s3-remote-delete.php` passa com cURL habilitado.
- Cada transporte S3 tem cobertura isolada.
- Falhas de teste mostram causa, etapa e artefato sem depender de estado global residual.

### Registro da execução local

- O fake de `DD_Maintenance` em `test-integrity-regressions.php` agora implementa `record_event()` usando o contrato real de `DD_Maintenance_Observability`.
- `DD_Maintenance_S3` aceita um callable de upload opcional; o caminho padrão continua selecionando cURL ou `wp_remote_request()` conforme o runtime.
- `test-s3-remote-delete.php` usa doubles independentes para upload cURL, upload via `wp_remote_request()`, retry transitório e HTTP inválido, sem alterar funções globais ou desabilitar extensões.
- `LegacyScriptRunner` gera artefato JSON para qualquer smoke falho, com script, etapa, código de saída, stdout e stderr.
- `FileSecurityTest` absorve a cobertura equivalente do script removido; smoke permanece apenas nos fluxos que precisam de processo separado.
- Verificado: `composer test` (`30` testes, `91` asserções, `1` skipped), `composer phpstan`, `composer test:unit`, `composer test:smoke`, os dois scripts de aceite da Fase 1 e um upload cURL real contra servidor HTTP local.

## Fase 2 — Checkpoints e I/O confiáveis

### Passos

1. Em `DD_Maintenance_Restore::restore_files_step()`, persistir `files_done`, `files_copied`, log e offset antes de retornar `completed=true`.
2. Adicionar regressão para interrupção imediatamente após a última cópia e repetir a mesma etapa.
3. Validar retorno e quantidade de bytes de todas as escritas críticas:
   - manifesto;
   - filas JSONL;
   - união de volumes;
   - dumps SQL;
   - logs JSONL;
   - arquivos de proteção;
   - loader MU temporário.
4. Quando uma escrita falhar, fechar handles, limpar artefatos incompletos e retornar `WP_Error` com código estável.
5. Não atualizar offset, contagem ou marcador de conclusão antes da escrita correspondente estar confirmada.
6. Validar o retorno de `SettingsRepository::save()` em `save_settings()`.
7. Validar o retorno de `Cron_Job_Store::save()` em criação, continuação e finalização de jobs.
8. Registrar evento de falha de checkpoint com `operation`, `session_id`, `step` e `failure_code`.
9. Verificar se limpeza parcial falhou e preservar o erro original junto do erro de cleanup.

### Critérios de aceite

- Uma falha de disco não avança o checkpoint.
- Repetir uma etapa concluída não copia tudo novamente.
- Um job cron nunca continua sem confirmar seu estado persistido.
- Falhas de log não escondem falhas da operação principal.
- Existem testes para escrita parcial, checkpoint inválido e retomada.

### Registro da execução local

- `restore_files_step()` agora confirma `files_copied`, `files_queue_offset`, `files_done` e o log no mesmo checkpoint antes de retornar concluído; uma repetição retorna o estado persistido sem recopiá-lo.
- Escritas de manifestos, filas JSONL, dumps SQL, metadados de volumes, logs, arquivos de proteção e loader MU validam todos os bytes; handles são fechados e artefatos incompletos são removidos ou truncados ao último checkpoint confirmado.
- Falhas de `SettingsRepository::save()`, `Cron_Job_Store::save()` e checkpoints de sessão geram erros/eventos estáveis com etapa, sessão e código de falha; jobs não são reagendados sem persistência confirmada.
- Limpezas parciais retornam seus erros sem substituir a falha original; falhas de log permanecem observáveis sem ocultar a falha operacional.
- Verificado: `composer test` (`33` testes, `100` asserções, `1` skipped), `composer phpstan`, `tests/test-integrity-regressions.php` e `tests/test-render-page.php`.


## Fase 3 — Restore de banco com resultado explícito

### Passos

1. Criar um adaptador pequeno para execução de queries de restore e coleta de erros.
2. Separar:
   - importação do dump;
   - atualização de `siteurl` e `home`;
   - atualização de `active_plugins`;
   - sincronização de prefixo;
   - search/replace;
   - pós-processamento Elementor.
3. Validar o retorno de cada query de pós-restore em:
   - options;
   - usermeta;
   - URLs;
   - caches e metadados Elementor.
4. Classificar erros como fatais ou avisos explícitos.
5. Não declarar restore íntegro quando uma alteração obrigatória de URL, prefixo ou configuração falhar.
6. Isolar a construção de identificadores de tabela e validar nomes derivados exclusivamente de tabelas descobertas pelo banco.
7. Remover queries de manutenção repetidas a cada lote quando puderem ser executadas somente na transição correta de estado.
8. Preservar amostras de erro sem registrar credenciais ou SQL sensível.

### Critérios de aceite

- Banco restaurado com falha de `siteurl`, `home` ou prefixo termina como falha classificada.
- Avisos SQL aparecem no evento e no log textual.
- O restore interrompido retoma sem repetir queries já confirmadas.
- O teste cobre banco grande, erro de query e finalização parcial.

### Registro da execução local

- O restore SQL usa `DD_Maintenance_Restore_Database_Adapter` para centralizar execução em `wpdb`/`mysqli`, contar falhas e guardar no máximo três amostras sanitizadas; consultas obrigatórias retornam `WP_Error` com códigos estáveis.
- A importação do dump ficou separada da finalização: `siteurl`, `home`, `active_plugins`, sincronização de prefixo, URLs, usermeta, caches e metadados Elementor são executados somente após EOF confirmado, com cada mutação validada.
- O prefixo descoberto é aceito apenas após validação do identificador; falhas de prefixo/configuração e de alterações obrigatórias de URL não produzem restore íntegro. Falhas SQL e avisos de pós-processamento aparecem no checkpoint, no log textual e no evento como `warning`.
- `db_initialized` impede repetir a preparação SQL entre lotes; o checkpoint final só marca `db_done` depois da finalização e dos avisos amostrados. Search/replace de usermeta usa paginação por `umeta_id`.
- Verificado: `composer test` (`36` testes, `110` asserções, `1` skipped), `composer phpstan`, `tests/test-integrity-regressions.php`, `tests/test-serialized-replace.php` e `php -l` dos arquivos alterados.

## Fase 4 — Reduzir o monólito de restore

### Ordem recomendada

1. Extrair `RestoreSessionService`.
2. Extrair `RestoreFilesService`.
3. Extrair `ArchiveService` para validação, extração e união de volumes.
4. Extrair `RestoreDatabaseService`.
5. Extrair `UrlMigrationService`.
6. Manter Elementor em um adaptador isolado e reversível.

### Regras de migração

- `DD_Maintenance_Restore` permanece como fachada temporária.
- Cada extração migra todos os chamadores antes de remover o método antigo.
- O formato de `state.json` permanece compatível durante a migração.
- Não duplicar loops de restore entre manual, AJAX e cron.
- Não adicionar uma camada genérica de abstração sem consumidor real.

### Critérios de aceite

- A fachada contém apenas composição e compatibilidade.
- Cada serviço tem dependências injetáveis ou doubles explícitos.
- Upload, restore local, AJAX e cron usam o mesmo caso de uso.
- Os testes deixam de depender de Reflection para exercitar regras centrais.

### Registro da execução local

- `DD_Maintenance_Restore` foi reduzida a uma fachada de composição e compatibilidade; a implementação operacional foi isolada em `DD_Maintenance_Restore_Implementation`.
- Foram criados serviços injetáveis para sessão, arquivos, arquivos compactados, banco, migração de URLs e o adaptador Elementor. Upload, restore local, AJAX e o workflow compartilhado continuam usando o mesmo contrato público.
- O formato de `state.json` e os métodos legados permanecem compatíveis; os loops de importação, cópia e finalização continuam centralizados na implementação, sem duplicação entre entradas.
- `FileSecurityTest` agora exercita `DD_Maintenance_Restore_Files_Service` diretamente, sem Reflection para a regra de cópia.
- Verificado: `composer test` (`36` testes, `110` asserções, `1` skipped), `composer phpstan`, `tests/test-integrity-regressions.php`, `tests/test-serialized-replace.php`, `tests/test-render-page.php`, `tests/test-chunked-upload-restore.php` e `php -l` dos serviços.

## Fase 5 — Reduzir o monólito administrativo

### Passos

1. Separar request parsing e validação de capability/nonce dos casos de uso.
2. Fazer controllers chamarem serviços de aplicação, não uma fachada de 3000 linhas.
3. Manter `DD_Maintenance_Settings` como composição e compatibilidade temporária.
4. Extrair handlers por responsabilidade:
   - configurações;
   - backup;
   - restore;
   - downloads;
   - logs;
   - S3;
   - wp-config.
5. Remover duplicação de checks administrativos sem reduzir a proteção de nenhum endpoint.
6. Mover JavaScript e CSS inline para assets versionados quando isso não alterar o contrato HTML.
7. Manter a renderização sem secret key, token ou conteúdo sensível.

### Critérios de aceite

- Cada handler tem fluxo curto: autorização, validação, chamada de caso de uso, resposta.
- A apresentação não contém regras de domínio de backup/restore.
- A página administrativa mantém comportamento e hooks existentes.
- A Secret Key nunca aparece em HTML, atributos, logs ou respostas AJAX.

### Registro da execução local

- `DD_Maintenance_Settings` passou a ser uma composição de compatibilidade; a implementação operacional foi isolada em `DD_Maintenance_Settings_Implementation`, mantendo a API pública existente.
- Foram extraídos request parsing/autorização (`DD_Maintenance_Admin_Request`), serviço de página administrativa e handlers separados para configurações, geral, backup, restore, downloads, logs, S3 e wp-config.
- JavaScript/CSS inline remanescentes no renderer (incluindo `onclick`/`onsubmit` e `style` attributes) também foram centralizados nos assets, com `data-*` para preservar as confirmações e downloads.
- JavaScript e CSS administrativos foram movidos para `assets/js/dd-maintenance-admin.js` e `assets/css/dd-maintenance-admin.css`, carregados com a versão do plugin e valores dinâmicos via `wp_localize_script`, preservando o contrato HTML.
- A renderização continua limpando `s3_secret_key`; o teste valida que a Secret Key não aparece no HTML e que o token necessário permanece apenas no asset externo, sem ser embutido na página.
- Verificado: `composer test` (`36` testes, `110` asserções, `1` skipped), `composer phpstan`, `tests/test-render-page.php`, `tests/test-integrity-regressions.php`, `tests/test-chunked-upload-restore.php` e `php -l` dos arquivos administrativos.

## Fase 6 — Observabilidade operacional

### Passos

1. Fazer `record_event()` diferenciar evento criado de evento persistido quando a escrita JSONL falhar.
2. Adicionar alerta explícito para falha de persistência de eventos.
3. Remover a duplicação de `step` dentro de `context` em `DD_Maintenance_Observability::make_event()`.
4. Emitir evento para:
   - falha de checkpoint;
   - falha de cleanup;
   - falha de `clear_cache()` do Elementor;
   - falha de criação/remoção de MU loader;
   - query obrigatória não aplicada.
5. Verificar que `correlation_id` atravessa AJAX, workflow, cron e sessão.
6. Testar redaction com valores sensíveis em chaves conhecidas e em estruturas aninhadas.
7. Não registrar valores arbitrários de request apenas porque foram sanitizados.

### Critérios de aceite

- Uma falha pode ser diagnosticada usando sessão, etapa e código de erro.
- Eventos de falha não expõem tokens, chaves, cookies ou SQL.
- Não existem sessões sem evento final em cenários normais.
- A UI diferencia sucesso, sucesso com avisos e falha.

### Registro da execução local

- `record_event()` agora retorna `persistence_status=persisted` ou `created_not_persisted`; falhas de JSONL preservam o evento no transient e armam alerta administrativo com operação, evento e correlação.
- `DD_Maintenance_Observability::make_event()` mantém `step` apenas no campo operacional, aplica whitelist ao contexto e não registra payloads arbitrários de request; a sanitização recursiva cobre chaves sensíveis aninhadas.
- Checkpoints de backup/restore, cleanup síncrono e de falha, cache Elementor, MU loader e queries obrigatórias emitem eventos de falha com sessão, etapa e código estável quando disponíveis.
- `correlation_id` é carregado na sessão de backup/restore e propagado pelos handlers AJAX, workflow, cron, upload manual e finalização síncrona; o resultado de restore preserva avisos para a UI.
- Verificado: `composer test` (`37` testes, `119` asserções, `1` skipped), `composer phpstan`, `node --check assets/js/dd-maintenance-admin.js`, `php -l` dos PHP alterados e os três smoke tests com assertions habilitadas.

## Fase 7 — Análise estática e qualidade contínua

### Passos

1. Subir PHPStan gradualmente de nível 1 para 3.
2. Corrigir os erros reais antes de subir para nível 5.
3. Reduzir os `ignoreErrors` para funções específicas.
4. Ativar `reportUnmatchedIgnoredErrors: true`.
5. Atualizar PHPStan dentro de uma alteração separada e controlada.
6. Fazer PHPCS passar sem excluir novas áreas do código próprio.
7. Criar regra de CI que impeça merge com:
   - falha de sintaxe;
   - teste quebrado;
   - extensão obrigatória ausente;
   - assertions desabilitadas;
   - PHPStan ou PHPCS com erro.

### Critérios de aceite

- A análise estática detecta erros de tipos e contratos relevantes.
- Ignores não utilizados causam falha.
- CI é obrigatória no branch de integração.
- O código próprio não depende de exclusões amplas para passar.

### Registro da execução local

- PHPStan foi elevado de nível `1` para `3`, com `reportUnmatchedIgnoredErrors: true`; o erro real de `Automatic_Upgrader_Skin` foi resolvido com chamada dinâmica compatível com os stubs WordPress, e o ignore não utilizado de aridade de `__()` foi removido.
- PHPStan foi atualizado de `1.12.34` para `2.2.14` em alteração controlada, mantendo o lockfile sincronizado.
- PHPCS passou a aplicar um conjunto explícito de regras críticas (`EscapeOutput`, comparações estritas e `StrictInArray`) sobre todo o código próprio, sem novas exclusões de diretórios; downloads binários têm exceções locais justificadas por preservação byte a byte.
- A CI agora valida sintaxe PHP, extensões obrigatórias, assertions habilitadas, PHPUnit, PHPStan e PHPCS, com o job `Integration quality gate` dependente dos jobs de testes e análise estática. Para cumprir a proteção de merge, o branch de integração deve marcar esse check como obrigatório nas branch protection rules; esse ajuste é administrativo no GitHub e não existe neste checkout.
- Verificado: `composer validate --strict`, `composer test` (`37` testes, `119` asserções, `1` skipped), `composer phpstan`, `composer phpcs`, `node --check assets/js/dd-maintenance-admin.js`, sintaxe PHP rastreada e parsing estrutural do workflow YAML; a formatação do YAML não foi reescrita para evitar ruído fora da fase.

## Fase 8 — Compatibilidade legada e remoção controlada

### Passos

1. Inventariar consumidores de:
   - classes `Backuper*`;
   - hooks `backuper_*`;
   - opções legadas;
   - transients legados;
   - arquivos wrapper.
2. Publicar aviso de depreciação antes da remoção.
3. Adicionar métrica ou log agregado de uso dos aliases, sem dados sensíveis.
4. Definir versão major de remoção.
5. Só remover aliases após o canário passar e não haver consumidor conhecido.
6. Preservar leitor de sessões antigas até todas as sessões expirarem.

### Critérios de aceite

- Não há remoção silenciosa de contrato público.
- O prazo de remoção está documentado.
- Sessões antigas não ficam ilegíveis durante a transição.
- Hooks novos e legados apontam para o mesmo caso de uso.

### Inventário confirmado

- **Classes e wrappers:** `Backuper`, `Backuper_Backup`, `Backuper_S3`, `Backuper_Updater`, `Backuper_Settings`, `DD_Gerenciador_Updates`, `backuper.php` e `class-backuper-*.php`.
- **Hooks:** `backuper_daily_maintenance` e ações `admin_post_backuper_*`; cada callback legado delega para o mesmo método canônico e contabiliza sua execução.
- **Opções:** `backuper_settings` e `dd_gerenciador_updates_password_hash`; a migração mantém os dados legados para leitura e rollback, sem exclusão silenciosa.
- **Transients:** `backuper_last_log` e `backuper_notice`; continuam como fallback de leitura e espelho de compatibilidade.
- **Sessões:** o leitor de `state.json` continua aceitando sessões sem `_schema_version` ou checksum, enquanto rejeita versões futuras e checksums inválidos.

### Registro da execução local

- O aviso administrativo de depreciação informa a remoção dos contratos na versão major `3.0.0`; wrappers também usam `_deprecated_file` quando disponível.
- `dd_maintenance_legacy_usage` agrega contadores e timestamps por wrapper, hook, opção ou transient, sem armazenar payloads, tokens ou identificadores de operador.
- Nenhum alias foi removido: a remoção fica bloqueada até o canário da Fase 9 e a confirmação de ausência de consumidores conhecidos.

## Fase 9 — Staging, canário e rollback

Executar a matriz descrita em `refactor.md:481-486`:

- site pequeno;
- banco grande;
- arquivo maior que um chunk;
- Elementor ativo;
- S3 real ou compatível;
- interrupção entre lotes;
- disco insuficiente;
- falha de rede.

### Evidências obrigatórias

Para cada cenário registrar:

- `correlation_id`;
- `session_id`;
- etapa;
- `failure_code`;
- `logs/events-YYYY-MM-DD.jsonl`;
- log textual;
- checksums dos volumes;
- contagem de arquivos;
- banco restaurado;
- URLs finais;
- configurações finais;
- resultado de cleanup.

### Rollout

1. Fazer restore real em staging com backup real.
2. Interromper e repetir cada etapa em pelo menos um cenário.
3. Confirmar retomada ou falha determinística.
4. Executar rollback em staging usando `DD_MAINTENANCE_DISABLE_OPERATIONS=true`.
5. Fazer canário em uma instalação não crítica.
6. Manter a versão anterior disponível.
7. Monitorar falhas, avisos, sessões sem evento final e divergências de checksum por 24 horas.
8. Só ampliar o rollout após restore verificado e rollback executado.

### Critérios de aceite

- Um backup real restaura arquivos, banco, URLs e configurações esperadas.
- O rollback foi executado, não apenas documentado.
- Nenhuma falha crítica ficou sem classificação.
- Os adapters legados só são removidos depois dessa fase.

### Preparação e status da execução

- O modo de rollback foi centralizado em `DD_Maintenance::operations_disabled()` e agora bloqueia também o início, a continuação via WP-Cron e o fluxo completo; as rejeições emitem `failure_code=operations_disabled`.
- Preflight local: `ZipArchive`, `curl` e `mysqli` estão disponíveis, mas `WP_CORE_DIR` não está definido e não há checkout WordPress/staging local.
- Não há credenciais S3 configuradas neste ambiente; nenhum backup real, banco restaurado, canário ou rollback de staging foi inventado.
- A matriz de staging e as evidências obrigatórias permanecem bloqueadas até existir uma instalação WordPress de staging, backup real verificável, transporte S3 compatível e acesso operacional para executar/reverter a versão.
- Verificado localmente: `composer test` (`39` testes, `124` asserções, `1` skipped), `composer phpstan`, `composer phpcs`, sintaxe PHP/JavaScript, três smoke tests e flag de rollback (`rollback flag: OK`).

## Ordem de PRs recomendada

1. **PR 1:** ambiente, CI e testes incompatíveis.
2. **PR 2:** checkpoints e validação de I/O.
3. **PR 3:** resultados explícitos de banco e pós-restore.
4. **PR 4:** extração incremental do restore.
5. **PR 5:** extração incremental da camada administrativa.
6. **PR 6:** observabilidade e redaction final.
7. **PR 7:** PHPStan/PHPCS e conversão dos scripts legados.
8. **PR 8:** staging, canário, rollback e remoção de compatibilidade.

## Definição de pronto

O `refactor2.md` está concluído quando:

- a suíte PHPUnit roda em local e CI;
- os smoke tests de restore e S3 são herméticos;
- todo checkpoint crítico valida sua persistência;
- restore de arquivos e banco falha de forma explícita;
- os monólitos administrativos e de restore foram reduzidos por extrações reais;
- eventos permitem diagnosticar falhas sem dados sensíveis;
- PHPStan e PHPCS são gates de CI úteis;
- restore real, canário e rollback foram executados em staging;
- aliases legados possuem migração e data de remoção.
