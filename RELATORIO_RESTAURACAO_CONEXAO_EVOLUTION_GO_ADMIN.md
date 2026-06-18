# Relatorio - Restauracao da conexao WhatsApp / Evolution Go no admin

Data: 2026-06-17

## Escopo

Restaurar a experiencia operacional de conexao WhatsApp/Evolution no painel admin, mantendo Evolution Go como camada tecnica e mantendo envio por botao desativado.

Nao foram executados setup, SQL, migracao real, restore, Composer, envio WhatsApp real ou Mercado Pago real.

## Comparacao com o ZIP antigo

Arquivo antigo analisado:

`C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\recantodaserra.zip`

No `recantodaserra.zip`, o fluxo visivel de Evolution/WhatsApp no admin ficava na area de comunicacao/integracoes e gravava configuracoes via `api/settings.php`.

Campos encontrados no admin antigo:

- URL da Evolution.
- Instancia do cliente.
- API key do cliente.
- Instancia da pousada/empresa.
- API key da pousada/empresa.
- WhatsApp da pousada/empresa.
- Mensagem de reserva.

Arquivos antigos relacionados:

- `admin/admin.js`
- `api/settings.php`
- `api/db.php`

Na copia antiga inspecionada nao foram encontrados arquivos separados `api/evolution_instance.php` ou `api/evolution_service.php`. O pacote atual passa a concentrar a operacao de instancia nesses endpoints novos, preservando a experiencia esperada no painel.

## O que estava quebrado/removido

- A area de conexao no painel atual ficava condicionada ao modo `evolution_global_managed`.
- Quando `.env` nao tinha configuracao global completa, a tela operacional podia sumir.
- O painel nao mostrava campos diretos para URL, API key e instancia.
- O fluxo de instancia nao estava claramente exposto como salvar, criar, conectar, QR, status e reset.
- A API de instancia dependia obrigatoriamente de `.env`, dificultando o uso por configuracao salva no banco.

## O que foi restaurado

Area do painel:

`WhatsApp / Evolution Go`

Campos restaurados/adaptados:

- URL da Evolution Go.
- API Key.
- Nome da instancia.
- Status visual da conexao.
- Area fixa para QR Code.

Botoes restaurados/adaptados:

- Salvar Configuracao.
- Criar Instancia.
- Conectar WhatsApp.
- Verificar Conexao.
- Resetar/Apagar Instancia.
- Validar Mensagem.

## Onde aparece agora

No painel admin, dentro da tela de configuracoes/comunicacao, na area:

`WhatsApp / Evolution Go`

A area fica visivel para o admin completo e nao depende de `vendor/`/Dompdf.

## Fluxo operacional

1. Preencher URL da Evolution Go, API Key e nome da instancia.
2. Clicar em `Salvar Configuracao`.
3. Clicar em `Criar Instancia`.
4. Clicar em `Conectar WhatsApp`.
5. O QR Code aparece na area fixa do painel.
6. Escanear o QR no WhatsApp da pousada.
7. Clicar em `Verificar Conexao`.
8. Se falhar, clicar em `Resetar/Apagar Instancia`, confirmar e criar/conectar novamente.

## Backend atualizado

Arquivo principal:

- `api/evolution_instance.php`

Acoes suportadas:

- `get_config`
- `save_config`
- `create`
- `connect`
- `status`
- `reset`

Aliases mantidos por compatibilidade:

- `get_qr`
- `qrcode`
- `check_status`
- `disconnect`
- `delete`

## Settings e banco

Novas settings usadas sem apagar settings antigas:

- `evolution_go_base_url`
- `evolution_go_api_key`
- `evolution_go_instance`

Settings legadas preservadas:

- `evo_url`
- `evo_apikey`
- `evo_instance`
- `evolution_go_apikey`

Arquivos atualizados:

- `api/db.php`
- `api/schema.php`
- `api/settings.php`
- `docs_migracao/MIGRACAO_SEGURA_BANCO_EXISTENTE_2026_06_17.sql`
- `docs_migracao/CHECKLIST_MIGRACAO_BANCO_EXISTENTE.md`

A migracao segura foi atualizada apenas com inserts aditivos e nao foi executada.

