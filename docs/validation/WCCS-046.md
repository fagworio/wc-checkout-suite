# Registro de validação — WCCS-046

**Tarefa:** WCCS-046 · "Implementar apresentação Classic"
**Fase:** F09 · Página customizada e pagamento
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Layout responsivo do anexo com gap correto; hooks preservados."

**Resultado:** **373 testes unitários PHP** (11 novos) · **1005 asserções de integração** em 41 provas, 0 falhas (20 novas) · **581 testes de JS** em 34 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/checkout/presentation.css` | O layout do anexo, escrito sob um escopo |
| `src/Checkout/Classic/ClassicAssets.php` | Entrega o ficheiro, os tokens e a classe que o escopo exige |
| `tests/Unit/Checkout/Classic/ClassicPresentationTest.php` | Lê o layout como folha de estilo |
| `tests/Integration/F09-wccs-046-classic-presentation-proof.php` | O que a página recebe, e o que só ela recebe |

## 3. `gap`, e não margem — a cláusula que o planeamento nomeia

A secção 17 regista o defeito:

> `.field + .field { margin-top: 9px; }` também afeta o segundo e o terceiro elemento dentro da grelha e provoca desalinhamento. A nova referência usa `gap` no container e nos wrappers de campo, sem margem entre inputs irmãos.

**Não há uma única margem de espaçamento neste ficheiro.** O espaço vem de `gap` no `form.checkout` e de `gap` nos wrappers de campos. E a asserção é uma **ausência**, não uma sobrescrita: um ficheiro que pusesse `margin: 0` nos irmãos continuaria a ser um ficheiro que tem essas margens, e a próxima pessoa a acrescentar um campo herdaria o mesmo desalinhamento um seletor depois. O teste percorre todas as declarações `margin*` do ficheiro e exige que o valor seja `0` — a única forma de margem que resta é o `margin-block: 0` de reset, que declara zero em vez de declarar espaço.

Os valores são lidos **em PHP**, não por expressão regular: uma primeira versão tentava reconhecer `margin: 0` por padrão e encontrava-o no espaço em branco antes do zero. Um padrão que precisa de tolerar o que está a testar é um padrão que vai mentir.

## 4. O defeito que este trabalho criou, e a prova apanhou

Todo o seletor do ficheiro está sob `.wccs-checkout` — e **nada emitia essa classe**. O comentário do próprio ficheiro dizia que era "o template" a pôr a classe no body, e este plugin **não traz template nenhum**: o `wp_body_open` do tema não conhece `wccs-checkout`, e o `body_class` do WooCommerce põe `woocommerce-checkout`.

Ou seja: a folha de estilo estava escrita, entregue, e **não se aplicava a nada**. Nenhum teste unitário o podia ver — o ficheiro está correto como ficheiro. Foi visto ao escrever a prova de integração, ao perguntar qual das partes entrega a classe, e ao não encontrar nenhuma.

A correção não foi alargar o escopo para uma classe alheia, mas **entregar a classe pelo mesmo gate que entrega o ficheiro**: um filtro `body_class` que chama exatamente `should_enqueue()`. Daí as duas propriedades que a prova afirma e o unitário também:

- a classe está **exatamente** nos pedidos que recebem a folha de estilo — um checkout com uma e sem a outra é um checkout com um ficheiro que não faz nada;
- em qualquer outro pedido o array de classes volta **idêntico** ao que o tema deixou, e o esquema publicado nem é lido (o `is_classic_checkout()` decide primeiro, por curto-circuito).

Isto custou **um hook**, e é o único que esta tarefa acrescenta. Ele está na lista partilhada `tests/Integration/support/storefront-hooks.php` — a lista única que existe precisamente para que um hook novo apareça num sítio e seja revisto, em vez de aparecer em cinco ficheiros e ser esquecido em quatro.

## 5. `hooks preservados` — afirmado, não prometido

A segunda metade do critério é sobre não tirar o formulário a quem o desenha. Quatro asserções o fecham:

- este plugin **não traz diretório de templates** — nem `templates/`, nem `woocommerce/`, nem `templates/woocommerce/`;
- **não aponta nenhum carregador de templates** para si (`woocommerce_locate_template`, `woocommerce_template_path` sem filtro);
- **não põe nada nas ações de que um formulário é desenhado** (`woocommerce_checkout_billing`, `…_shipping`, `…_before_customer_details`, `…_order_review`, `woocommerce_before/after_checkout_form`) — a lista é varrida sobre **todas** as classes do plugin, não sobre um namespace;
- e a metade storefront continua a ser **exatamente** a lista partilhada, com `woocommerce_checkout_fields@20:filter_fields` lá dentro: os campos continuam a chegar ao formulário pela via suportada, filtrados para o array do WooCommerce.

A apresentação **estiliza classes que já existem** — `form-row-wide`, `form-row-first`, `form-row-last` do WooCommerce, e `wccs-col-3`/`wccs-col-4` do adaptador — e não acrescenta marcador, wrapper nem elemento. É isso que a mantém compatível com todos os `woocommerce_*` que renderizam para dentro do formulário.

O teste unitário liga as duas pontas de uma delas: as classes `wccs-col-` que o adaptador emite (WCCS-021) e as que o ficheiro de estilo interpreta. Se as listas divergirem, um campo é emitido com uma classe que ninguém lê — que é um campo que passa silenciosamente a ocupar a largura toda.

## 6. A folha de estilo é um ficheiro, e não parte do bundle

Um layout que chega com o JavaScript é um layout que o cliente só vê depois de o bundle correr; numa ligação lenta isso é um checkout visivelmente diferente daquele que o comerciante aprovou. É servido como ficheiro próprio, com os **tokens como dependência declarada** — a prova lê os `deps` registados e confirma `wccs-design-tokens` — para que as variáveis que ele lê existam antes de ele as usar. Um ficheiro que carregasse antes das variáveis desenhava com os fallbacks, que é a diferença que ninguém nota até um comerciante mudar um token e nada se mexer.

O ficheiro é também imune ao build: a apresentação não depende de `npm run build` ter corrido, ao contrário do bundle.

## 7. A asserção que ficou obsoleta, e a diferença entre medir e julgar

A prova WCCS-025 falhou na primeira execução do sweep depois desta mudança:

```
FAIL  And no asset of this plugin is left registered on the request  [assets=2]
```

As duas "assets" eram as duas folhas de estilo novas — registadas pela **secção anterior do próprio harness**, que exercita o caminho positivo. A asserção estava certa quanto à intenção ("um pedido que não é o checkout não fica com nada do plugin") e errada quanto à medição: contava o registo do harness, não o do pedido.

A correção foi limpar os handles do plugin antes de fazer a pergunta, para que ela seja sobre o que **este** pedido regista. Não foi enfraquecer a asserção para `<= 2`.

### 7.1 A falha intermitente da WCCS-008, agora com causa tratada

No mesmo sweep, a prova da WCCS-008 falhou 15 asserções em cadeia a partir de uma só:

```
FAIL  No schema option exists before the proof  [options=3]
```

A asserção exigia uma base de dados que **outra coisa** tivesse deixado limpa, e o mesmo ficheiro terminava a comparar o estado final com esse valor de partida (`after === before`) em vez de com zero. Nenhuma das duas é sobre o código: são sobre o ambiente. Uma prova que só passa porque ninguém escreveu antes falha pela razão errada, e falha de forma intermitente — é a segunda vez que este sintoma aparece e a primeira em que a causa é tratada em vez de o sweep ser repetido até passar.

O ficheiro passa a **limpar ele próprio** os três options de que trata antes de medir, e a afirmar zero no fim. Fica executável em qualquer ordem, sozinho ou a seguir a qualquer outra prova — que é o que uma prova tem de ser. O que **não** foi identificado foi a origem dos três options: o sweep instrumentado, que conta as opções `wccs_` depois de cada um dos 41 harnesses, mostra zero em todos eles. A asserção deixou de poder falhar por isso, e a origem desconhecida fica registada como desconhecida em vez de inventada.

## 8. O que NÃO foi provado, e porquê

**O arranjo renderizado.** Esta loja **não tem página de checkout clássico**, por isso o caminho positivo é exercitado entregando à função os seus dois booleanos — a mesma substituição que a WCCS-025 usa. O que está provado é a entrega: o ficheiro, os tokens, a classe e a ausência de hooks que substituam o formulário. **O layout como o cliente o vê precisa de um browser, e fica registado como não observado em vez de afirmado.**

**Um tema que já estilize o checkout.** A precedência entre este ficheiro e a Storefront não foi medida num browser. O escopo mantém as regras dentro do checkout; qual das duas ganha onde as duas são específicas é uma observação de browser.

**Um token que o comerciante mude.** O teste afirma que todos os tokens lidos existem no ficheiro de tokens — nenhum valor cai no fallback por engano — mas não observa a mudança a propagar-se.

## 9. Fecho

O gate da fase — *"layout responsivo aplicado, hooks preservados"* — está **cumprido na costura e aberto na observação**, como os anteriores.

**Próxima tarefa:** **WCCS-047**, a apresentação no checkout de blocos.
