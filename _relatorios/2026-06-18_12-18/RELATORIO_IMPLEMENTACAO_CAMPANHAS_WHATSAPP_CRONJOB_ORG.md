# Relatorio de Implementacao - Campanhas WhatsApp com cron-job.org

Data: 2026-06-18 12:18

## Veredito

Implementacao concluida no codigo do projeto, sem executar migracao real, sem disparar WhatsApp real, sem chamar Mercado Pago e sem usar worker de terminal/VPS.

O recurso foi desenhado para Hostinger compartilhada: fila em MySQL, agendamento por destinatario no banco e worker HTTP acionado por cron externo. O worker processa por padrao 1 destinatario por chamada e nao usa `sleep`, loop longo ou processo residente.

## Arquivos criados

- `docs_migracao/MIGRACAO_CAMPANHAS_WHATSAPP_2026_06_18.sql`
- `api/whatsapp_campaign_lib.php`
- `api/whatsapp_campaigns.php`
- `api/whatsapp_campaign_worker.php`
- `api/cron_provider_service.php`
- `_relatorios/2026-06-18_12-18/RELATORIO_IMPLEMENTACAO_CAMPANHAS_WHATSAPP_CRONJOB_ORG.md`

## Arquivos alterados

- `admin/index.html`
- `admin/admin.js`
- `RELATORIO_FINAL_PRE_SUBIDA_CLIENTE_2026_06_17.md`

## Banco de dados

Foi criada migracao manual e idempotente:

- `whatsapp_campaigns`
- `whatsapp_campaign_recipients`
- `whatsapp_opt_outs`
- `whatsapp_campaign_settings`

O script usa `CREATE TABLE IF NOT EXISTS` e inserts condicionais em `whatsapp_campaign_settings`.

Nao foram usados `DROP TABLE`, `TRUNCATE`, alteracao destrutiva em reservas/usuarios, nem execucao automatica da migracao.

## Funcionamento implementado

- Campanhas sao criadas apenas a partir de hospedes/clientes ja existentes em `reservations`.
- Fonte de dados: `guest_name`, `guest_phone`, `status`, `checkout_date`, `created_at`.
- Nao existe campo para CSV, lista externa ou numeros colados.
- Telefones sao normalizados e deduplicados por campanha.
- Opt-out fica em `whatsapp_opt_outs`.
- Mensagens aceitam variaveis `{nome}`, `{primeiro_nome}`, `{pousada}` e `{telefone}`.
- Imagem opcional e aceita em JPG, PNG ou WEBP, ate 5 MB.
- Campanhas suportam `draft`, `sending`, `paused`, `cancelled` e `completed`.
- Worker valida `worker_key` por query string ou header `X-Worker-Key`.
- Worker processa no maximo `max_per_worker_run`, default 1.
- Pausas e delays sao materializados em `scheduled_at` por destinatario.
- O envio usa Evolution API normal:
  - texto: `/message/sendText/{instance}`
  - imagem: `/message/sendMedia/{instance}` com `mediatype=image`
- Nao foi reintroduzido `sendButtons`, `/send/button` ou payload `buttons`.

## Painel admin

Foi adicionada a tela `Campanhas WhatsApp`:

- titulo interno
- mensagem
- publico selecionavel dentro da base de reservas
- imagem opcional
- previa de publico
- dry-run/previa operacional
- iniciar campanha
- lista de progresso
- detalhes
- pausar, retomar e cancelar
- configuracoes tecnicas de automacao

A area `Configuracoes de Automacao` inclui:

- Worker URL
- chave do worker mascarada
- API key opcional do cron-job.org
- Job ID
- delay minimo/maximo
- quantidade por chamada
- opt-out automatico
- copiar URL
- gerar nova chave
- criar/atualizar job no cron-job.org
- desativar job
- instrucoes manuais para cron-job.org

## cron-job.org

Baseado na documentacao oficial consultada:

- endpoint base: `https://api.cron-job.org/`
- autenticacao: header `Authorization: Bearer <apiKey>`
- payload JSON
- criar job: `PUT /jobs`
- atualizar job: `PATCH /jobs/{jobId}`
- metodo HTTP GET representado por `requestMethod = 0`
- agenda com arrays `minutes`, `hours`, `mdays`, `months`, `wdays`

Fonte oficial: https://docs.cron-job.org/rest-api.html

O painel tambem permite configuracao manual: criar um HTTP GET para a Worker URL com intervalo de 1 minuto.

## Seguranca e limites

- API key da Evolution nao e exibida pela nova tela.
- API key do cron-job.org e salva apenas se informada e volta mascarada.
- Chave do worker volta mascarada; a URL completa e exibida apenas dentro do admin autenticado para permitir configuracao manual do cron.
- Worker rejeita chamada sem chave correta.
- Endpoint administrativo exige sessao admin via `be_require_admin_auth`.
- Upload de imagem bloqueia extensoes PHP na pasta `uploads/campaigns`.
- Nao ha envio para numeros externos ao banco.
- Nao ha worker residente.
- Nao ha dependencia de Composer/vendor.

## Validacoes executadas

Executado com sucesso:

- `node --check script.js`
- `node --check admin/admin.js`
- `node --check test_browser.js`

Buscas estaticas:

- `sendButtons|/send/button|buttons`: sem ocorrencias nos arquivos novos/alterados relevantes.
- `DROP TABLE|TRUNCATE|DELETE FROM reservations|DELETE FROM admins|ALTER TABLE reservations|setup.sql|setup.php`: sem ocorrencias na migracao e nos endpoints de campanha.

ZIP regenerado:

- `sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`
- tamanho: `3.761.851` bytes
- arquivos no ZIP: `84`
- SHA-256: `675982F031FEB4B46A097712874D04F84F6A07E2D76A02B8AAF463BDF2BA32A0`
- novos arquivos de campanha presentes no ZIP: sim
- padrões proibidos inspecionados no ZIP (`.env`, `config/`, `vendor/`, `node_modules/`, `backups/`, `.git/`, `.cursor/`, `sessions/`, zips antigos, dumps e logs): sem ocorrencias.

Nao executado:

- `php -l`, porque `php` nao esta no PATH deste ambiente.
- Migracao SQL real.
- Envio WhatsApp real.
- Sincronizacao real com cron-job.org.
- Teste HTTP real em Hostinger.

## Checklist para homologacao

1. Fazer backup do banco e arquivos atuais.
2. Subir o ZIP em ambiente de homologacao.
3. Preservar `.env`, `config/database.php`, uploads e storage reais.
4. Executar manualmente `docs_migracao/MIGRACAO_CAMPANHAS_WHATSAPP_2026_06_18.sql`.
5. Entrar como admin completo.
6. Abrir `Campanhas WhatsApp`.
7. Configurar/confirmar Evolution API normal ja conectada.
8. Conferir Worker URL em `Configuracoes de Automacao`.
9. No cron-job.org, criar job GET a cada 1 minuto apontando para a Worker URL, ou salvar API key e usar o botao de criar/atualizar.
10. Fazer uma campanha pequena em ambiente controlado.
11. Conferir pausa, retomada, cancelamento e contadores.

## Pendencias de runtime

- Validar PHP lint em servidor com PHP.
- Validar execucao da migracao no banco real.
- Validar envio Evolution API normal com credenciais reais.
- Validar chamada do worker pelo cron-job.org em homologacao.
