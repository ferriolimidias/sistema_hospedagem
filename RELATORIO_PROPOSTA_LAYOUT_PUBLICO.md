# Relatorio - Proposta de layout publico

Data: 2026-06-17

## Diagnostico geral

O site publico ja tem uma estrutura completa para pousada/hospedagem:

- Header fixo.
- Hero com carrossel.
- Secao "sobre".
- Listagem de chales.
- Diferenciais.
- Videos.
- Depoimentos.
- Localizacao.
- FAQ.
- CTA de reserva.
- Modal de reserva com datas, hospedes, cupom, extras, pet, resumo de preco e pagamento.

O problema nao e falta de estrutura. O ponto principal e acabamento visual: logo pouco harmonica, hierarquia visual irregular, cards e formulario com cara mais tecnica do que hoteleira, e alguns estados de carregamento/erro ainda simples.

## Logo

Problema observado:

- A logo pode ficar quadrada ou pesada se a imagem enviada tiver canvas quadrado.
- Ha estilos inline em `index.php` e `script.js`, o que dificulta padronizar altura, largura e alinhamento.
- A regra dinamica do JS prioriza `company_logo`, enquanto a personalizacao nova usa `logoPrincipalImg` e `logoAlternativaImg`.

Proposta:

- Usar uma classe fixa para a imagem da logo.
- Aplicar `object-fit: contain`.
- Definir limite de largura e altura.
- Evitar recorte e evitar fundo quadrado forçado.
- Usar logo principal em fundo claro e logo alternativa em fundo escuro/transparente.
- Preferir PNG com fundo transparente ou SVG limpo.

Exemplo de direcao visual futura:

```css
.navbar .logo img {
    width: auto;
    max-width: 180px;
    max-height: 52px;
    object-fit: contain;
    border-radius: 0;
}
```

Nao apliquei essa mudanca nesta etapa; e apenas proposta segura para proxima fase.

## Header e hero

Diagnostico:

- O header ja funciona, mas pode ficar visualmente pesado dependendo da logo.
- O hero tem boa base, com imagem e CTA, mas pode ganhar melhor hierarquia e leitura.
- O conteudo do hero deve continuar direto: nome/beneficio, subtitulo curto e botoes claros.

Melhorias sugeridas:

- Padronizar altura do header.
- Melhorar contraste do texto do hero com overlay mais refinado.
- Evitar hero alto demais em celular.
- Dar mais respiro entre titulo, subtitulo e botoes.
- Manter CTA principal para reserva e CTA secundario para conhecer chales.

## Cards de chales

Diagnostico:

- A listagem existe e carrega via API.
- Os cards tem base funcional, mas podem ficar mais comerciais e comparaveis.
- Imagens precisam manter proporcao consistente.
- Informacoes de preco, capacidade e CTA devem ficar mais previsiveis.

Melhorias sugeridas:

- Definir `aspect-ratio` fixo para imagens dos cards.
- Padronizar altura de titulos e descricoes.
- Usar badges discretos para capacidade, diaria ou disponibilidade.
- Alinhar preco e botao no rodape do card.
- No mobile, tornar o botao principal mais facil de tocar.
- Criar estado vazio melhor quando nao houver chales cadastrados.

## Botoes

Diagnostico:

- Ha botoes funcionais, mas a hierarquia visual pode ser mais clara.
- Alguns fluxos ainda usam `alert()`.

Melhorias sugeridas:

- Padronizar botao primario para reserva.
- Padronizar botao secundario para detalhes/mais informacoes.
- Evitar estilos muito diferentes entre home, cards e modal.
- Trocar `alert()` por mensagens visuais dentro do modal quando possivel.

## Modal e formulario de reserva

Diagnostico:

O modal de reserva e completo: datas, adultos/criancas, idades, disponibilidade, cupom, extras, pet, resumo de preco e pagamento. O fluxo e rico, mas pode parecer denso.

Melhorias sugeridas:

- Separar visualmente dados da estadia, opcionais e pagamento.
- Mostrar resumo de preco em bloco sempre claro.
- Melhorar mensagens de indisponibilidade e validacao.
- Exibir loading durante consultas de disponibilidade/cupom.
- Desabilitar botao de concluir enquanto houver validacao pendente.
- No mobile, reduzir rolagem visual e deixar o CTA final facil de encontrar.

## Portugues Brasil

Ponto encontrado:

- Em `index.php`, o FAQ usa "Fale connosco". Para PT-BR, o ideal e "Fale conosco".

Tambem ha comentarios em `script.js` com termos como `MOCK`, apesar de o fluxo chamar endpoints reais. Isso nao aparece necessariamente ao usuario, mas confunde manutencao.

## Responsividade mobile

Diagnostico:

- Existem media queries e estrutura responsiva.
- O risco maior esta em componentes densos: header com logo grande, cards, modal e resumo de reserva.

Melhorias sugeridas:

- Limitar logo em telas pequenas.
- Garantir que botoes do hero quebrem linha sem apertar texto.
- Cards em uma coluna com imagem proporcional.
- Modal com espacamento menor e CTA visivel.
- Resumo de preco legivel sem esmagar labels.

## Prioridades

### Critico

- Corrigir regra da logo para nao depender apenas de `company_logo` quando `logoPrincipalImg`/`logoAlternativaImg` existirem.
- Garantir que a personalizacao carregue em banco atualizado com todas as colunas necessarias.
- Evitar que fluxo de PDF apareca como disponivel em ambiente sem `vendor/`, se esses botoes estiverem expostos ao usuario.

### Importante

- Padronizar CSS da logo com `object-fit: contain`, limites de altura/largura e sem recorte.
- Melhorar hierarquia do formulario de reserva.
- Trocar feedbacks via `alert()` por mensagens no proprio layout.
- Corrigir "Fale connosco" para "Fale conosco".
- Melhorar estados de loading, erro e vazio.

### Desejavel

- Melhorar cards de chales com imagem consistente, badges e rodape alinhado.
- Refinar overlay do hero.
- Ajustar espacos entre secoes.
- Criar uma paleta mais coerente com pousada/natureza: verde/musgo/floresta, neutros claros e um acento quente discreto.

### Polimento visual

- Sombra leve e consistente em cards.
- Transicoes sutis em hover.
- Icones mais consistentes.
- Melhor alinhamento do rodape.
- Reduzir comentarios confusos no JS em etapa de manutencao.

## Proposta pratica de layout

Sem reescrever o sistema, a melhor proposta e evoluir o layout atual:

1. Header mais limpo, com logo contida e sem aspecto quadrado.
2. Hero com imagem forte, overlay legivel e dois CTAs bem hierarquizados.
3. Secao de chales com cards mais consistentes e foco em reserva.
4. Modal de reserva dividido por blocos: estadia, opcionais, resumo e pagamento.
5. Estados de carregamento e erro integrados ao visual, sem depender de alertas do navegador.
6. Mobile com logo menor, cards em uma coluna e formulario mais escaneavel.

Essa abordagem preserva `index.php`, `styles.css`, `script.js` e as APIs atuais. Nao exige trocar framework nem reescrever o sistema.
