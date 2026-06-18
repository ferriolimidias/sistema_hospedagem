# Comandos para Homologacao

Substitua a URL e o caminho pelos valores reais do ambiente de teste. Nao execute estes comandos contra producao.

## 1. Dependencias PHP

```bash
cd /caminho/do/sistema_hospedagem
composer validate
composer install --no-dev --optimize-autoloader
test -f vendor/autoload.php
test -d vendor/dompdf/dompdf
```

## 2. Lint de todos os arquivos PHP

```bash
cd /caminho/do/sistema_hospedagem
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l
```

## 3. Variaveis para os testes HTTP

```bash
export BASE_URL="https://homologacao.seudominio.com.br"
export ORIGIN_INVALIDA="https://origem-nao-autorizada.example"
```

## 4. Endpoints protegidos sem login

```bash
curl -i "$BASE_URL/api/users.php"
curl -i -X POST "$BASE_URL/api/chalets.php" \
  -H "Content-Type: application/json" \
  --data '{}'
curl -i "$BASE_URL/api/debug_env.php"
curl -i "$BASE_URL/api/test_db.php"
curl -i "$BASE_URL/api/seed_defaults.php"
curl -i "$BASE_URL/api/schema.php"
curl -i "$BASE_URL/api/sync_assets.php"
curl -i "$BASE_URL/api/settings.php?key=mercadoPagoSettings"
```

Resultados esperados:

- `users.php`: `401`.
- `POST chalets.php`: `401` ou `403`.
- `debug_env.php`, `test_db.php` e `seed_defaults.php`: `401` sem login ou `403` com `APP_DEBUG=false`.
- `schema.php`: `404` ou bloqueio equivalente.
- `sync_assets.php` sem chave: `403`.
- `settings.php?key=mercadoPagoSettings`: resposta sem token; o valor deve ser `null` ou filtrado.

## 5. CORS com origem nao permitida

```bash
curl -i -X OPTIONS "$BASE_URL/api/chalets.php" \
  -H "Origin: $ORIGIN_INVALIDA" \
  -H "Access-Control-Request-Method: POST"
```

Resultado esperado: `403`.

## 6. Arquivos e diretorios sensiveis

```bash
curl -i "$BASE_URL/.env"
curl -i "$BASE_URL/.env.homologacao.example"
curl -i "$BASE_URL/config/database.php"
curl -i "$BASE_URL/backups/"
curl -i "$BASE_URL/vendor/"
curl -i "$BASE_URL/database.sql"
curl -i "$BASE_URL/arquivo.log"
curl -i "$BASE_URL/arquivo.zip"
```

Resultado esperado: `403` ou `404`, sem exibir conteudo.

## 7. Instalador

Depois de concluir a instalacao e confirmar que `config/database.php` existe:

```bash
curl -i "$BASE_URL/setup.php"
```

Resultado esperado: `403`. Depois dos testes iniciais, remova ou renomeie `setup.php` no servidor por procedimento controlado.

## 8. Verificacoes locais no servidor

```bash
test ! -f .env.example.real
test -f .env
test -f config/database.php
test -f vendor/autoload.php
test -w storage/contracts
test -w storage/logs
```

Nao imprima o conteudo de `.env` ou `config/database.php` no terminal compartilhado.

