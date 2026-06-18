# Relatorio final pre-subida cliente

Data: 2026-06-17

## Veredito

Pode subir a atualizacao para homologacao/cliente sem `vendor/`, desde que contratos/recibos PDF sejam tratados como indisponiveis ate a instalacao futura do Dompdf.

O fluxo principal permanece independente de `vendor/`:

- site publico;
- reservas;
- painel basico;
- personalizacao;
- relatorios CSV.

PDF fica opcional e protegido por mensagem segura: `Recurso de PDF indisponível neste ambiente.`

## Correcoes finais aplicadas

- Corrigida a aplicacao dinamica da logo para considerar `customization.logoPrincipalImg` e `customization.logoAlternativaImg`.
- Removidos estilos inline principais da logo no header e criadas classes CSS para logo responsiva.
- Corrigido texto visivel `Fale connosco` para `Fale conosco`.
- Removido comentario interno `MOCK` do fluxo real de reserva.
- Criado endpoint `api/system_capabilities.php`.
- PDF passou a ser capacidade opcional, sem erro fatal quando `vendor/`/Dompdf nao existem.
- Webhook Mercado Pago nao tenta gerar contrato automaticamente se PDF estiver indisponivel.
- Painel desabilita/oculta acoes de contrato/recibo PDF quando backend informa PDF indisponivel.
- Evolution foi centralizada em funcoes `evo_go_*`, mantendo aliases antigos para compatibilidade interna.
- Evolution Go foi revisada para nao usar envio de botao; todo fluxo com botao foi convertido para mensagem de texto.
- Novas variaveis `.env.example` para Evolution Go: `EVOLUTION_GO_BASE_URL`, `EVOLUTION_GO_API_KEY`, `EVOLUTION_GO_INSTANCE`.
- `api/db.php` nao remove mais `evo_url` legado automaticamente.
- Criada migracao SQL segura/aditiva em `docs_migracao`.
- Criado checklist de migracao em `docs_migracao`.

## Correcao complementar - WhatsApp / Evolution Go

Aplicada em 2026-06-17.

- Area do painel renomeada para `WhatsApp / Evolution Go`.
- Tela de conexao voltou a ficar visivel no painel admin, sem depender de `vendor/`/Dompdf e sem depender exclusivamente de `.env`.
- Campos visiveis no painel: URL da Evolution Go, API Key e nome da instancia.
- Botoes visiveis no painel: Salvar Configuracao, Criar Instancia, Conectar WhatsApp, Verificar Conexao, Resetar/Apagar Instancia e Validar Mensagem.
- QR Code agora e exibido em area propria dentro do painel.
- `api/evolution_instance.php` agora aceita as acoes `get_config`, `save_config`, `create`, `connect`, `status` e `reset`, mantendo aliases antigos `get_qr`, `check_status`, `disconnect`, `qrcode` e `delete`.
- Configuracao Evolution Go passa a ler primeiro as settings `evolution_go_base_url`, `evolution_go_api_key` e `evolution_go_instance`, com fallback seguro para `.env`.
- API key salva nao e retornada inteira para o painel; apenas mascara e status de configuracao.
- Migração segura atualizada apenas com inserts aditivos das novas settings, sem apagar settings legadas.
- `.env.example` e `.env.homologacao.example` usam placeholders ficticios para Evolution Go.

## Arquivos alterados/criados

- `.env.example`
- `.env.homologacao.example`
- `index.php`
- `script.js`
- `styles.css`
- `admin/admin.js`
- `api/contract_service.php`
- `api/debug_env.php`
- `api/db.php`
- `api/evolution_instance.php`
- `api/evolution_service.php`
- `api/generate_contract.php`
- `api/mp_webhook.php`
- `api/schema.php`
- `api/settings.php`
- `api/system_capabilities.php`
- `docs_migracao/MIGRACAO_SEGURA_BANCO_EXISTENTE_2026_06_17.sql`
- `docs_migracao/CHECKLIST_MIGRACAO_BANCO_EXISTENTE.md`

## Melhorias de layout aplicadas

