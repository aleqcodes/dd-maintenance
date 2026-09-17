# DD Maintenance

Plugin WordPress para backup, restauração, armazenamento S3/DigitalOcean Spaces e manutenção administrativa.

## Instalação

1. Faça backup do site e do banco antes de instalar ou atualizar.
2. Copie o diretório `dd-maintenance` para `wp-content/plugins/`.
3. Ative **DD Maintenance** em **Plugins**.
4. Abra **Ferramentas → DD Maintenance**, valide os diretórios e execute um backup pequeno antes de habilitar cron ou restauração.
5. Em produção, promova primeiro a mesma versão em staging e guarde os artefatos de validação.

### Requisitos

- WordPress 5.4 ou superior.
- PHP 7.4 ou superior.
- Extensões PHP usadas pelo fluxo: `curl`, `mysqli`, `zip`, `mbstring`, `xml`, `dom` e `intl`.
- `wp-cron` funcional para tarefas agendadas, ou um cron de sistema que execute `wp-cron.php`.
- Espaço livre para o arquivo temporário, cada volume e o arquivo SQL. O limite de volume configurável não substitui o limite de `upload_max_filesize`/`post_max_size` do PHP.

## Configuração S3 e Spaces

As credenciais podem ser preenchidas na página administrativa ou fornecidas por ambiente:

```text
DD_MAINTENANCE_S3_KEY
DD_MAINTENANCE_S3_SECRET
```

Bucket, região e endpoint podem ser definidos na tela ou no `wp-config.php`:

```php
define( 'DD_MAINTENANCE_S3_KEY',    getenv( 'DD_MAINTENANCE_S3_KEY' ) );
define( 'DD_MAINTENANCE_S3_SECRET',  getenv( 'DD_MAINTENANCE_S3_SECRET' ) );
define( 'DD_MAINTENANCE_S3_BUCKET',  'meu-bucket' );
define( 'DD_MAINTENANCE_S3_REGION',  'nyc3' );
define( 'DD_MAINTENANCE_S3_ENDPOINT', 'https://nyc3.digitaloceanspaces.com' );
```

A precedência das credenciais é: constantes do `wp-config.php`, variáveis de ambiente e configuração persistida. Nunca versione chaves, nunca as coloque em tickets ou URLs e conceda ao bucket somente as operações e o prefixo necessários. O plugin não registra segredo, `Authorization`, cookie, token ou corpo SQL nos eventos.

Teste listagem, upload, download e exclusão remota em staging. Sem um endpoint S3/Spaces real, o smoke local não comprova conectividade, política IAM ou consistência de um provedor.

## Permissões e arquivos locais

O PHP precisa conseguir criar e escrever:

```text
wp-content/uploads/dd-maintenance/
wp-content/uploads/dd-maintenance/logs/
wp-content/uploads/dd-maintenance/session_*/
```

A pasta de backup não pode ser symlink. Na criação, o plugin instala `index.php`, `.htaccess` e `web.config` para impedir acesso HTTP direto; confirme também o bloqueio no proxy, CDN e servidor web. Não conceda permissões mais amplas que as necessárias ao usuário do PHP. O diretório de `mu-plugins` também precisa ser gravável somente quando o operador aprovar o shield de compatibilidade Elementor.

Se a criação falhar, corrija proprietário, grupo e ACL no servidor em vez de tornar toda a árvore gravável. Não armazene cópias de credenciais em arquivos de diagnóstico.

## Backup, volumes e restauração

Na página administrativa, selecione os componentes necessários, mantenha uma cópia local até validar a cópia remota e defina a retenção local. O arquivo SQL e os arquivos são divididos em volumes quando necessário. `split_size_mb` é limitado a 25–1000 MB e o padrão é 200 MB; o valor efetivo deve ser menor que o espaço temporário disponível.

O fluxo de restauração é resumidamente:

