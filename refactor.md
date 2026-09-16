# Plano de refatoração — DD Maintenance

## Objetivo

Tornar o plugin seguro para operações de backup e restauração, reduzir o acoplamento e deixar cada fluxo testável, observável e reversível.

Este plano é deliberadamente incremental. Não fazer um refactor big-bang: cada fase deve gerar um commit/PR pequeno, passar pelos critérios de aceite e manter o fluxo anterior funcionando até a migração estar concluída.

## Escopo

Incluído:

- `dd-maintenance.php` e arquivos de compatibilidade na raiz;
- `includes/class-dd-maintenance.php`;
- `includes/class-dd-maintenance-settings.php`;
- `includes/class-dd-maintenance-backup.php`;
- `includes/class-dd-maintenance-restore.php`;
- `includes/class-dd-maintenance-s3.php`;
- `includes/class-dd-maintenance-config.php`;
- testes em `tests/`.

Não alterar `updraftplus/`: é código de terceiro. A dependência deve ser tratada como artefato externo e coberta somente por testes de integração no limite usado pelo plugin.

## Problemas que o plano precisa resolver

1. `restore_files_step()` usa `$target` antes de inicializá-lo em `includes/class-dd-maintenance-restore.php:989-1007`. Isso pode enfileirar destinos inválidos e concluir sem restaurar arquivos.
2. Há supressão de erros com `@`, escritas de sessão sem verificação e restaurações parciais reportadas como concluídas.
3. A chave secreta S3 é armazenada em opções e renderizada novamente em campos visíveis e ocultos (`includes/class-dd-maintenance-settings.php:2131-2134`, `:2349-2353`, `:3072-3129`).
4. O fallback S3 usa `file_get_contents()` para carregar a parte inteira em memória (`includes/class-dd-maintenance-s3.php:877-885`), embora a interface aceite partes de até 500 MB.
5. Classes monolíticas acumulam apresentação, AJAX, persistência, cron e regras de domínio.
6. Existem pipelines de backup duplicados e um bloco AJAX duplicado/inacessível em `includes/class-dd-maintenance-settings.php:3615-3658`.
7. O código altera arquivos de terceiros e cria plugins MU durante o fluxo de compatibilidade Elementor (`includes/class-dd-maintenance-restore.php:2397-2580`).
8. Os testes são scripts manuais, dependem de mocks e usam `assert()`, sem runner, CI, PHPUnit, PHPStan ou PHPCS próprios.
9. Limites de fragmento, constantes, parâmetros, mensagens da UI e testes divergem: várias mensagens dizem 25 MB, enquanto a configuração permite tamanhos maiores.
10. A extração de ZIP precisa de política explícita contra symlinks e toda escrita precisa respeitar o limite canônico do diretório de destino.

---

## Regras de execução

- Corrigir primeiro comportamento e segurança; só depois reorganizar classes.
- Um PR deve ter uma responsabilidade principal.
- Não misturar alteração funcional com reformatagem global.
- Preservar nomes de opções, hooks e formatos de sessão até existir uma migração explícita.
- Toda mudança de contrato deve migrar todos os chamadores antes de remover o contrato antigo.
- Nenhum erro crítico deve ser convertido em sucesso silencioso.
- Não executar código de terceiros nem modificar arquivos externos sem uma decisão explícita e registrada.
- Não incluir `updraftplus/` em uma reescrita automática ou em uma regra de estilo do projeto.
- Em produção, manter uma cópia recuperável da sessão e dos artefatos antes de cada migração.

## Definição de pronto global

A refatoração só está concluída quando:

- backup local, download, upload dividido, S3, restore de arquivos, restore de banco e cron têm testes executáveis;
- falhas de I/O e de banco têm resultado explícito e aparecem no log com contexto;
- nenhum segredo é renderizado em HTML ou duplicado sem necessidade;
- nenhum arquivo extraído escapa do diretório autorizado;
- os fluxos manual, AJAX e cron usam o mesmo serviço de workflow;
- o projeto tem runner, CI, análise estática e padrão de código;
- uma restauração real de staging foi feita a partir de um backup real;
- existe procedimento documentado de rollback por fase.