- Logo do header agora usa classe `.site-logo-img`.
- Logo usa `object-fit: contain`, largura/altura maxima e nao e cortada.
- Suporte melhor para logo horizontal, vertical ou quadrada.
- Logo do footer e da secao sobre tambem considera a personalizacao nova.
- Overlay do hero ficou mais coerente com pousada/natureza.
- Cards de chales ganharam proporcao de imagem mais consistente.
- Blocos do modal de reserva ganharam separacao visual leve.
- Mobile recebeu limite menor de logo.

## Status da personalizacao

A personalizacao continua ativa.

- Area no painel: `Personalizacao`.
- Admin completo consegue ver a area.
- Perfil limitado pode ocultar por permissao, sem significar remocao.
- Campos preservados: favicon, logo principal, logo alternativa, hero, sobre, hospedagens, diferenciais, depoimentos, localizacao, videos, WhatsApp flutuante e rodape.
- O site publico carrega dados por `index.php` e por `script.js`.

## Status da logo

Corrigido o ponto principal: o JavaScript nao depende mais apenas de `company_logo`. Agora considera:

1. `customization.logoPrincipalImg`;
2. `customization.logoAlternativaImg`;
3. `company_logo`;
4. `company_logo_light`;
5. fallback textual.

## Status sem vendor/Dompdf

- `api/contract_service.php` verifica se `vendor/autoload.php` e Dompdf existem antes de gerar contrato.
- `api/generate_contract.php` retorna 503 com mensagem segura quando PDF esta indisponivel.
- `api/evolution_service.php` nao gera recibo/contrato PDF se PDF estiver indisponivel.
- `api/mp_webhook.php` ignora geracao automatica de contrato quando PDF esta indisponivel.
- `admin/admin.js` desabilita acoes de contrato/recibo PDF quando `pdf_available=false`.

## Status dos relatorios CSV

Relatorios CSV continuam independentes de `vendor/` e Dompdf.

O pacote mantem `api/reports.php`. Nenhuma alteracao foi feita no fluxo CSV.

## Evolution Go

Todas as chamadas WhatsApp/Evolution do sistema continuam passando pelo backend, sem expor API key no front-end.

Funcoes centralizadas/adaptadas:

- `evo_go_request()`
- `evo_go_send_text()`
- `evo_go_send_media()`
- `evo_go_format_number()`
- `evo_go_is_configured()`
- `evo_go_button_to_text_message()`

Aliases preservados para compatibilidade:

- `evo_send_text()`
- `evo_send_button()`
- `evo_send_media()`
- `evo_send_pix()`

Observacao importante: `evo_send_button()` e `evo_go_send_button()` permanecem apenas como compatibilidade interna. Elas nao chamam endpoint de botao, nao montam payload `buttons` e convertem o conteudo para texto enviado por `evo_go_send_text()`.

## Funcoes WhatsApp/Evolution mapeadas

- Status da instancia: `api/evolution_instance.php`.
- Conexao/QR code: `api/evolution_instance.php`.
- Validacao de configuracao: `api/system_capabilities.php` e `evo_go_is_configured()`.
- Envio de texto: `evo_go_send_text()`.
- Envio de reserva/confirmacao/check-in/check-out/saldo: `evo_notify_event()`.
- Envio de PIX/pagamento: `evo_send_pix()` usando texto com chave PIX no corpo da mensagem.
- Botao WhatsApp: desativado por instabilidade no ambiente atual.
- Envio de recibo PDF: mantido, mas bloqueado sem PDF.
- Envio de contrato PDF: mantido, mas bloqueado sem PDF.
- Envio de midia/documento: `evo_go_send_media()`, disponivel apenas quando houver PDF gerado.
- Teste pelo painel: mantido, com PDF desabilitado se indisponivel.
- Webhook Mercado Pago: notifica por WhatsApp, mas nao gera PDF se Dompdf estiver ausente.

## Endpoints Evolution

Implementados/mantidos pela camada Evolution Go:

- `POST /message/sendText/{instance}`
- `POST /message/sendMedia/{instance}`
- `POST /instance/create`
- `GET/POST /instance/connect/{instance}`
- `GET /instance/connectionState/{instance}`
- `DELETE /instance/delete/{instance}`

Observacao: nao havia documentacao local da Evolution Go no projeto. A implementacao preserva endpoints simples ja usados pelo sistema, centraliza a camada e altera configuracao/nomenclatura para Evolution Go. O envio com botao foi removido/desativado; mensagens com link, PIX ou instrucoes seguem como texto.