1. autenticar o operador com capacidade e nonce;
2. receber ou localizar o backup e validar o caminho dentro da pasta segura;
3. montar a sessão, baixar/copiar os volumes e validar presença e checksum;
4. extrair em diretório temporário sem symlink ou traversal;
5. restaurar o SQL por etapas, copiar arquivos permitidos e atualizar URLs a partir das opções confiáveis do WordPress;
6. limpar temporários, preservar os eventos e conferir o resultado no painel.

Faça uma restauração real em staging antes de restaurar produção. Registre checksum antes/depois, tabelas afetadas, consultas obrigatórias, URLs finais e arquivos remanescentes. Nunca use `HTTP_HOST` como origem confiável para URLs de restauração.

## Cron e rollback operacional

Frequências suportadas: `daily`, `weekly`, `biweekly` e `monthly`. O horário é `HH:MM`; o padrão é 03:00. O cron precisa executar tanto o evento principal quanto as continuações de sessões longas.

Para bloquear novos backups, uploads e restores durante rollback, defina temporariamente no `wp-config.php` antes de carregar o plugin:

```php
define( 'DD_MAINTENANCE_DISABLE_OPERATIONS', true );
```

Remova a constante somente depois de validar a versão anterior, restaurar o serviço e confirmar que não há sessão concorrente. Essa chave não é uma variável de ambiente automática: ela é uma constante de configuração do WordPress.

## Elementor

A compatibilidade Elementor é opt-in por restauração. O operador precisa decidir explicitamente, e o plugin só aceita fingerprints e caminhos reconhecidos. O patch guarda backup, fingerprint, versão e checksum antes/depois, escreve atomicamente e instala o shield MU apenas quando aprovado.

Antes de aplicar, copie o arquivo original, registre o checksum e interrompa se a versão não for reconhecida. Para undo, o checksum pós-patch precisa ser o esperado; se um terceiro alterou o arquivo, o resultado é `undo_conflict` e o plugin não sobrescreve a alteração. Se a compatibilidade estiver desabilitada, o restore básico não deve instalar shield nem modificar arquivos Elementor.

## Logs, eventos e diagnóstico

Logs de texto e eventos JSONL ficam em `wp-content/uploads/dd-maintenance/logs/`. Cada evento operacional pode incluir `correlation_id`, `session_id`, `step`, status, contadores e código de falha. Falhas de persistência aparecem separadamente no resumo administrativo.

A retenção física padrão é de 30 arquivos de log e 30 arquivos JSONL de eventos. Mensagens e contexto são redigidos e limitados; não use logs para transportar SQL, conteúdo de requisições, cookies ou credenciais. Ao abrir um chamado, forneça versão, PHP, WordPress, código de falha, correlação e etapa, removendo dados pessoais.

Códigos frequentes:

| Código | Ação |
| --- | --- |
| `s3_config` | confira bucket, região, endpoint e precedência das credenciais |
| `s3_http_*` / `s3_request_failed` | confira endpoint, DNS, TLS, IAM e janela de retry |
| `file_missing` / `restore_local_not_found` | confirme nome, volume e retenção local |
| `checksum_mismatch` / `invalid_checksum` | descarte a cópia incompleta e repita a transferência |
| `session_path_unsafe` / `restore_path_unsafe` | remova symlink e corrija o diretório configurado |
| `restore_mkdir_failed` | corrija permissão e espaço temporário |
| `undo_conflict` | investigue a alteração externa; não force a cópia |
| `event_retention_failed` | confira permissão da pasta de logs e espaço em disco |

## Segurança e credenciais

Use HTTPS, contas administrativas nominativas, nonces, capacidades mínimas e IAM limitado ao bucket/prefixo. Não envie segredos por e-mail, commit, issue, screenshot ou query string. Rotacione chaves depois de qualquer exposição. Proteja banco, `wp-config.php`, dumps SQL e arquivos de backup com as mesmas regras de produção.

A restauração altera dados: confirme o destino, faça snapshot independente, execute em janela aprovada e mantenha rollback. O plugin não transforma o painel em autorização para ignorar controles do provedor ou do servidor.

## Compatibilidade e migração

Os contratos abaixo continuam ativos durante a linha 2.x. O aviso administrativo só é registrado depois que um alias, wrapper ou hook legado for efetivamente usado; o contador salva apenas contrato e contagem, sem dados do operador.