---

# Fase 0 — Baseline e preparação

## Passos

1. Criar uma branch exclusiva da refatoração.
2. Registrar a versão atual do plugin, versão do PHP, WordPress e extensões requeridas.
3. Separar claramente código próprio de `updraftplus/`.
4. Documentar os contratos atuais:
   - opções (`dd_maintenance_settings` e `backuper_settings`);
   - transients;
   - hooks e ações AJAX;
   - formato dos arquivos de sessão;
   - nomes de arquivos temporários;
   - tokens e expiração do restore;
   - formato do índice do backup.
5. Executar a baseline com assertions habilitadas:

   ```bash
   php -d zend.assertions=1 -d assert.exception=1 tests/test-serialized-replace.php
   php -d zend.assertions=1 -d assert.exception=1 tests/test-integrity-regressions.php
   php -d zend.assertions=1 -d assert.exception=1 tests/test-local-backup-download.php
   php -d zend.assertions=1 -d assert.exception=1 tests/test-s3-remote-delete.php
   php -d zend.assertions=1 -d assert.exception=1 tests/test-elementor-patch.php
   php -d zend.assertions=1 -d assert.exception=1 tests/test-render-page.php
   ```

6. Executar `php -l` em todos os PHP do plugin.
7. Executar o self-check de backup em ambiente que possua `ZipArchive`, `mysqli` e `curl`.
8. Guardar o resultado da baseline como artefato da branch.

## Critérios de aceite

- Os contratos existentes estão listados antes de qualquer mudança.
- Há uma forma reproduzível de executar os testes.
- O ambiente de CI declara explicitamente as extensões `ZipArchive`, `mysqli` e `curl`.
- Existe um backup de staging e uma cópia dos arquivos de sessão para rollback.

## Rollback

Nenhuma alteração de produção nesta fase. Se a baseline não puder ser executada, corrigir o ambiente antes de alterar o código.

---

# Fase 1 — Correção crítica da restauração

## 1.1 Corrigir o destino de arquivos

Arquivo: `includes/class-dd-maintenance-restore.php`.

Passos:

1. Em `restore_files_step()`, calcular `$target` imediatamente depois de `$relative`.
2. Normalizar separadores de caminho antes da validação.
3. Rejeitar caminho absoluto, `..` e entrada vazia.
4. Validar que o destino está dentro de `$dest_dir` usando comparação de caminho canônico.
5. Não adicionar uma entrada à fila se a validação falhar.
6. Não tratar warning de variável indefinida como fluxo válido.

Teste obrigatório:

- criar um arquivo existente dentro do volume;
- executar o lote normal;
- verificar que o arquivo foi copiado para o destino esperado;
- verificar que o lote seguinte não repete nem perde o arquivo;
- verificar que uma origem inexistente produz erro explícito.

## 1.2 Tornar o estado do restore confiável

1. Definir estados explícitos: `pending`, `running`, `completed`, `completed_with_errors`, `failed`.
2. Registrar contadores de arquivos, queries e erros por lote.
3. Separar erro recuperável de erro que invalida a restauração.
4. Impedir que uma sessão com checkpoint inválido continue.
5. Validar schema mínimo ao carregar qualquer sessão.
6. Fazer o restore reportar falha quando o destino de um arquivo ou a persistência do checkpoint não funcionar.

## Critérios de aceite da Fase 1

- O teste de arquivo existente falha antes da correção e passa depois.
- Uma restauração com erro de cópia não aparece como sucesso.
- Uma sessão corrompida é recusada sem sobrescrever dados.
- Não existem referências a `$target` antes da sua inicialização no fluxo de arquivos.

## Rollback

Se o novo estado não puder ler sessões antigas, manter um leitor compatível somente durante a migração e remover o leitor após todos os jobs antigos expirarem. Não criar alias permanente sem prazo de remoção.

---

# Fase 2 — Erros, checkpoints e transações

## 2.1 Remover supressão de erros

