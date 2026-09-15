# Fase 3 — Checkout atual como referência

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §6, §27 · **Fase 3**
**Pergunta:** o lojista consegue gerenciar o checkout que a loja **já corre** sem o reconstruir à
mão — e a tela diz a verdade sobre o que aquele checkout consegue aceitar?

## 1. O que foi implementado

**`resources/admin/app/schema/coreCheckout.ts` (novo)** — o checkout real ao lado do documento que
o configura:

- `coreCheckout()` lê o inventário **ao vivo** (`CoreFields`, que pergunta a
  `woocommerce_checkout_fields`) e cruza-o com o documento: cada campo nativo diz se já é
  **gerenciado** (existe uma definição com o mesmo identificador) ou se ainda está só na loja. As
  secções vêm na ordem que o WooCommerce declara e os campos na prioridade que ele corre — é um
  relatório, não uma segunda opinião sobre o checkout.
- `hasCoreCheckout()` distingue «não consegui ler» de «não há nada»: um inventário indisponível é
  um estado com motivo, e a tela tem de dizer qual dos dois é.
- `adoptCoreSection()` adota **todos** os campos de uma secção de uma vez, e fá-lo chamando
  `adoptCoreField()` campo a campo: duas formas de adotar um campo do WooCommerce seriam duas
  respostas à mesma pergunta. Campos já gerenciados ficam como estão, portanto repetir a ação não
  muda nada.
- `typeWasRemapped()` expõe o que §6.7 exige: um tipo que não tem equivalente na Suite é dito **na
  hora**, não descoberto depois.

**`resources/admin/app/components/CoreCheckoutPanel.js` (novo)** — o painel «Checkout padrão»:

- Lista as secções da loja com os seus campos nativos, cada campo com o selo **Nativo**, a chave
  real (`billing_first_name`), a marca de obrigatório e, quando houve mapeamento de tipo, a frase
  que o explica.
- Cada campo tem «Usar»; cada secção tem «Usar esta seção» — é o que evita a reconstrução manual:
  adotar uma parte do checkout é **uma** ação, não vinte.
- O que já é gerenciado aparece marcado e **sem** ação, e a secção diz «N de M gerenciados».
- Não diz nada quando não há nada a dizer: sem inventário legível, ou com tudo já gerenciado, o
  painel desaparece em vez de ocupar a tela com uma promessa vazia.
- É desenhado **acima** do editor e apenas na área de checkout (`FieldManagerView`): os campos
  nativos e a sua ordem pertencem ao checkout, e uma área de exibição não os pode gerenciar.

**Qual checkout a loja corre** (§6.2, §6.7) — o servidor lê-o e a tela começa nele:

- `BlocksRenderer::store_uses_blocks_checkout()` é a pergunta ao nível da **loja** (sem a guarda de
  pedido que `is_blocks_checkout()` tem, porque o lojista está no wp-admin), e `is_blocks_checkout()`
  passou a delegar nela — a regra vive uma vez.
- `Assets::bootstrap_data()` publica `checkoutMode` (`blocks`/`classic`), o `AppShell` leva-o até ao
  ecrã e a barra de contexto começa no checkout **real**: o lojista não tem de dizer ao plugin qual
  é o seu checkout, e a limitação dos campos nativos no Blocks aparece sem ele a procurar.

**O que a fase já tinha e passa a estar provado como referência:** o inventário lido ao vivo
(`CoreFields`), a identidade da WooCommerce preservada ao adotar (`origin: core`, que é o que
protege o campo de ser apagado), a ordenação e as secções do editor, o `show_title` por omissão
**desligado** na criação de secção (§6.4), e os dois adaptadores (Classic e Blocks) já existentes.

**O que a edição de um campo nativo muda, dito antes de escrever** (§6.7) — «não criar uma cópia
silenciosa» e «se uma propriedade não puder ser alterada no Blocks, informar imediatamente»:

- `nativeFieldSupport( $modo )` e `nativeFieldSummary( $modo )` (em `coreCheckout.ts`) respondem com
  o que os adaptadores **de facto** fazem: no Classic, `ClassicAdapter::apply_to_core()` escreve
  etiqueta, descrição, placeholder, largura e ordem no campo real do WooCommerce; o tipo e o
  obrigatório continuam a ser da plataforma (shipping, tax e payment leem-nos). No Blocks, o plugin
  não altera campos nativos de todo.
- O inspetor desenha isso para um campo `origin: core` (`#wccs-native-support`), com uma linha por
  propriedade e a marca «aplicado» ou «da plataforma» — antes de o lojista escrever, não depois de
  guardar.
- **O adaptador de Blocks deixou de registar um campo nativo como se fosse adicional.**
  `BlocksAdapter::build()` recusa `origin: core` com `core_field_not_registered`: entregar
  `billing_first_name` à API de campos adicionais pedia à plataforma para desenhar um **segundo**
  campo com o nome do real — a cópia silenciosa que §6.7 proíbe. Um campo do plugin continua a ser
  registado, portanto a regra é a origem e não o tipo.

## 2. Prova

