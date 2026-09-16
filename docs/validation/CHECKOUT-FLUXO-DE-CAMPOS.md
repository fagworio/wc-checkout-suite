# O fluxo dos campos do checkout, segundo a referência visual 01

**Documento:** `roadmap/.../Roadmap.md` §6.2–§6.7, §1.3 · **Referência:** `wc-checkoutsuite-doc-assets/01-checkout.png`
**Origem:** pedido direto, com a tela atual e o protótipo lado a lado.

## 1. O que a tela fazia, e o que a referência desenha

A tela de campos tinha todas as peças e não tinha o fluxo. As seções eram uma **tira horizontal
de separadores** no topo do painel do meio — só o nome e a contagem —, o checkout da própria loja
ocupava o meio inteiro acima da lista, e a coluna da direita era o inspector de propriedades, que
abria a partir de um campo já escolhido. O protótipo desenha outra coisa, e desenha-a em três colunas:

```text
[ Seções do checkout ]   [ A seção e os seus campos ]   [ Adicionar campo ]
  ícone + nome + frase     nome + ajuda + «exibir        Novo campo | Campo existente
  + «Adicionar seção»      título da seção»              tipo, nome, slug, descrição,
                           lista de campos               obrigatório, regras, ajustes do tipo
                           + «Adicionar campo nesta
                             seção»
                           Prévia da seção
```

## 2. O que foi mudado

- **Coluna da esquerda: as seções.** A tira virou a lista do desenho — ícone, nome, a frase que diz
  o que a seção guarda, e a contagem — com o botão tracejado «Nova seção» no fim. Os mesmos
  manipuladores, e a mesma classe `.section-tabs`, para que os seletores e as provas continuem a
  falar da mesma coisa.
- **Coluna do meio: a seção e os campos.** O cabeçalho passou a dizer o que o painel faz
  («Gerencie os campos desta seção. Arraste para reordenar.»), a mostrar em que destinos a seção
  está ativa e a trazer o interruptor **«Exibir título da seção»** — que grava
  `presentation.show_title` (§6.4) e fica desativado com a razão à vista numa seção implícita do
  WooCommerce, que não tem onde guardar a escolha. Abaixo da lista, o botão tracejado
  **«Adicionar campo nesta seção»**.
- **Coluna do meio: a prévia da seção.** O bloco «Prévia da seção» renderiza os campos da seção
  aberta com o rótulo, o obrigatório e a largura de cada um. Não é o renderizador do WooCommerce —
  a prévia completa continua a ser a da seção «Prévia do checkout» — e di-lo no texto.
- **Coluna da direita: adicionar campo.** Sem campo selecionado, a coluna mostra o painel
  «Adicionar campo»: **Novo campo** e **Campo existente**, e por baixo a lista dos campos nativos
  que esta seção ainda não adotou, cada um com «Usar». Com um campo selecionado, a coluna continua a
  mostrar as propriedades dele, como antes.
- **O formulário de criação ganhou o que o protótipo pede**: além de nome, chave, seção, largura e
  obrigatoriedade, passou a pedir **descrição**, **ajustes do tipo** (lidos do catálogo, por isso
  «aceitar apenas PDFs» e «tamanho máximo» aparecem para o tipo de arquivo) e **regras de exibição**
  (a mesma árvore de condições do inspector). `PickerChoice` e `buildField` passaram a levar
  `conditions`, para que uma regra escrita antes de o campo existir chegue ao campo criado.
- **O checkout da própria loja desceu** para o fim da coluna do meio, depois da lista e da prévia: o
  desenho abre na seção, nos campos e na amostra, e adotar o checkout que a loja já corre é o passo
  que se faz depois de ver o que falta.

## 2b. As ações da linha: a lixeira e a duplicação (pedido seguinte)

O desenho põe as ações do campo **na linha** — duplicar e excluir — e não dentro de um menu. A linha
passou a ter um grupo `.row-quick` com esses dois ícones, sempre à vista:

- **duplicar** (`copy`) — o mesmo `onDuplicate` que o menu já chamava;
- **excluir** (`trash`, ícone novo — não existia) — o mesmo `onRemove`; e
- no campo que não se pode excluir, **«Por que não posso excluir?»** (`info`), que é o caminho da
  proteção nativa: um campo do WooCommerce não se apaga, explica-se.

O item **«Editar campo» saiu do menu**: a área de edição é o painel ao lado, e clicar na linha já a
abre — um segundo caminho para o mesmo sítio só fazia duvidar de qual estava ativo. O menu continua
com o que ele é o único sítio para fazer: desativar, arquivar, duplicar como personalizado e explicar
a proteção.

A lixeira fica na tinta discreta das outras ações e só fica vermelha com o rato em cima: é a resposta
ao gesto, não o estado de repouso de uma linha que ninguém pediu para apagar.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/browser/fase16-usability.mjs` | **25/0**, com cinco asserções novas: as seções estão na coluna da esquerda com nome, frase, contagem e o criar tracejado; o interruptor do título existe e chama-se «Exibir título da seção»; «Adicionar campo nesta seção» existe e está visível; o formulário abre com o tipo escolhido; e pede nome, chave, descrição, regras e confirmação |
| `tests/browser/fase14-custom-checkout.mjs` / `fase15-accessibility.mjs` / `fase10-workflows.mjs` | **11/0**, **20/0**, **16/0** — a lista de seções, o diálogo e a acessibilidade continuam a passar com a coluna nova |
| `tests/browser/fase3-checkout-reference.mjs` | **14/0** no cenário que o próprio script documenta (o fixture limpo); a adoção de uma seção nativa continua a funcionar com o painel mais abaixo |
| `tests/js/screens/FieldsScreen.test.js` / `tests/js/design/useNarrowViewport.test.js` | **39/39** e **3/3**. A prévia repete os rótulos dos campos de propósito, e as consultas passaram a ser feitas **dentro da lista** (`role="list"` com o nome «Campos da seção», e as linhas com `role="listitem"`) — que é também a estrutura que um leitor de ecrã passa a ter |
| `tests/js/screens/FieldsScreen.test.js` | **41/41**, com dois testes novos: a lixeira da linha exclui o campo e a marca como alteração não salva, e o ícone de duplicar cria a cópia — sem passar pelo menu |
| `tests/browser/fase16-usability.mjs` | **27/0**, com duas asserções novas: «Excluir …» e «Duplicar …» estão na linha como ícones, e o menu deixou de oferecer «Editar campo» |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**825 testes**, 51 suites); build compila |
| Comparação visual | a tela foi aberta em `…&section=fields&area=checkout` e fotografada; as três colunas, o botão tracejado e o painel «Adicionar campo» estão como na referência |

## 4. Deliberadamente fora

- **As abas de destino continuam cartões**, com a tira de sub-destinos para Admin e «Mais destinos»,
  e não a fila plana de seis abas com ícone da referência. Aquilo é a navegação da casca (§4) e mexer
  nela muda o modelo de destinos, não o fluxo dos campos — fica para uma decisão própria.
- **A coluna da direita não é um formulário «configurar e depois criar»**: o tipo é escolhido no
  catálogo e o campo nasce com os valores do formulário, que fica logo editável no mesmo sítio. O
  desenho mostra o formulário antes de o campo existir; aqui o campo existe quando o formulário é
  submetido, e o que se ganha é que as regras e os ajustes do tipo entram já na criação.
- **A ordem dos campos na prévia é a da lista**, e não há arrastar dentro da prévia.