1. Remover `@` de `restore.php`, `main.php`, `settings.php` e demais classes próprias.
2. Para cada operação, escolher explicitamente:
   - lançar exceção;
   - retornar `WP_Error`;
   - registrar e continuar, quando o erro for realmente não crítico.
3. Incluir no log a operação, sessão, arquivo, destino e causa.
4. Nunca registrar valores secretos.

## 2.2 Centralizar o armazenamento de sessão

Criar um `SessionStore` responsável por:

- serialização JSON;
- schema/versionamento;
- escrita em arquivo temporário;
- `rename()` atômico;
- permissões do arquivo;
- leitura e validação;
- limpeza segura;
- detecção de corrupção.

Migrar `save_restore_session_data()` e `save_session_data()` para esse serviço. Os chamadores devem tratar o resultado; nenhum deve ignorar `false`.

## 2.3 Restore de banco

1. Definir se cada falha de query interrompe ou apenas registra o lote.
2. Persistir o contador de erros junto do checkpoint.
3. Retornar resultado estruturado, não apenas arrays sem contrato.
4. Quando possível, agrupar operações transacionais.
5. Documentar as operações que não podem ser revertidas.
6. Evitar informar “concluído” quando existem erros não recuperados.

## Critérios de aceite

- Falha de escrita do checkpoint interrompe a sessão.
- Falha de cópia obrigatória retorna estado `failed`.
- Falha de query aparece com contexto e estado correto na UI e no log.
- Um teste força falha de I/O e verifica o resultado observado, não a implementação interna.

## Rollback

Manter leitura dos formatos anteriores de sessão durante uma janela de compatibilidade. Se o formato novo falhar em staging, interromper a migração e continuar processando somente sessões no formato antigo.

---

# Fase 3 — Credenciais e transporte S3

## 3.1 Remover exposição de segredos

1. Não colocar `s3_secret_key` em `value` de inputs.
2. Remover o segredo de campos ocultos de cron.
3. Aceitar campo vazio como “manter segredo atual”.
4. Preferir, nesta ordem:
   - constante de `wp-config.php`;
   - secret store do ambiente;
   - opção de WordPress não carregada automaticamente.
5. Manter uma única fonte de verdade.
6. Migrar a opção legacy e apagar a cópia quando não houver mais consumidores.
7. Rotacionar segredos que tenham sido expostos em HTML ou logs.


### Procedimento de rotação

1. Gere um par novo no provedor S3/Spaces.
2. Configure `DD_MAINTENANCE_S3_KEY` e `DD_MAINTENANCE_S3_SECRET` em `wp-config.php` ou no secret store do ambiente.
3. Execute um upload de teste e confirme a leitura/listagem do objeto.
4. Revogue o par antigo somente após o teste passar.
5. Remova apenas a chave antiga da opção `dd_maintenance_settings` após confirmar que nenhum processo ainda a utiliza.

## 3.2 Corrigir upload sem cópia integral

1. Usar cURL com leitura por stream para partes grandes.
2. Se cURL não estiver disponível, bloquear tamanhos que não possam ser processados com segurança; não fingir suporte.
3. Calcular limite efetivo com base no `memory_limit` e no overhead do WordPress.
4. Centralizar a escolha do transporte em `S3Client`.
5. Testar:
   - arquivo pequeno;
   - arquivo maior que o limite de memória seguro;
   - retry sem duplicar a parte;
   - resposta HTTP inválida;
   - remoção remota idempotente.

## Critérios de aceite

- O segredo nunca aparece no HTML renderizado.
- O POST de configurações não apaga um segredo quando o campo é omitido.
- Uploads grandes não usam `file_get_contents($file)` para formar o corpo completo.
- Falhas de transporte retornam erro com status e request id, sem incluir credenciais.

## Rollback

Preservar a leitura da configuração antiga durante a migração, mas não voltar a renderizar o segredo. Se o novo transporte falhar, bloquear o upload e indicar requisito de cURL; não retornar silenciosamente ao caminho inseguro.

---

# Fase 4 — Separação arquitetural

## 4.1 Extrair workflows

Criar serviços pequenos, usando composição:

