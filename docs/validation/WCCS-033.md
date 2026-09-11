# Registro de validação — WCCS-033

**Tarefa:** WCCS-033 · "Implementar evaluators PHP/JS"
**Fase:** F06 · Conditional Logic e política de valores
**Prioridade:** `required_v1` · **Dependências:** F03, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Paridade em vazio, zero, false, arrays, contexto e negações."

**Resultado:** **293 testes unitários PHP** (54 novos) · **812 asserções de integração** em 29 provas, 0 falhas (19 novas) · **531 testes de JS** em 30 suítes (56 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Conditions/TreeConditionEvaluator.php` | O motor: a mesma pergunta, respondida no servidor |
| `resources/checkout/conditions.js` | O motor no browser, com as mesmas decisões |
| `resources/fixtures/conditions.json` | 52 casos, uma expectativa escrita à mão por caso |
| `src/Checkout/Classic/ClassicConditionContext.php` | O contexto confiável, lido dos objetos do WooCommerce |
| `src/Checkout/Classic/ClassicSubmission.php` | Passa a entregar à regra o que foi **aceite**, não o que foi enviado |
| `tests/Unit/Domain/Conditions/TreeConditionEvaluatorTest.php` | A suíte PHP sobre as fixtures |
| `tests/js/checkout/conditions.test.js` | A suíte JS sobre as **mesmas** fixtures |
| `tests/Integration/F06-wccs-033-condition-engine-proof.php` | O motor ligado a uma loja real |

## 3. Onde está a paridade, e onde não está

A paridade **não** é afirmada aqui: é afirmada duas vezes contra a mesma expectativa. `resources/fixtures/conditions.json` é um ficheiro só, com 52 casos e uma resposta escrita à mão em cada um; a suíte PHP responde com o motor PHP, a suíte JS responde com o motor JS, e uma divergência falha uma asserção de um dos lados. É a mesma técnica que a WCCS-028 usou para os documentos brasileiros, e pela mesma razão: duas implementações não se comparam por parecerem-se.

Os grupos das fixtures são as palavras do aceite — `empty`, `zero`, `false`, `lists`, `context`, `negation` — mais o que uma regra pode ser sem poder ser lida (`unreadable`). Duas asserções defendem a própria cobertura: os grupos têm de ser exatamente os dez, e **todos** os operadores e **todas** as fontes do vocabulário têm de aparecer em pelo menos um caso. Sem elas, perder um grupo deixaria as restantes asserções verdes e a paridade daquele operador por testar.

## 4. As quatro decisões que fazem as respostas iguais

**Vazio não é falso.** `0`, `'0'` e `false` são valores; `''`, `null` e a lista vazia não são. Um carrinho de zero é um total, e uma caixa de consentimento desmarcada é uma resposta. Nem o PHP nem o JS usam o seu próprio teste de veracidade: ambos leem a mesma lista de formas.

**A comparação é decidida pelos valores, não pela linguagem.** Dois números comparam-se como números, duas listas elemento a elemento, e o resto como texto — com um booleano escrito como `1` ou `0`, porque `(string) true` é `'1'` no PHP e `String(true)` é `'true'` no JavaScript, e essa diferença sozinha bastaria para duas lojas iguais se comportarem de maneira diferente.

**Um número só é número quando está escrito como um.** Ambos os lados usam o mesmo padrão, porque `is_numeric( '0x1A' )` é falso no PHP e `Number( '0x1A' )` é 26 no JavaScript. Há um caso de fixture só para essa diferença.

**O motor nunca oculta um campo por não ter percebido a regra.** Uma árvore ilegível, um grupo vazio, uma fonte ou um operador fora do vocabulário respondem todos "combina" — a mesma direção do avaliador permissivo que respondia antes. O validador recusa essas formas em cada gravação, portanto isto é sobre corrupção; e, em corrupção, a resposta perigosa é a que tira um campo do checkout.

## 5. O contexto, e a decisão que o planeamento não tomou

O servidor recalcula as condições com contexto confiável do carrinho e do cliente: `ClassicConditionContext` lê os objetos do próprio WooCommerce e nunca o corpo do pedido. `function_exists( 'WC' )` sozinho não chega — é verdade desde que o ficheiro do plugin é carregado, bem antes de o WooCommerce construir os seus objetos, e essa lição já foi paga neste projeto com um erro fatal na loja — por isso a verificação é acompanhada de `did_action( 'woocommerce_init' )`.

O planeamento nomeia as fontes sem dizer o que está dentro de duas delas, e inventar em silêncio seria pior do que escolher e registar:

- `cart_items` guarda os **identificadores** dos produtos, como texto. Um identificador é estável e é o que o próprio WooCommerce usa; um nome muda e é traduzido. Uma regra que queira nomear um produto por algo mais amigável é uma decisão de produto, e pertence ao seletor que teria de o oferecer.
- `cart_categories` guarda os **slugs** das categorias, que é a forma estável que a taxonomia usa.

Uma asserção garante que **nenhuma** fonte do vocabulário ficou sem resposta: `ClassicConditionContext::missing()` é passado a `Sources::all()` e tem de devolver vazio. Uma fonte acrescentada ao vocabulário sem resposta aparece como essa lacuna em vez de responder sempre "ausente".

## 6. A decisão que a prova fixa: a regra lê o que foi aceite, não o que foi enviado

O caminho óbvio era entregar ao motor o que o formulário enviou. É precisamente o que o `§11` proíbe — *"uma regra que lê o formulário submetido para decidir se um campo era obrigatório é uma regra que um atacante desliga"* — e o comentário que a WCCS-021 deixou em `ClassicSubmission::context()` já o anunciava, razão pela qual o contexto ficou vazio até esta tarefa. Foi evitado ao desenhar, não apanhado por uma falha: o que a prova faz é **fixá-lo**, para que a alternativa não volte por distração.

`ClassicSubmission::normalize()` passa a construir o mapa `fields` com os valores **canónicos já aceites** nesta submissão, por ordem de definição, e um valor recusado não contribui nada. O campo que a regra lê é então o que a loja aceitou para ele, e não o que foi publicado no pedido.

A prova demonstra as duas metades com um CPF: com `529.982.247-25` o campo dependente vê o valor canónico e decide; com `111.111.111-11` — recusado — o campo dependente não vê valor nenhum, a regra não combina, o campo fica oculto e o valor residual é descartado.

Fica registada a consequência: uma regra que lê outro campo é **sensível à ordem** das definições, e um campo que dependa de um campo posterior lê-o como ausente. Não é um descuido — é a única ordem em que existe uma resposta que a loja aceitou —, e está escrita no código onde é decidida.

## 7. O que foi ligado, e o que deliberadamente não foi

O motor é registado no `ConditionEvaluatorRegistry` com a chave `rules`, e **não** passa a ser o padrão: `active()` continua a devolver o avaliador permissivo `always`. A consequência é explícita e provada — a loja comporta-se exatamente como antes desta tarefa até um documento nomear o motor, e é isso que permite que a política de valores (WCCS-034) decida *quando* mudar, em vez de a mudança acontecer por acidente dentro desta tarefa.

O que está ligado é o caminho completo: um documento que nomeia `rules` decide a visibilidade através do pipeline real, com o contexto real. A prova mostra as três situações — regra que não combina e oculta, regra que combina e mantém o valor, e documento que não nomeia motor nenhum e continua a ser respondido pelo permissivo.

O motor JavaScript **não** foi ligado ao checkout. Ele é a metade cliente da mesma semântica e é consumido pela suíte de testes; ligá-lo à página decide, por acidente, as duas perguntas que a WCCS-034 tem de decidir — o que acontece ao valor residual de um campo ocultado e o que acontece ao `required`. Entregá-lo agora e ligá-lo lá é a diferença entre uma biblioteca e uma política.

## 8. O que NÃO foi provado, e porquê

**Uma regra a decidir num checkout a sério.** Nada corre no browser: não há página de checkout clássico nesta loja (`CLASSIC-TEST-SURFACE`) e nenhum browser foi aberto. A prova corre o pipeline com contexto real, mas dentro do WP-CLI.

**A política do valor residual e do `required`.** É a WCCS-034. O que ficou provado é que um campo oculto por regra não é gravado, porque o pipeline já o fazia; o que **não** está provado é a decisão de quando o motor passa a ser o padrão.

**A paridade do `preview` do editor com o motor.** São duas coisas diferentes de propósito. O `preview` responde *"com estes valores, a minha regra combina?"* para uma regra que está a ser escrita, e responde "não combina" a um operador que não conhece; o motor responde "visível" por não ter percebido a regra. As duas escolhas são defensáveis e a diferença está registada aqui em vez de ficar implícita — um documento com um operador fora do vocabulário é mostrado como contradição no editor, e nunca chega ao motor.

**Um carrinho com produtos.** A loja não tem produtos, e criar um é uma alteração de dados fora da raiz do plugin. A prova exercita o carrinho real, que está vazio: lista vazia e total `0.0` — estados reais, e o caso `cart_items` com produtos está coberto pelas fixtures, que fornecem o contexto diretamente.

## 9. Próxima tarefa

**WCCS-034 — "Implementar discard/preserve"** (aceite: *valor residual oculto é descartado por padrão; required segue estado no servidor*). É onde o motor deixa de ser opcional: decide-se quando `rules` passa a ser o padrão, liga-se a metade cliente ao checkout, e fecha-se a pergunta que o `§11` deixa em aberto sobre o que acontece ao valor que o cliente tinha escrito num campo que acabou de ficar oculto.

---

## 10. Correção posterior, encontrada na WCCS-034

A §7 acima diz que o motor é registado **sem** passar a ser o padrão, e registou a razão: a mudança pertencia à tarefa que decidisse a política de valores. A WCCS-034 tomou essa decisão e `ConditionEvaluatorRegistry::DEFAULT_KEY` passou de `always` para `rules`.

A decisão não foi uma preferência: com a metade cliente a avaliar a mesma árvore, o servidor e o browser têm de concordar sobre o mesmo documento. Se o browser ocultasse um campo e o servidor o considerasse visível, o cliente fecharia o formulário e o servidor recusaria o pedido por um campo obrigatório que ninguém vê — que é exatamente o risco que o gate da F06 nomeia. O avaliador permissivo continua registado e alcançável por nome, para um documento que fixe o motor com que foi escrito.

A prova desta tarefa afirmava `active() === 'always'`. Passou a afirmar o que é verdade hoje — `rules` é o padrão e `always` continua alcançável — em vez de fixar um padrão que já foi substituído: uma prova que fixa um estado ultrapassado falha pela razão certa e é editada pela razão errada.
