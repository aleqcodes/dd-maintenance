# Plano de refatoração 3 — hardening, Clean Code e operação segura

## Objetivo

Levar o DD Maintenance de uma base funcional, com confiabilidade inicial, para uma arquitetura:

- segura nas fronteiras administrativas, de restore e de transporte;
- composta por casos de uso pequenos e testáveis;
- com contratos tipados e invariantes explícitos;
- observável sem registrar segredos ou conteúdo sensível;
- validada em WordPress real, CI e staging;
- preparada para remover compatibilidade legada sem quebra silenciosa.

Este documento parte do estado deixado por `refactor2.md` e da revisão Clean Code da codebase atual. Não considera suficiente criar uma fachada que apenas delega para uma classe monolítica: uma extração só conta quando a responsabilidade e a lógica saem da implementação original.

## Estado atual auditado

### Evidências positivas

- `DD_Maintenance_File_Security` centraliza traversal, caminhos absolutos, symlinks, extração ZIP e cópia confinada.
- `DD_Maintenance_Session_Store` grava sessões com arquivo temporário, checksum, permissão `0600` e `rename()` atômico.
- O restore público usa token efêmero com hash SHA-256, expiração e `hash_equals()`.
- A observabilidade possui schema, correlation ID, whitelist de contexto e redaction recursivo.
- A Secret Key não é reidratada na estrutura principal da renderização administrativa.
- PHPUnit, PHPStan, PHPCS, sintaxe PHP e sintaxe JavaScript passam no ambiente local atual.
- Os testes locais cobrem traversal, symlink, checksum, redaction, adapters, contratos de sessão e fluxos legados.

### Problemas prioritários

| Prioridade | Problema | Evidência |
|---|---|---|
| P1 | Implementações monolíticas continuam concentrando domínio | `restore-implementation.php` tem 2.898 linhas; `settings-implementation.php`, 1.609; `backup.php`, 1.415; `s3.php`, 1.189 |
| P1 | CI não está versionada no branch atual | `.github/workflows/ci.yml` está local e não rastreada |
| P1 | Restore real, canário e rollback ainda não foram executados | `WP_CORE_DIR` ausente; staging não disponível |
| P2 | Fallback de URL depende de `HTTP_HOST` | `restore-implementation.php:438-456` e loader gerado em `:2818-2821` |
| P2 | Upload S3 assinado segue redirects | `s3.php:897-912`, `CURLOPT_FOLLOWLOCATION => true` |
| P2 | Contratos de resultado são arrays/objetos públicos mutáveis | `*-result.php` e `progress.php` usam propriedades públicas e cópia genérica |
| P2 | Repositório aceita configurações arbitrárias | `settings-repository.php:81-101` |
| P2 | Superglobais continuam espalhadas na implementação administrativa | `settings-implementation.php` usa diretamente `$_GET`, `$_POST` e `$_FILES` |
| P2 | PHPCS e PHPStan ainda formam gates limitados | PHPStan nível 3; PHPCS aplica apenas três grupos de regras |
| P3 | Aviso legacy aparece para todo administrador | `legacy-compatibility.php:155-172` não verifica uso efetivo |
| P3 | Não existe `README.md` raiz com runbook operacional | ausência de documentação de instalação, staging e rollback |

## Regras de execução

1. Uma mudança funcional deve ter uma responsabilidade principal.
2. Não misturar reformatagem global com extração ou correção comportamental.
3. Não alterar contratos públicos sem inventário de consumidores e plano de migração.
4. Não remover aliases, hooks, transients ou formatos de sessão antes do canário aprovado.
5. Não usar `HTTP_HOST` como fonte confiável de identidade do site.
6. Não seguir redirects em requests S3 assinados sem validar destino e refazer assinatura.
7. Nenhuma etapa pode declarar sucesso antes de persistir o checkpoint correspondente.
8. Cada etapa incremental deve ser idempotente ou falhar explicitamente.
9. Erros de I/O, banco, transporte, checksum e cleanup nunca podem virar sucesso silencioso.
10. Logs e eventos não podem conter senha, Secret Key, Access Key, token, cookie, Authorization ou SQL bruto.
11. Controllers cuidam de protocolo; casos de uso cuidam de negócio; adapters cuidam de WordPress, filesystem, banco e rede.
12. Uma fachada de compatibilidade não pode conter regras novas nem esconder falhas.
13. Cada fase termina com checkout executável e verificação específica.
14. Não considerar uma fase concluída porque o wrapper foi criado; medir redução real de responsabilidade e tamanho.
15. Mudanças destrutivas exigem backup verificável, rollback ensaiado e evidência anexada.

## Arquitetura alvo

```text
WordPress hooks / AJAX
        |
        v
Controllers autenticados
        |
        v
Requests normalizados + políticas
        |
        v
Casos de uso
  |       |       |
  v       v       v
Sessão  Domínio  Resultados
  |       |       |
  +-------+-------+
          |
          v
Ports/adapters
  - WordPress
  - filesystem
  - mysqli/wpdb
  - S3/HTTP
  - Elementor
```

### Responsabilidade alvo por módulo

| Módulo | Responsabilidade permitida |
|---|---|
| `DD_Maintenance` | composição, lifecycle, cron e políticas globais mínimas |
| `AdminController` | autenticação, nonce, request/response e redirect |
| `BackupUseCase` | orquestração do backup |
| `BackupDatabaseDumper` | dump incremental e resultado do banco |
| `BackupFileIndexer` | inventário de arquivos e fila |
| `BackupArchiveWriter` | criação, divisão e checksum de volumes |
| `RestoreUseCase` | orquestração do restore |
| `RestoreArchiveService` | validação, união e extração de volumes |
| `RestoreDatabaseImporter` | importação SQL e transição de estado |
| `RestoreFileCopier` | cópia segura e progresso de arquivos |
| `RestoreUrlMigrator` | Search & Replace serializado/JSON/URL |
| `S3Client` | protocolo, autenticação e operações S3 |
| `S3Transport` | transporte HTTP/cURL, timeout e resposta |
| `SettingsRepository` | schema, defaults, validação e persistência |
| `SessionStore` | estado atômico, checksum e lifecycle |
| `Observability` | eventos, redaction e persistência operacional |
| `ElementorAdapter` | compatibilidade isolada, reversível e checksumada |

---

## Fase 0 — baseline, inventário e controle da mudança

### Objetivo

Fixar uma fotografia reproduzível antes de novas extrações e separar fatos de hipóteses.

### Passos

1. Registrar branch, commit, versão do plugin, PHP e extensões.
2. Registrar todos os arquivos próprios auditados e suas linhas.
3. Inventariar consumidores de:
   - classes canônicas;
   - classes `Backuper*`;
   - hooks novos e legacy;
   - opções e transients;
   - arquivos wrapper;
   - `state.json` de backup e restore;
   - eventos JSONL.
