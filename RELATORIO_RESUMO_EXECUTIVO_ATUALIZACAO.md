# Resumo Executivo da Atualizacao

Data: 17 de junho de 2026.

## O que foi melhorado ate agora

- APIs administrativas criticas foram protegidas.
- CORS foi endurecido com allowlist.
- Debug/test/schema/seed foram bloqueados.
- `sync_assets.php` passou a exigir chave interna.
- `.gitignore` e exemplos de ambiente foram melhorados.
- O painel recebeu funcoes novas: relatorios CSV, cupons, extras, regras sazonais, consumo, FNRH, Evolution, contratos e usuarios.
- Textos do cliente/admin foram amplamente padronizados em portugues Brasil.
- `.htaccess` passou a bloquear arquivos sensiveis.

## O que realmente mudou

O sistema atual deixou de ser apenas site/reserva/admin simples e virou uma plataforma mais completa de hospedagem, com:

- disponibilidade;
- precificacao por regras;
- cupons;
- extras;
- consumo;
- relatorios CSV;
- contratos PDF;
- FNRH;
- WhatsApp/Evolution;
- Mercado Pago mais estruturado;
- instalador e schema idempotente.

## O que nao mudou

- `database.sql` base permaneceu igual ao antigo.
- Imagens principais e alguns assets continuam iguais.
- A arquitetura continua PHP/MySQL sem framework novo.
- Nao houve execucao de banco nesta auditoria.

## Vendor/Dompdf e necessario?

Depende do fluxo:

- Para site publico, reserva basica, admin basico e relatorios CSV: nao.
- Para contratos PDF, recibos PDF e envio desses PDFs por WhatsApp: sim.

Como o sistema atual tem botoes e endpoints reais de contrato/recibo PDF, Dompdf e dependencia real se esses recursos forem usados.

## Relatorios

O relatorio gerencial atual e CSV:

- `api/reports.php?action=export`
- `Content-Type: text/csv`
- `fputcsv`
- `php://output`

Nao depende de Dompdf.

## Banco foi alterado?

O arquivo `database.sql` nao mudou, mas o schema efetivo do sistema atual mudou por codigo:

- novas tabelas;
- muitas colunas novas;
- migrador em `api/schema.php`;
- alteracoes automaticas em `api/db.php`.

## Posso usar o mesmo banco?

Provavelmente sim, mas com cuidados:

- fazer backup;
- testar em copia/homologacao;
- nao apagar banco;
- nao rodar `setup.sql`;
- nao rodar `setup.php` em producao existente;
- preservar `config/database.php`;
- validar se o usuario MySQL pode executar `ALTER TABLE`.

## Posso subir so arquivos alterados?

Tecnicamente sim, mas e mais seguro subir um pacote controlado excluindo sensiveis e preservando:

- `config/database.php`;
- `.env`, se usado;
- uploads/imagens reais;
- `storage/contracts` e logs gerados se ja existirem;
- banco atual.

Nao apague a pasta inteira do site sem backup.

## Setup.php deve ser rodado?

Nao em banco existente com dados.

Use `setup.php` apenas para instalacao limpa ou homologacao vazia. Em producao existente, preserve `config/database.php` e atualize arquivos com backup.

## O que testar antes

1. Login admin.
2. Site publico.
3. Listagem de chales.
4. Disponibilidade.
5. Criacao de reserva de teste.
6. Relatorio CSV.
7. Cupons/extras se usados.
8. Regras sazonais.
9. Mercado Pago sandbox.
10. Evolution apenas numero de teste.
11. FNRH apenas ambiente controlado.
12. Contrato PDF somente se Dompdf estiver instalado.
13. Bloqueios de `.env`, `config`, SQL, ZIP e storage.

## Proximos passos recomendados

1. Decidir se contratos PDF serao usados agora.
2. Se nao forem usados, documentar que `vendor/Dompdf` fica fora do deploy inicial e ocultar/evitar botoes de contrato.
3. Se forem usados, instalar Dompdf em ambiente com Composer e incluir `vendor/`.
4. Criar ambiente de homologacao com copia do banco atual.
5. Validar migracoes automaticas e fluxos principais.
6. Subir producao em janela controlada, preservando banco, config e uploads.

## Veredito

O sistema atual traz melhorias reais e relevantes, mas tambem adiciona schema e fluxos novos. E possivel atualizar mantendo o banco atual, desde que a atualizacao seja testada em copia e que `setup.php`/`setup.sql` nao sejam usados em producao com dados.

