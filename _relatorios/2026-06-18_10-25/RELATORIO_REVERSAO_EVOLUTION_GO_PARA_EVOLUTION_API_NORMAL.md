# Relatorio - Reversao para Evolution API normal com UX simplificada

Data: 2026-06-18 10:25

## Estado atual

O sistema permanece usando Evolution API normal, nao Evolution Go.

A camada operacional usa:

- `POST /instance/create`;
- `GET /instance/connect/{instanceName}`;
- `GET /instance/connectionState/{instanceName}`;
- `DELETE /instance/delete/{instanceName}`;
- `POST /message/sendText/{instanceName}` com texto simples.

## Simplificacao aplicada nesta rodada

- Fluxo principal do painel reduzido para `Conectar WhatsApp` e `Resetar`.
- Criacao de instancia ficou totalmente automatica.
- Verificacao de status ficou automatica.
- Instancia saiu do fluxo normal e foi para `Avancado`.
- Configuracao URL/API key ficou separada da conexao.
- Contrato/recibo/PDF nao ficam na area WhatsApp.

## Seguranca e limites

- Sem credenciais reais nos exemplos.
- Sem envio real executado.
- Sem migracao real executada.
- Sem setup executado.
- Banco existente preservado.

## ZIP

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

- Tamanho: `3.746.327` bytes
- Arquivos: `79`
- SHA-256: `7CB0103735914E389D59E3B34EAC68BF21AE8CF9A1FC7520320F6C8804C89124`

