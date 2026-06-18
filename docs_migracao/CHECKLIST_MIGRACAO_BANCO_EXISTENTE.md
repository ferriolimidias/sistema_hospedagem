# Checklist - Migracao segura em banco existente

Data: 2026-06-17

## Antes de executar

- Fazer backup completo do banco MySQL atual.
- Fazer backup dos arquivos atuais do site.
- Preservar `config/database.php`.
- Preservar `.env`, se existir no servidor.
- Preservar `images/uploads/`.
- Preservar `storage/`.
- Nao rodar `setup.php`.
- Nao rodar `setup.sql`.
- Nao executar restore sobre o banco real.

## Arquivo de migracao

Usar apenas em homologacao primeiro:

`docs_migracao/MIGRACAO_SEGURA_BANCO_EXISTENTE_2026_06_17.sql`

O arquivo foi preparado para ser aditivo:

- usa `CREATE TABLE IF NOT EXISTS`;
- consulta `INFORMATION_SCHEMA` antes de `ALTER TABLE`;
- insere settings padrao apenas se a chave nao existir;
- nao usa `DROP TABLE`;
- nao usa `TRUNCATE`;
- nao usa `DELETE FROM`;
- nao apaga `personalizacao`;
- nao apaga `chalets`;
- nao apaga `reservations`;
- nao altera senha/admin existente.

## Ordem recomendada

1. Criar subdominio/pasta de homologacao.
2. Restaurar uma copia do banco atual em homologacao.
3. Configurar credenciais da copia em `config/database.php` da homologacao.
4. Executar a migracao segura na copia.
5. Subir os arquivos atualizados.
6. Acessar site publico.
7. Acessar painel.
8. Conferir chales, reservas, personalizacao e configuracoes.
9. Testar reserva sem pagamento real.
10. Testar relatorio CSV.
11. Conferir logs de erro PHP.
12. Repetir em producao apenas apos validacao.

## Validacoes obrigatorias

- `personalizacao` continua com dados antigos.
- `chalets` continua com dados antigos.
- `reservations` continua com dados antigos.
- `settings` antigas continuam preservadas.
- `evo_url`, `evo_instance` e `evo_apikey` existem e nao foram apagados pela migracao.
- `evolution_provider` existe e pode indicar `evolution_api`.
- Settings antigas de Evolution Go, se existirem no banco, nao sao apagadas automaticamente.
- A API key da Evolution API nao aparece inteira no painel apos ser salva.
- PDF fica indisponivel com mensagem segura se `vendor/` nao existir.
- CSV baixa normalmente.

## Se a Hostinger rejeitar algum ALTER

Nao improvise em producao.

1. Copie a mensagem de erro.
2. Confirme versao do MySQL/MariaDB.
3. Ajuste apenas a linha rejeitada em homologacao.
4. Rode novamente na copia.
5. So depois aplique em producao.

## Veredito operacional

Pode usar o mesmo banco atual, desde que a migracao seja testada primeiro em copia e que arquivos locais sensiveis nao sejam sobrescritos.