4. Registrar tamanho das classes e métodos maiores que 100 linhas.
5. Registrar o conjunto de comandos oficiais em `composer.json`.
6. Definir quais arquivos são código próprio, teste, fixture ou terceiro.
7. Preservar artefatos locais do operador; não incluir `.phpunit.result.cache` nem workflow fora do escopo de publicação.
8. Criar uma matriz de comportamento antes de mover qualquer método.

### Entregáveis

- `refactor3.md` como plano vigente.
- inventário de contratos públicos;
- mapa de dependências;
- baseline de tamanho/complexidade;
- matriz de comandos e ambientes;
- lista de riscos conhecidos e responsáveis.

### Critérios de aceite

- Outra pessoa consegue reproduzir o baseline sem adivinhar comandos.
- Todo contrato removível tem consumidor conhecido ou classificação explícita.
- Nenhum arquivo de terceiro é confundido com código próprio.
- A baseline registra limitações de WordPress core e staging.

---

## Fase 1 — fronteiras de segurança e autorização

### Objetivo

Garantir que todas as entradas administrativas, de AJAX e de restore atravessem uma política única e testável.

### Passos

1. Centralizar cada ação em uma tabela de políticas:
   - capability exigida;
   - nonce/action;
   - método HTTP esperado;
   - se aceita sessão pública;
   - se é bloqueada por rollback;
   - formato de resposta.
2. Manter `DD_Maintenance_Admin_Request` como único ponto de autorização.
3. Fazer cada controller autenticado chamar a política antes do caso de uso.
4. Tornar `DD_Maintenance_Settings_Implementation` interna à composição, ou remover dela o registro de hooks.
5. Impedir que uma instanciação direta de implementação registre hooks sem autorização.
6. Separar claramente:
   - ações autenticadas de administrador;
   - continuação pública de restore com token;
   - chamadas internas de cron.
7. Para o restore público, aceitar somente modos explicitamente listados.
8. Validar formato, tamanho e expiração do token antes de carregar estado.
9. Avaliar rotação de token por etapa; caso não seja adotada, documentar o token como bearer de janela deslizante.
10. Adicionar rate limit ou mecanismo equivalente para continuação pública, se disponível no ambiente.
11. Não aceitar redirection de aba arbitrário; usar allowlist de abas.

### Critérios de aceite

- Cada `admin_post_*` rejeita capability ausente e nonce inválido.
- Cada ação destrutiva tem teste de autorização negativo.
- AJAX de backup exige usuário, capability e nonce.
- AJAX público só aceita os cinco modos de continuação documentados.
- `upload_restore_*` nunca autoriza continuação pública por reutilização de identificador.
- Rollback bloqueia início, continuação manual, AJAX e cron.
- Instanciar uma implementação interna não cria uma segunda superfície de hooks.

---

## Fase 2 — contratos de request, configuração e resultados

### Objetivo

Eliminar arrays ambíguos e concentrar invariantes em contratos pequenos.

### Passos

1. Criar requests normalizados para:
   - salvar configurações;
   - iniciar backup;
   - continuar etapa de backup;
   - iniciar upload de restore;
   - continuar restore;
   - apagar backup local;
   - apagar objeto S3;
   - baixar arquivo/log.
2. Fazer sanitização e validação na borda, sem misturar com regra de domínio.
3. Transformar `SettingsRepository` no dono do schema:
   - allowlist de chaves;
   - defaults;
   - booleans;
   - frequência;
   - horário;
   - retenção;
   - tamanho mínimo/máximo de volume;
   - endpoint e região.
4. Definir o tratamento do segredo:
   - não reexibir no HTML;
   - preservar valor anterior quando o campo vier vazio;
   - priorizar constante, ambiente e opção de forma documentada;
   - nunca copiar o segredo para logs ou resultados.
5. Tornar resultados imutáveis na prática, usando construtores/fábricas e propriedades privadas quando compatível com PHP 7.4.
6. Definir contratos separados para:
   - progresso;
   - resultado de backup;
   - resultado de restore;
   - upload remoto;
   - falha com cleanup.
7. Limitar `from_array()` à borda legacy e validar cada campo.
8. Substituir strings mágicas de etapas por constantes agrupadas por workflow.
9. Definir códigos de erro estáveis em uma tabela única.

### Critérios de aceite

- Nenhum caso de uso recebe `$_POST`, `$_GET` ou `$_FILES` diretamente.
- Configuração inválida é rejeitada no repositório/request, não durante renderização.
- Resultados não podem produzir `completed=true` sem dados mínimos de conclusão.
- `errors`, `warnings` e `log` possuem tipos e semântica documentados.
- Todos os passos de AJAX usam constantes ou um registry de etapas.
- Compatibilidade legacy converte dados uma única vez na borda.

---

## Fase 3 — persistência, sessões e idempotência

### Objetivo

Tornar checkpoints e retomadas determinísticos sob interrupção, concorrência e falha de disco.

### Passos

1. Separar `SessionStore` de `SessionPolicy`.
2. Validar schema completo ao carregar estado de backup e restore.
3. Manter leitura de sessões antigas somente enquanto durar a janela de migração.
4. Adicionar versão explícita e migração de schema quando necessário.
5. Usar lock por sessão para impedir duas continuações simultâneas.
6. Definir transições válidas:
   - `created -> running -> completed`;
   - `running -> failed`;
   - `running -> cleanup_pending`;
   - `cleanup_pending -> cleaned`.
7. Persistir estado somente depois de confirmar a escrita associada.
8. Fazer repetição de etapa concluída retornar estado salvo sem recopiá-la.
9. Validar tamanho, checksum e existência de cada volume antes de avançar.
10. Registrar `started_at`, `updated_at`, `finished_at` e `last_step`.
11. Diferenciar falha operacional, sessão expirada, sessão corrompida e cleanup incompleto.
12. Criar garbage collector de sessões abandonadas com idade e lock respeitados.

### Critérios de aceite

- Duas requisições concorrentes não processam o mesmo checkpoint simultaneamente.
- Interrupção imediatamente após escrita não duplica arquivo nem query.
- Estado corrompido não é interpretado como sessão válida.
- Falha de `rename`, checksum ou escrita mantém o erro original e informa cleanup.
- Sessões expiradas não podem ser reativadas por token antigo.
- O garbage collector não remove sessão em uso.

---

## Fase 4 — decomposição real do backup

### Objetivo

Reduzir `class-dd-maintenance-backup.php` sem alterar o fluxo público.

### Ordem

1. Extrair `BackupDatabaseDumper`.
2. Extrair `BackupFileIndexer`.
3. Extrair `BackupManifestStore`.
4. Extrair `BackupArchiveWriter`.
5. Extrair `LargeFileChunker`.
6. Extrair `BackupCleanupService`.
7. Manter `DD_Maintenance_Backup` como fachada temporária somente até todos os consumidores migrarem.

