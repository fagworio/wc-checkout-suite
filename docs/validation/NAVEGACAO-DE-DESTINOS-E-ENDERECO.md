# Navegação de destinos: os estilos da tira e o endereço da tab interna

**Documento:** `roadmap/.../WC-CheckoutSuite-...-Roadmap.md` §4 (arquitetura de navegação administrativa), §5.1–§5.3 (design system), §6.4, §30.1
**Origem:** defeito reportado com captura de ecrã — a tira de destinos dentro de um grupo desenhava-se com o botão do browser e a tab interna não ia para o endereço.

## 1. O que estava quebrado

**Os estilos.** A tira de destinos de um grupo — «Pedido · Perfil do cliente», dentro de Admin — era
desenhada por `.wccs-editor-subareas`, e **essa regra não existia em CSS nenhum**: só o marcador em
`FieldManagerView.js`. Sem ela, os botões caíam no controlo do browser e mediam-se assim:

```
display: inline-block · background: rgb(239, 239, 239) · border: 2px outset
strong: inline · small: inline
```

Os dois efeitos que a captura mostrava são esses: o botão cinzento do browser em vez do sistema de
desenho, e «Pedido» colado ao caminho — «PedidoAdmin → WooCommerce → Pedidos → Editar pedido.» — porque
`<strong>` e `<small>` são elementos em linha quando ninguém diz o contrário. A fila de cartões acima
(`.wccs-editor-areas`) tem regra desde sempre; a tira por baixo, que é a mesma decisão um nível abaixo,
ficou sem nenhuma.

**O endereço.** A `section` já vivia no endereço desde a fase do shell — `?section=rules` abre as regras
— mas o **destino dentro da tela** não. Escolher «Pedido» no admin mudava o ecrã e deixava o endereço
igual, portanto não havia link para mandar a alguém, e recarregar a página voltava ao checkout.

## 2. O que foi feito

- `resources/admin/app/components/components.css` ganhou o bloco `.wccs-editor-subareas`: a mesma
  linguagem dos cartões acima, um tamanho abaixo — grelha flexível que quebra em ecrã estreito, cartão
  com a borda e a superfície do sistema de desenho, `strong` e `small` como blocos (nome numa linha, o
  caminho noutra, em tinta discreta), estado ativo com a borda da marca e sem qualquer decoração de
  texto herdada.
- `resources/admin/app/sectionUrl.js` ganhou `AREA_PARAM`, `readArea()` e `areaHref()`, na mesma
  forma e com as mesmas promessas das suas gémeas da secção: um valor que este build não tem é
  **ignorado** em vez de devolvido, e cada função escreve **só** o seu parâmetro, para que escolher um
  destino nunca perca a tela onde ele está.
- `FieldsScreen` lê o destino do endereço ao montar (`readArea(...) || 'checkout'`) e escreve-o com
  `replaceState` a cada mudança, à imagem do que a casca já faz para a secção. Os destinos válidos vêm
  do mesmo vocabulário que desenha a tira (`navigation()`), portanto um destino novo fica linkável no
  momento em que existe — não há segunda lista para manter em passo.

`replaceState` e não `pushState`, pela razão que a casca já documenta: escolher um destino não é
navegação, e um botão de voltar cheio de cliques de tabs torna a saída da tela uma tarefa.

## 3. Prova

| Prova | Resultado |
|---|---|
| Observador FASE-16 legado (resultado histórico) | **20/0**, com três asserções novas: escolher um destino escrevia `area=` no endereço **sem perder** `section=fields`; o destino mostrava o nome e o caminho em linhas próprias e vestia o sistema de desenho (`border: solid`, superfície branca, sem decoração de texto) em vez do botão do browser; e o endereço, seguido por outra pessoa, reabria o mesmo destino com «Pedido» selecionado. O observador foi aposentado após a matriz UX-008. |
| `tests/js/app/sectionUrl.test.js` | 5 testes novos (19 no total): lê o destino nomeado, ignora um destino que o build não tem, escreve `area=` e preserva a secção, não escreve um destino inválido, e sobrevive a uma ida e volta pelo endereço |
| `tests/js/screens/FieldsScreen.test.js` | **39/39**. O endereço passou a ser reposto entre testes (`beforeEach`) e a árvore desmontada (`afterEach`): o ecrã guarda o destino no endereço, e o jsdom dá o mesmo `window` a todos os testes do ficheiro — sem a reposição, o teste que abre o perfil do cliente deixava o seguinte a renderizar o perfil do cliente, e a falha aparecia como 26 testes lentos em vez de um. O que tem de ser limpo é o do teste, não o do ecrã |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**823 testes**, 51 suites); build compila |

## 4. Limites que ficam registados

- **A tab da secção *dentro* do destino** (a lista de contentores à esquerda: «Cobrança», «Pedido») não
  foi para o endereço. `section` já nomeia a tela do shell, e o contentor ativo é estado do editor que
  várias operações (criar, remover, mover em massa) reescrevem; ligá-lo ao endereço exige passar todas
  essas por um funil próprio. Fica nomeado em vez de meio feito.
- **A verificação visual foi feita num Chrome**, como todas as provas de browser deste repositório. A
  tira é CSS simples e sem media query própria além da quebra natural do flex, mas não foi medida noutro
  motor.
