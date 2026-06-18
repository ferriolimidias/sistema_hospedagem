# Relatorio Comparativo - Recanto Antigo vs Sistema Atual

Data: 17 de junho de 2026.

## Escopo

Comparacao estatica entre:

- ZIP antigo: `C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\recantodaserra.zip`
- Extracao de analise: `C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\_analise_recanto_antigo`
- Sistema atual: `C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\sistema_hospedagem`

Nao foram alterados arquivos de codigo, banco ou configuracoes nesta auditoria.

## Inventario

ZIPs encontrados na raiz:

- `recantodaserra.zip` - 17.858.340 bytes - identificado como pacote antigo original.
- `sistema_hospedagem_homologacao_2026-06-15_corrigido.zip` - pacote de homologacao.
- `sistema_hospedagem_homologacao_2026-06-15_final.zip` - pacote de homologacao.
- `sistema_hospedagem_homologacao_2026-06-15.zip` - ZIP vazio de tentativa anterior.

Relatorios Markdown existentes na pasta atual incluem relatorios de melhorias, auditoria, homologacao, pacote, vendor e correcoes.

## Resultado da comparacao

Comparacao feita excluindo `node_modules`, `.git`, `.cursor` e `backups`:

- Arquivos novos: 59.
- Arquivos removidos: 7.
- Arquivos alterados: 20.
- Arquivos iguais: 13.

## Arquivos novos relevantes

- Infra/configuracao: `.env.example`, `.env.homologacao.example`, `.gitignore`, `.gitattributes`, `bootstrap.php`, `composer.json`, `setup.php`, `setup.sql`.
- Protecoes: `.htaccess` alterado, `api/.htaccess`, `storage/contracts/.htaccess`, `storage/logs/.htaccess`.
- APIs novas: `api/admin_coupons.php`, `api/admin_extra_services.php`, `api/availability.php`, `api/booking_extras.php`, `api/booking_options.php`, `api/checkin_link.php`, `api/consumptions.php`, `api/contract_access.php`, `api/contract_service.php`, `api/create_preference.php`, `api/debug_env.php`, `api/download_contract.php`, `api/evolution_instance.php`, `api/evolution_service.php`, `api/faqs.php`, `api/finance_stats.php`, `api/fnrh_service.php`, `api/generate_contract.php`, `api/login.php`, `api/pay_balance.php`, `api/pricing.php`, `api/reports.php`, `api/schema.php`, `api/seasonal_rules.php`, `api/stay_discounts.php`, `api/sync_assets.php`, `api/validate_coupon.php`.
- Banco/migracoes: `migration_2026_05_06_seasonal_rules*.sql`, `migration_2026_05_07_commercial_booking_improvements.sql`, `migration_2026_05_13_institutional_and_calendar.sql`.
- Runtime: `checkin.php`, `storage/contracts/.htaccess`, `storage/logs/.htaccess`.

## Arquivos removidos ou nao presentes no atual

- `admin/debug_admin.js`
- `admin/test_render.js`
- `test_admin.js`
- quatro arquivos de upload com problema de encoding no nome (`Chal�`), substituidos por equivalentes com nome acentuado correto no atual.

## Arquivos alterados

- `.htaccess`
- `admin/admin.css`
- `admin/admin.js`
- `admin/index.html`
- `admin/login.html`
- `api/auth.php`
- `api/chalets.php`
- `api/check_settings.php`
- `api/customization.php`
- `api/db.php`
- `api/mp_webhook.php`
- `api/reservations.php`
- `api/seed_defaults.php`
- `api/send_webhook.php`
- `api/settings.php`
- `api/test_db.php`
- `api/users.php`
- `index.php`
- `script.js`
- `styles.css`

## Arquivos iguais

Exemplos: `database.sql`, `package.json`, `package-lock.json`, `test_browser.js`, imagens base em `images/` e alguns uploads.

## Melhorias de seguranca aplicadas

- `api/users.php` passou a exigir autenticacao administrativa em todos os metodos.
- `api/chalets.php` passou a exigir autenticacao nos metodos mutaveis.
- `api/debug_env.php`, `api/test_db.php` e `api/seed_defaults.php` foram restringidos a admin e `APP_DEBUG=true`.
- `api/schema.php` bloqueia acesso HTTP direto.
- `api/sync_assets.php` exige `SYNC_ASSETS_KEY` configurada e valida.
- CORS em `api/db.php` deixou de refletir qualquer origem arbitraria e passou a usar allowlist.
- Mutacoes administrativas validam `Origin` ou `Referer`.
- Cookie administrativo usa `HttpOnly`, `SameSite=Lax` e `Secure` quando HTTPS.
- `.htaccess` bloqueia `.env`, `config`, backups, `vendor`, `node_modules`, dumps, logs e ZIPs no Apache/LiteSpeed.

