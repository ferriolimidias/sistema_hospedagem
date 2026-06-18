# Relatorio - Reversao de Evolution Go para Evolution API normal

Data: 2026-06-18 09:03

## Motivo da reversao

A adaptacao para Evolution Go foi abandonada porque a criacao/conexao de instancia ficou instavel. O projeto voltou a usar Evolution API normal, preservando:

- envio por texto simples;
- ausencia de botao;
- PDF opcional sem quebrar o sistema;
- CSV;
- personalizacao;
- banco existente preservado;
- exemplos sem credenciais reais.

## Referencias consultadas

- Docker Hub `evoapicloud/evolution-api`: foi encontrada tag `v2.3.7`, mais recente que a `v2.3.6` citada no pedido.
- Documentacao Evolution API: `GET /instance/connect/{instance}`.
- Documentacao Evolution API: `GET /instance/connectionState/{instance}`.
- Indice da documentacao mostra `POST Create Instance`, `DEL Delete Instance` e rotas de mensagens.

Links:

- https://hub.docker.com/r/evoapicloud/evolution-api/tags
- https://docs.evoapicloud.com/api-reference/instance-controller/instance-connect
- https://docs.evoapicloud.com/api-reference/instance-controller/connection-state

## Comparacao com o ZIP antigo

ZIP analisado sem modificar o original:

`C:\Users\paulo\OneDrive\Documentos\Sistema hospedagem\recantodaserra.zip`

O fluxo antigo usava:

- painel `Integracao WhatsApp (Evolution API)`;
- `api/send_webhook.php`;
- setting JSON `evolutionSettings`;
- campos `url`, `clientInstance`, `clientApikey`, `companyInstance`, `companyApikey`, `companyPhone`, `reservationMsg`;
- envio `POST /message/sendText/{instance}`;
- payload antigo com `number` e `text`.

Nesta reversao, a compatibilidade de leitura com `evolutionSettings` foi mantida quando possivel, mas o fluxo principal passou a usar settings simples:

- `evo_url`;
- `evo_apikey`;
- `evo_instance`;
- `owner_whatsapp`;
- `evolution_reservation_message`.

## Endpoints Go removidos como padrao

Nao ha mais uso operacional de:

- `EVOLUTION_GO_BASE_URL`;
- `EVOLUTION_GO_API_KEY`;
- `EVOLUTION_GO_INSTANCE`;
- `evolution_go_base_url`;
- `evolution_go_api_key`;
- `evolution_go_instance`.

As chaves antigas com `evolution_go_*` continuam apenas como sensiveis/legadas em filtros de seguranca e compatibilidade de retorno, sem serem usadas como fonte principal.

## Endpoints Evolution API normal implementados

Instancia:

- `POST /instance/create`
- `GET /instance/connect/{instanceName}`
- `GET /instance/connectionState/{instanceName}`
- `DELETE /instance/delete/{instanceName}`

Mensagem:

- `POST /message/sendText/{instanceName}`
- payload com `number`, `textMessage.text`, `delay` e `linkPreview=false`.

Midia/documento:

- `POST /message/sendMedia/{instanceName}` continua disponivel na camada interna.
- So deve ser usado quando houver PDF/arquivo disponivel.
- Sem PDF, o sistema retorna mensagem segura e nao quebra.

## Fluxo de conectar WhatsApp

1. Admin abre `WhatsApp / Evolution API`.
2. Informa URL da Evolution API e API key.
3. Clica em `Salvar Configuracao`.
4. Clica em `Conectar WhatsApp`.
5. Backend cria instancia automaticamente se `evo_instance` estiver vazio.
6. Backend chama `GET /instance/connect/{instance}`.
7. Painel exibe QR Code ou codigo de pareamento.
8. Admin escaneia ou pareia no WhatsApp.
9. Admin clica em `Verificar Conexao`.
10. Em falha, admin clica em `Resetar/Apagar Instancia`.
11. Proximo `Conectar WhatsApp` cria nova instancia.

