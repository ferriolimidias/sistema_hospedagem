# Relatorio - Restauracao da conexao WhatsApp / Evolution API no admin

Data: 2026-06-18 09:03

## Tela admin

A area foi ajustada para:

`WhatsApp / Evolution API`

Campos principais:

- URL da Evolution API;
- API Key;
- Instancia atual;
- WhatsApp do Dono;
- Mensagem de reserva para o hospede;
- toggles de notificacao.

Botoes principais:

- Salvar Configuracao;
- Conectar WhatsApp;
- Verificar Conexao;
- Resetar/Apagar Instancia;
- Validar Mensagem em dry-run.

Foram removidos da area de conexao:

- botao separado de criar instancia;
- validacao de contrato;
- validacao de recibo;
- qualquer promessa de botao interativo.

## Fluxo operacional

1. Preencher URL/API key.
2. Salvar configuracao.
3. Clicar em `Conectar WhatsApp`.
4. Backend cria instancia se necessario.
5. Backend retorna QR Code ou codigo de pareamento.
6. Painel mostra QR/codigo.
7. Usuario conecta no WhatsApp.
8. Usuario verifica status.
9. Se falhar, reseta/apaga instancia.
10. Novo conectar cria nova instancia.

## Seguranca

- API key nao e exposta integralmente.
- `.env.example` e `.env.homologacao.example` usam apenas placeholders.
- Nenhum envio real foi executado nesta rodada.
- Nenhuma migracao real foi executada.
- Nenhum dado real foi apagado.

## Compatibilidade

O ZIP antigo usava `evolutionSettings` com `clientInstance` e `companyInstance`. O novo fluxo usa uma instancia principal `evo_instance` para o sistema, mas a mensagem de reserva customizada foi preservada por setting simples `evolution_reservation_message` e fallback de leitura do JSON antigo quando existir.

## Validacao

Checks JS passaram:

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

PHP lint nao executado localmente por ausencia de `php` no PATH.