```text
BackupWorkflow
RestoreWorkflow
FileRestoreService
SqlRestoreService
SqlDumpParser
SessionStore
StorageAdapter
S3Client
CronJobStore
```

Os handlers AJAX, cron e tela administrativa devem apenas:

1. validar capacidade e nonce;
2. normalizar entrada;
3. chamar o caso de uso;
4. converter o resultado para resposta HTTP/UI.

Nenhum handler deve conter regras de backup ou restore.

## 4.2 Dividir a classe de settings

A classe atual mistura renderização, persistência, scripts, AJAX, permissões e coordenação. Separar em:

```text
AdminPageRenderer
SettingsRepository
AdminActionController
RestoreActionController
BackupActionController
```

Manter os textos e contratos de UI inicialmente. A primeira extração deve mover comportamento sem alterar a saída.

## 4.3 Reduzir o singleton principal

Em `class-dd-maintenance.php`:

1. extrair cron;
2. extrair storage/logging;
3. extrair migrações e compatibilidade;
4. construir dependências explicitamente;
5. deixar a classe principal apenas como adaptador do ciclo de vida do WordPress.

Evitar um novo service locator global.

## 4.4 Criar DTOs/resultados explícitos

Substituir arrays sem contrato nos limites principais por estruturas documentadas, por exemplo:

```text
BackupResult
RestoreResult
RestoreProgress
SessionState
StorageUploadResult
```

Durante a transição, validar arrays antigos na borda e convertê-los para o formato interno.

## Critérios de aceite

- AJAX, cron e execução manual usam o mesmo workflow.
- A camada administrativa não conhece detalhes de ZIP, SQL ou S3.
- Cada serviço pode ser testado sem carregar a página inteira.
- Não há alteração de opções/hooks sem teste de compatibilidade.

## Rollback

Cada extração deve preservar o adaptador antigo até todos os chamadores migrarem. Remover adaptadores somente após os testes de integração passarem.

---

# Fase 5 — Elementor, ZIP e segurança de arquivos

## 5.1 Isolar compatibilidade Elementor

1. Mover a lógica para `ElementorCompatibility`.
2. Tornar a operação explícita, idempotente e versionada.
3. Criar backup/checksum antes de qualquer modificação.
4. Validar que o conteúdo atual corresponde ao esperado antes de aplicar regex.
5. Não modificar arquivo de terceiros se o checksum não for reconhecido.
6. Registrar arquivo, versão, checksum anterior e resultado.
7. Adicionar operação de desfazer.
8. Executar somente mediante decisão explícita do fluxo de restore.

O teste deve chamar a implementação real, não uma cópia local da função.

## 5.2 Endurecer ZIP e caminhos

1. Rejeitar entradas symlink e tipos de arquivo não suportados.
2. Normalizar separadores e encoding.
3. Rejeitar caminhos absolutos e traversal.
4. Validar `realpath()` do diretório pai antes de escrever.
5. Impedir que links simbólicos existentes no destino redirecionem a escrita.
6. Aplicar a mesma política em volumes, chunks, manifestos e cópias finais.
7. Armazenar temporários e backups fora da raiz pública quando possível.
8. Se permanecer em uploads, documentar requisitos equivalentes para Apache, Nginx e demais servidores suportados.

Enquanto os temporários permanecerem em `wp-content/uploads/dd-maintenance`, o servidor deve impedir acesso HTTP e execução de PHP nesse caminho: Apache deve usar `.htaccess` com `Deny from all` (ou `Require all denied`) e `RemoveHandler`/`RemoveType` para PHP; Nginx deve negar a localização com `location ^~ /wp-content/uploads/dd-maintenance/ { deny all; }` antes do handler PHP-FPM. Em outros servidores, aplicar regra equivalente de negação por caminho e execução. A aplicação ainda rejeita links, tipos não regulares, traversal, caminhos absolutos e destinos cujo `realpath()` saia da raiz autorizada.

## Critérios de aceite

- Testes cobrem traversal, caminho absoluto, symlink e destino fora da raiz.
- Nenhum artefato extraído pode sair do diretório autorizado.
- Patch Elementor não altera arquivo desconhecido ou modificado por outra versão.
- Falha de proteção interrompe o restore antes da escrita.

