# Relatorio - Restauracao da conexao WhatsApp / Evolution Go no admin - atualizacao da rodada

Data: 2026-06-17 15:37

Esta versao atualiza o fluxo operacional da instancia Evolution Go.

## Fluxo operacional corrigido

1. Preencher ou manter salvos URL da Evolution Go e API key.
2. Nao preencher instancia no `.env`.
3. Se nao houver instancia no painel, clicar em `Criar Instancia`.
4. O backend gera nome automaticamente, normaliza caracteres e adiciona sufixo curto unico.
5. O backend salva a instancia em `settings.evolution_go_instance`.
6. Clicar em `Conectar WhatsApp`.
7. O painel exibe QR Code.
8. Escanear o QR no WhatsApp da pousada.
9. Clicar em `Verificar Conexao`.
10. Se falhar, clicar em `Resetar/Apagar Instancia`.
11. O backend tenta apagar remotamente e limpa a instancia local.
12. O painel volta para estado sem instancia e permite criar outra.

## Correcao de texto/configuracao

- O campo do painel agora e `Instancia atual`.
- Sem instancia, o painel mostra `Nenhuma instancia criada. Clique em Criar Instancia.`
- `.env.example` e `.env.homologacao.example` nao exigem mais `EVOLUTION_GO_INSTANCE`.
- O fallback `EVOLUTION_GO_INSTANCE` segue aceito apenas para reaproveitamento legado/manual.

## Seguranca

- API key nao e exposta integralmente no front.
- Nenhuma credencial real foi adicionada aos exemplos.
- Nenhuma chamada real de WhatsApp foi executada nesta rodada.
- Envio por botao continua desativado; o fluxo permanece texto/midia simples.

