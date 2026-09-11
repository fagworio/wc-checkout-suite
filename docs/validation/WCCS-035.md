# Registro de validação — WCCS-035

**Tarefa:** WCCS-035 · "Criar compilador de condições nativas"
**Fase:** F06 · Conditional Logic e política de valores
**Prioridade:** `required_v1` · **Dependências:** F03, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Somente condições representáveis são compiladas; demais capacidades ficam explícitas."

**Resultado:** **310 testes unitários PHP** (14 novos) · **839 asserções de integração** em 31 provas, 0 falhas (8 novas) · **541 testes de JS** em 31 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Conditions/NativeConditionCompiler.php` | Traduz a árvore para a linguagem de regras que o Blocks já lê |
| `src/Domain/Conditions/NativeConditionCompilation.php` | O que foi compilado, e o que não foi com a razão ao lado |
| `tests/Unit/Domain/Conditions/NativeConditionCompilerTest.php` | As traduções e as recusas |
| `tests/Integration/F06-wccs-035-native-condition-compiler-proof.php` | O WooCommerce a decidir o que foi compilado |

## 3. Onde foi lida a linguagem de destino

O `§11` diz para compilar para "os mecanismos nativos de `hidden/required`, que usam JSON Schema", e a linguagem não foi adivinhada: foi lida no WooCommerce 11.1.0 instalado.

- `CheckoutFields::is_hidden_field()` e `is_required_field()` entregam a regra a `Validation::validate_document_object()`, que a valida contra o **document object** do checkout — `cart`, `customer` e `checkout` — com JSON Schema draft-07.
- `Validation::is_valid_schema()` valida a regra contra o meta-schema draft-07 antes de a aceitar no registo; uma regra que não seja JSON Schema é recusada com `_doing_it_wrong`.
- `hidden => true` **não** é suportado: o WooCommerce regista o campo como visível. Só uma regra esconde, o que confirma que o caminho é a compilação e não uma flag.
- O documento carrega: `cart.items` (um id de produto por unidade), `cart.shipping_rates` (os ids das taxas escolhidas), `cart.totals.total_price` (**em unidades menores**), `customer.id` (zero para visitante), `customer.billing_address.*` e `customer.additional_fields`.

## 4. As três decisões do compilador

**Tudo ou nada por campo.** Uma regra só é compilada se **todas** as suas folhas forem representáveis. Compilar as folhas que cabem não é uma tradução parcial: um `all` fica mais fraco e um `any` fica mais largo, e o mesmo documento esconderia campos diferentes em dois checkouts sem que nenhum deles o dissesse. Uma regra não compilável devolve `null` e uma nota por cada folha que a travou.

**O documento decide o que existe.** Uma fonte só é compilada quando o documento a carrega de facto. `payment_method` e `cart_categories` são nomeadas pelo `§11` e **não** estão lá — o documento tem identificadores de produto, não as categorias a que pertencem — por isso são recusadas com essa razão, em vez de mapeadas para algo que apenas se parece. `field` é recusada porque o caminho de outro campo depende da localização que o adapter do Blocks lhe der, e essa decisão é do adapter.

**A unidade faz parte do significado.** `cart_total` é publicado em unidades menores; o lojista escreveu um preço. A conversão acontece uma vez, aqui, onde as casas decimais da moeda são conhecidas. Do mesmo modo, `cart_items` compara-se com **inteiros** e `shipping_method` com uma **lista** (uma igualdade sobre uma lista é uma pertença).

## 5. O defeito que a prova apanhou

A primeira versão compilava `customer_logged_in` como qualquer outra fonte booleana: `equals true` virava `{"const": true}` sobre `customer.id`. O id é um inteiro, `const: true` nunca casa com `7`, e a regra compilada esconderia o campo em todos os casos — em silêncio, porque uma regra que não casa é uma resposta legítima.

Foi apanhado porque a prova **não** compara o resultado com uma expectativa escrita à mão: entrega a regra compilada ao validador do próprio WooCommerce, com um `DocumentObject` real, e compara com a resposta do motor desta extensão sobre a mesma situação. Nas duas primeiras execuções, dois casos discordaram — "um visitante não está autenticado" e "um membro está autenticado". A tradução passou a ser semântica e não um cast: "está autenticado" é `{"minimum": 1}` sobre o id, e "não está" é `{"maximum": 0}`.

## 6. O que a prova estabelece

Não que a saída *pareça* JSON Schema, mas que **o código que a vai ler decide o que o motor decide**:

- 10 regras representáveis compilam e passam a validação de schema do WooCommerce — com um controlo negativo que mostra que essa validação não é vazia (um schema que não é JSON Schema é recusado);
- 12 casos comparam as duas decisões sobre o mesmo documento: país que casa e que não casa, total acima e abaixo (com a conversão de unidades), produto no carrinho e fora dele, método de envio, visitante, membro, `in` sobre o estado, `is_empty` e um grupo `all` — **zero discordâncias**;
- 5 regras não compiláveis devolvem uma nota com a fonte que as travou, e uma regra metade representável não é compilada de todo;
- um campo sem regra não é uma falha: não há decisão para o mecanismo nativo tomar, e as notas ficam vazias.

## 7. Registado, não escondido

**`shipping_method` em vários pacotes.** O motor lê o primeiro método escolhido; o documento guarda todos os selecionados. Um checkout de um pacote torna as duas leituras idênticas; um de vários não, e a regra nativa testa pertença à lista. Está escrito na prova e aqui.

**`cart_total` e as casas decimais.** A conversão usa as casas decimais da moeda da loja. Uma moeda sem casas decimais compila valores diferentes, e é por isso que o número de casas é um argumento do compilador e não uma constante.

**A moeda não viaja.** O documento não diz de que moeda é o total. Uma loja multi-moeda teria de o resolver antes de compilar; hoje isso não é expressável, e fica registado em vez de assumido.

## 8. O que NÃO foi provado, e porquê

**Um campo a ser escondido no checkout Blocks.** O compilador produz a regra e o WooCommerce decide-a — mas nenhum campo desta extensão foi registado no Blocks com essa regra, porque o adapter do Blocks é a F07. O que está provado é a tradução e o acordo entre as duas decisões, não a integração.

**A observação em browser.** Continua a faltar: `CLASSIC-TEST-SURFACE` para o checkout clássico e a WCCS-063 para o browser.

**Uma regra multi-moeda.** Registada em §7.

## 9. Fecho da fase F06

As cinco tarefas estão concluídas: a AST e os operadores (031), o editor (032), os dois motores com fixtures comuns (033), a política de valores e o motor como padrão (034) e o compilador nativo (035). O gate — *"Fluxo PF/PJ funciona após múltiplas trocas; adulteração do browser não burla regra"* — está **cumprido ao nível da costura e não observado**: cada estado é decidido pelo código real, com contexto real, e a adulteração está provada; o que falta é um cliente a fazê-lo num checkout renderizado, que é o que `CLASSIC-TEST-SURFACE` e a WCCS-063 fecham.

**Próxima tarefa:** **WCCS-036 — "Criar tipos próprios do Blocks"**, a primeira da F07, onde o checkout Blocks deixa de ser um alvo de compilação e passa a ter os seus próprios tipos de campo registados.
