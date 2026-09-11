# ADR-0005 — Prévia visual reflui por container queries, não por viewport

WC CheckoutSuite · decisão tomada na execução da tarefa **WCCS-015** (F02)

**Status:** Aceito
**Fases de impacto:** F02, F03, F09, F10 (toda tela que ofereça prévia)
**Origem:** decisão de implementação, **não** diretriz do `ROADMAP.md`

## Contexto

O aceite da WCCS-015 pede que "Desktop/tablet/mobile" sejam "identificados como prévia". A implementação
óbvia — estreitar uma `div` e chamá-la de "mobile" — satisfaz a letra do critério e produz uma **mentira
visual**: o layout do checkout é decidido por *media queries de viewport*, que continuam respondendo à
janela do navegador. Numa janela larga, a "prévia mobile" mostraria o layout de desktop, e a tela de admin
estaria **confirmando um layout que não existe**.

O §17 do `ROADMAP.md` fixa os pontos de quebra do produto (760 px, 1020 px, 1550 px) mas não diz como a
prévia deve reproduzi-los dentro de uma moldura mais estreita que a janela. Essa lacuna é o contexto desta
decisão.

## Decisão

A prévia estabelece um **contêiner de consulta** e o conteúdo previsto opta por `@container`:

1. A superfície da prévia declara `container-type: inline-size` e **nomeia** o contêiner
   (`container-name: wccs-preview`).
2. A largura vem de token, nunca de número solto: `--wccs-preview-width` é definida por classe de
   dispositivo a partir de `--wccs-size-preview-desktop|tablet|mobile`.
3. Conteúdo previsto que precise refluir usa `@container wccs-preview (...)`, e o limiar da consulta mais
   estreita é **o mesmo token** de `--wccs-breakpoint-compact` (760 px).
4. Conteúdo que só tenha regras de viewport **não** reflui. A moldura declara essa limitação por escrito numa
   superfície obrigatória de capacidades, em vez de deixar a diferença invisível.

O contêiner é nomeado de propósito: uma consulta sem nome casa com o ancestral mais próximo que for
contêiner, e a introdução futura de outro contêiner mudaria o layout da prévia sem que ninguém percebesse.

## Consequências

- **O seletor de dispositivo passa a ser um mecanismo, não decoração.** Trocar para "mobile" muda de fato a
  largura contra a qual o conteúdo é disposto.
- **A prévia é fiel por largura, não por escala.** Um dispositivo mais largo que a área de admin rola
  horizontalmente. Encolher mostraria um layout que não corresponde ao dispositivo escolhido.
- **O palco não pode ser centralizado com `justify-content: center`.** Em contêiner rolável isso corta a
  borda inicial e torna o lado esquerdo de uma prévia larga inalcançável. O padrão é
  `justify-content: flex-start` no palco e `margin-inline: auto` na superfície, e as três propriedades estão
  travadas por asserção em `F02-wccs-015-preview-proof.php`.
- **A largura tem de vir do sistema de design.** As três larguras vivem em `tokens.json` (canônico) e
  `tokens.css`, e a prova falha se os dois divergirem — a prévia não pode inventar uma largura que o design
  system desconhece.
- **Todo conteúdo futuro que precise refluir na prévia assume o custo de escrever consultas de contêiner.**
  É trabalho adicional deliberado: a alternativa é uma prévia que mente.

## Alternativas consideradas e rejeitadas

| Alternativa | Por que foi rejeitada |
|---|---|
| Estreitar uma `div` com regras de viewport | Não funciona: as media queries continuam respondendo à janela. A prévia confirmaria um layout falso — exatamente o que o aceite pede para evitar. |
| Reproduzir as três larguras em `iframe` | Isola estilos e tokens, exigindo duplicar o contexto de tema e de design dentro do `iframe`, e o custo de sincronização é maior que o das consultas de contêiner. |
| Aplicar `transform: scale()` como mecanismo principal | Escala não altera a largura de layout, então não resolveria o reflow sozinha. Continua sendo uma melhoria possível **de apresentação** (ajustar à tela), não o mecanismo de layout. |
| Copiar as regras de viewport para regras de contêiner em duplicidade | Duas listas de pontos de quebra que divergem na primeira alteração. O limiar é o mesmo token, verificado por asserção. |
| Declarar a limitação apenas na documentação | A limitação desapareceria da tela e só existiria para quem lê o repositório. A superfície de capacidades é obrigatória na moldura. |

## Como verificar conformidade

`tests/Integration/F02-wccs-015-preview-proof.php` (24 asserções) falha se:

- a superfície deixar de declarar `container-type: inline-size` ou `container-name: wccs-preview`;
- existirem menos de duas consultas `@container wccs-preview (...)`;
- a consulta mais estreita deixar de usar 760 px;
- alguma das três classes de dispositivo deixar de apontar para a sua largura de token;
- `tokens.json` e `tokens.css` divergirem em qualquer largura de prévia;
- o palco voltar a centralizar com `justify-content: center`.

`tests/js/components/PreviewFrame.test.js` (13 testes) cobre a metade que o PHP não alcança: que trocar o
dispositivo troca `data-viewport`, que o `aria-pressed` acompanha, que a matriz de capacidades segue o
adaptador e que **nenhuma requisição é feita**.

O `§17` do `ROADMAP.md` permanece a fonte dos pontos de quebra do produto; este ADR **não** o contraria:
apenas determina como a prévia os reproduz dentro de uma moldura.