## Banco e migracao

Criados:

- `docs_migracao/MIGRACAO_SEGURA_BANCO_EXISTENTE_2026_06_17.sql`
- `docs_migracao/CHECKLIST_MIGRACAO_BANCO_EXISTENTE.md`

A migracao:

- cria tabelas ausentes com `CREATE TABLE IF NOT EXISTS`;
- adiciona colunas ausentes via `INFORMATION_SCHEMA`;
- insere settings padrao apenas se a chave nao existir;
- adiciona settings Evolution Go sem apagar settings antigas;
- nao usa `DROP TABLE`;
- nao usa `TRUNCATE`;
- nao usa comando executavel `DELETE FROM`;
- nao apaga `personalizacao`, `chalets`, `reservations` ou `settings`;
- nao altera usuario/senha admin existente.

## Como aplicar sem apagar dados

1. Fazer backup do banco real.
2. Fazer backup dos arquivos atuais.
3. Criar homologacao com copia do banco.
4. Executar a migracao apenas na copia.
5. Subir o pacote na homologacao.
6. Conferir site, painel, personalizacao, reservas e CSV.
7. So depois repetir em producao.

Nao rodar:

- `setup.php`;
- `setup.sql`;
- restore sobre banco real.

## O que preservar na Hostinger

- Banco MySQL atual.
- `config/database.php`.
- `.env`, se existir no servidor.
- `images/uploads/`.
- `storage/`.
- arquivos ja gerados/necessarios do cliente.

## O que nao subir

- `.env` local.
- `config/database.php` local.
- `config/.installed.lock`.
- `vendor/`.
- `node_modules/`.
- `backups/`.
- `.git/`.
- `.cursor/`.
- `setup.sql` no runtime.
- SQLs antigos perigosos.
- logs.
- dumps.
- tokens.
- chaves.
- credenciais.
- zips antigos.

## Validacoes executadas

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

Tambem foi validado por busca estatica que o pacote final nao contem `/message/sendButtons`, `/send/button`, `send/button` nem payload `buttons` nos arquivos criticos.

Nao executado:

- PHP lint: `php` nao esta disponivel no PATH local.
- Composer: nao executado por instrucao do projeto e ausencia local.
- Envio real WhatsApp: nao executado.
- Pagamento real Mercado Pago: nao executado.
- Migracao real: nao executada.

`git diff` nao foi executado porque `git` nao esta disponivel no PATH local.

## ZIP final

Nome:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Caminho:

