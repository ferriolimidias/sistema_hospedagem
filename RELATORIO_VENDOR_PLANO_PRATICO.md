# Relatorio - Vendor e Dompdf: plano pratico

Data: 2026-06-17

## Veredito

`vendor/` nao e necessaria para o fluxo principal atual do site.

O sistema pode seguir para atualizacao principal sem `vendor/` se o objetivo imediato for:

- site publico;
- reservas;
- painel administrativo basico;
- relatorios CSV;
- configuracoes;
- personalizacao;
- cupons, extras e regras comerciais sem PDF.

`vendor/` passa a ser necessaria quando forem usados:

- contratos PDF;
- recibos PDF;
- envio de PDFs por WhatsApp/Evolution;
- qualquer endpoint que use Dompdf.

## Evidencia no projeto

- `composer.json` declara `dompdf/dompdf`.
- `api/contract_service.php` carrega `../vendor/autoload.php` e usa `Dompdf`.
- `api/evolution_service.php` carrega `../vendor/autoload.php` para gerar recibo/PDF de teste.
- `api/reports.php` gera CSV com `Content-Type: text/csv`; nao depende de Dompdf.

## Vendor e necessaria para relatorios CSV?

Nao. O relatorio gerencial CSV em `api/reports.php` nao usa Dompdf.

## Vendor e necessaria para contratos/recibos PDF?

Sim. Sem `vendor/autoload.php` e Dompdf, contratos e recibos PDF falham ou retornam erro de dependencia ausente.

## Recomendacao para agora

Nao bloquear a atualizacao principal por causa de `vendor/`.

Condicao recomendada:

- Se os botoes/fluxos de PDF estiverem visiveis, ocultar/desabilitar em etapa futura ou exibir mensagem segura: "PDF indisponivel neste ambiente. Instale as dependencias antes de gerar contratos/recibos."
- Nao tentar chamar PDF em producao sem `vendor/`.
- Nao enviar mensagem real via Evolution para testar PDF.

## Caminhos para gerar vendor no futuro

### Opcao 1 - VPS ou ambiente Linux limpo

Mais recomendada para este projeto.

Fluxo:

```bash
php -v
composer validate
composer install --no-dev --optimize-autoloader
test -f vendor/autoload.php
test -d vendor/dompdf/dompdf
```

Depois:

- baixar `vendor/`;
- baixar/preservar `composer.lock`;
- subir `vendor/` junto com o projeto na Hostinger;
- nao subir `.env`, banco, backups, logs ou credenciais.

Vantagens:

- Ambiente mais parecido com hospedagem Linux.
- Dependencias geradas de forma limpa.
- Facil validar `vendor/autoload.php` e Dompdf.

Riscos:

- Precisa de uma VPS ou ambiente temporario.
- Conferir versao de PHP compativel com a Hostinger.

### Opcao 2 - Windows local

Boa alternativa se houver PHP e Composer instalados localmente.

Fluxo:

```powershell
php -v
composer validate
composer install --no-dev --optimize-autoloader
Test-Path vendor\autoload.php
Test-Path vendor\dompdf\dompdf
```

Vantagens:

- Simples se PHP/Composer ja estiverem instalados.
- Gera `composer.lock` e `vendor/` sem depender da Hostinger.

Riscos:

- Pode haver diferenca entre extensoes/versao do PHP local e PHP da Hostinger.
- Composer local precisa estar configurado corretamente.

### Opcao 3 - GitHub Actions

Boa opcao se o deploy for por GitHub e se quiser gerar um artefato pronto.

Ideia:

- workflow roda em Linux;
- instala PHP;
- roda `composer validate`;
- roda `composer install --no-dev --optimize-autoloader`;
- gera ZIP/artefato com `vendor/`;
- exclui `.env`, banco, backups, logs, dumps e credenciais.

Vantagens:

- Reprodutivel.
- Nao depende do computador local.
- Pode gerar pacote final ja com `vendor/`.

Riscos:

- Precisa configurar workflow com cuidado para nao empacotar arquivos sensiveis.
- Precisa conferir se o artefato inclui `vendor/` e exclui `.env`/bancos/logs.

### Opcao 4 - Composer na Hostinger via SSH

So usar se o plano permitir SSH e Composer/PHP adequados.

Fluxo:

```bash
composer validate
composer install --no-dev --optimize-autoloader
```

Vantagens:

- Gera dependencia diretamente no ambiente final.

Riscos:

- O usuario informou que o servidor atual e hospedagem simples sem terminal disponivel.
- Nao deve ser o caminho principal para este caso.

## Plano pratico recomendado

1. Atualizar o sistema principal sem `vendor/`, mantendo PDF desabilitado ou tratado com mensagem segura.
2. Quando for ativar contratos/recibos PDF, gerar `vendor/` em VPS Linux ou GitHub Actions.
3. Preservar `composer.lock` junto com `vendor/`.
4. Subir `vendor/` para a Hostinger junto com o projeto.
5. Testar somente em homologacao antes de expor botoes de PDF em producao.

## Conclusao

Para a atualizacao principal, `vendor/` nao e bloqueio. Para PDF, `vendor/` e obrigatoria. O caminho mais pratico e gerar `vendor/` fora da Hostinger, preferencialmente em ambiente Linux limpo ou GitHub Actions, e subir a pasta pronta quando a funcionalidade de PDF for realmente usada.