| Contrato antigo | Contrato canônico | Ação |
| --- | --- | --- |
| `Backuper` | `DD_Maintenance` | alterar referências PHP |
| `Backuper_Backup` | `DD_Maintenance_Backup` | alterar referências PHP |
| `Backuper_S3` | `DD_Maintenance_S3` | alterar referências PHP |
| `Backuper_Updater` | `DD_Maintenance_Updater` | alterar referências PHP |
| `Backuper_Settings` | `DD_Maintenance_Settings` | alterar referências PHP |
| `DD_Gerenciador_Updates` | `DD_Maintenance_Config` | alterar referências PHP |
| `admin_post_backuper_*` | `admin_post_dd_maintenance_*` equivalente | migrar integrações e automações |
| `backuper_daily_maintenance` | `dd_maintenance_daily_maintenance` | recriar agendamento |
| `backuper_settings` | `dd_maintenance_settings` | validar opção após migração |
| `dd_gerenciador_updates_password_hash` | `dd_maintenance_password_hash` | confirmar hash migrado |
| `backuper_last_log` / `backuper_notice` | `dd_maintenance_last_log` / `dd_maintenance_notice` | atualizar leitores |
| `backuper.php` e `class-backuper*.php` | `dd-maintenance.php` e classes canônicas | remover includes antigos |

A tabela programática completa está em `DD_Maintenance_Legacy_Compatibility::migration_table()`. Para inventário antes de uma major, leia a opção `dd_maintenance_legacy_usage` em ambiente autorizado e filtre somente contratos agregados. Preserve sessões antigas até expirarem ou serem concluídas; não remova alias enquanto houver uso observado.

Calendário de remoção: contratos legados permanecem na 2.x, são inventariados e validados em canário, e a remoção é alvo de `3.0.0`. Na major, chamadas a contrato removido devem falhar com mensagem acionável indicando o símbolo canônico e a documentação de migração, nunca com fallback silencioso.

## Staging, canário e rollback

A execução real é externa ao repositório. Use uma cópia sanitizada e isolada do site, banco e bucket; não substitua esta etapa por doubles locais.

A matriz mínima de staging contém 18 cenários:

1. site pequeno;
2. banco grande;
3. arquivo acima do limite;
4. múltiplos volumes;
5. Elementor ativo;
6. prefixo alternativo de tabela;
7. URLs diferentes entre origem e destino;
8. interrupção durante extração;
9. interrupção durante SQL;
10. interrupção durante cópia de arquivos;
11. disco cheio;
12. falha de rede S3;
13. timeout e retry;
14. checksum inválido;
15. sessões concorrentes;
16. continuação pública autenticada por token;
17. rollback com operações desabilitadas;
18. falha de limpeza.

Para cada cenário, guarde commit, versão do plugin, PHP, WordPress, extensões, IDs de correlação/sessão, etapa, código de falha, eventos JSONL, log textual, checksums, arquivos e tabelas, consultas obrigatórias, erros, avisos, URLs/configuração finais, decisão Elementor, limpeza, duração e resultado de repetição. Classifique P0/P1/P2/P3 com evidência; qualquer P0/P1 bloqueia promoção.

Valide o formato sem expor credenciais:

```bash
php tests/staging/validate-evidence.php /caminho/evidence.json
```

Procedimento de promoção:

1. fixe commit e artefatos;
2. execute a matriz completa e uma restauração real;
3. provoque interrupções controladas e confirme retomada idempotente;
4. execute rollback com `DD_MAINTENANCE_DISABLE_OPERATIONS` e valide o serviço;
5. promova um canário isolado;
6. monitore 24 horas, sem P0/P1 e sem regressão de backup/restore;
7. somente após aprovação registre a decisão de remover compatibilidade na próxima major.

Este checkout não contém credenciais, site WordPress, banco ou bucket de staging; portanto a matriz real, o rollback real, o canário e o monitoramento de 24 horas permanecem pendentes até serem executados por um operador no ambiente correspondente.