## Criacao automatica de instancia

Arquivo:

- `api/evolution_instance.php`

Regra:

- usa `evo_instance` se existir;
- se nao existir, gera nome com slug de `company_name`, `site_title` ou `pousada_sistema`;
- adiciona sufixo curto unico;
- remove acentos, espacos e caracteres invalidos;
- salva em `settings.evo_instance` somente apos criacao aceita ou conflito de instancia existente.

## Reset/apagar instancia

Arquivo:

- `api/evolution_instance.php`

Regra:

- le `evo_instance`;
- tenta `DELETE /instance/delete/{instance}`;
- limpa somente `settings.evo_instance`;
- nao apaga URL/API key;
- painel volta para estado sem instancia.

## QR Code

O backend aceita:

- `qrcode.base64`;
- `qrcode.code`;
- `base64`;
- `code`;
- `pairingCode`;
- campos equivalentes sob `data` ou `instance`.

O painel renderiza:

- imagem se houver base64/data image;
- codigo textual se houver pairing code;
- mensagem amigavel se nao vier QR/codigo.

## Envio sem botao

Confirmado:

- nao ha `sendButtons`;
- nao ha `/send/button`;
- nao ha `send/button`;
- nao ha payload `buttons` nos arquivos criticos.

`evo_send_button()` permanece apenas como compatibilidade interna e converte para texto.

## Exemplos e seguranca

`.env.example`:

- usa `EVOLUTION_BASE_URL`;
- usa `EVOLUTION_API_KEY`;
- deixa `# EVOLUTION_INSTANCE=` comentado;
- nao tem credencial real.

`.env.homologacao.example`:

- usa `EVOLUTION_BASE_URL`;
- usa `EVOLUTION_API_KEY`;
- deixa `# EVOLUTION_INSTANCE=` comentado;
- nao tem credencial real.

API key salva no banco nao e exibida inteira no painel; o endpoint retorna apenas mascara/status.

## Validacoes

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

PHP lint:

- nao executado porque `php` nao esta disponivel no PATH local.

Nao executado:

- setup.php;
- setup.sql;
- migracao real;
- envio WhatsApp real;
- Mercado Pago real;
- FNRH real;
- Composer;
- geracao de `vendor/`.

## ZIP final

Regenerado:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

- Tamanho: `3.745.633` bytes
- Quantidade de arquivos: `79`
- SHA-256: `9952FA5339D6BFEB535E70D1E260D6A9849B0B2A5633CBDA249B3177D7C73C1A`

Validacao do ZIP:

- proibidos encontrados: `0`;
- `.env` ausente;
- `config/database.php` ausente;
- `config/.installed.lock` ausente;
- `vendor/` ausente;
- `node_modules/` ausente;
- `backups/` ausente;
- `.git/` ausente;
- `.cursor/` ausente;
- `api/_sessions/` ausente;
- `uploads/private/` ausente;
- `storage/private/` ausente;
- contratos gerados ausentes;
- logs/dumps/tokens/chaves/credenciais ausentes;
- zips antigos ausentes;
- `setup.sql` ausente.

## Pendencias para Hostinger

- Validar PHP lint no servidor ou ambiente com PHP.
- Configurar URL/API key reais somente no painel ou `.env` do servidor.
- Testar `Conectar WhatsApp` com numero controlado.
- Confirmar retorno real de QR Code/pairing code da Evolution API instalada.
- Verificar `connectionState` depois do pareamento.
- Fazer um envio real controlado apenas em homologacao.
- So depois liberar notificacoes reais em producao.

## Veredito

O criterio de aceite foi atendido estaticamente: o sistema usa Evolution API normal como fluxo principal, a tela admin permite salvar configuracao, conectar, gerar QR/codigo, verificar status e resetar instancia; mensagens seguem como texto simples, sem botao, preservando banco existente e pacote seguro para Hostinger.
