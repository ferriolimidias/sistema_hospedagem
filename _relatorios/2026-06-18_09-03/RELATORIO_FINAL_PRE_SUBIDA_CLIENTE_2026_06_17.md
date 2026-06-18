# Relatorio final pre-subida cliente - atualizacao Evolution API normal

Data: 2026-06-18 09:03

## Atualizacao aplicada

O projeto foi revertido de Evolution Go para Evolution API normal.

Fluxo principal atual:

- settings principais: `evo_url`, `evo_apikey`, `evo_instance`;
- painel: `WhatsApp / Evolution API`;
- conectar cria instancia automaticamente se `evo_instance` estiver vazio;
- QR Code/codigo de pareamento aparece no painel;
- status usa `GET /instance/connectionState/{instance}`;
- reset usa `DELETE /instance/delete/{instance}` e limpa somente `evo_instance`;
- envio de texto usa `POST /message/sendText/{instance}` com `textMessage.text`.

## Preservado

- Sem envio de botao.
- Sem `sendButtons`.
- Sem payload `buttons`.
- PDF continua opcional.
- CSV continua independente.
- Personalizacao nao foi removida.
- Banco existente preservado.
- Migração segura continua aditiva e nao foi executada.

## ZIP final

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

- Tamanho: `3.745.633` bytes
- Arquivos: `79`
- SHA-256: `9952FA5339D6BFEB535E70D1E260D6A9849B0B2A5633CBDA249B3177D7C73C1A`

## Validacoes

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

Nao foi possivel executar PHP lint local porque `php` nao esta no PATH.

## Veredito

Pode seguir para homologacao estatica/cliente com Evolution API normal, desde que o teste real de QR, status e envio seja feito na Hostinger ou ambiente equivalente antes de producao.
