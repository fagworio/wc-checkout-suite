# Registro de validação — WCCS-015

**Tarefa:** WCCS-015 · "Criar preview visual"
**Fase:** F02 · Design system e shell administrativo (última tarefa da fase)
**Prioridade:** `required_v1` · **Dependências:** WCCS-012, WCCS-013
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Desktop/tablet/mobile identificados como prévia; nenhum dado real necessário."

**Resultado:** **79 testes de JS** (9 suítes, 20 novos nesta tarefa) · **24 asserções PHP** da prova dedicada · **307 asserções de integração** em 11 provas, 0 falhas · 57 testes unitários PHP · os **7 gates** verdes.

## 2. O problema que o aceite esconde

O aceite é fácil de cumprir de forma desonesta. Estreitar uma `div` e chamá-la de "mobile" satisfaz a letra do critério e mostra o **layout de desktop**, porque o layout do checkout é decidido por *media queries de viewport* — e essas continuam respondendo à janela do navegador, não importa quão estreito seja o invólucro. A prévia confirmaria um layout que não existe.

Por isso a entrega não é "uma moldura com três botões". É um **contêiner de consulta**:

| Peça | Regra | Por quê |
|---|---|---|
| `.wccs-preview__surface` | `container-type: inline-size` + `container-name: wccs-preview` | Publica a largura **do próprio invólucro** como referência de layout |
| `.wccs-preview__surface[data-viewport="…"]` | `--wccs-preview-width: var(--wccs-size-preview-…)` | A largura vem de token, não de número solto no JS |
| Conteúdo previsto | `@container wccs-preview (max-width: 1020px / 760px)` | Reflui com a moldura, não com a janela |

O contêiner é **nomeado** de propósito: uma consulta sem nome casa com o ancestral mais próximo que for contêiner, e um contêiner futuro em outro ponto da tela passaria a decidir o layout da prévia sem ninguém perceber.

A prova verifica o mecanismo no **bundle compilado**, não só na origem: as duas consultas `@container`, o `container-type` e as três larguras sobrevivem à minificação.

### Consequência honesta

Conteúdo que só tenha regras de viewport **não** reflui dentro da prévia. A moldura não finge o contrário: ela exibe uma superfície de limitações obrigatória, alimentada por `previewCapabilities.js`, e quando um adaptador não tem entrada ela mostra um aviso dizendo que o resultado é **não verificado** em vez de renderizar uma prévia que parece completa.

## 3. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/admin/app/components/PreviewFrame.js` | A moldura: seletor de dispositivo, adaptador e contexto, superfície de limitações, `renderPreview(context)` |
| `resources/admin/app/components/Segmented.js` | Controle de escolha única em botões reais, com `aria-pressed` |
| `resources/admin/app/previewCapabilities.js` | Matriz de capacidades por adaptador, com motivo em cada nível |
| `resources/admin/app/components/components.css` | Blocos `Segmented` e `Preview`, incluindo o contêiner de consulta |
| `resources/design-tokens/tokens.json` · `tokens.css` | `preview_desktop` 1280, `preview_tablet` 768, `preview_mobile` 375 |
| `resources/admin/app/AppShell.js` | A seção **Appearance** passa a renderizar a prévia |
| `tests/js/components/PreviewFrame.test.js` · `Segmented.test.js` | 20 testes de comportamento |
| `tests/Integration/F02-wccs-015-preview-proof.php` | 24 asserções do lado do servidor |

## 4. Como cada palavra do aceite foi provada

### "Desktop/tablet/mobile identificados" — ✅

As três classes de dispositivo são seletores reais que o conteúdo previsto lê, e cada uma está ligada à sua largura de token:

| Dispositivo | Token (JSON = canônico) | CSS | Largura |
|---|---|---|---|
| Desktop | `preview_desktop` | `--wccs-size-preview-desktop` | 1280 px |
| Tablet | `preview_tablet` | `--wccs-size-preview-tablet` | 768 px |
| Mobile | `preview_mobile` | `--wccs-size-preview-mobile` | 375 px |

A prova confere que o **JSON e o CSS concordam** e que **cada** classe aponta para a sua largura. A maior das consultas de contêiner (760 px) usa o mesmo limiar de `--wccs-breakpoint-compact`, então a prévia não inventa um ponto de quebra paralelo ao do sistema de design.

Do lado do DOM, 13 testes provam que trocar o dispositivo troca `data-viewport` na superfície, e que o `aria-pressed` acompanha — "identificado" também precisa valer para quem não vê a tela.

### "como prévia" — ✅

Três afirmações simultâneas, todas verificadas:

1. Um selo **"Preview"** permanece visível em todos os estados da moldura — não é um estado transitório.
2. A declaração de que os valores são sintéticos e que o checkout real **nunca é tocado** está no bundle publicado.
3. A matriz de capacidades traz **7 níveis, 7 motivos**. A prova falha se um nível ficar sem motivo, porque um nível sem motivo é a mesma falha em miniatura: uma afirmação que ninguém consegue conferir. Os quatro níveis usados são exatamente os do `§8` — `native`, `suite`, `limited`, `unsupported`.

### "nenhum dado real necessário" — ✅

Duas verificações independentes:

- **Não existe de onde ler dado real.** A prévia não introduziu nenhuma rota REST: o conjunto continua em quatro (`draft`, `publish`, `revisions`, `restore`).
- **Nada é lido.** Um teste instala um espião em `globalThis.fetch` e interage com dispositivo e adaptador; o espião não é chamado.

