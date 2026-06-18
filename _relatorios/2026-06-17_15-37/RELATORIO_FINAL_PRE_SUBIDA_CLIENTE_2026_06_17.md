# Relatorio final pre-subida cliente - atualizacao da rodada

Data: 2026-06-17 15:37

Esta versao atualiza o ponto da Evolution Go que estava obsoleto no relatorio anterior.

## Atualizacao aplicada

- `EVOLUTION_GO_INSTANCE` nao e mais variavel obrigatoria nos exemplos `.env`.
- `.env.example` e `.env.homologacao.example` deixam `# EVOLUTION_GO_INSTANCE=` comentado e vazio.
- A instancia passa a ser criada pelo painel admin quando nao existir em settings.
- O nome da instancia e gerado automaticamente a partir do nome da empresa/pousada, normalizado e com sufixo curto unico.
- A instancia gerada e salva em `settings.evolution_go_instance` e tambem em `evo_instance` por compatibilidade.
- O painel mostra a instancia atual ou a mensagem `Nenhuma instancia criada. Clique em Criar Instancia.`
- Reset/apagar instancia limpa `evolution_go_instance` e `evo_instance`, permitindo recriacao.

## Veredito atualizado

Pode seguir para homologacao/cliente como pacote estatico, mantendo as ressalvas anteriores:

- PHP lint nao foi executado localmente porque `php` nao esta no PATH.
- Composer/vendor nao foram instalados.
- Envios WhatsApp reais, Mercado Pago real e migracao real nao foram executados.
- Producao ainda exige validacao runtime no servidor.

## ZIP final atualizado

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

- Tamanho: `3.780.153` bytes
- Arquivos: `92`
- SHA-256: `3E152A9A38A4E9CB733DC56CB298D488EB32C05B0D15FBAEAAF1A80B976DBB6A`

