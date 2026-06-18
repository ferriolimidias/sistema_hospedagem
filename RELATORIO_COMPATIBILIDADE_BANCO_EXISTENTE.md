# Relatorio de Compatibilidade com Banco Existente

Data: 17 de junho de 2026.

## Escopo

Analise estatica de `setup.php`, `api/schema.php`, `api/db.php`, `api/seed_defaults.php`, SQLs e APIs. Nenhuma query foi executada.

## Comparacao geral de schema

O `database.sql` base do ZIP antigo e o `database.sql` atual permanecem iguais. A diferenca real esta no codigo PHP atual, principalmente em `api/db.php`, `api/schema.php` e `setup.php`, que adicionam tabelas e colunas em tempo de execucao/instalacao.

## Tabelas do antigo

O antigo tinha como base:

- `admins`
- `settings`
- `personalizacao`
- `chalets`
- `chalet_custom_prices`
- `reservations`

## Tabelas novas no atual

- `seasonal_rules`
- `stay_discounts`
- `coupons`
- `extra_services`
- `reservation_consumptions`
- `faqs`

## Colunas novas principais

Em `admins`:

- `auth_token`

Em `reservations`:

- `expires_at`
- `mp_init_point`
- `contract_filename`
- `last_contract_sent_at`
- `balance_paid`
- `balance_paid_at`
- `coupon_code`
- `discount_amount`
- `extras_json`
- `extras_total`
- `fnrh_access_token`
- `fnrh_data`
- `guest_cpf`
- `guest_address`
- `guest_car_plate`
- `guest_companion_names`
- `fnrh_status`
- `fnrh_submitted_at`
- `fnrh_last_response`
- `additional_value`
- `children_ages`
- `brings_pet`
- `payment_method`

Em `chalets`:

- `base_guests`
- `extra_guest_fee`
- `max_guests`

Em `seasonal_rules`:

- `rule_type`
- `recurring_days`
- `start_date` e `end_date` passam a aceitar `NULL` no migrador.

Em `personalizacao`:

- `loc_subtitulo`
- `loc_map_embed`
- `videos_enabled`
- `videos_json`
- `logo_principal`
- `logo_alternativa`

## Colunas removidas ou tabelas removidas

Nao foi identificada remocao estrutural de coluna ou tabela no codigo atual.

## Mudanca de tipo

Foi identificado `MODIFY COLUMN` em `seasonal_rules.start_date` e `seasonal_rules.end_date` para aceitar `NULL`, usado por regras recorrentes.

## Operacoes perigosas encontradas

- `setup.sql` contem `DELETE FROM personalizacao` e `DELETE FROM chalets`. Nao use esse arquivo em banco com dados reais.
- APIs administrativas possuem `DELETE FROM` para remover registros sob acao do painel: chalets, reservas, usuarios, cupons, extras, consumos, regras sazonais e FAQs.
- `api/db.php` remove `settings.evo_url` antigo: `DELETE FROM settings WHERE setting_key = 'evo_url'`. Isso parece limpeza de chave legada, mas e uma alteracao de dado.

Nao foram encontrados `DROP TABLE` ou `TRUNCATE` relevantes no codigo atual analisado.

## Setup atual

`setup.php`:

- cria banco se tiver permissao;
- roda `runInitialSchema($pdo)`;
- cria/atualiza admin informado;
- grava `config/database.php`;
- cria `config/.installed.lock`;
- bloqueia execucao quando `config/database.php` ja existe.

Para banco existente com dados, nao rode `setup.php` sem copia de homologacao. Ele e instalador/migrador inicial, nao procedimento de atualizacao de producao.

## Seeds e duplicidade

`api/schema.php` usa `CREATE TABLE IF NOT EXISTS` e muitos `INSERT ... ON DUPLICATE KEY UPDATE setting_value = setting_value`, o que evita sobrescrever varias configuracoes. Mesmo assim:

- pode inserir defaults ausentes em `settings`;
- pode inserir FAQs/chales padrao se tabelas estiverem vazias;
- pode criar novas tabelas e colunas automaticamente;
- pode exigir privilegio `ALTER`.

`api/seed_defaults.php` exige admin e `APP_DEBUG=true`, mas ainda deve ser tratado como utilitario sensivel.

## Respostas diretas

1. Alguma tabela nova foi adicionada?
   - Sim: `seasonal_rules`, `stay_discounts`, `coupons`, `extra_services`, `reservation_consumptions`, `faqs`.

2. Alguma coluna nova foi adicionada?
   - Sim, muitas colunas listadas acima.

3. Alguma coluna foi removida?
   - Nao identificado.

4. Algum tipo de coluna mudou?
   - Sim, datas de `seasonal_rules` podem ser modificadas para nullable.

5. Alguma tabela foi removida?
   - Nao identificado.

6. O setup atual sobrescreve dados existentes?
   - Pode inserir/atualizar estruturas e criar admin. Nao deve ser usado como atualizador direto de banco com dados.

7. O seed atual pode duplicar registros?
   - Settings usam chave unica/ON DUPLICATE. FAQs/chales padrao dependem de checagens internas; ainda deve ser testado em copia.

8. Existe risco de apagar dados?
   - Sim se usar `setup.sql` em banco real, porque contem DELETE em tabelas de conteudo. APIs administrativas tambem podem apagar sob acao do usuario.

9. Da para atualizar os arquivos mantendo o mesmo banco?
   - Provavelmente sim, desde que o banco receba as colunas/tabelas novas de forma controlada em homologacao antes. Nao apague o banco.

10. Quais cuidados tomar?
   - Backup completo de arquivos e MySQL.
   - Testar copia em homologacao.
   - Preservar `config/database.php`.
   - Nao rodar `setup.sql`.
   - Nao rodar `setup.php` em banco de producao com dados.
   - Verificar permissao de `ALTER TABLE`.
   - Validar relatorios, reservas, precos e login apos deploy.

## Proposta segura de migracao

Nao executei nenhuma migracao. A proposta segura e:

1. Exportar backup do banco atual.
2. Restaurar copia em homologacao.
3. Subir arquivos atuais preservando `config/database.php`.
4. Acessar paginas/API em homologacao para observar se `api/db.php` aplica `ALTER TABLE` sem erro.
5. Comparar tabelas e colunas apos homologacao.
6. So depois replicar em producao com janela de manutencao e backup.