### Passos técnicos

1. Injetar filesystem, relógio, session store e adapter de banco.
2. Remover acesso direto a `new SettingsRepository()` dentro dos métodos.
3. Validar identificadores de tabelas em uma função única.
4. Separar SQL de schema, paginação de linhas e serialização SQL.
5. Separar indexação de diretórios, filtros e persistência JSONL.
6. Fazer o writer conhecer apenas manifestos normalizados.
7. Fazer o cleanup operar por artefato declarado na sessão.
8. Garantir que cada componente tenha uma saída explícita e testável.
9. Remover compatibilidade interna quando todos os chamadores usarem o workflow.

### Critérios de aceite

- A classe principal não contém dump SQL, traversal de filesystem e escrita ZIP simultaneamente.
- Cada etapa mantém o mesmo contrato de progresso.
- Backup manual, AJAX e cron usam o mesmo caso de uso.
- Teste de banco grande não depende de número artificial de tabelas.
- Teste de arquivo grande cobre retomada no meio do chunk.
- Falhas de manifesto e volume não avançam o estado.

---

## Fase 5 — decomposição real do restore

### Objetivo

Remover a concentração de banco, arquivos, ZIP, sessões, URLs e Elementor em `restore-implementation.php`.

### Ordem

1. `RestoreSessionService` com lógica própria de lifecycle.
2. `RestoreArchiveService` para validar, unir e extrair volumes.
3. `RestoreFileCopier` para cópia segura e progresso.
4. `RestoreDatabaseImporter` para importação incremental.
5. `RestoreUrlMigrator` para strings serializadas, JSON e URLs.
6. `RestorePostProcessor` para opções, prefixo, caches e estados finais.
7. `ElementorRestoreAdapter` para regras específicas do Elementor.
8. `RestoreCleanupService` para artefatos temporários.

### Passos técnicos

1. Transferir métodos completos, não apenas criar delegadores.
2. Definir dependências no construtor, sem `new` espalhado em métodos.
3. Proibir que a camada de banco escreva diretamente no filesystem.
4. Proibir que a camada de arquivos altere opções WordPress.
5. Manter `DD_Maintenance_Restore` apenas como compatibilidade durante a migração.
6. Isolar criação de MU loader em uma política explícita, checksumada e reversível.
7. Tornar o comportamento de Elementor opt-in e observável.
8. Remover duplicação entre restore síncrono, AJAX e cron.
9. Validar a raiz de cada operação antes de qualquer escrita.

### Critérios de aceite

- `Restore_Implementation` cai substancialmente de tamanho e não é mais o dono de todas as responsabilidades.
- O restore completo pode ser testado com doubles de cada dependência.
- Upload, arquivo local, AJAX e cron chamam o mesmo caso de uso.
- A fachada compatível não contém lógica de domínio nova.
- O MU loader pode ser instalado, verificado, removido e revertido com eventos distintos.
- Uma falha de Elementor não é confundida com sucesso do banco ou dos arquivos.

---

## Fase 6 — banco, prefixo e migração de URLs

### Objetivo

Proteger a integridade do banco restaurado e tornar pós-processamento verificável.

### Passos

1. Separar parser de dump, executor de query e finalizador de banco.
2. Definir transação/commit conforme a capacidade real do adapter.
3. Garantir restauração de `FOREIGN_KEY_CHECKS` em bloco de finalização mesmo após erro.
4. Validar e registrar retorno de toda query obrigatória.
5. Derivar prefixo somente de identificadores descobertos e validados.
6. Validar prefixo antes de escrever `wp-config.php`.
7. Atualizar `siteurl` e `home` somente com URLs confiáveis e validadas.
8. Fazer Search & Replace em páginas, postmeta, options, termmeta e usermeta por paginação.
9. Preservar serialização PHP e JSON sem alterar tamanhos de forma inválida.
10. Separar warnings recuperáveis de falhas fatais.
11. Não executar pós-processamento Elementor quando a base mínima não estiver íntegra.
12. Produzir estatísticas finais:
    - queries processadas;
    - tabelas;
    - erros;
    - warnings;
    - linhas alteradas;
    - prefixo original e final, sem dados sensíveis.

### Critérios de aceite

- Prefixo inválido nunca chega a SQL ou `wp-config.php`.
- Falha em `siteurl`, `home` ou prefixo termina como falha classificada.
- Search & Replace repetido é idempotente.
- Query obrigatória falha com código estável e amostra redigida.
- O número de queries e erros aparece no resultado e no evento.
- Banco parcialmente importado nunca é reportado como restore íntegro.

---

## Fase 7 — cliente S3 e transporte seguro

### Objetivo

Separar protocolo S3, assinatura, endpoint e transporte HTTP, reduzindo risco operacional e de exposição de credenciais.

### Passos

1. Extrair `S3Endpoint` para parsing, normalização e allowlist de esquemas.
2. Extrair `S3Signer` para canonical request e SigV4.
3. Extrair `S3Transport` para cURL e `wp_remote_request()`.
4. Extrair `S3ResponseParser` para XML, status, request ID e erros.
5. Desabilitar redirects no upload assinado por padrão.
6. Se redirects forem indispensáveis:
   - aceitar somente host permitido;
   - rejeitar mudança de esquema insegura;
   - refazer assinatura no destino;
   - não carregar Authorization indiscriminadamente.
7. Rejeitar endpoint HTTP para produção, permitindo exceção explícita apenas para ambiente local/teste.
8. Limitar corpo de resposta, mensagem de erro e quantidade de objetos processados.
9. Não incluir headers assinados nos logs.
10. Definir timeout, low-speed timeout, retry e backoff em uma política única.
11. Impedir retry de operações não idempotentes sem chave de idempotência.
12. Validar que deleção remota esteja confinada ao prefixo/identificador solicitado.

### Critérios de aceite

- O transporte cURL e o WordPress podem ser testados separadamente.
- Nenhum upload assinado segue redirect não validado.
- Erro S3 expõe somente código amigável, status e request ID.
- Respostas grandes não consomem memória indefinidamente.
- Retry é aplicado somente aos códigos classificados como transitórios.
- Testes cobrem endpoint customizado, redirect, timeout, retry e resposta XML inválida.

---

## Fase 8 — camada administrativa e renderização

### Objetivo

Reduzir a implementação administrativa e separar protocolo, aplicação e apresentação.

### Passos

1. Criar controllers por grupo:
   - settings;
   - backup;
   - restore;
   - logs/downloads;
   - S3;
   - wp-config;
   - atualizações.
2. Fazer cada controller seguir o fluxo:
   - autorizar;
   - construir request;
   - chamar caso de uso;
   - converter resultado;
   - definir notice/JSON/redirect.
