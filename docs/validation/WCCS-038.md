# Registro de validação — WCCS-038

**Tarefa:** WCCS-038 · "Integrar Store API e validação"
**Fase:** F07 · Checkout Blocks nativo e tipos próprios
**Prioridade:** `required_v1` · **Dependências:** F04, F05, F06
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Erro final bloqueia pagamento; payload tipado; retry não duplica gravação."

**Resultado:** **327 testes unitários PHP** (4 novos) · **894 asserções de integração** em 34 provas, 0 falhas (16 novas) · **554 testes de JS** em 32 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/Blocks/StoreApiExtension.php` | O namespace tipado que leva os valores dos campos controlados |
| `src/Checkout/Blocks/BlocksValidation.php` | Validação e gravação no mesmo hook, com a recusa que trava o pedido |
| `src/Domain/Conditions/CheckoutConditionContext.php` | O contexto confiável, agora partilhado pelos dois checkouts |
| `tests/Integration/F07-wccs-038-store-api-integration-proof.php` | As três cláusulas do aceite |

## 3. Os campos controlados não são campos nativos — e é isso que faltava

Um campo que esta extensão desenha não existe para o WooCommerce: nada leva o seu valor ao servidor. O namespace `wc-checkoutsuite` no endpoint `checkout` é o que passa a levar, e **o tipo declarado é o tipo que o componente produz**, não o que o cliente vê: um multiselect declara `array` (com `items: string`), um checkbox declara `boolean`, o resto declara `string`.

A consequência prática é que a **rota valida a forma antes de qualquer código desta extensão correr**: um pedido que mande uma lista onde vai uma string é recusado pela plataforma. Um schema que dissesse `string` para todos os campos seria decoração, e é o que o teste unitário fixa campo a campo.

Só entram no namespace os campos que o renderer desenha e cujo armazenamento é nativo — a mesma regra, lida do mesmo sítio. Um campo não pode ser desenhado sem poder ser enviado, nem enviado sem ser desenhado.

## 4. A recusa é uma exceção porque é a única forma de travar

O hook é `woocommerce_store_api_checkout_update_order_from_request`, e a documentação do WooCommerce diz o que ele faz quando um callback lança: *"will make the Checkout Block render in a warning state, effectively preventing checkout"*. É o único ponto em que uma extensão consegue impedir um pagamento deste lado — e é por isso que **validação e gravação partilham o hook**: o pedido é aceite com os seus valores ou não é aceite de todo. Não existe o estado intermédio "o pedido avançou mas os valores não".

A recusa sai com o código prefixado (`wc-checkoutsuite_required`), a mensagem da própria regra e o campo que a produziu nos dados adicionais, para o bloco a renderizar contra esse campo. A mensagem é escapada como o WooCommerce escapa as suas próprias: vem de uma string traduzida com um rótulo escrito pelo lojista lá dentro, e um rótulo não é sítio para descobrir que escapar era trabalho de outro. O código e o identificador do campo **não** são escapados, e a razão está escrita no código: são legíveis por máquina, e escapar um código muda o contrato com que o bloco o compara.

O pipeline é o mesmo do checkout clássico — normalização, visibilidade, política de valor oculto, regras do tipo e validadores nomeados — com o contexto confiável lido dos objetos do WooCommerce. Uma regra não pode significar uma coisa num checkout e outra no outro.

## 5. `retry` não duplica: a prova conta as linhas

Duas submissões idênticas, com o pedido **guardado** entre elas como a rota faria a seguir:

- a primeira grava o payload;
- a segunda grava **o mesmo payload, byte a byte**;
- a tabela `wc_orders_meta` continua com **uma linha**.

Nada aqui acrescenta: a gravação é um `update_meta_data` de uma chave só. É o que um cliente a repetir um pedido que expirou — ou um cliente a carregar duas vezes em "finalizar" — realmente faz.

## 6. O contexto confiável foi generalizado

O `ClassicConditionContext` da WCCS-033 passou a `CheckoutConditionContext` em `Domain\Conditions`. O motivo é direto: as perguntas que ele responde — que país é o do cliente, o que está no carrinho, se está autenticado — são as mesmas nos dois checkouts, e os objetos do WooCommerce que as respondem são os mesmos. Mantê-lo no namespace do clássico obrigaria a uma segunda implementação no Blocks, e as duas discordariam na primeira vez que uma fosse editada. A prova da WCCS-033 foi atualizada para o nome novo.

## 7. O que a prova estabelece

- Os campos controlados são declarados no namespace com os tipos que os componentes produzem, as listas dizem o que contêm, e o namespace está registado **no endpoint real** do Store API (`ExtendSchema::get_endpoint_data()`, o mesmo objeto que o schema da rota funde);
- um campo nativo **não** é declarado (a plataforma leva-o sozinha);
- um campo obrigatório vazio lança a `RouteException` que a rota transforma em recusa, com o código desta extensão e o campo nos dados adicionais, e **nada fica gravado** no pedido;
- duas submissões iguais deixam uma linha e o mesmo payload, e o que se lê de volta é o que foi submetido.

## 8. O defeito que a prova apanhou — e o que ela não fez

A primeira execução da prova **morreu em silêncio** (exit 255, sem `RESULT`): chamava `SchemaController::extend()`, que não existe. É a terceira vez nesta sessão que um harness morre sem dizer porquê; o que o torna visível é a varredura ler o **código de saída** de cada prova e não apenas a linha `RESULT` — sem isso, uma prova que nunca correu contaria como uma prova sem falhas.

E deixou o `wccs_schema_published` atrás. A prova passou a **estabelecer o estado em vez de o herdar** (apaga a opção antes de medir a linha de base), que é a disciplina adotada desde a WCCS-020 e a razão pela qual voltou a ser precisa aqui.

## 9. O que NÃO foi provado, e porquê

**O POST completo em `/wc/store/v1/checkout`.** Esta loja não tem produtos, portanto não há carrinho que se construa nem pedido que se coloque pela rota. O que está provado é o código que a rota chama, com o pedido e o pedido HTTP que ela lhe passa. O percurso completo é a WCCS-040 e o trabalho de browser da F10.

**A ligação da metade cliente ao namespace.** O bundle do Blocks constrói os elementos e tem a costura de registo (WCCS-037), mas ainda não envia valores pelo namespace: isso depende de onde os componentes são colocados, que é a mesma tarefa de integração.

**Os slots.** Continua registado como decisão da integração, não desta tarefa.

## 10. Próxima tarefa

**WCCS-039 — "Implementar matriz no admin"** (aceite: *limites de core, largura, seção e tipo visíveis antes de publicar*). É onde a classificação desta fase (native/controlled/restricted, com o motivo de cada um) deixa de viver só nas provas e passa a ser visível ao lojista **antes** de ele publicar um campo que o checkout não honra.