## Alteracoes visuais e de UX

- Site publico e painel receberam textos mais padronizados em portugues Brasil.
- Painel ganhou controles para financeiro, relatorios, cupons, extras, regras sazonais, FNRH, Evolution e contrato.
- Foram adicionados estados vazios e mensagens mais amigaveis em diversos fluxos.
- `admin/admin.css` recebeu melhorias para painel, acoes em tabela, conta da hospedagem e responsividade.

## Alteracoes em APIs

O projeto antigo tinha APIs centrais como `db.php`, `chalets.php`, `reservations.php`, `settings.php`, `customization.php`, `auth.php`, `users.php`, `mp_webhook.php`, `seed_defaults.php`, `test_db.php`.

O projeto atual adicionou uma camada muito maior de endpoints para disponibilidade, precificacao, cupons, extras, consumos, relatorios, contratos, FNRH e Evolution.

## Alteracoes em banco/schema/setup

O ZIP antigo criava/alterava tabelas principalmente em `api/db.php`. O atual adicionou `api/schema.php` e `setup.php` com instalador e migrador idempotente.

Novas tabelas relevantes no schema atual:

- `seasonal_rules`
- `stay_discounts`
- `coupons`
- `extra_services`
- `reservation_consumptions`
- `faqs`

Novas colunas relevantes:

- `admins.auth_token`
- `reservations.expires_at`, `mp_init_point`, `contract_filename`, `last_contract_sent_at`, `balance_paid`, `balance_paid_at`, `coupon_code`, `discount_amount`, `extras_json`, `extras_total`, `fnrh_access_token`, `fnrh_data`, `guest_cpf`, `guest_address`, `guest_car_plate`, `guest_companion_names`, `fnrh_status`, `fnrh_submitted_at`, `fnrh_last_response`, `additional_value`, `children_ages`, `brings_pet`, `payment_method`
- `chalets.base_guests`, `extra_guest_fee`, `max_guests`
- `seasonal_rules.rule_type`, `recurring_days`, datas nullable
- `personalizacao.loc_subtitulo`, `loc_map_embed`, `videos_enabled`, `videos_json`, `logo_principal`, `logo_alternativa`

## Mercado Pago

- O webhook atual tenta gerar contrato PDF apos confirmacao de pagamento, mas captura falha para nao travar totalmente o fluxo.
- `create_preference.php` trata token ausente com mensagem segura.
- O painel exibe configuracao de webhook e token em area administrativa.

## Evolution API

- Foram adicionados `evolution_instance.php` e `evolution_service.php`.
- Ha fluxos de mensagens de reserva, check-in, check-out, recibo e contrato via WhatsApp.
- Envios reais dependem de configuracao da Evolution e devem ser testados apenas em homologacao controlada.

## FNRH

- Foram adicionados campos de hospede e endpoints/servicos para FNRH.
- O fluxo fica ligado a check-in e status FNRH.

## Contratos, PDF e CSV

- CSV real: `api/reports.php?action=export` gera `text/csv` usando `fputcsv` e `php://output`.
- PDF real: `api/contract_service.php`, `api/generate_contract.php`, `api/download_contract.php` e trechos de `api/evolution_service.php` usam Dompdf para contratos e recibos.
- Sem `vendor/autoload.php` e Dompdf, relatorios CSV continuam independentes, mas contrato/recibo PDF falham.

## Impacto funcional

O sistema atual e muito mais amplo que o ZIP antigo. Ele adiciona gestao de precos, disponibilidade, cupons, extras, consumos, relatorios, FNRH, Evolution, contrato e controle administrativo mais forte.

## Impacto de seguranca

As mudancas reduzem riscos criticos do ZIP antigo, principalmente APIs administrativas abertas e diagnosticos expostos.

## Impacto visual

O visual atual esta mais completo e funcional, mas ainda ha oportunidades de polimento em consistencia de botoes, reducao de alerts, densidade de tabelas e responsividade de acoes administrativas.

## Impacto no banco

Ha alteracoes substanciais de schema. A estrategia atual tenta ser aditiva/idempotente, mas deve ser aplicada primeiro em copia de homologacao com backup, porque `api/db.php` executa varios `ALTER TABLE` automaticamente quando incluido.

