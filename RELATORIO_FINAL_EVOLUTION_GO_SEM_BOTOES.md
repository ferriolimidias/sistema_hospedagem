# Relatorio final - Evolution Go sem botoes

Data: 2026-06-17

## Resumo da decisao

A Evolution Go continua sendo usada, mas o envio de botoes foi desativado em todos os fluxos.

Motivo: o envio de botao na Evolution Go ficou instavel/não confiavel no ambiente atual. Para producao, o sistema agora usa apenas mensagens de texto e midia/documento simples quando PDF estiver disponivel.

## Arquivos analisados

- `RELATORIO_FINAL_PRE_SUBIDA_CLIENTE_2026_06_17.md`
- `api/evolution_service.php`
- `api/evolution_instance.php`
- `api/system_capabilities.php`
- `api/mp_webhook.php`
- `api/create_preference.php`
- `api/generate_contract.php`
- `api/contract_service.php`
- `admin/admin.js`
- `script.js`
- `.env.example`
- `.env.homologacao.example`

## Funcoes que usavam botao

Arquivo: `api/evolution_service.php`

- `evo_go_send_button()`
  - Antes: chamava `/message/sendButtons/{instance}` e montava payload com botoes.
  - Depois: converte titulo, descricao e dados auxiliares em texto e chama `evo_go_send_text()`.

- `evo_send_button()`
  - Antes: alias para envio real de botao.
  - Depois: alias de compatibilidade que tambem vira texto.

- `evo_send_pix()`
  - Antes: usava `evo_go_send_button()` para tentar enviar botao de copiar chave PIX.
  - Depois: envia texto com chave PIX no corpo da mensagem.

## Endpoints removidos/desativados

Nao ha mais chamada a:

- `/message/sendButtons`
- `/send/button`
- `send/button`

Tambem nao ha payload `buttons` nos arquivos criticos do pacote.

## Endpoints mantidos

- `POST /message/sendText/{instance}`
- `POST /message/sendMedia/{instance}`
- `POST /instance/create`
- `GET/POST /instance/connect/{instance}`
- `GET /instance/connectionState/{instance}`
- `DELETE /instance/delete/{instance}`

## Modelos de mensagem

### Reserva/confirmacao

Formato usado por `evo_message_for_recipient()`:

```text
Olá, {nome}! Sua reserva na {pousada} foi recebida com sucesso.

Chalé: {chale}
Check-in: {checkin}
Check-out: {checkout}
Valor da reserva: {total}

Obrigado por escolher {pousada}.
```

### PIX/pagamento

Formato usado por `evo_send_pix()`:

```text
Olá, {nome}! Recebemos sua pré-reserva em {pousada}.
Check-in: {checkin}
Check-out: {checkout}
Total da reserva: {total}

Chave PIX:
{chave_pix}

Após o pagamento, envie o comprovante por aqui.
```

### Check-in/FNRH

Mantido como texto com link:

```text
Olá, {nome}! Sua reserva na {pousada} está confirmada para {checkin} — {checkout}.

Para agilizar sua chegada, preencha o pré-check-in online (FNRH) neste link seguro:
{link}

Nos vemos em breve!
```

### Check-out/saldo

Mantido como texto:

```text
Olá, {nome}! Sua estadia em {pousada} foi finalizada com sucesso.

Resumo do Fechamento:
Hospedagem: {stay_total}
Consumo/Extras: {consumption_total}
Total Geral: {grand_total}
```

## Fluxos revisados

- Reserva
  - Funcao: `evo_notify_event()` e `evo_message_for_recipient()`.
  - Depois: texto simples.
  - Botao: nao usa.

- Confirmacao de reserva
  - Funcao: `evo_notify_event()` com evento `reserva` ou `payment_confirmed`.
  - Depois: texto simples.
  - Botao: nao usa.

- PIX/pagamento
  - Funcao: `evo_send_pix()`.
  - Depois: texto com chave PIX.
  - Botao: nao usa.