O `renderPreview` recebe o contexto completo (`viewport`, `adapter`, `personType`, `customer`) e é responsável pelo conteúdo — a moldura não busca nada por conta própria.

## 5. Dois defeitos reais encontrados no caminho

Nenhum dos dois veio do ambiente; os dois são bugs meus ou latentes que teriam ido para produção.

### 5.1 `Field.js` era inutilizável sob `strict`

O JSDoc declarava `help`, `error` e `required` como **obrigatórios** (sem colchetes). Com `strict: true` e `checkJs`, a primeira chamada real de `Field` falhou na checagem de tipos: *"missing the following properties … `help`, `error`"*.

Isso estava latente desde a WCCS-013 e ninguém tinha percebido **porque nenhum componente jamais chamou `Field`** — o shell só usava `Notice`. A prévia sintética foi o primeiro consumidor e expôs o defeito. Corrigido: os três passam a opcionais.

Vale registrar como lição: a suíte de componentes testava `Field` em isolamento, passando as props explicitamente, então o teste nunca esbarrou na assinatura que o JSDoc publicava. Um teste que passa todas as props não prova que uma chamada normal compila.

### 5.2 O início de uma prévia larga ficaria inalcançável

A primeira versão centralizava o palco com `justify-content: center` e `overflow-x: auto`. Em um contêiner rolável, centralizar **corta a borda inicial**: o desktop de 1280 px numa tela de admin mais estreita perderia o lado esquerdo sem forma de rolar até ele.

Corrigido com o padrão correto: `justify-content: flex-start` no palco e `margin-inline: auto` na superfície — que resolve para zero assim que o conteúdo transborda. As três propriedades estão travadas por asserção, porque o bug voltaria com uma edição inocente.

## 6. Buraco fechado nos parsers de prova de 012 e 013

As provas afirmam "todo seletor está escopado". O parser extraía seletores com `(?:^|\})` como fronteira — `}` apenas. Com o primeiro bloco `@container` no arquivo, o **primeiro seletor dentro do bloco segue `{`, não `}`**, e escapava da verificação: a prova reportava cobertura total enquanto uma regra ficava sem verificar.

`{` passou a ser fronteira nos dois arquivos. O prelúdio `@container …` continua excluído pela classe `[^{}@]`, então a checagem não ganhou falsos positivos. Ambas as provas seguem verdes com o CSS novo (012: 36 asserções; 013: 17, com 46 tokens distintos todos declarados).

## 7. Observação honesta: o que foi e o que não foi observado

**Não abri um navegador nesta tarefa.** Não há captura de tela nem medição no navegador, e não afirmo que o resultado visual foi visto.

O que sustenta a aceitação é, portanto, duplo e explícito:

- **O mecanismo**, verificado estruturalmente no bundle publicado — contêiner nomeado, `inline-size` ligada a token por classe de dispositivo, duas consultas de contêiner, larguras concordando entre JSON e CSS;
- **O comportamento no DOM**, verificado por 13 testes que executam o componente em jsdom.

O que **falta** e fica registrado como pendência real: confirmar em navegador que o reflow de 3 → 2 → 1 colunas acontece como o CSS descreve. Isso pertence à revisão visual da **WCCS-063**, junto com a contenção de foco dos diálogos, que também está adiada para lá. Enquanto isso não acontecer, o layout da amostra sintética é uma afirmação do CSS, não uma observação.

## 8. O que NÃO foi feito

- **Nenhum campo real é previsto.** O conteúdo é a amostra sintética embutida ou o que o chamador passar via `renderPreview`. O editor que fornece campos de verdade é F03, e é ele que vai ligar a prévia ao schema.
- **Nenhum ajuste de escala.** Um dispositivo mais largo que a área de admin rola horizontalmente em vez de encolher. Preferi fidelidade de largura a conveniência: encolher mostraria um layout que não corresponde ao dispositivo escolhido. Registrado como limitação de UX, não como recurso.
- **Nenhuma revisão de RTL.** O build emite `index-rtl.css`, e as regras da prévia usam propriedades lógicas (`inline-size`, `margin-inline`), mas isso não foi verificado em uma tela RTL.
- **Nenhuma auditoria automatizada de acessibilidade.** O `aria-pressed` e o agrupamento rotulado são testados; um axe/Lighthouse completo é WCCS-063.
- **Nenhuma prévia na tela de checkout real.** A prévia vive na seção **Appearance** do admin e não toca o frontend.

## 9. Estado da fase F02: encerrada

| Tarefa | Status |
|---|---|
| WCCS-011 — design tokens | ✅ |
| WCCS-012 — shell administrativo | ✅ |
| WCCS-013 — componentes básicos | ✅ |
| WCCS-014 — client REST | ✅ |
| **WCCS-015 — preview visual** | ✅ |

Com a F02 encerrada, o design system, o shell, a biblioteca de componentes, o cliente REST e a prévia existem, estão testados e chegam ao bundle. **Nenhuma tela ainda edita campos** — esse é o trabalho da F03.

## 10. Próxima tarefa

**WCCS-016** (F03 — editor de campos), primeira do editor propriamente dito. É a fase onde a decisão adiada na WCCS-013 e reconfirmada na WCCS-014 se paga: a **migração dos componentes e do cliente para TypeScript**, que o `§18` do planejamento já pede para o admin.
