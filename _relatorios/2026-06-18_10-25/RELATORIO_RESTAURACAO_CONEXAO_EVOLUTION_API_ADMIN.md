# Relatorio - Conexao WhatsApp / Evolution API no admin

Data: 2026-06-18 10:25

## Tela ajustada

A area principal agora contem somente:

- status da conexao;
- orientacao curta;
- `Conectar WhatsApp`;
- `Resetar`;
- area de QR Code.

## Fluxo operacional

`Conectar WhatsApp` cria instancia automaticamente se necessario, conecta e mostra QR Code/codigo. A verificacao de status acontece automaticamente por polling.

`Resetar` confirma com o usuario, limpa a instancia local, tenta remover a instancia remota, cria nova instancia e retorna novo QR Code/codigo.

## Configuracoes

URL/API key ficam em `Configuracoes da Evolution API`, separadas da area operacional. A instancia fica em `Avancado`, recolhida e somente leitura.

## Confirmacoes

- `Evolution Go` nao aparece na tela do painel.
- `Criar Instancia` nao aparece no painel.
- `Verificar Conexao` nao aparece no painel.
- `Validar Contrato` e `Validar Recibo` nao aparecem na area de conexao.
- Envio por botao continua desativado.

## Checks

```bash
node --check script.js
node --check admin/admin.js
node --check test_browser.js
```

Todos passaram. PHP lint nao foi executado por ausencia de `php` no PATH.

