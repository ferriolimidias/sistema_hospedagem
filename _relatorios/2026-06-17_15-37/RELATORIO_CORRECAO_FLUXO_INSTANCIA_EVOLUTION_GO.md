# Relatorio - Correcao do fluxo de instancia Evolution Go

Data: 2026-06-17 15:37

## Escopo

Revisao e correcao do fluxo ponta a ponta da instancia Evolution Go sem executar setup, SQL, migracao real, envio WhatsApp real, Mercado Pago real, Composer ou geracao de `vendor/`.

## Resultado objetivo

`EVOLUTION_GO_INSTANCE` nao e mais obrigatorio no `.env`.

A instancia e gerenciada pelo painel:

1. URL e API key sao lidas primeiro de settings do banco.
2. Se settings estiverem vazias, URL/API key usam fallback do `.env`.
3. A instancia usa primeiro `settings.evolution_go_instance`.
4. Fallback de instancia no `.env` existe apenas para reaproveitamento manual/legado.
5. Ausencia de instancia no `.env` nao e erro fatal.
6. Sem instancia salva, o painel cria uma instancia automaticamente.

## Mapeamento do fluxo

1. Leitura de `EVOLUTION_GO_INSTANCE`:
   - `api/evolution_service.php`, somente como fallback final se `evolution_go_instance` e `evo_instance` estiverem vazios.
   - `.env.example` e `.env.homologacao.example` deixam a variavel comentada.
2. Leitura de `evolution_go_instance`:
   - `api/evolution_instance.php` em `evoi_instance_setting()`.
   - `api/evolution_service.php` em `evo_http_config()`.
   - `api/system_capabilities.php` por meio de `evo_http_config()`.
   - `api/settings.php` filtra a chave como sensivel para leitura publica.
3. Criacao da instancia:
   - `api/evolution_instance.php`, acao `create`.
   - Tambem ocorre no fluxo `connect` se ainda nao houver instancia salva.
4. Geracao do nome:
   - `evoi_instance_name()` usa `company_name`, depois `site_title`, depois `pousada_sistema`.
   - O nome e normalizado sem acentos, espacos ou caracteres fora de letras, numeros, `_` e `-`.
   - Foi adicionado sufixo curto unico de 6 caracteres.
5. Salvamento da instancia:
   - `evoi_save_instance_settings()` salva `evolution_go_instance`.
   - Tambem atualiza `evo_instance` por compatibilidade legada.
6. Conexao por QR Code:
   - `api/evolution_instance.php`, acao `connect`.
   - Endpoint remoto: `GET /instance/connect/{instance}` com fallback `POST /instance/connect/{instance}`.
7. Verificacao de status:
   - `api/evolution_instance.php`, acao `status` ou `check_status`.
   - Endpoint remoto: `GET /instance/connectionState/{instance}`.
   - Sem instancia salva, retorna estado fechado e indica necessidade de criacao.
8. Reset/apagar instancia:
   - `api/evolution_instance.php`, acao `reset`, `disconnect`, `delete`.
   - Chama `DELETE /instance/delete/{instance}` quando ha instancia.
   - Depois limpa `evolution_go_instance` e `evo_instance`.
9. Envio de mensagens:
   - `api/evolution_service.php` usa a instancia de `evo_http_config()`.
   - Envio de texto: `POST /message/sendText/{instance}`.
   - Envio de midia: `POST /message/sendMedia/{instance}`.

## Painel admin

Arquivo revisado:

- `admin/admin.js`

Estado esperado da tela `WhatsApp / Evolution Go`:

- Mostra URL da Evolution Go.
- Mostra campo de API key sem expor chave salva; o backend retorna apenas mascara/status.
- Mostra `Instancia atual`.
- Se nao houver instancia, mostra `Nenhuma instancia criada. Clique em Criar Instancia.`
- Mantem os botoes `Criar Instancia`, `Conectar WhatsApp`, `Verificar Conexao` e `Resetar/Apagar Instancia`.
- Mostra QR Code dentro do painel ao conectar.
- Atualiza status visual conectado/conectando/desconectado.

O campo de instancia permanece visivel como campo avancado/opcional. Ele nao e obrigatorio para criar instancia.

## Arquivos corrigidos

- `.env.example`
- `.env.homologacao.example`
- `api/evolution_instance.php`
- `api/evolution_service.php`
- `admin/admin.js`

## Exemplos `.env`

`.env.example` corrigido:

- `EVOLUTION_GO_BASE_URL` usa placeholder.
- `EVOLUTION_GO_API_KEY` usa placeholder.
- `EVOLUTION_GO_INSTANCE` ficou comentado e vazio.
- Nao ha URL privada, API key real ou nome real de instancia.

`.env.homologacao.example` corrigido:

- `EVOLUTION_GO_BASE_URL` usa placeholder de homologacao.
- `EVOLUTION_GO_API_KEY` usa placeholder.
- `EVOLUTION_GO_INSTANCE` ficou comentado e vazio.
- Nao ha URL privada, API key real ou nome real de instancia.

## Migracao segura

Arquivo revisado:

- `docs_migracao/MIGRACAO_SEGURA_BANCO_EXISTENTE_2026_06_17.sql`

Resultado:

- Nao foi executada.
- Continua aditiva.
- Cria settings Evolution Go apenas se nao existirem.
- Nao sobrescreve valor existente.
- Nao usa `DROP`.
- Nao usa `TRUNCATE`.
- Nao usa comando executavel `DELETE`.
- Nao altera admin/senha.
- `evolution_go_instance` entra vazia para ser criada pelo painel.
- Nao ha instancia real no SQL.

## Botao WhatsApp

Confirmado por busca estatica nos arquivos criticos:

- Nenhum `message/sendButtons`.
- Nenhum `/send/button`.
- Nenhum `send/button`.
- Nenhum payload `buttons` nos arquivos criticos.

As funcoes de compatibilidade `evo_go_send_button()` e `evo_send_button()` continuam convertendo conteudo para texto.

## Validacoes executadas

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

PHP lint:

- Nao executado porque `php` nao esta disponivel no PATH local.

Nao executado:

- setup.php;
- setup.sql;
- migracao real;
- envio WhatsApp real;
- Mercado Pago real;
- Composer;
- geracao de `vendor/`.

## ZIP final

ZIP regenerado:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Caminho:

`C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\sistema_hospedagem\sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

Tamanho:

- `3.780.153` bytes

Quantidade de arquivos:

- `92`

SHA-256:

`3E152A9A38A4E9CB733DC56CB298D488EB32C05B0D15FBAEAAF1A80B976DBB6A`

Validacao do ZIP:

- `.env`: ausente.
- `config/database.php`: ausente.
- `config/.installed.lock`: ausente.
- `vendor/`: ausente.
- `node_modules/`: ausente.
- `backups/`: ausente.
- `.git/`: ausente.
- `.cursor/`: ausente.
- `api/_sessions/`: ausente.
- `uploads/private/`: ausente.
- `storage/private/`: ausente.
- contratos gerados: ausentes.
- logs/dumps/tokens/chaves/credenciais/zips antigos: ausentes.
- `setup.sql` perigoso no runtime: ausente.

## Veredito

O criterio de aceite desta rodada foi atendido no codigo estatico: o sistema nao depende de `EVOLUTION_GO_INSTANCE` no `.env`; a instancia pode ser criada pelo painel, salva em settings, conectada por QR Code, resetada se falhar e recriada automaticamente.

Validacao runtime em servidor com PHP/cURL/Evolution Go real ainda e necessaria antes de declarar producao.