3. Remover leitura direta de superglobais dos casos de uso.
4. Criar view models para as abas.
5. Fazer o renderer receber dados já calculados.
6. Remover `new SettingsRepository()` e `new DD_Maintenance_S3()` de métodos de renderização.
7. Separar consultas de backups, logs e status de configuração de HTML.
8. Manter assets JS/CSS versionados e sem lógica de domínio.
9. Centralizar allowlist de tabs, actions e modos AJAX.
10. Garantir que respostas de erro para UI sejam redigidas e escapadas.
11. Remover `DD_Maintenance_Admin_Handler` se não houver comportamento próprio após a migração.

### Critérios de aceite

- Nenhum método de renderização executa Search & Replace, restore, upload ou atualização.
- Cada controller tem fluxo curto e previsível.
- As abas preservam o HTML funcional e os hooks públicos existentes.
- A Secret Key, token e SQL nunca aparecem no HTML ou resposta de UI.
- Os testes de renderização verificam comportamento observável, não detalhes de wiring.
- A classe administrativa principal deixa de ser um monólito.

---

## Fase 9 — Elementor, código gerado e filesystem externo

### Objetivo

Isolar o comportamento de compatibilidade que altera arquivos de terceiros.

### Passos

1. Manter a compatibilidade Elementor fora dos casos de uso centrais.
2. Definir explicitamente quando o patch é permitido.
3. Validar caminho real, plugin esperado, checksum e fingerprint antes de modificar arquivo.
4. Criar backup atômico antes do patch.
5. Registrar versão do patch, checksum anterior e posterior.
6. Gerar MU plugin a partir de template validado, não por concatenação dispersa.
7. Não usar host da requisição no código gerado.
8. Garantir idempotência de instalação e remoção.
9. Implementar rollback quando o arquivo de destino mudar depois do patch.
10. Emitir evento para instalado, já instalado, rejeitado, removido e falha.
11. Documentar que modificar arquivo de terceiro exige aprovação operacional.

### Critérios de aceite

- Caminho fora da allowlist é rejeitado.
- Arquivo com fingerprint desconhecido não é alterado.
- Patch parcial não deixa arquivo truncado.
- Undo não sobrescreve alteração de terceiro feita depois sem sinalizar conflito.
- Loader não usa fallback de `HTTP_HOST`.
- Compatibilidade Elementor pode ser desligada sem quebrar backup/restore básico.

---

## Fase 10 — observabilidade, privacidade e diagnóstico

### Objetivo

Produzir diagnóstico operacional útil sem transformar logs em cópia de requests ou credenciais.

### Passos

1. Definir catálogo de operações e eventos.
2. Padronizar `correlation_id`, `session_id`, `step`, `status` e `failure_code`.
3. Garantir evento de início, progresso, conclusão e falha para backup, restore, cron e S3.
4. Registrar falha de cleanup separadamente da falha principal.
5. Garantir que falha de persistência de evento seja visível sem interromper indevidamente a operação.
6. Aplicar allowlist de contexto e redaction recursivo.
7. Truncar strings, IDs de request e mensagens externas.
8. Nunca registrar:
   - `s3_secret_key`;
   - Authorization;
   - token de restore;
   - cookie;
   - SQL completo;
   - conteúdo de post/meta;
   - payload arbitrário de request.
9. Definir retenção de logs e eventos.
10. Criar um formato de resumo operacional para administradores.
11. Fazer a UI distinguir sucesso, sucesso com avisos, falha e falha de persistência.

### Critérios de aceite

- Todo evento crítico contém etapa e código de falha.
- Eventos redigidos passam por testes com estruturas aninhadas.
- Nenhum erro externo retorna body ilimitado à UI.
- É possível diagnosticar uma sessão usando apenas IDs, etapas e contadores.
- Sessões normais terminam com evento final.
- Falha de escrita de log não mascara falha do backup/restore.

---

## Fase 11 — testes, qualidade estática e CI

### Objetivo

Transformar qualidade em regressão automática real, não apenas em comandos locais verdes.

### Estratégia de testes

#### Unitários

Cobrir regras puras e boundaries:

- requests e validação;
- configuração e defaults;
- resultados e invariantes;
- paths e symlinks;
- checksum e sessões;
- parser S3;
- signer S3;
- redaction;
- classificação de erros;
- transições de estado.

#### Integração com doubles

Cobrir:

- `wpdb` e `mysqli` com erros;
- filesystem com escrita parcial;
- cURL e `wp_remote_request()` separadamente;
- retry e timeout;
- falha de rename/unlink;
- opções e transients;
- controllers e nonce/capability.

#### WordPress real

Executar contra cada versão suportada declarada:

- carregamento do plugin;
- registro de hooks;
- admin-post;
- AJAX autenticado;
- AJAX de continuação pública;
- cron;
- atualização de opções;
- `WP_Filesystem`;
- restore mínimo em banco temporário.

#### Smoke

Manter apenas cenários que precisam de processo separado:

- pipeline completo de backup;
- upload dividido;
- restore em múltiplas requisições;
- patch Elementor;
- falha que gera artefato.

### Passos de qualidade

1. Versionar workflow CI quando ele entrar no escopo do branch.
2. Executar matriz PHP `7.4`, `8.0` e `8.2`, se ainda forem versões suportadas.
3. Executar matriz WordPress documentada.
4. Falhar CI quando faltar extensão obrigatória ou assertions.
5. Validar sintaxe de todos os PHP rastreados.
6. Subir PHPStan gradualmente de nível 3 para 5 e depois avaliar nível 6+.
7. Reduzir `ignoreErrors` e manter `reportUnmatchedIgnoredErrors: true`.
8. Ampliar PHPCS além de apenas escaping, strict comparisons e `StrictInArray`.
9. Separar regras de legado das regras de código novo.
10. Publicar logs de PHPUnit, PHPStan, PHPCS e artefatos de restore.
11. Tornar o check de integração obrigatório na proteção do branch.
12. Medir cobertura por comportamento crítico, não apenas percentual global.

### Critérios de aceite

- CI executa em ambiente limpo, sem depender de estado local.
- Nenhum teste de produção depende de funções globais redefinidas para esconder o transporte real.
- PHPStan e PHPCS detectam regressões novas.
- O teste de WordPress core não é silenciosamente pulado na matriz de CI.
- Falhas de smoke preservam artefato com etapa, stdout, stderr e código.
- Cada finding P1/P2 corrigido tem regressão comportamental ou justificativa técnica.

---

## Fase 12 — compatibilidade legacy e documentação

### Objetivo

Preparar migração controlada e tornar a operação compreensível para usuários e mantenedores.

### Compatibilidade

