# Relatorio - Personalizacao do site publico

Data: 2026-06-17

## Veredito

A personalizacao do site publico nao foi removida. Ela continua presente no backend, no painel administrativo e no carregamento do site publico.

O que mudou em relacao ao modelo antigo e que a personalizacao principal passou a ficar concentrada na tabela `personalizacao`, com apoio de algumas chaves em `settings`. O painel exibe a area como `Personalizacao`.

## Onde aparece no painel

- Arquivo do menu: `admin/index.html`.
- View administrativa: `admin/admin.js`.
- Item do menu: `data-view="customization"`.
- Nome exibido: `Personalizacao`.

Observacao importante: a view pode ficar oculta para usuarios com perfil limitado. Em `admin/admin.js`, o menu `customization` aparece para administrador completo ou para usuarios com permissao especifica. No fallback de secretaria, `customization` fica oculto junto com configuracoes, usuarios, financeiro, cupons e regras sazonais.

## Campos existentes hoje

O endpoint `api/customization.php` trabalha com estes grupos:

- Favicon.
- Logo principal.
- Logo alternativa.
- Hero da pagina inicial: titulo, subtitulo e imagens.
- Sobre nos: titulo, texto e imagem.
- Secao de hospedagens/acomodacoes: subtitulo, titulo e descricao.
- Diferenciais: cinco blocos de titulo e descricao.
- Depoimentos: tres depoimentos com nome, local, texto e imagem.
- Localizacao: endereco, subtitulo, texto sobre chegada de carro, link do mapa e embed de mapa.
- Videos: status ativo/inativo e lista JSON de videos.
- WhatsApp flutuante: numero e mensagem.
- Rodape: descricao, endereco, email, telefone e copyright.

Campos de identidade visual tambem aparecem via `settings`, como nome da empresa, titulo do site, descricao, cores e logos legadas (`company_logo` e `company_logo_light`).

## Logo principal e alternativa

As duas continuam existindo:

- `logo_principal` na tabela `personalizacao`.
- `logo_alternativa` na tabela `personalizacao`.
- `logoPrincipalImg` e `logoAlternativaImg` no JSON usado pelo painel e pelo site.

O `index.php` usa essas logos no carregamento inicial da pagina. Se nao houver logo nova em `personalizacao`, ele tenta cair para as logos antigas de `settings`.

## O painel permite alterar logo, imagens, cores e textos?

Sim, parcialmente separado por areas:

- Logo, favicon, imagens e textos institucionais: pela view `Personalizacao` em `admin/admin.js`, salvando em `api/customization.php`.
- Cores e configuracoes gerais: pela area de configuracoes/settings, usando `api/settings.php`.

O envio de imagens usa `FormData`, com uploads para `images/uploads/`.

## O site publico carrega esses dados?

Sim. Ha dois caminhos:

- `index.php` consulta `personalizacao` no PHP e renderiza conteudo inicial da home.
- `script.js` consulta `api/settings.php` e aplica dinamicamente dados de `customization`, configuracoes e elementos da home.

Isso significa que o site publico nao depende apenas de JavaScript para mostrar a personalizacao basica; parte importante ja vem renderizada pelo PHP.

## Algo foi removido?

Pela auditoria estatica, nao encontrei evidencia de que a personalizacao tenha sido removida. Ela continua em:

- `api/customization.php`.
- `api/settings.php`.
- `index.php`.
- `script.js`.
- `admin/index.html`.
- `admin/admin.js`.
- `api/schema.php`.
- `setup.sql`.

O que pode dar a impressao de remocao:

- Usuario sem permissao de administrador completo pode nao ver o menu `Personalizacao`.
- Algumas configuracoes de identidade visual estao divididas entre `Personalizacao` e `Configuracoes`.
- Logos novas ficam em `personalizacao`; logos antigas ainda existem em `settings`.

## Bugs ou riscos provaveis

1. Inconsistencia no carregamento dinamico da logo

`index.php` renderiza `logoPrincipalImg` e `logoAlternativaImg` corretamente no carregamento inicial. Porem, em `script.js`, o trecho que le `data.customization.logoPrincipalImg` e `data.customization.logoAlternativaImg` so atualiza o header quando `data.company_logo` existe. Se o ambiente tiver apenas as novas logos de `personalizacao`, a logo inicial pode aparecer pelo PHP, mas a atualizacao dinamica pode nao aplicar a mesma regra depois.

Risco: apos salvar/alterar personalizacao, a logo pode parecer nao atualizar de forma consistente ate recarregar a pagina, ou pode cair para texto/icone antigo em alguns fluxos.

2. Migracao parcial em `api/customization.php`

`api/customization.php` tenta adicionar automaticamente `logo_principal`, `logo_alternativa` e `loc_subtitulo` se faltarem. Ja `loc_map_embed`, `videos_enabled` e `videos_json` aparecem no `api/schema.php`. Se um banco antigo nunca passar pela rotina completa de schema, pode faltar coluna para mapas/videos.

Risco: salvar personalizacao em banco antigo pode falhar se colunas novas ainda nao existirem.

3. Perfil de usuario limitado

O menu `Personalizacao` pode ficar oculto por permissao. Isso nao e remocao, mas pode parecer que a funcionalidade sumiu.

4. Sanitizacao de mapa embed

`locMapEmbed` permite `iframe` via `strip_tags`. Isso e funcional para Google Maps, mas precisa ser usado apenas por administrador confiavel.

## Resposta direta

A personalizacao continua. Nao foi removida. Ela esta no painel em `Personalizacao`, com armazenamento principal na tabela `personalizacao`. As logos principal e alternativa continuam existindo. O principal ponto a corrigir em etapa futura e a regra de logo no JavaScript, para tratar as logos novas de `personalizacao` mesmo quando `company_logo` nao existir.