| Prova | Resultado |
|---|---|
| `tests/js/schema/coreCheckout.test.js` (novo) | 12 testes: secções e campos na ordem da loja; quantos gerenciados; só os adotáveis; nada quando o inventário não foi lido; tipo mapeado; adoção de uma secção inteira na ordem da loja; ordem/etiqueta/obrigatório reais preservados; o que já é gerenciado fica como está; nada a fazer numa secção completa; o que o Classic escreve no campo real; o Blocks não escreve nada; as frases não prometem mais do que isso |
| `tests/js/components/CoreCheckoutPanel.test.js` (novo) | 7 testes: lista secções e campos nativos; adota um campo; adota a secção; marca o que é gerenciado e não oferece ação; avisa do tipo mapeado; cala-se sem inventário e com tudo gerenciado |
| `FieldsScreen.test.js` (3 testes novos) | o checkout da loja aparece numa primeira entrada e uma secção é adotada numa ação; a barra começa no checkout que a loja corre (`blocks`) e não avisa quem corre o clássico |
| `FieldProperties.test.js` (3 testes novos) | o estado do que é aplicado aparece para um campo nativo (Classic); no Blocks diz que a plataforma é dona; e não aparece nada disso num campo do plugin |
| `BlocksAdapterTest` (2 testes novos) | um campo nativo não é registado como adicional (`core_field_not_registered`); um campo do plugin continua a ser |
| `tests/Integration/FASE3-checkout-reference-proof.php` (novo) | **18/0** pela rota real: inventário lido ao vivo (4 secções, 20 campos, com identidade/etiqueta/tipo/prioridade), leitura estável, `store_checkout_mode()` respondido pela loja e publicado no bootstrap, a definição que o ecrã constrói é aceite pelo servidor com a identidade da WooCommerce, a ordem real (`10…110`) e as etiquetas da loja, adotar duas vezes não duplica nada, o campo nativo não é registado como adicional, o campo do plugin é, o Classic escreve a etiqueta **sem** tomar conta do obrigatório, e o harness não deixa ótica nenhuma |
| `tests/browser/fase3-checkout-reference.mjs` (novo) | **14/0**: o painel mostra exatamente as secções que o servidor reporta para aquele pedido (comparado com a resposta da rota), os 20 campos nativos com selo «Nativo», a barra no modo da loja (`blocks`), a secção adotada numa ação, os campos guardados com identidade e prioridade da WooCommerce, o inspetor a dizer o que o checkout aceita (e a coincidir com o modo da loja), e a secção a deixar de ser oferecida |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**687 testes**, 45 suites) |
| `composer check` | phpcs e phpstan sem erros; **504 testes, 1829 asserções** |
| Varredura de integração | **69 harnesses, 1568 asserções, 0 falhas** |
| `tests/browser/f14-links-observation.mjs` · `f14-bindings-observation.mjs` | **22/0** e **13/0**: o painel novo convive com o editor por destino e com a lista de usos |

## 3. Limites que ficam registados

- **O checkout da loja corre em Blocks, e no Blocks um campo nativo não tem ordem nem largura
  livre.** É o próprio ecrã que o diz («Modo Blocks · matriz de capacidades»), agora sem o lojista
  ter de escolher o modo à mão, e o inspetor diz propriedade a propriedade o que aquele checkout
  aceita. O adaptador Classic continua a aplicar etiqueta, descrição, placeholder, largura e ordem;
  o Blocks não aplica nada a um campo nativo — e, desde esta fatia, também não o registra como campo
  adicional. Ou seja: num checkout Blocks, editar um campo nativo é hoje uma declaração de intenção
  que a loja não honra, e o ecrã di-lo; honrá-la exige uma integração com a API de endereços do
  Blocks, que não está aberta por filtro nesta versão.
- **O obrigatório e o tipo nunca são tomados.** Nos dois checkouts, `required` e o tipo continuam a
  ser do WooCommerce e de quem mais os leia (shipping, tax, payment). O inspetor di-lo; o adaptador
  Classic não os escreve.
- **A adoção é explícita, secção a secção ou campo a campo.** Não há «adotar tudo»: §6.2 pede que a
  tela mostre o checkout real, não que o tome por conta própria — e um documento que passasse a
  gerenciar vinte campos nativos de uma vez seria uma alteração grande que ninguém reviu.
- **O inventário é lido por pedido, e o pedido muda o que a WooCommerce declara.** Na mesma loja a
  rota respondeu quatro secções (billing, shipping, account, order) e o ecrã, no pedido do
  wp-admin, três (sem `account`, que depende das opções de conta/registo). O painel mostra o que o
  servidor respondeu **àquele** pedido — que é a verdade do pedido —, mas o conjunto pode não ser
  exatamente o que um cliente vê no frontend. Fechar essa diferença é trabalho de uma fase de
  apresentação (Fase 14), onde o checkout passa a ser lido do lado que o desenha.
- **Campos nativos que a loja já não tem** não são listados: o inventário é o que existe agora, e um
  campo que desapareceu do checkout não é oferecido para adoção. O que já estiver no documento
  mantém-se, porque o documento é histórico de configuração e não um espelho da loja.
- **A Fase 3 não inclui perfis de checkout** (§6.3): «Checkout padrão», «Checkout digital» e
  «Produtos restritos» são a Fase 7, com o modelo, o resolver e a prévia de cenário.