- Link de check-in
  - Funcao: painel gera link e envia texto por `evolution_service.php`.
  - Depois: texto com URL.
  - Botao: nao usa.

- Check-in
  - Funcao: `evo_notify_event()` com evento `checkin`.
  - Depois: texto simples com link quando houver token.
  - Botao: nao usa.

- Check-out
  - Funcao: `evo_notify_event()` com evento `checkout`.
  - Depois: texto simples.
  - Botao: nao usa.

- Saldo
  - Funcao: `evo_notify_event()` com evento `balance_paid`.
  - Depois: texto simples.
  - Botao: nao usa.

- Recibo
  - Funcao: `folio_receipt`.
  - Depois: midia/documento somente se PDF estiver disponivel; caso contrario, bloqueia com mensagem segura.
  - Botao: nao usa.

- Contrato
  - Funcao: `resend_contract_media` e `generate_contract.php`.
  - Depois: midia/documento somente se PDF estiver disponivel; caso contrario, bloqueia com mensagem segura.
  - Botao: nao usa.

- Teste pelo painel
  - Funcoes: `testEvolutionConnection()`, `testPixMessageNow()` e `testEvolutionMedia()`.
  - Depois: usam `dry_run: true` para montar payload sem enviar mensagem real.
  - Botao: nao usa.

- Webhook Mercado Pago
  - Funcao: `api/mp_webhook.php`.
  - Depois: notifica via `evo_notify_event()` usando texto. PDF continua opcional.
  - Botao: nao usa.

## Dry-run

Foi adicionado suporte a dry-run na camada Evolution Go.

O dry-run retorna:

- endpoint que seria chamado;
- metodo HTTP;
- tipo de envio: `text` ou `media`;
- numero mascarado parcialmente;
- payload sanitizado;
- sem API key.

O painel usa `dry_run: true` nos testes para nao enviar mensagem real.

## Restauracao do painel WhatsApp / Evolution Go

Aplicada em 2026-06-17.

- Area visivel no painel admin com titulo `WhatsApp / Evolution Go`.
- Campos de configuracao: URL da Evolution Go, API Key e nome da instancia.
- API Key salva nao e exibida inteira apos gravacao; o painel mostra apenas mascara/status.
- Acoes operacionais restauradas: salvar configuracao, criar instancia, conectar WhatsApp, exibir QR Code, verificar status e resetar/apagar instancia.
- A tela nao depende de `vendor/`/Dompdf.
- A configuracao pode ser salva em `settings` e tem fallback para `.env`.
- Nenhuma acao do painel promete ou chama envio de botao.

## PDF opcional

PDF continua opcional:

- sem `vendor/`, contrato/recibo PDF retorna `Recurso de PDF indisponível neste ambiente.`;
- envio de documento/midia so ocorre quando PDF existe/esta disponivel;
- o fluxo principal e CSV continuam sem Dompdf.

## Validacoes executadas

Passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

PHP lint:

- Nao executado. `php` nao esta disponivel no PATH local.

Busca estatica:

- Nenhum `/message/sendButtons`.
- Nenhum `/send/button`.
- Nenhum `send/button`.
- Nenhum payload `buttons` nos arquivos criticos.

## ZIP final regenerado

Sim.

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

## Veredito

Nao existe mais envio de botao em nenhum fluxo critico da Evolution Go. Todos os envios WhatsApp usam texto ou midia simples. Reserva, PIX, check-in, check-out e saldo foram adaptados para texto. PDF continua opcional e nao quebra sem `vendor/`. O painel nao promete envio com botao e os testes usam dry-run.

ZIP final para subir:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

## Pendencias futuras

- Validar envio real de texto da Evolution Go em homologacao com numero controlado.
- Validar envio de midia apenas quando `vendor/`/Dompdf forem instalados.
- Confirmar documentacao oficial da Evolution Go para endpoint de texto/midia do ambiente contratado.
- Rodar PHP lint em ambiente com PHP.
