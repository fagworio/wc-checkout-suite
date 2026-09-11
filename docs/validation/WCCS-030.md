# Registro de validação — WCCS-030

**Tarefa:** WCCS-030 · "Implementar UX de erro"
**Fase:** F05 · Presets Brasil, IMask e validação remota
**Prioridade:** `required_v1` · **Dependências:** F04
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Mensagens por campo; primeiro erro focado; regra crítica revalidada no submit."

**Resultado:** **215 testes unitários PHP** · **755 asserções de integração** em 26 provas, 0 falhas (13 novas) · **413 testes de JS em 27 suites** (16 novos) · os **7 gates** verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/checkout/errors.js` | Mensagem por campo, `aria-invalid`, `aria-describedby`, resumo com links |
| `resources/checkout/validate.js` | Decide localmente ou pergunta ao servidor |
| `resources/checkout/form.js` | Ao sair do campo, e a guarda do submit |
| `resources/checkout/index.js` | Liga tudo ao `checkout_place_order` do WooCommerce |
| `ClassicAssets::rules()` | A regra de cada campo **e a sua redação** |

## 3. O defeito que eu quase enviei

O primeiro handler era assim:

```js
$( document.body ).on( 'checkout_place_order', ( event ) => {
	if ( form.guard( event.target ) ) { return true; }
	return false;
} );
```

`form.guard()` devolve uma `Promise`. **Uma Promise é sempre *truthy*.** O WooCommerce lê `false` como "abandona este pedido" e qualquer outra coisa como "pode seguir" — portanto a guarda aprovava **todos** os pedidos, enquanto o código parecia estar a verificar alguma coisa.

Não é um bug de sintaxe: é um bug de contrato entre duas camadas, e o tipo de coisa que passa numa revisão porque o código *lê-se* bem. Corrigido com uma guarda **síncrona**, que decide apenas o que consegue decidir no momento, e com um teste cujo nome é a regra: *"is synchronous, because a promise is not a refusal"*.

**E é essa a decisão de desenho da tarefa.** O WooCommerce decide no momento; uma regra que precise do servidor não é perguntada aqui. Não é uma perda: a mesma regra corre outra vez no servidor quando o pedido é colocado — a mesma camada, o mesmo momento — e uma viagem de ida e volta no browser só acrescentaria uma máquina de estados e um ciclo de re-submissão sem reforçar nada que o submit já não reforce.

## 4. "Mensagens por campo"

Provado em jsdom, contra um formulário real:

| Afirmado | |
|---|---|
| a mensagem fica na **`.form-row` do próprio campo**, não numa lista no topo | ✔ |
| o campo leva `aria-invalid` e aponta para a mensagem com `aria-describedby` | ✔ |
| uma descrição que outro plugin já tinha posto é **preservada** e a nossa é acrescentada | ✔ |
| o valor **nunca** é apagado | ✔ |
| a mensagem desaparece quando o campo é corrigido | ✔ |
| o resumo é uma lista de **links** para os campos, com `aria-live="polite"` | ✔ |
| o resumo desaparece quando não há erros | ✔ |

A redação **não** está escrita em JavaScript. Cada mensagem viaja do servidor com a sua regra, porque uma mensagem mantida em duas línguas passa a dizer duas coisas diferentes na primeira vez que uma delas for editada. Uma prova afirma que a mensagem que o checkout mostra é *exatamente* a que o `DocumentValidator` produziria.

## 5. "Primeiro erro focado"

A guarda percorre os campos **em ordem de página**, sequencialmente, e falha no primeiro que falha. No fim, se algo falhou, foca o primeiro e traz o resumo a público. Um teste afirma que `document.activeElement` é o primeiro campo inválido — e é por isso que a verificação é sequencial e não um `Promise.all`: uma página de pedidos paralelos é uma página de respostas a competir pelo mesmo campo.

## 6. "Regra crítica revalidada no submit"

Há três afirmações distintas aqui, e vale separá-las:

**A decisão não é lembrada.** `guard()` volta a decidir todos os campos no submit; um teste muda o valor entre duas chamadas e exige que a segunda resposta seja diferente da primeira.

**A regra corre outra vez no servidor.** Não é uma promessa do cliente: o `process_checkout()` valida o pedido com o mesmo `ValueProcessor`, e as WCCS-022/028 provam que um documento errado não o deixa passar.

**Um timeout não liberta nada.** Quando uma regra não pôde ser conferida, o campo recebe uma **nota** — não um erro — e o pedido não é bloqueado. O `§10` pede "alternativa explícita ou bloquear com orientação"; a alternativa é esta: o valor **não** é liberado, é conferido pela camada que é dona da regra, no momento em que o pedido é colocado. Bloquear recusaria uma venda porque a rede estava lenta, e não tornaria a regra mais cumprida.

A nota é um elemento diferente (`wccs-field-note`) e **não** marca o campo como inválido — é a diferença entre um erro e um pedido de desculpas. Há um teste para isso, e outro que afirma que uma nota não impede o pedido.

## 7. Uma interface nova, para não repetir a redação

O `ClassicAssets` precisa de saber qual o código e a mensagem de cada validador. A primeira versão usava `method_exists()`, e o PHPStan apanhou-a: um `callable&object` não tem `failure_message()`.

Em vez de um `instanceof` sobre a classe concreta — que acoplaria os assets a um validador — ficou uma interface pequena, `FailureMessageInterface`, com `failure_code()` e `failure_message()`. Um validador que a implemente publica a sua redação; um que não implemente continua a correr no servidor, e o browser simplesmente não tem nada a dizer sobre o campo de antemão.

## 8. O que NÃO foi provado, e porquê

**Uma página de checkout renderizada.** O formulário real, o `focusout` real e o `checkout_place_order` real são exercidos em jsdom e por leitura do código do WooCommerce (`checkout.js`, linha 905: `triggerHandler('checkout_place_order', …)` e o `!== false` que abandona o pedido). Observar tudo isto numa página é a `CLASSIC-TEST-SURFACE`.

**Um leitor de ecrã.** `aria-describedby`, `aria-invalid` e a região `polite` são afirmados no DOM; o que um leitor de ecrã faz com eles é F12, na auditoria de acessibilidade.

**Uma regra que só o servidor saiba responder.** O caminho existe, é exercido por um teste com um transporte simulado, e nenhum preset desta versão o usa — todas as regras desta versão são aritmética. É o caminho que uma consulta de CEP vai usar.

## 9. A F05 está completa

Cinco tarefas, cinco concluídas. O objetivo da fase — *"entregar documentos e máscaras corretos, com validação sem fricção"* — tem agora as três partes: os contratos (WCCS-026), as máscaras (WCCS-027), as regras (WCCS-028) e as duas metades da validação remota (WCCS-029/030).

**O gate da fase continua aberto por uma razão só:** *"documento inválido não finaliza"* está provado na costura — um POST forjado com quatro documentos errados produz quatro erros na coleção que o WooCommerce transforma em avisos, e o `process_checkout()` não cria pedido quando essa coleção não está vazia — mas **observar** o checkout a terminar ou a não terminar precisa de uma página com o shortcode, que esta loja não tem.

## 10. Próxima tarefa

**F06 · Conditional Logic e política de valores.** A primeira tarefa é a **WCCS-031**, e é onde o `PermissiveConditionEvaluator` deixa de ser um lugar-place: o motor de condições, a AST declarativa avaliada em PHP e JS, e a política de valores ocultos (`discard`/`preserve`) que a WCCS-011 já deixou ligada ao pipeline mas que nada ainda produz.

O `§11` é explícito sobre o que não pode ser esquecido: o servidor recalcula com contexto confiável do carrinho e do cliente, e *"hidden input estático é diferente de campo condicionalmente ocultado — nunca carrega informação confiável de preço, permissão ou identidade"*.