## Endpoints Evolution Go usados

Centralizados no codigo:

- `POST /instance/create`
- `GET /instance/connect/{instance}`
- `POST /instance/connect/{instance}` como fallback na conexao.
- `GET /instance/connectionState/{instance}`
- `DELETE /instance/delete/{instance}`
- `POST /message/sendText/{instance}`
- `POST /message/sendMedia/{instance}`

Funcoes centrais existentes/adicionadas:

- `evo_go_request()`
- `evo_go_instance_create()`
- `evo_go_instance_connect()`
- `evo_go_instance_status()`
- `evo_go_instance_delete()`
- `evo_go_send_text()`
- `evo_go_send_media()`
- `evo_go_is_configured()`
- `evo_go_format_number()`

## Envio com botao

Confirmacao:

- Nao ha chamada a `/message/sendButtons`.
- Nao ha chamada a `/send/button`.
- Nao ha chamada a `send/button`.
- Nao ha payload `buttons` nos arquivos criticos do pacote.
- `evo_go_send_button()` e `evo_send_button()` permanecem apenas como aliases de compatibilidade interna e convertem o conteudo para texto via `evo_go_send_text()`.

O fluxo principal e texto/midia simples, sem tentar botao primeiro.

## Credenciais e exemplos

Arquivos verificados:

- `.env.example`
- `.env.homologacao.example`
- `.gitignore`

Resultado:

- `.env.example` usa placeholders ficticios.
- `.env.homologacao.example` usa placeholders ficticios.
- `.env` continua ignorado.
- `.env.*` continua ignorado, com excecao de `.env.example` e `.env.homologacao.example`.
- A API key salva nao e exibida inteira no painel; o retorno mostra apenas mascara/status.
- Nenhuma credencial real foi impressa neste relatorio.

## Validacoes executadas

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

Busca estatica:

- Sem `sendButtons` em codigo critico.
- Sem `/send/button` em codigo critico.
- Sem `send/button` em codigo critico.
- Sem payload `buttons` em codigo critico.

PHP lint:

- Nao executado porque `php` nao esta disponivel no PATH local.

Nao executado:

- envio real WhatsApp;
- pagamento real Mercado Pago;
- setup;
- migracao real;
- restore;
- Composer;
- geracao de `vendor/`.

## ZIP final

Regenerado:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Caminho:

`C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\sistema_hospedagem\sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Tamanho:

- `3,735,615` bytes

Quantidade de arquivos:

- `74`

SHA-256:

`698F51E76614D5C01EF5E784E83AB87354DB4334F6C511D20D2C1E26A12D6E62`

Validacao do ZIP:

- `index.php` presente.
- `script.js` presente.
- `styles.css` presente.
- `admin/admin.js` presente.
- `api/evolution_instance.php` presente.
- `api/evolution_service.php` presente.
- `.env.example` presente.
- `.env.homologacao.example` presente.
- `.env` ausente.
- `config/database.php` ausente.
- `config/.installed.lock` ausente.
- `vendor/` ausente.
- `node_modules/` ausente.
- `backups/` ausente.
- `api/_sessions/` ausente.
- `uploads/private/` ausente.
- `storage/private/` ausente.
- `*.sql`, `*.sqlite`, `*.log`, `*.zip` antigos e relatorios Markdown ausentes.

## Pendencias para teste real na Hostinger

- Rodar PHP lint em ambiente com PHP.
- Testar a tela `WhatsApp / Evolution Go` no painel admin real.
- Configurar URL/API key/instancia reais apenas em homologacao controlada.
- Criar instancia e validar retorno da Evolution Go.
- Gerar QR Code e escanear com numero controlado.
- Verificar status apos conexao.
- Testar reset/apagar instancia em caso de falha.
- Testar dry-run sem envio real.
- So ativar envio real depois de homologar com numero controlado.

## Veredito

O fluxo visual e operacional de conexao WhatsApp / Evolution Go foi restaurado no painel admin, com configuracao, criacao de instancia, conexao, QR Code, status e reset. O envio por botao continua desativado e o pacote final foi regenerado sem `.env`, banco local, logs, backups, zips antigos ou credenciais reais.