`C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\sistema_hospedagem\sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Tamanho:

- 3.735.615 bytes

Quantidade de arquivos:

- 74

SHA-256:

`698F51E76614D5C01EF5E784E83AB87354DB4334F6C511D20D2C1E26A12D6E62`

## Validacao do ZIP

Confirmado:

- `index.php` presente.
- `script.js` presente.
- `styles.css` presente.
- `admin/admin.js` presente.
- `api/evolution_service.php` presente.
- `api/system_capabilities.php` presente.
- migracao segura em `docs_migracao` presente.
- `.env` ausente.
- `config/database.php` ausente.
- `vendor/` ausente.
- `node_modules/` ausente.
- `backups/` ausente.
- `setup.sql` ausente.
- SQL perigoso de runtime ausente.
- logs ausentes.
- SQLite ausente.
- zips antigos ausentes.

## Checklist de subida

1. Fazer backup do banco e arquivos atuais.
2. Subir o ZIP em homologacao.
3. Preservar `config/database.php` e `.env` da hospedagem.
4. Nao substituir uploads/storage reais sem conferencia.
5. Aplicar migracao segura apenas apos backup e primeiro em copia.
6. Acessar home e conferir logo/personalizacao.
7. Entrar no painel como admin completo.
8. Conferir menu `Personalizacao`.
9. Fazer reserva teste sem pagamento real.
10. Baixar CSV.
11. Conferir que PDF aparece indisponivel se `vendor/` nao existir.
12. Configurar Evolution Go apenas com credenciais reais quando for operar de verdade.
13. Usar validacao/dry-run no painel para testes; nao enviar WhatsApp real em teste com cliente real.
14. Nao chamar Mercado Pago real durante homologacao.

## Pendencias futuras

- Instalar/gerar `vendor/` e Dompdf quando contratos/recibos PDF forem usados.
- Validar endpoint simples de texto/midia da Evolution Go com documentacao oficial/ambiente do fornecedor antes de ativar envio real.
- Rodar PHP lint em ambiente com PHP.
- Testar HTTP real na Hostinger.
- Testar `.htaccess` bloqueando `.env`, config, SQL, ZIP e storage privado.
- Homologar Mercado Pago em sandbox.

## Respostas diretas

1. Posso subir sem vendor?
   - Sim, para fluxo principal e CSV. PDF fica indisponivel com fallback seguro.

2. CSV continua funcionando?
   - Sim, o fluxo CSV nao depende de Dompdf.

3. PDF quebra?
   - Nao deve quebrar fluxo principal. Quando chamado, retorna mensagem segura.

4. Personalizacao continua?
   - Sim, continua no painel e no site publico.

5. Pagina publica ficou melhor?
   - Sim, com ajustes pontuais de logo, hero, cards e modal.

6. Evolution foi migrada?
   - A camada do sistema foi centralizada/adaptada para Evolution Go, mantendo aliases internos para nao reescrever todo o sistema.

7. Botao de reserva no WhatsApp foi implementado?
   - Nao. A decisao atual e nao usar botao na Evolution Go. O fluxo foi convertido para texto com chave/link/instrucoes.

8. Existe fallback para botao?
   - O texto agora e o fluxo principal. Nao ha tentativa previa de botao.

9. Ha migracao segura?
   - Sim, em `docs_migracao`, aditiva e sem apagamento.

10. Posso usar o mesmo banco atual?
   - Sim, com backup, homologacao e preservando `config/database.php`, `.env`, uploads e storage.

11. Qual ZIP subir?
   - `sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`.

---

## Atualizacao 2026-06-18 - Campanhas WhatsApp com cron externo

Foi implementado o modulo `Campanhas WhatsApp` para uso em hospedagem compartilhada, sem terminal, sem VPS e sem worker residente.

Arquivos principais:

- `docs_migracao/MIGRACAO_CAMPANHAS_WHATSAPP_2026_06_18.sql`
- `api/whatsapp_campaign_lib.php`
- `api/whatsapp_campaigns.php`
- `api/whatsapp_campaign_worker.php`
- `api/cron_provider_service.php`
- `admin/index.html`
- `admin/admin.js`

Escopo entregue:

- campanhas somente para hospedes/clientes ja cadastrados em `reservations`;
- sem CSV, listas externas ou numeros colados;
- deduplicacao por telefone normalizado;
- opt-out em tabela propria;
- fila em banco com `scheduled_at`;
- delays e pausas salvos em configuracao;
- worker HTTP protegido por chave;
- cron externo via URL manual ou integracao opcional com cron-job.org;
- envio via Evolution API normal, usando texto ou imagem;
- sem `sendButtons`, `/send/button` ou payload `buttons`.

Validacao local:

- `node --check script.js`: OK
- `node --check admin/admin.js`: OK
- `node --check test_browser.js`: OK
- `php -l`: nao executado porque `php` nao esta no PATH deste ambiente.

Nao foi executado:

- migracao real no banco;
- envio WhatsApp real;
- sincronizacao real com cron-job.org;
- teste HTTP real em Hostinger.

Relatorio detalhado:

- `_relatorios/2026-06-18_12-18/RELATORIO_IMPLEMENTACAO_CAMPANHAS_WHATSAPP_CRONJOB_ORG.md`

ZIP atualizado:

- `sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`
- tamanho: `3.761.851` bytes
- arquivos no ZIP: `84`
- SHA-256: `675982F031FEB4B46A097712874D04F84F6A07E2D76A02B8AAF463BDF2BA32A0`

Orientacao de subida:

1. Fazer backup do banco e arquivos.
2. Subir o ZIP atualizado.
3. Executar manualmente a migracao `docs_migracao/MIGRACAO_CAMPANHAS_WHATSAPP_2026_06_18.sql`.
4. Configurar a Worker URL no cron-job.org a cada 1 minuto, ou usar a integracao opcional do painel.
5. Homologar com campanha pequena e controlada antes de liberar uso operacional.
