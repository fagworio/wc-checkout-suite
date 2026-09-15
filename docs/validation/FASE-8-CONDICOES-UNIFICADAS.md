# Fase 8 — Regras condicionais unificadas

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §6.8, §6.9, §11, §14, §27 · **Fase 8**
**Gate:** «mesma condição produz mesma decisão frontend/backend.»

## 1. O que a fase encontrou

A Fase 7 já tinha levado as regras a todos os consumidores — campo, perfil de checkout e as
superfícies do pedido — com **um** construtor, **um** validador e **um** motor, avaliado em PHP e em
JavaScript contra o mesmo ficheiro de casos. Faltavam duas coisas.

A primeira é o **vocabulário**. A §6.8 lista as fontes que uma regra pode ler: produto, categoria,
tag, virtual/downloadable, quantidade, subtotal, país, estado, método de envio, método de pagamento,
usuário, role e outro campo. O catálogo publicava nove delas e não tinha tag, as duas propriedades
do produto, quantidade, subtotal nem role — portanto metade dos cenários que o documento descreve
(o exemplo da §6.8 é precisamente uma categoria; o da §6.9, uma categoria num perfil) não tinha como
ser escrita, e nenhuma regra podia ser escrita sobre a loja que vende ficheiros.

A segunda é o **conflito**. A §6.9 pede que a sobreposição de perfis seja *mostrada*, e a Fase 7
mostra-a. Não havia nada equivalente para uma regra: `country equals BR` **e** `country equals PT`
no mesmo grupo `all` é uma regra que nenhum carrinho satisfaz — um campo que nunca aparece, um
perfil que nunca corre — e o editor aceitava-a em silêncio.

## 2. O que foi mudado

### 2.1 O vocabulário, completo

- **`Sources`** ganhou as seis fontes que faltavam da §6.8: `cart_tags` (lista), `cart_virtual` e
  `cart_downloadable` (booleanos), `cart_quantity` e `cart_subtotal` (números) e `user_role`
  (lista). Todas são fontes de **servidor**: o carrinho não está na página, e a §11 exige que o
  servidor recalcule com contexto confiável.
- **`CheckoutConditionContext`** responde por todas: as tags com o mesmo passeio que já lia as
  categorias (`taxonomy_slugs()` substitui `category_slugs()`), a quantidade com
  `get_cart_contents_count()`, o subtotal com `get_subtotal()` — **antes** do envio, porque uma
  regra sobre «quanto o cliente está a comprar» não pode mudar de resposta quando o método de envio
  muda —, e a role com `wp_get_current_user()->roles`. `missing()` continua vazio: nenhuma fonte do
  vocabulário é uma regra que ninguém consegue decidir.
- **`cart_virtual`/`cart_downloadable` lêem-se como uma afirmação sobre todos os artigos** — «nada
  neste carrinho tem de ser enviado» é o que um checkout sem endereço serve —, e a etiqueta
  publicada diz isso mesmo ("Every product in the cart is virtual") para o comerciante não ter de
  adivinhar qual das duas quantidades um booleano significava. Um carrinho vazio responde `false` às
  duas em vez de reclamar uma propriedade que nada tem, e um produto que não se consegue ler
  responde `false`: a resposta que esconde um endereço necessário é a perigosa.

### 2.2 Paridade, provada e mantida

- **`resources/fixtures/conditions.json`**: 52 → **70 casos**, com casos para cada fonte nova —
  tag contida e não contida, carrinho virtual, um artigo que tem de ser enviado, quantidade
  comparada e no zero, subtotal acima/abaixo/igual ao limiar, role contida e ausente num convidado.
  Uma expectativa escrita, duas implementações, e uma divergência falha uma asserção de um lado em
  vez de chegar a uma loja.
- **`resources/checkout/conditions.js`** passa a conhecer o vocabulário inteiro, com a razão
  declarada: conhecer uma chave e ser-lhe entregue são coisas diferentes. O motor responde a uma
  regra que lhe dão; **quem decide o que chega à página é o transporte** — `ClassicAssets` publica
  só as regras cuja todas as fontes a página responde, e o servidor recalcula as outras.
- **`ConditionVocabularyTest::test_the_fixture_vocabulary_is_the_published_one()`** (novo) fecha o
  ciclo que o ficheiro de casos já reclamava: o vocabulário que os casos cobrem **é** o que a loja
  publica. Uma fonte acrescentada de um lado sem caso do outro falha, em vez de estreitar em
  silêncio o que «paridade» quer dizer.

