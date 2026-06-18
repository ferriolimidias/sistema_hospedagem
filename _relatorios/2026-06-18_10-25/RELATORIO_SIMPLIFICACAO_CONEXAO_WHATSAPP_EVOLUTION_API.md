# Relatorio - Simplificacao da conexao WhatsApp / Evolution API

Data: 2026-06-18 10:25

## O que estava errado

A area de conexao misturava configuracao tecnica, criacao manual de instancia, verificacao manual, reset com texto tecnico e validacoes de PDF. Isso deixava o fluxo confuso e ainda parecia expor partes do fluxo antigo de Evolution Go.

## Botoes removidos da area principal

- `Salvar Configuracao` saiu da area principal e foi para `Configuracoes da Evolution API`.
- `Criar Instancia` nao existe no painel.
- `Verificar Conexao` nao existe no painel.
- `Validar Contrato` nao fica na area de conexao.
- `Validar Recibo` nao fica na area de conexao.
- `Resetar/Apagar Instancia` virou apenas `Resetar`.

## Nova tela

A area principal `WhatsApp / Evolution API` agora mostra:

- status;
- orientacao curta;
- botao `Conectar WhatsApp`;
- botao `Resetar`;
- area de QR Code/codigo de pareamento.

URL/API key ficam em area separada `Configuracoes da Evolution API`. A instancia fica apenas em `Avancado`, recolhida, sem exigir preenchimento do usuario.

## Botao Conectar WhatsApp

Ao clicar:

1. o painel chama `api/evolution_instance.php` com `action=connect`;
2. o backend valida URL/API key;
3. se `evo_instance` nao existir, gera uma instancia automaticamente;
4. cria a instancia em `POST /instance/create`;
5. salva `settings.evo_instance`;
6. chama `GET /instance/connect/{instanceName}`;
7. retorna QR Code ou codigo;
8. o painel renderiza o QR/codigo;
9. o painel inicia polling automatico de status.

## Botao Resetar

Ao clicar:

1. confirma: `Isso vai reiniciar a conexao do WhatsApp e gerar um novo QR Code. Deseja continuar?`;
2. backend tenta `DELETE /instance/delete/{instanceName}`;
3. limpa `settings.evo_instance`;
4. gera nova instancia;
5. cria e conecta;
6. retorna novo QR Code/codigo para o painel.

URL/API key nao sao apagadas.

## QR Code

O painel aceita:

- base64 puro;
- `data:image/...`;
- codigo de pareamento textual.

## Verificacao automatica de status

Depois que o QR aparece, o painel consulta `status` automaticamente a cada 5 segundos. O polling:

- para quando detectar `open` ou `connected`;
- mostra `Conectado`;
- para apos limite razoavel de tentativas e mostra mensagem amigavel;
- nao exige botao manual de verificar.

## Confirmacoes

- Nao existe botao `Criar Instancia` no painel.
- Nao existe botao `Verificar Conexao` no painel.
- Nao aparece `Evolution Go` no painel.
- Contrato/recibo nao ficam na area de conexao.
- Nao ha `sendButtons`.
- Nao ha `/send/button`.
- Nao ha payload `buttons` nos arquivos criticos.

## Validacoes executadas

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
- envio real WhatsApp;
- Mercado Pago real;
- FNRH real;
- Composer;
- geracao de `vendor/`.

## ZIP final

Regenerado:

`sistema_hospedagem_atualizacao_hostinger_final_2026-06-17.zip`

- Tamanho: `3.746.327` bytes
- Quantidade de arquivos: `79`
- SHA-256: `7CB0103735914E389D59E3B34EAC68BF21AE8CF9A1FC7520320F6C8804C89124`

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

## Pendencias de teste real na Hostinger

- Validar PHP lint em ambiente com PHP.
- Configurar URL/API key reais apenas no servidor/painel.
- Clicar em `Conectar WhatsApp` e confirmar QR real.
- Confirmar polling de status apos leitura do QR.
- Testar `Resetar` em homologacao com numero controlado.
- Fazer envio real somente em homologacao.

