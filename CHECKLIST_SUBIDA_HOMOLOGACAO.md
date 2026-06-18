# Checklist de Subida para Homologacao

## 1. Arquivos que devem subir

- `.htaccess`
- `bootstrap.php`
- `checkin.php`
- `composer.json`
- `index.php`
- `script.js`
- `styles.css`
- `setup.php`, somente para a instalacao inicial
- `admin/`
- `api/`
- `images/`
- `storage/contracts/.htaccess`
- `storage/logs/.htaccess`
- `.env.example`
- `.env.homologacao.example`, apenas como modelo de configuracao

O pacote nao inclui `vendor/`. Execute o Composer no servidor para instalar o Dompdf e as demais dependencias declaradas.

## 2. Arquivos que nao devem subir

- `.env` real ou qualquer copia com credenciais
- `config/database.php`
- `config/.installed.lock`
- `.git/`, `.cursor/` e metadados locais
- `backups/`
- `node_modules/`
- `vendor/` vindo da maquina local
- `api/_sessions/`
- `uploads/private/` e `storage/private/`
- conteudo gerado em `storage/contracts/` e `storage/logs/`
- arquivos `*.sql`, `*.sqlite`, `*.log` e `*.zip`
- dumps, backups, chaves privadas, tokens, credenciais e arquivos temporarios
- `package.json`, `package-lock.json` e scripts locais de teste, pois nao sao necessarios no runtime PHP
- relatorios Markdown, que devem permanecer fora do webroot

## 3. Variaveis de ambiente

Crie o `.env` diretamente no servidor, fora de repositorio, usando `.env.homologacao.example` como referencia:

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://homologacao.seudominio.com.br
ALLOWED_ORIGINS=https://homologacao.seudominio.com.br
SYNC_ASSETS_KEY=troque-por-chave-forte
EVOLUTION_GLOBAL_URL=
EVOLUTION_GLOBAL_KEY=
```

Observacoes:

- `ALLOWED_ORIGINS` aceita origens separadas por virgula.
- Nunca reutilize a chave de homologacao em producao.
- As variaveis `DB_*` do modelo documentam o banco de homologacao, mas o codigo atual le a conexao de `config/database.php`.
- O `setup.php` cria `config/database.php` a partir do formulario de instalacao.
- O Mercado Pago atual e configurado no painel e salvo no banco; deixe-o desativado ou use somente credencial sandbox.
- Deixe Evolution API sem URL, chave e instancia enquanto envio de mensagens nao estiver autorizado.

## 4. Preparacao no servidor

Requisitos recomendados:

- PHP 8.x com `pdo_mysql`, `curl`, `gd`, `fileinfo`, `mbstring` e `json`.
- MySQL/MariaDB com base e usuario exclusivos de homologacao.
- Composer 2.
- Apache/LiteSpeed com `.htaccess` habilitado. Em Nginx, replique manualmente os bloqueios.
- HTTPS valido.

Comandos:

```bash
cd /caminho/do/sistema_hospedagem
composer validate
composer install --no-dev --optimize-autoloader
test -f vendor/autoload.php
test -d vendor/dompdf/dompdf
```

## 5. Lint PHP

```bash
cd /caminho/do/sistema_hospedagem
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
```

Nao prossiga para os testes no navegador se algum arquivo retornar erro de sintaxe.

## 6. Instalacao e banco

- Crie uma base vazia e um usuario MySQL exclusivos de homologacao.
- Nao importe dumps e nao execute restore.
- Confirme que `config/database.php` nao veio no upload.
- Acesse `/setup.php` somente no ambiente de homologacao.
- Informe no instalador apenas credenciais do banco de homologacao.
- Crie um administrador de teste, sem reutilizar senha de producao.
- Confirme a criacao de `config/database.php` com permissao restrita.
- Depois da instalacao, confirme que `/setup.php` retorna `403`.
- Remova ou renomeie `setup.php` no servidor quando a instalacao e os testes iniciais terminarem.

## 7. Checklist no navegador

- [ ] Abrir a pagina inicial por HTTPS.
- [ ] Confirmar carregamento de estilos, scripts e imagens.
- [ ] Listar e abrir os detalhes dos chales.
- [ ] Consultar disponibilidade por datas.
- [ ] Simular pre-reserva sem pagamento real.
- [ ] Testar cupom valido e invalido, se houver dados de teste.
- [ ] Acessar `/admin/login.html`.
- [ ] Entrar com administrador exclusivo de homologacao.
- [ ] Listar reservas, chales, cupons, extras, consumos e usuarios.
- [ ] Criar e editar somente registros identificados como teste.
- [ ] Gerar e abrir contrato/PDF apos instalar o Composer.
- [ ] Confirmar logout e bloqueio do painel apos sair.
- [ ] Confirmar que segredos nao aparecem no site publico ou no console.
- [ ] Manter Mercado Pago desativado ou estritamente em sandbox.
- [ ] Manter Evolution API desativada para impedir mensagens reais.
- [ ] Nao testar FNRH contra servico real sem ambiente oficial de homologacao.

## 8. Checklist de endpoints bloqueados

Sem sessao administrativa:

- [ ] `GET /api/users.php` retorna `401`.
- [ ] `POST /api/chalets.php` retorna `401` ou `403`.
- [ ] `GET /api/debug_env.php` retorna `401` ou `403`.
- [ ] `GET /api/test_db.php` retorna `401` ou `403`.
- [ ] `GET /api/seed_defaults.php` retorna `401` ou `403`.
- [ ] `GET /api/schema.php` retorna `404` ou bloqueio equivalente.
- [ ] `GET /api/sync_assets.php` sem chave retorna `403`.
- [ ] `GET /api/settings.php?key=mercadoPagoSettings` nao retorna token.
- [ ] `OPTIONS` com origem nao autorizada retorna `403`.
- [ ] Acesso web a `.env`, `config/`, `backups/`, `vendor/`, logs, SQL e ZIP retorna `403` ou `404`.

Use os comandos prontos em `COMANDOS_HOMOLOGACAO.md`.

## 9. Observacoes importantes

### `setup.php`

O instalador cria tabelas e grava `config/database.php`. Use apenas em uma base vazia de homologacao. Ele se bloqueia quando o arquivo de configuracao ja existe, mas ainda deve ser removido ou renomeado apos a instalacao.

### `.env`

Deve ser criado no servidor e nunca enviado dentro do ZIP. Use `APP_DEBUG=false`. O arquivo `.env.homologacao.example` contem apenas valores ficticios.

### `config/database.php`

Contem a credencial efetivamente usada pela aplicacao. Nao faz parte do pacote. Deve ser criado pelo instalador no servidor ou manualmente por responsavel autorizado, com permissao restrita.

### `vendor/`

Nao deve ser copiado de outra maquina. Deve ser criado no servidor com `composer install --no-dev --optimize-autoloader`. A geracao de contratos e PDFs depende do Dompdf.

### Uploads e arquivos gerados

O pacote leva apenas imagens publicas atuais e arquivos `.htaccess` de protecao. Nao leve uploads privados, contratos gerados, sessoes ou logs. Garanta escrita somente nos diretorios exigidos pela aplicacao e bloqueie execucao de PHP em uploads.