## Rollback

Restaurar arquivos modificados a partir do backup verificado. Nunca tentar reconstruir o arquivo original apenas invertendo uma regex.

---

# Fase 6 — Eliminar duplicação e alinhar contratos

## Passos

1. Remover o bloco AJAX duplicado/inacessível em `settings.php:3615-3658`.
2. Centralizar o pipeline de backup em `BackupWorkflow`.
3. Remover o parâmetro `$offset` não utilizado de `zip_batch_step()` depois de migrar todos os chamadores.
4. Remover `VOLUME_PAYLOAD_SIZE` se não tiver consumidor real.
5. Definir uma única fonte para tamanho de chunk.
6. Gerar mensagens da UI a partir dessa configuração, sem textos fixos de 25 MB.
7. Atualizar testes para o contrato efetivo, incluindo valores configuráveis.
8. Corrigir `base_name` no job de cron e verificar a associação do log.
9. Isolar wrappers `Backuper*` e aliases legacy em uma camada de compatibilidade com prazo de remoção.
10. Remover funções, hooks, transients e opções legacy somente após busca de consumidores e migração comprovada.

A camada `DD_Maintenance_Legacy_Compatibility` registra os aliases retroativos e é carregada
somente na borda do plugin. O alvo de remoção é a próxima major version, após inventário
dos consumidores externos e publicação do aviso de depreciação; até lá, hooks, transients,
opções e wrappers com consumidores ativos permanecem preservados.

## Critérios de aceite

- Existe uma única implementação de cada etapa do backup.
- UI, runtime e testes exibem e validam o mesmo limite.
- Não existem parâmetros ou constantes sem uso.
- Logs de cron identificam corretamente o backup correspondente.
- A compatibilidade legacy está isolada e documentada.

## Rollback

Reverter apenas o commit da limpeza. Não reintroduzir o bloco duplicado para corrigir uma falha de contrato; corrigir o contrato centralizado.

---

# Fase 7 — Testes, análise estática e CI

## Passos

1. Criar `composer.json` somente para ferramentas e dependências realmente usadas pelo projeto.
2. Migrar testes puros para PHPUnit.
3. Criar testes de integração WordPress para:
   - nonces e capabilities;
   - AJAX de upload dividido;
   - sessão e retomada;
   - restore de arquivos;
   - restore de banco;
   - cron;
   - configuração S3 sem exposição de segredo.
4. Substituir `assert()` como único mecanismo de teste por asserções do runner.
5. Fazer `test-chunked-upload-restore.php` chamar o handler de produção.
6. Fazer `test-elementor-patch.php` chamar o patch de produção.
7. Adicionar regressão para `$target` com arquivo existente.
8. Configurar PHPStan em nível inicial e aumentar gradualmente.
9. Configurar PHPCS com WordPress Coding Standards.
10. Criar CI com versões suportadas de PHP e WordPress e extensões:
    - `ZipArchive`;
    - `mysqli`;
    - `curl`.
11. Separar testes unitários, integração e smoke tests.
12. Publicar artefatos de log quando um teste de restore falhar.

## Critérios de aceite

- Um comando único executa a suíte.
- CI falha com assertions desabilitadas ou ambiente sem extensão obrigatória.
- Os testes verificam efeitos observáveis, não apenas HTML não vazio ou cópias de implementação.
- Há cobertura para erros e transições de estado, não apenas caminhos felizes.

## Rollback

Ferramentas de qualidade não devem bloquear hotfixes de produção, mas o CI deve impedir merge de alterações que quebrem sintaxe, contratos críticos ou testes de restore.

---

# Fase 8 — Observabilidade e rollout

## Passos

1. Padronizar eventos de log:
   - início/fim de operação;
   - sessão;
   - etapa;
   - progresso;
   - duração;
   - bytes processados;
   - contagem de erros;
   - código de falha.