### 2.3 Detecção de conflito

- **`conflicts()`** em `schema/conditions.ts`: dentro de um grupo `all`, dois membros que não podem
  valer ao mesmo tempo — valores diferentes para a mesma fonte, um valor e a sua negação, uma lista
  que tem de conter e de não conter a mesma entrada, uma fonte pedida vazia e presente, um valor
  fora do limite que o próprio limite exclui, e dois limites que se não cruzam.
- **É um aviso, não uma recusa** (§6.9): um comerciante pode estar a meio de uma edição, ou manter
  uma regra que uma promoção tornou impossível, e uma loja que se recusasse a gravar seria uma loja
  que recusa trabalho a decorrer. O que não pode é ficar calada.
- **Só grupos `all` são lidos como conjunção.** Dentro de um `any` um par contraditório tem
  alternativas ao lado; e um `all` aninhado é o seu próprio âmbito em vez de fundido com o pai — o
  que só pode tornar o relatório mais estreito do que a verdade, nunca mais largo. Um aviso que
  gritasse sem razão seria desligado, e o verdadeiro iria com ele.
- **`ConditionBuilder`** mostra-o num aviso próprio, ao lado do que já diz sobre uma regra que não
  pode ser gravada.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE8-conditions-parity-proof.php` (novo) | **31/0**, 3 notas: `missing()` vazio; um carrinho real com um produto digital com tag e categoria e outro físico entrega tag, categoria, virtual/downloadable, quantidade, subtotal (antes do envio) e role; um carrinho misto desmente as duas propriedades; um carrinho vazio responde zero e vazio; um convidado não tem role; sete regras sobre as fontes novas decidem **através do pipeline de valores** como os valores dizem; a regra sobre o país viaja para a página e a regra sobre a tag **não**; e o mesmo vocabulário selecciona um perfil de checkout por categoria e outro por tag |
| `resources/fixtures/conditions.json` | 70 casos, lidos por `TreeConditionEvaluatorTest` (PHP) e por `tests/js/checkout/conditions.test.js` (JS): cada caso executado em dois motores, com uma expectativa escrita, e nenhum deles a divergir |
| `ConditionVocabularyTest` | a lista da §6.8 completa, o tipo e o âmbito de cada fonte nova, e o vocabulário dos casos **igual** ao publicado |
| `tests/js/schema/conditions.test.js` | 14 spec novos para `conflicts()`: o que é conflito e — tão importante — o que não é |
| `tests/browser/fase8-rule-builder.mjs` (novo) | **7/0** num browser real: o construtor oferece as 15 fontes publicadas; uma regra sobre uma tag compõe-se e lê-se como frase; duas comparações que nada satisfaz são **mostradas** e não recusadas; gravar escreve o grupo no documento e a loja devolve-o |
| `composer check` | phpcs e phpstan sem erros; **571 testes, 1960 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**763 testes**, 47 suites); build compila |
| Varredura de integração | **73 harnesses, 1675 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **A página decide cinco fontes, e nenhuma delas é o carrinho.** País, estado, método de envio,
  método de pagamento e outro campo do mesmo formulário. Tudo o resto é recalculado pelo servidor
  (§11) e o transporte é o que os separa: `ClassicAssets` publica apenas as regras cuja todas as
  fontes a página responde. Um browser que respondesse a uma pergunta que não consegue responder
  esconderia um campo que o servidor considera obrigatório, e é essa a direcção do erro que a §11
  proíbe.
- **A detecção de conflito é do editor e não do servidor.** Uma regra que nada satisfaz é
  configuração que o comerciante pode escrever — e o servidor não a recusa. Onde ela tem
  consequência (um perfil que nunca corre, um campo que nunca aparece) o aviso é o produto; uma
  segunda implementação em PHP seria um segundo dialecto da mesma ideia, que é o que a §14 proíbe.
- **`cart_items` e `cart_categories` mantêm-se** ao lado de `cart_tags`, porque a §6.8 nomeia as
  três e um carrinho pode ser descrito por qualquer delas. As categorias passaram a ser lidas pelo
  mesmo método que as tags, o que não muda o que a fonte vale.
- **O produto é lido por `product_id`.** Uma variação de um produto variável responde com o id do
  produto-pai, como já acontecia; uma regra sobre a variação exige um atributo, e a §6.8 não o
  nomeia — fica registado em vez de inventado.