1. Manter aliases `Backuper*` durante a janela definida.
2. Medir uso agregado sem dados de operador.
3. Mostrar aviso somente quando houver uso efetivo de contrato legacy, salvo decisão explícita de aviso global.
4. Documentar versão alvo de remoção `3.0.0`.
5. Publicar tabela de migração:
   - classe antiga -> classe nova;
   - hook antigo -> hook novo;
   - opção antiga -> opção nova;
   - transient antigo -> transient novo;
   - wrapper antigo -> carregador principal.
6. Preservar sessões antigas até a expiração completa.
7. Não remover aliases antes do canário e do inventário externo.
8. Depois da remoção, deixar erro explícito e acionável, não classe silenciosamente inexistente.

### Documentação

Criar ou atualizar:

- `README.md` com instalação e requisitos;
- configuração S3 e variáveis de ambiente;
- permissões de filesystem;
- backup e restore;
- limites de volume;
- cron;
- Elementor;
- troubleshooting;
- segurança e tratamento de credenciais;
- staging, canário e rollback;
- compatibilidade e calendário de remoção.

### Critérios de aceite

- Um operador novo consegue instalar e executar um backup sem consultar código.
- O procedimento de rollback está documentado e já foi ensaiado.
- Cada contrato legacy possui substituto documentado.
- O aviso legacy não polui páginas quando não há uso conhecido.
- A documentação não promete integração que não foi verificada.

---

## Fase 13 — staging, canário e rollout

### Objetivo

Validar o comportamento real antes de produção e criar evidência de rollback executável.

### Matriz obrigatória

Executar em staging com backup real:

1. site pequeno;
2. banco grande;
3. arquivo maior que um volume;
4. múltiplos volumes;
5. Elementor ativo;
6. prefixo de banco diferente;
7. URLs de origem e destino diferentes;
8. interrupção durante extração;
9. interrupção durante importação SQL;
10. interrupção durante cópia de arquivos;
11. disco insuficiente;
12. falha de rede S3;
13. timeout e retry;
14. checksum inválido;
15. sessão concorrente;
16. restore público após troca do banco;
17. modo rollback ativo;
18. falha de cleanup.

### Evidência por cenário

Registrar:

- commit e versão do plugin;
- PHP e WordPress;
- extensões;
- `correlation_id`;
- `session_id`;
- etapa interrompida;
- `failure_code`;
- eventos JSONL;
- log textual;
- checksums dos volumes;
- quantidade de arquivos;
- tabelas, queries, erros e warnings;
- URLs finais;
- configurações finais;
- estado do Elementor;
- resultado de cleanup;
- tempo total;
- resultado após repetição.

### Rollout

1. Restaurar backup real em staging.
2. Interromper cada etapa crítica e repetir.
3. Confirmar retomada sem duplicação.
4. Ativar `DD_MAINTENANCE_DISABLE_OPERATIONS=true`.
5. Confirmar rejeição de novas operações e registro do evento.
6. Executar rollback documentado.
7. Validar site restaurado após rollback.
8. Fazer canário em instalação não crítica.
9. Manter versão anterior pronta para retorno.
10. Monitorar por pelo menos 24 horas:
    - falhas;
    - warnings;
    - sessões sem evento final;
    - divergências de checksum;
    - erros S3;
    - falhas de cleanup;
    - uso de aliases legacy.
11. Expandir rollout somente sem P0/P1 aberto.

### Critérios de aceite

- Restore real restaura arquivos, banco, URLs e configurações esperadas.
- Backup real pode ser baixado, verificado e restaurado.
- Interrupções são retomáveis ou falham deterministicamente.
- Rollback foi executado, não apenas documentado.
- Nenhuma falha crítica fica sem classificação.
- Compatibilidade legacy só é removida após canário aprovado.

---

## Matriz de riscos

| Risco | Probabilidade | Impacto | Mitigação | Gate |
|---|---:|---:|---|---|
| Extração altera contrato público | média | alto | adapters temporários, testes de contrato e migração de todos os callers | Fases 2-5 |
| Restore duplica arquivos/queries | média | crítico | lock, estado idempotente e regressão de interrupção | Fase 3 |
| Redirect S3 quebra assinatura ou expõe header | média | alto | redirects desabilitados/allowlist/re-sign | Fase 7 |
| Host inválido corrompe URLs | baixa/média | alto | URL confiável e validação de host | Fase 1/6 |
| Falha de disco mascara operação | média | alto | escrita completa, checkpoint posterior e cleanup explícito | Fase 3/4/5 |
| Patch Elementor quebra plugin de terceiro | média | alto | checksum, backup atômico, fingerprint e undo | Fase 9 |
| CI passa sem WordPress real | alta | alto | matriz real sem skip silencioso | Fase 11 |
| Alias removido com consumidor externo | média | alto | telemetria agregada, documentação e canário | Fase 12/13 |
| Logs expõem dado sensível | baixa/média | alto | whitelist, redaction e limite de body | Fase 7/10 |
| Refactor cria wrappers sem reduzir complexidade | alta | médio | medir linhas, dependências e responsabilidades removidas | Todas |

## Dependências entre fases

```text
Fase 0
  |
  +--> Fase 1 --> Fase 2 --> Fase 3
  |                  |          |
  |                  +--> Fase 8
  |                             |
  +--> Fase 4 --> Fase 5 --> Fase 6
                 |             |
                 +----------> Fase 9

Fase 7 depende de Fase 2 e pode evoluir em paralelo após os contratos.
Fase 10 depende dos contratos das Fases 2 e 3.
Fase 11 consolida todas as fases técnicas.
Fase 12 depende do inventário da Fase 0 e dos contratos estabilizados.
Fase 13 depende de 1 a 12 e de ambiente externo disponível.
```

## Definição de pronto do Refactor 3

O plano só está concluído quando todos os itens forem verdadeiros:

- os monólitos de backup, restore, S3 e administração foram reduzidos por extrações reais;
- as fachadas restantes contêm apenas composição ou compatibilidade comprovada;
- toda entrada administrativa usa request normalizado e política única;
- resultados e configurações possuem invariantes explícitos;
- sessões possuem lock, checksum, versão e transições válidas;
- backup e restore são idempotentes nas interrupções testadas;
- redirects S3 e fallback de host estão endurecidos;
- erros externos são limitados, redigidos e classificáveis;
- eventos permitem diagnosticar falhas sem dados sensíveis;
- testes unitários, integração com doubles, WordPress real e smoke têm fronteiras claras;
- PHPStan e PHPCS são gates úteis e progressivamente mais rigorosos;
- CI roda em ambiente limpo e não depende de skips silenciosos;
- README e runbook de operação existem;
- aliases legacy têm inventário, substituto, aviso e data de remoção;
- restore real, canário e rollback foram executados em staging;
- nenhum P0 ou P1 permanece aberto no momento do rollout.

## Sequência recomendada de PRs