2. Não registrar tokens, chaves ou conteúdo SQL sensível.
3. Adicionar correlação entre AJAX, cron e sessão.
4. Exibir na UI a diferença entre sucesso, sucesso com avisos e falha.
5. Testar backup e restore em staging com:
   - site pequeno;
   - site com banco grande;
   - arquivo maior que um chunk;
   - Elementor instalado;
   - S3 real ou ambiente compatível;
   - interrupção entre lotes;
   - falta de espaço;
   - falha de rede.
6. Fazer rollout canário em uma instalação não crítica.
7. Monitorar falhas durante uma janela definida pela operação.
8. Só então remover adapters e caminhos legacy.

## Runbook de staging e rollout

Antes de habilitar a versão em produção, registrar uma execução independente para cada cenário:

1. **Matriz de staging:** site pequeno; banco grande; arquivo maior que um chunk; Elementor ativo; S3 real ou compatível; interrupção entre lotes; disco insuficiente; falha de rede.
2. **Evidências obrigatórias:** `correlation_id`, `session_id`, etapa, `failure_code`, arquivo `logs/events-YYYY-MM-DD.jsonl`, log textual, checksums dos volumes, contagem de arquivos, banco restaurado, URLs esperadas e configurações esperadas.
3. **Retomada:** interromper cada fluxo em uma etapa diferente, repetir a mesma requisição e confirmar que a sessão retoma o checkpoint ou termina com erro determinístico; uma sessão corrompida deve ser descartada e nunca reutilizada.
4. **Canário:** habilitar primeiro em uma única instalação não crítica, mantendo a versão anterior disponível e anotando horário inicial, volume de operações, erros e avisos. Não ampliar o rollout se houver falha de integridade, perda de correlação ou erro não classificado.
5. **Monitoramento:** acompanhar por 24 horas após o canário; revisar eventos `status=failure` e `status=warning`, falhas de rede/S3, sessões sem evento final e divergências de checksum a cada hora. Encerrar o canário somente sem falhas críticas e após um restore verificado.
6. **Rollback ensaiado em staging:** definir `DD_MAINTENANCE_DISABLE_OPERATIONS` como `true` no `wp-config.php` para desabilitar uploads, backups e restores novos (AJAX e handlers administrativos), preservar sessões e logs, reverter o plugin para a versão anterior, restaurar Elementor somente de backup verificado, rotacionar credenciais se houver suspeita de exposição e marcar sessões corrompidas como não reutilizáveis. Registrar o resultado e o horário de cada passo.

## Critérios de aceite

- É possível diagnosticar uma falha usando apenas sessão, etapa e código de erro.
- Um restore interrompido retoma ou falha de forma determinística.
- Um backup real restaura corretamente arquivos, banco, URLs e configurações esperadas.
- O procedimento de rollback foi executado em staging, não apenas documentado.

## Rollback

1. Desabilitar novos uploads/restores se houver risco de corrupção.
2. Reverter para a versão anterior do plugin.
3. Preservar sessões e logs para investigação.
4. Restaurar arquivos Elementor somente pelo backup verificado.
5. Rotacionar credenciais se houver suspeita de exposição.
6. Não reutilizar uma sessão que tenha sido marcada como corrompida.

---

## Ordem mínima obrigatória

Se houver pouca capacidade para executar tudo de uma vez, a ordem não deve ser alterada:

1. Corrigir `$target` e adicionar regressão.
2. Corrigir checkpoints, cópias e estados de erro.
3. Remover segredos do HTML e reduzir sua duplicação.
4. Corrigir o transporte S3 para não carregar partes inteiras na memória.
5. Endurecer ZIP, symlinks e limites canônicos.
6. Unificar workflows.
7. Separar classes e handlers.
8. Migrar testes para um runner real.
9. Adicionar análise estática, CI e rollout de staging.

Não iniciar a divisão das classes antes dos itens 1–5: reorganizar código com um restore incorreto apenas espalha o defeito por mais componentes.

## Resultado esperado

Ao final, o plugin deve ter uma camada WordPress fina, workflows de domínio únicos, armazenamento de sessão confiável, transporte S3 streaming, restauração de arquivos confinada ao destino, compatibilidade Elementor reversível e uma suíte que valide os fluxos reais de produção.
