# Relatorio - Atualizacao com banco existente e publicacao

Data: 2026-06-17

## Veredito

E possivel atualizar usando o mesmo banco atual, desde que a subida seja feita com backup previo, em ambiente de homologacao primeiro, sem rodar `setup.php` e sem rodar `setup.sql` no banco real.

A atualizacao deve preservar dados, configuracoes locais e arquivos enviados por usuarios/administradores.

## O que preservar

Preservar no servidor:

- Banco MySQL atual.
- `config/database.php`.
- `.env`, se estiver sendo usado no ambiente.
- `images/uploads/`.
- uploads publicos usados por chales, hero, logo, favicon, depoimentos e conteudo.
- `storage/` que contenha arquivos reais necessarios.
- contratos/recibos ja gerados, se existirem.
- qualquer pasta privada de sessao/armazenamento que tenha uso operacional.

Nao sobrescrever esses itens sem backup e conferencia.

## O que nao subir

Nao subir para o servidor publico:

- `.env` local.
- `config/database.php` de outro ambiente.
- `config/.installed.lock` de outro ambiente.
- backups.
- dumps.
- arquivos `.sql`.
- arquivos `.sqlite`.
- arquivos `.log`.
- arquivos `.zip` antigos.
- tokens.
- chaves.
- credenciais.
- `.git/`.
- `.cursor/`.
- `node_modules/`.
- `api/_sessions/`.
- `uploads/private/`.
- `storage/private/`, salvo se houver uma rotina clara e segura para migrar conteudo privado.

## Setup.php deve ser usado?

Nao em producao com banco existente.

`setup.php` e util para instalacao inicial ou ambiente vazio/controlado. Em ambiente real ja configurado, ele pode alterar configuracoes e criar estado de instalacao que nao representa o ambiente atual.

Para atualizacao com banco existente:

- nao abrir `setup.php` no navegador de producao;
- nao usar `setup.php` para trocar conexao;
- preservar `config/database.php`;
- testar em homologacao antes.

## Setup.sql deve ser usado?

Nao.

`setup.sql` nao deve ser rodado em banco real com dados. Relatorios anteriores ja apontaram risco porque o arquivo contem operacoes de limpeza de conteudo, incluindo `DELETE FROM personalizacao` e `DELETE FROM chalets`.

Rodar esse arquivo no banco atual pode apagar personalizacao e chales.

## Mudancas automaticas no banco

O projeto possui rotinas que podem criar tabelas e adicionar colunas automaticamente em `api/db.php` e `api/schema.php`.

Exemplos de colunas/tabelas que podem ser adicionadas:

- `admins.auth_token`.
- colunas em `reservations` para expiracao, Mercado Pago, contratos, saldo, FNRH, cupons, extras, criancas, pet e forma de pagamento.
- colunas em `chalets` para hospedes base, maximo e taxa de hospede extra.
- tabelas comerciais como cupons, servicos extras, consumos, regras sazonais e descontos.
- colunas de `personalizacao`: `loc_subtitulo`, `loc_map_embed`, `videos_enabled`, `videos_json`, `logo_principal`, `logo_alternativa`.

Essas alteracoes tendem a ser incrementais, mas ainda sao alteracoes reais de schema. Por isso, devem ser validadas primeiro em copia do banco.

## Personalizacao depende de colunas novas?

Sim. A personalizacao atual usa campos novos, principalmente:

- `logo_principal`.
- `logo_alternativa`.
- `favicon`.
- `loc_subtitulo`.
- `loc_map_embed`.
- `videos_enabled`.
- `videos_json`.

`api/customization.php` tenta criar automaticamente algumas colunas (`logo_principal`, `logo_alternativa`, `loc_subtitulo`). Ja `loc_map_embed`, `videos_enabled` e `videos_json` aparecem na rotina de schema. Em banco antigo, a recomendacao e testar a abertura do painel e o salvamento da personalizacao em homologacao antes de producao.

## Procedimento seguro de atualizacao

1. Fazer backup completo do banco MySQL atual.
2. Fazer backup dos arquivos atuais do site.
3. Fazer backup especifico de uploads/imagens e storage.
4. Criar ambiente de homologacao com copia do banco.
5. Subir os arquivos novos sem `.env`, sem `config/database.php` de outro ambiente e sem backups/logs/dumps.
6. Configurar a conexao da homologacao manualmente com credenciais proprias.
7. Acessar o site publico.
8. Acessar painel administrativo.
9. Conferir chales, reservas, personalizacao e configuracoes.
10. Fazer uma reserva de teste sem pagamento real.
11. Testar relatorio CSV.
12. Conferir logs de erro do PHP/Hostinger.
13. So depois repetir a publicacao no ambiente principal.

## Checklist de teste

Site publico:

- Home abre sem erro.
- Logo aparece harmonica.
- Hero carrega imagens.
- Chales aparecem.
- Detalhes de chale abrem.
- Datas funcionam.
- Disponibilidade responde.
- Cupom invalido mostra erro sem quebrar formulario.
- Extras e pet recalculam resumo.
- Reserva de teste nao chama pagamento real sem configuracao segura.

Painel:

- Login funciona.
- Dashboard abre.
- Chales aparecem.
- Reservas aparecem.
- Personalizacao aparece para administrador completo.
- Upload de imagem funciona em homologacao.
- Configuracoes carregam.
- Relatorio CSV baixa corretamente.

Banco:

- Dados antigos continuam.
- Chales antigos continuam.
- Personalizacao antiga continua.
- Reservas antigas continuam.
- Nenhum `setup.sql` foi executado.
- Nenhum restore foi feito por engano.

## Publicacao direta

Para subir em producao com o mesmo banco:

- subir apenas arquivos do sistema;
- nao subir credenciais locais;
- nao sobrescrever `config/database.php`;
- nao limpar `images/uploads/`;
- nao limpar `storage/`;
- nao rodar `setup.php`;
- nao rodar `setup.sql`;
- monitorar erros apos a troca.

## Pendencias antes de producao

- Validar em homologacao com copia do banco real.
- Confirmar que as colunas novas de `personalizacao` existem.
- Corrigir em etapa futura a regra dinamica da logo no `script.js`.
- Decidir se botoes/fluxos de PDF ficarao ocultos enquanto `vendor/` estiver ausente.
- Testar fluxo de reserva sem chamar Mercado Pago real.
- Testar envio WhatsApp apenas com credenciais sandbox/ficticias ou manter desabilitado.

## Conclusao

A atualizacao com banco existente e viavel, mas nao deve ser feita por restore nem por `setup.sql`. O caminho correto e preservar banco/configuracoes/uploads, testar em homologacao, deixar PDF desabilitado se `vendor/` nao existir e publicar somente depois de confirmar que dados antigos continuam intactos.