1. **PR 1:** baseline, contratos e política de autorização.
2. **PR 2:** requests, resultados, configuração e constantes de etapas.
3. **PR 3:** locks, estados, idempotência e cleanup.
4. **PR 4:** decomposição do backup.
5. **PR 5:** decomposição do restore.
6. **PR 6:** banco, prefixo e Search & Replace.
7. **PR 7:** S3 endpoint, signer, transport e erros.
8. **PR 8:** controllers administrativos e view models.
9. **PR 9:** Elementor, MU loader e rollback de filesystem externo.
10. **PR 10:** observabilidade, redaction e retenção.
11. **PR 11:** testes reais, PHPStan, PHPCS e CI.
12. **PR 12:** documentação, compatibilidade e preparação de remoção.
13. **PR 13:** staging, canário, rollback e rollout.

Cada PR deve manter o checkout executável, atualizar seus testes comportamentais e declarar explicitamente quais critérios ainda dependem de staging.

## Registro de execução — Fases 0 e 1

### Fase 0 — baseline executável

Baseline capturado em `refactor/fase-0`, commit `c0bdb97`, sem alterar os artefatos locais não rastreados `.github/` e `.phpunit.result.cache`.

Comandos executados antes das mudanças:

- `composer validate --strict`: manifesto válido.
- `composer test`: 39 testes, 124 asserções, 1 teste ignorado.
- `composer phpstan`: 52 arquivos analisados, 0 erros.
- `composer phpcs`: concluído sem violações.
- `node --check assets/js/dd-maintenance-admin.js`: sintaxe válida.
- verificação de sintaxe de todos os PHP rastreados: sem erros.

Inventário operacional:

- composição canônica: `DD_Maintenance` → `DD_Maintenance_Settings` → workflows, handlers e controllers;
- superfícies críticas: `DD_Maintenance_Backup_Implementation` (1.415 linhas), `DD_Maintenance_Restore_Implementation` (2.898), `DD_Maintenance_S3` (1.189), `DD_Maintenance_Settings_Implementation` (1.609) e `DD_Maintenance_Admin_Page_Renderer` (1.173);
- compatibilidade legacy permanece concentrada nos controllers e em `DD_Maintenance_Legacy_Compatibility`;
- estado de backup/restore usa `DD_Maintenance_Session_Store`, com checksum e proteção de caminho já cobertos por regressões;
- autorização administrativa existente está centralizada em `DD_Maintenance_Admin_Request`, mas o registro direto de hooks da implementação ainda criava uma superfície alternativa;
- não há `README.md` na raiz;
- CI existe apenas como arquivo local não rastreado e não foi promovido neste ciclo.

Limitações explícitas do baseline:

- não há `WP_CORE_DIR` disponível nesta máquina;
- não há staging WordPress real nem credenciais S3 para executar restore/upload contra serviços externos;
- validação de WordPress nesta fase usa os doubles e scripts existentes do projeto;
- a execução de staging, canário e rollback real permanece bloqueada até a Fase 13.

### Fase 1 — hardening inicial

Objetivos executados nesta fase: remover o registro implícito de hooks da implementação interna, preservar autorização em todas as entradas administrativas, rejeitar fallback de host controlado pela requisição e impedir redirects automáticos no transporte S3.

Mudanças aplicadas:

- `DD_Maintenance_Settings_Implementation` deixou de aceitar o modo de registro direto; seu construtor apenas compõe dependências. A fachada `DD_Maintenance_Settings` permanece dona dos hooks e conecta apenas handlers com autorização.
- `DD_Maintenance_Admin_Request::authorize()` e `authorize_ajax()` foram exercitados por regressões de capacidade, sessão e nonce; o handler de backup mantém a rejeição antes do caso de uso.
- sessões de restore agora aceitam somente `http`/`https` persistidos, sem credenciais, query ou fragmento; valores inválidos resultam em URL vazia e nunca usam `HTTP_HOST`;
- o MU loader gerado retorna o valor persistido de `siteurl`/`home` sem derivar host da requisição;
- PUT S3 via cURL usa `CURLOPT_FOLLOWLOCATION => false`; o transporte HTTP alternativo usa `'redirection' => 0`.

Verificação após as mudanças:

- `vendor/bin/phpunit tests/Integration/WordPressBoundaryTest.php`: 15 testes, 45 asserções, OK;
- `composer test`: 44 testes, 141 asserções, 1 teste ignorado, OK;
- `composer phpstan`: 52 arquivos, 0 erros;
- `composer phpcs`: OK;
- `composer validate --strict`: manifesto válido;
- `node --check assets/js/dd-maintenance-admin.js`: OK;
- smoke real contra endpoint HTTP local que respondeu `302`: resultado `s3_upload`, sem requisição seguida (`not-followed`).

Estado de saída: Fases 0 e 1 executadas. Permanecem fora deste ciclo a decomposição dos monólitos, restore contra WordPress real, staging/canário/rollback e promoção do CI local não rastreado.

Complemento de fronteira:

- `DD_Maintenance_Admin_Request` agora expõe a tabela única de políticas (capability, nonce, método, acesso público, bloqueio por rollback e formato de resposta); admin-post e AJAX passam por seus métodos de autorização;
- a allowlist de abas (`general`, `config`, `s3`, `cron`, `restore`, `logs`) também normaliza o alias legado `backups`; valores arbitrários caem em `general`;
- os cinco modos públicos de continuação permanecem explicitamente allowlisted e exigem sessão `rst_` com bearer token validado por hash e expiração;
- a rotação por etapa não foi adotada: o token continua bearer de janela deslizante de duas horas, decisão registrada para migração posterior;
- não há rate limiter compartilhado disponível nos doubles nem no runtime local; o controle aplicável nesta fase permanece token opaco, hash persistido, expiração deslizante e rejeição de identificadores de upload.

## Registro de execução — Fases 2 e 3

### Fase 2 — contratos administrativos e resultados

Executado:

- requests administrativos tipados (`Settings`, `Backup`, `Restore` e `Artifact`) capturam `POST`, `GET` e `FILES` uma vez, normalizam texto/chaves/flags/inteiros e removem o acesso a superglobais dos casos de uso;
- `DD_Maintenance_Settings_Repository` passou a ser a borda única de schema: allowlist, defaults, booleanos, frequência, horário, retenção, região, endpoint e limites de volumes;
- segredos preservam o valor persistido quando o campo recebido está vazio; nenhum segredo é incluído em resultados/logs;
- resultados de progresso, backup, restore, upload S3 e cleanup usam propriedades privadas, factories e getters; conclusão de backup exige artefato;
- conversões `from_array()` ficaram concentradas nos adaptadores de saída/compatibilidade;
- etapas de backup/restore e códigos de erro de sessão agora possuem registros centrais.

### Fase 3 — sessões, concorrência e retomada

Executado:

