# Relatorio de Auditoria de Bugs e Riscos

Data: 17 de junho de 2026.

## Validacoes executadas

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

Nao executados:

- PHP lint: PHP indisponivel.
- Composer validate: Composer indisponivel.
- Testes HTTP reais: sem servidor PHP/MySQL nesta sessao.
- Testes de pagamento, Evolution e FNRH: nao executados por seguranca.

## Bugs e riscos objetivos

### R1 - Fluxo PDF depende de Dompdf ausente

- Arquivos: `api/contract_service.php`, `api/generate_contract.php`, `api/evolution_service.php`, `api/mp_webhook.php`.
- Impacto: gerar contrato, recibo PDF e envio de PDF por WhatsApp falham sem `vendor/autoload.php` e Dompdf.
- Recomendacao: se for usar contratos, instalar `dompdf/dompdf`; se nao for usar, ocultar/desabilitar botoes de contrato ate instalar.

### R2 - `setup.sql` contem DELETE em tabelas de conteudo

- Arquivo: `setup.sql`.
- Impacto: rodar em banco real pode apagar `personalizacao` e `chalets`.
- Recomendacao: nao usar `setup.sql` em producao; manter apenas como referencia/instalacao vazia.

### R3 - `api/db.php` aplica ALTER TABLE automaticamente

- Arquivo: `api/db.php`.
- Impacto: ao subir arquivos em banco antigo, a primeira chamada pode tentar alterar schema automaticamente.
- Recomendacao: testar em homologacao; confirmar permissoes MySQL; fazer backup antes.

### R4 - `api/db.php` remove chave legada `evo_url`

- Arquivo: `api/db.php`.
- Trecho: `DELETE FROM settings WHERE setting_key = 'evo_url'`.
- Impacto: apaga configuracao legada se existir.
- Recomendacao: documentar migracao Evolution e preservar configuracoes atuais antes.

### R5 - PDF no webhook pode falhar silenciosamente

- Arquivo: `api/mp_webhook.php`.
- Impacto: pagamento pode confirmar, mas contrato nao ser gerado.
- Recomendacao: criar monitoramento/log visivel no painel para falha de contrato.

### R6 - Hostinger pode nao respeitar `.htaccess` se configuracao variar

- Arquivos: `.htaccess`, `api/.htaccess`, `images/uploads/.htaccess`, `storage/*/.htaccess`.
- Impacto: arquivos sensiveis podem ficar expostos se regras nao forem aplicadas.
- Recomendacao: testar URLs diretas para `.env`, `config/database.php`, SQL, ZIP e storage.

### R7 - Dependencia de extensoes PHP

- Arquivos: `api/db.php`, uploads, cURL, Mercado Pago, Evolution.
- Impacto: uploads precisam `gd` e `fileinfo`; integrações precisam `curl`; DB precisa `pdo_mysql`.
- Recomendacao: conferir extensoes ativas na Hostinger.

### R8 - Alertas no front ainda expõem experiencia inconsistente

- Arquivos: `script.js`, `admin/admin.js`.
- Impacto: UX irregular e mensagens tecnicas ocasionais.
- Recomendacao: substituir gradualmente por toast/modal padrao.

### R9 - Instalacao sobre banco existente

- Arquivos: `setup.php`, `api/schema.php`, `api/db.php`.
- Impacto: risco operacional se rodar instalador em banco com dados.
- Recomendacao: preservar `config/database.php`; nao rodar `setup.php`; testar atualizacao em copia.

### R10 - Mercado Pago e Evolution exigem ambiente controlado

- Arquivos: `api/create_preference.php`, `api/mp_webhook.php`, `api/evolution_service.php`, `api/evolution_instance.php`.
- Impacto: chamadas reais podem cobrar/enviar mensagens.
- Recomendacao: validar apenas sandbox/numeros de teste.

## Segurança

Melhorias confirmadas por leitura:

- Usuarios admin protegidos.
- Mutacoes de chales protegidas.
- Debug/test/seed bloqueados por admin/debug.
- CORS com allowlist.
- `sync_assets.php` exige chave.
- Settings sensiveis filtradas em GET publico.

Risco residual:

- Necessario testar 401/403 por HTTP real.
- Necessario testar bloqueios de `.htaccess` na Hostinger.

## Banco

Risco principal e operacional: schema atual adiciona tabelas/colunas automaticamente. Nao ha evidencia de `DROP TABLE` ou `TRUNCATE`, mas ha `DELETE` em utilitarios/acoes administrativas.

## Recomendacoes

1. Fazer backup de arquivos e banco antes de qualquer deploy.
2. Testar em subdominio de homologacao com copia do banco.
3. Nao rodar `setup.php` em producao existente.
4. Preservar `.env`, `config/database.php`, uploads e storage gerado.
5. Validar se contratos PDF serao usados; se sim, resolver Dompdf antes.
6. Executar PHP lint e testes HTTP reais em ambiente com PHP.