- `DD_Maintenance_Session_Policy` foi separado do armazenamento e define schema `2`, migração, validação, expiração e transições;
- `DD_Maintenance_Session_Store` usa escrita atômica, checksum, lock exclusivo por diretório, API de transição e garbage collector que ignora locks ocupados;
- sessões de backup/restore persistem `created_at`, `started_at`, `updated_at`, `finished_at`, `last_step`, `status` e tipo;
- estados antigos sem schema continuam legíveis durante a janela de migração, enquanto versões futuras, checksum inválido e schema incompleto são rejeitados;
- etapas já concluídas liberam o lock e retornam resultado idempotente; volumes são verificados por existência, tamanho e SHA-256 antes da extração;
- o token de restore libera locks também em rejeições de token, evitando bloquear a próxima requisição;
- contratos de limpeza, falhas, expiração e corrupção possuem códigos estáveis.

Verificação:

- `composer test`: 49 testes, 166 asserções, 1 teste ignorado, OK;
- `composer phpstan`: 57 arquivos analisados, 0 erros;
- `composer phpcs`: OK;
- `composer test:unit`: 27 testes, 105 asserções, OK;
- `composer test:integration`: 17 testes, 48 asserções, 1 teste ignorado, OK;
- regressões de requests, DTOs, checksum, lock, transições e migração adicionadas em `tests/Unit/RequestAndSessionContractsTest.php`.

Limitações mantidas: não houve restore/upload contra WordPress real ou S3 real; essas verificações continuam dependentes de staging/canário da Fase 13.

## Registro de execução — Fases 4 e 5

### Fase 4 — decomposição do backup

Executado:

- `DD_Maintenance_Backup` agora compõe serviços explícitos para dump SQL, indexação, escrita de volumes, finalização e cleanup;
- os pontos públicos de etapa preservam os contratos existentes e encaminham para componentes com validação de `session_id`;
- o repositório de configurações e o `SessionStore` são dependências do backup, evitando construção repetida durante as etapas;
- os métodos `legacy_*` mantêm compatibilidade controlada para o núcleo existente enquanto os consumidores externos passam pelas fachadas de serviço;
- checkpoints, retomada, idempotência e cleanup continuam usando o mesmo estado persistido.

### Fase 5 — decomposição do restore

Executado:

- criada a orquestração `DD_Maintenance_Restore_Archive_Service` para volumes, banco, arquivos e finalização;
- criados componentes explícitos para importer de banco, copier de arquivos, finalização e cleanup;
- `DD_Maintenance_Restore` encaminha as operações públicas para os componentes, mantendo `DD_Maintenance_Restore_Session_Service` como dono de sessão e autorização;
- upload, arquivo local, restore síncrono e continuação AJAX preservam a mesma implementação de lifecycle;
- falhas continuam acionando cleanup e retornando códigos existentes; token, checksum, raiz segura e progressos permanecem no fluxo compartilhado.

Verificação:

- `composer test`: 52 testes, 172 asserções, 1 teste ignorado, OK;
- `composer test:unit`: 30 testes, 114 asserções, OK;
- `composer phpstan`: 67 arquivos analisados, 0 erros;
- `composer phpcs`: OK;
- regressões de contratos de decomposição adicionadas em `tests/Unit/DecompositionContractsTest.php`;
- backup batch, restore de volume grande, retomada SQL, cópia idempotente, token e cleanup existentes continuam passando.

Limitação explícita: os métodos `legacy_*` permanecem no núcleo como compatibilidade interna; a remoção física deles fica condicionada à eliminação dos consumidores históricos fora da suíte atual.

## Registro de execução — Fases 6 e 7

### Fase 6 — banco de dados e migração de URLs

Executado:

- `DD_Maintenance_Restore_Sql_Parser` separa leitura incremental, comentários, aspas, semicolons em strings, contagem de tabelas e comandos ignorados (`CREATE/DROP DATABASE` e `USE`);
- `DD_Maintenance_Restore_Query_Executor` concentra execução, erros obrigatórios e amostras redigidas do adaptador de banco;
- `DD_Maintenance_Restore_Database_Finalizer` centraliza cleanup dependente do driver, descoberta de `options` e validação de prefixos antes de SQL/configuração;
- o restore SQL síncrono agora usa parser, executor e `try/finally` para fechar o arquivo; a continuação progressiva restaura `FOREIGN_KEY_CHECKS` nos caminhos de erro;
- `DD_Maintenance_Restore_Url_Migrator` explicita a fronteira de substituição recursiva e preserva serialização PHP/JSON; o pós-processamento só ocorre após a finalização mínima da conexão;
- estatísticas síncronas preservam queries, tabelas, comandos ignorados, erros, amostras redigidas, warnings e linhas alteradas; `siteurl`/`home` só são escritos quando URLs HTTP(S) confiáveis são encontradas.

### Fase 7 — cliente S3 e transporte

Executado:

- `DD_Maintenance_S3_Endpoint` normaliza endpoints, host/porta e URI de objeto;
- `DD_Maintenance_S3_Signer` encapsula Signature V4 sem expor a Secret Key;
- `DD_Maintenance_S3_Transport` concentra requests HTTP sem redirects e preserva o double legado para upload;
- `DD_Maintenance_S3_Response_Parser` limita corpos, extrai códigos/request IDs e redige credenciais em erros;
- `DD_Maintenance_S3_Retry_Policy` restringe retry a falhas transitórias de operações idempotentes;
- deleção remota rejeita identificadores/chaves com traversal, caracteres de controle ou fora do prefixo do site;
- `DD_Maintenance_S3` compõe as novas fronteiras mantendo os contratos públicos e a compatibilidade dos transportes existentes.

Verificação:

- `composer test`: 59 testes, 190 asserções, 1 teste ignorado, OK;
- `composer test:unit`: 37 testes, 132 asserções, OK;
- `composer phpstan`: 76 arquivos analisados, 0 erros;
- `composer phpcs`: OK;
- `php tests/test-s3-remote-delete.php`: smoke de listagem, agrupamento, deleção, transporte de upload e retry, OK;
- regressões adicionadas em `tests/Unit/RestoreAndS3DecompositionTest.php`.

Limitação explícita: não houve conexão contra banco WordPress ou S3 real; a suíte usa doubles existentes e o smoke local de transporte.

## Registro de execução — Fases 8 e 9

### Fase 8 — controllers administrativos e fronteira de apresentação

Executado:

- os handlers administrativos existentes foram formalizados por responsabilidade para settings, backup, restore, downloads/logs, S3, `wp-config` e atualizações, mantendo os controllers de ações como única entrada de hooks WordPress;
- `DD_Maintenance_Admin_Page_Data` passou a calcular settings, status de configuração, backups locais/remotos, cron, logs, S3 e limites de upload antes da renderização;
- `DD_Maintenance_Admin_Page_Renderer` deixou de consultar repositórios, transients, cron, backups e S3 ou construir dependências de domínio durante a montagem do HTML;
- segredos S3 são removidos do view model, respostas administrativas continuam escapadas/redigidas e a política declarativa de capacidades, nonces, métodos e respostas permanece centralizada em `DD_Maintenance_Admin_Request`;
- o `DD_Maintenance_Admin_Handler` residual foi removido; `DD_Maintenance_Settings` é agora o alvo do controller de ações e delega para os handlers especializados.

### Fase 9 — compatibilidade Elementor controlada

Executado:

- patch Elementor exige decisão explícita, aceita somente os caminhos exatos `elementor`/`pro-elements` sob os diretórios de plugins autorizados e rejeita fingerprint desconhecido sem modificar o arquivo;
- backup original, checksum antes/depois, fingerprint e versão do patch são persistidos; escrita e cópia usam arquivos temporários e rename atômico;
- o loader MU é gerado por template dedicado, sem `HTTP_HOST`, com criação e remoção idempotentes;
- o undo verifica o checksum pós-patch e retorna `undo_conflict` diante de alteração externa, sem sobrescrever trabalho de terceiros;
- decisões e resultados de compatibilidade emitem eventos operacionais sem caminhos ou conteúdo de arquivos, além do histórico local para auditoria;
- a aprovação operacional é explícita no fluxo `apply_restore_decision`; compatibilidade desabilitada não instala shield nem altera o restore básico.

Verificação:

- `composer test`: 59 testes, 192 asserções, 1 teste ignorado, OK;
- `composer phpstan`: 77 arquivos analisados, 0 erros;
- `composer phpcs`: OK;
- `php tests/test-render-page.php`: todas as abas administrativas renderizadas com sucesso;
- `php tests/test-elementor-patch.php`: decisão, fingerprint, allowlist, backup, idempotência, conflito de undo, shield e eventos validados;
- `WordPressBoundaryTest`: loader sem host da requisição e criação idempotente validados na suíte.

Limitação explícita: a aplicação continua limitada às versões/fingerprints Elementor reconhecidos; arquivos fora da allowlist exigem nova aprovação técnica e fingerprint antes de qualquer patch.

## Registro de execução — Fases 10 e 11

### Fase 10 — observabilidade, privacidade e diagnóstico

Executado:

- `DD_Maintenance_Observability` agora mantém catálogo de operações/eventos, schema normalizado, IDs limitados, contadores, etapas e códigos de falha obrigatórios para eventos em estado `failure`;
- eventos de S3 passaram a registrar início e conclusão/falha para upload, listagem e exclusão, complementando os eventos existentes de backup, restore e cron;
- contexto e mensagens são filtrados por allowlist, redigidos recursivamente e limitados a 500 caracteres; logs legados também são redigidos antes de transient e arquivo físico;
- SQL, Authorization, cookies, tokens, secrets, passwords e access keys não são persistidos em mensagens ou contexto;
- retenção foi centralizada em constantes e aplicada a logs físicos e arquivos JSONL de eventos;
- falha de persistência continua retornando o evento criado, grava alerta temporário e fica visível no resumo administrativo;
- a UI administrativa agora distingue sucesso, sucesso com avisos, falha, informação e falha de persistência por resumo operacional.

### Fase 11 — testes, qualidade estática e CI

Executado:

- adicionadas regressões comportamentais para catálogo, defaults de falha, redaction de logs, limites, resumo de persistência e eventos S3;
- workflows CI mantêm matriz PHP `7.4`, `8.0`, `8.2` e WordPress `6.6`, `6.7`, validam extensões/assertions, sintaxe rastreada e preservam logs/artefatos;
- CI executa smoke em processos separados com assertions habilitadas, incluindo backup em volumes, upload dividido, integridade, Elementor e render administrativo;
- criado alvo incremental `phpstan:level5` para as fronteiras novas, mantendo o alvo legado no nível 3 enquanto os findings históricos são tratados;
- PHPCS foi ampliado com `WordPress.Security.ValidatedSanitizedInput` e separado entre código novo (`phpcs.xml`) e compatibilidade (`phpcs-legacy.xml`);
- o `integration-gate` depende dos jobs de testes e qualidade estática; a regra de branch continua sendo configuração externa do repositório.

Verificação:

- `composer test`: 62 testes, 205 asserções, 1 teste ignorado, OK;
- `composer phpstan`: 77 arquivos analisados, 0 erros;
- `composer phpstan:level5`: 3 fronteiras incrementais analisadas, 0 erros;
- `composer phpcs`: regras de código novo e legacy, OK;
- YAML do workflow validado com PyYAML;
- smokes de backup, upload dividido, integridade, S3, render administrativo e Elementor, OK.

Limitação explícita: a matriz WordPress real depende de execução no GitHub Actions com download das versões declaradas; branch protection não pode ser aplicada pelo código do plugin.

## Registro de execução — Fases 12 e 13

### Fase 12 — compatibilidade e documentação operacional

Executado:

- mantidos aliases, wrappers, hooks, opções e transients Backuper na linha 2.x;
- adicionado `DD_Maintenance_Legacy_Compatibility::migration_table()` com inventário estático de classes, hooks, opções, transients, wrappers e versão alvo de remoção;
- o uso legado continua agregado apenas por contrato, contagem e timestamps, sem dados do operador; o aviso administrativo só é registrado depois de uso efetivo;
- criado `README.md` com instalação, requisitos, S3/Spaces, permissões, backup, restore, limites, cron, Elementor, diagnóstico, segurança, migração, staging, canário e rollback;
- documentada a remoção planejada para `3.0.0`, condicionada a inventário e canário aprovados.

### Fase 13 — matriz de staging e rollout

Preparado:

- criado `tests/staging/evidence.example.json` com os 18 cenários mínimos e todos os campos de evidência;
- criado `tests/staging/validate-evidence.php`, com validação de forma, status, tipos, cobertura dos 18 cenários e rejeição de chaves sensíveis; `--require-pass` exige aprovação de todos os cenários;
- documentado no README o procedimento de restauração real, interrupção/retomada, rollback com `DD_MAINTENANCE_DISABLE_OPERATIONS`, canário e monitoramento de 24 horas.

Verificação:

- `composer test`: 63 testes, 211 asserções, 1 teste ignorado, OK;
- `composer phpstan`: 77 arquivos analisados, 0 erros;
- `composer phpstan:level5`: 3 fronteiras incrementais analisadas, 0 erros;
- `composer phpcs`: regras de código novo e legacy, OK;
- `php -l` no arquivo de compatibilidade e no validador, sem erros;
- validador de evidências no template, render administrativo, Elementor, volumes e integridade, OK;

Limitação explícita: este checkout não fornece WordPress, banco, bucket S3/Spaces, credenciais nem ambiente staging. A execução real dos 18 cenários, restauração, interrupções, rollback, canário e janela de 24 horas permanece pendente e não foi declarada como concluída.