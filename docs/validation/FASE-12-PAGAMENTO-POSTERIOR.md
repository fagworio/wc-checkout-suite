# Fase 12 — Pagamento posterior

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §13.4, §13.7, §20, §27 · **Fase 12**
**Gate:** «nenhum cenário duplica cobrança.»

## 1. O que a fase encontrou

A Fase 11 deixou o registo que diz o que cada gateway **provou** e a Fase 10 deixou o motor que move
estados, com uma regra dura: nenhum estado pago podia ser aplicado porque nada concluía um pagamento.
Faltava exatamente isso — quem cobra.

O §20 dá sete regras e chama-lhes não negociáveis. Lidas juntas são uma frase: **um pagamento
acontece quando um gateway diz que aconteceu, uma vez.** Esta fase é essa frase escrita como código,
e o gate é o mesmo dito ao contrário: nenhum cenário duplica uma cobrança.

## 2. O que foi mudado

### 2.1 O serviço, e a porta única

- **`PaymentActionService`** — a única porta por onde uma ação chega a um gateway. Pergunta ao registo
  de capabilities (Fase 11: sem prova, não se pede), pergunta ao registo de adapters (há quem saiba
  executar?), **regista a intenção antes de chamar**, chama, e regista o que voltou.
- **`PaymentActionAdapterInterface`** — o seam, e não uma integração. O plugin **não traz adapter
  nenhum**: qual endpoint, que campos, que identificador volta é conhecimento que só existe ao lado
  do gateway, e um adapter escrito sem o gateway à frente é um palpite com nome de classe. Quem o tem
  à frente regista-o por `wccs_register_payment_action_adapters`.
- **`PaymentAdapterRegistry`** — um adapter de contrato desconhecido é **recusado em vez de chamado**:
  a forma de uma cobrança é o último sítio para se ser otimista.
- **`PaymentActionResult`** — quatro respostas e não duas: `confirmed`, `pending`, `refused`,
  `unsupported`. Só a primeira conclui o pagamento (§20 regra 4); a quarta é o que manda o cliente
  para a página de pagamento, e juntá-la a `refused` diria a um cliente que o pagamento foi recusado
  quando a verdade é que a loja nunca perguntou.

### 2.2 A idempotência, e a decisão de não tentar outra vez

A chave é `payment:<ação>`, registada no **log do pedido**:

1. há resultado → `already_executed`, e o gateway não é chamado;
2. há intenção sem resultado → `outcome_unknown`, e o gateway **não é chamado**;
3. não há nada → regista a intenção, chama, regista o resultado.

O segundo caso é a decisão que faz o gate: uma chamada que pode ter acontecido não se repete. O custo
de parar é uma nota no pedido e uma pessoa a olhar para o registo do gateway; o custo de adivinhar é
uma cobrança dupla. A nota diz isso mesmo, e é por isso que ela existe.

### 2.3 Concluir um pagamento, do jeito que a WooCommerce exige

`WC_Order::payment_complete()` **só age** se o pedido estiver num dos quatro estados que a própria
WooCommerce aceita (`on-hold`, `pending`, `failed`, `cancelled`). Um estado criado por esta loja
**não** é um deles — portanto uma confirmação num pedido que espera em «Análise pendente» seria
registada pelo gateway e **silenciosamente ignorada pela loja**. O serviço devolve o pedido a
`pending` primeiro, deixa-o dito numa nota, e deixa a WooCommerce decidir o estado seguinte (§20
regras 4 e 5). Sem esse passo, a regra 1 da §20 («Análise pendente não deve chamar
`payment_complete()`») seria verdadeira por acidente e o pagamento nunca concluiria.

A lista dos quatro vem da WooCommerce quando a versão a tem (o enum nasceu na 10.9) e está nomeada
aqui quando não tem, sempre através do filtro da própria WooCommerce — uma loja que estenda a lista
estende esta resposta.

### 2.4 O callback, e o fallback

- **`confirm()`** é o caminho de um webhook, e é idempotente pelo **estado do próprio pedido**: um
  callback entregue duas vezes encontra um pedido já pago e não faz nada — nem uma segunda conclusão,
  nem uma segunda redução de estoque, que é o que `payment_complete()` faria se fosse chamado outra
  vez (§20 regra 6).
- **O fallback não precisa de gateway.** Quando a capability ou o adapter faltam, a resposta é o link
  «Pagar pedido» da própria WooCommerce, com a razão numa nota e o endereço devolvido a quem chamou.

### 2.5 A ponte que faltava entre a Fase 10 e a Fase 11

- **`Workflows::strategy_capabilities()`** — a tabela que liga as opções da §13.2 passo 4 ao
  vocabulário da §20: `capture_after_approval` precisa de `capture`, `authorize_now` de `authorize` e
  `capture`, `generate_after_approval` de `create_after_approval`, e `request_after_approval` de nada
  (é a página da plataforma). Uma tabela só, lida pelo validador e pelo runtime.
- **As estratégias de pagamento passam a ser aceites** e as de estoque continuam recusadas, porque
  nada reserva estoque ainda. O ecrã de workflow passou a oferecê-las, e o que continua fora do
  select é a reserva — que é a fase seguinte.
- **A regra do estado pago mudou de forma**: um workflow pode mover um pedido para um estado que a
  WooCommerce considera pago **se a sua estratégia de pagamento executar uma ação**. Sem isso, nada
  concluiu um pagamento e o estado estaria a dizer que o dinheiro chegou (§13.8).
- **`WorkflowTransitionService::decide(approve)`** corre a ação que a estratégia pede — e só aprovar:
  uma reprovação que capturasse dinheiro seria o pior erro que este plugin poderia enviar.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE12-payment-actions-proof.php` (novo) | **31/0**, 3 notas, com um adapter de prova que **conta as chamadas**: sem capability o gateway não é chamado e o cliente é mandado para a página «Pagar pedido»; com capability o gateway é chamado **uma** vez, o pedido fica pago com o identificador da transação e a WooCommerce decide o estado; correr a mesma ação outra vez — e cinco vezes no total — não volta a chamar ninguém, não move a data de pagamento e não reduz estoque outra vez; uma intenção sem resultado **recusa-se a repetir** e deixa a nota; uma recusa e um `pending` não pagam nada; um callback repetido três vezes confirma uma vez e reduz estoque uma vez; aprovar corre a ação da estratégia, e uma estratégia que não faz nada não pede nada ao gateway; e um workflow que reivindica um estado pago sem executar uma ação é recusado |
| `tests/Unit/Domain/Payments/PaymentActionServiceTest.php` (novo) | 6 testes, 30 asserções: só uma confirmação é um pagamento, um resultado fora do vocabulário é lido como o seguro, o resultado carrega a transação, um adapter de contrato desconhecido é recusado em vez de chamado, um adapter sem gateway não serve loja nenhuma, e um registado responde pelas suas ações |
| `tests/Unit/Domain/Workflow/WorkflowDefinitionTest.php` | a tabela estratégia→capability afirmada linha a linha, e a regra do estado pago com e sem ação |
| `tests/js/screens/WorkflowsScreen.test.js` | o select de pagamento oferece as estratégias que o serviço corre, e o de estoque continua a oferecer uma só |
| `tests/browser/fase10-workflows.mjs` | **14/0** com as asserções atualizadas: o select de estoque oferece só `none`, o de pagamento oferece as cinco estratégias que o serviço consegue executar, e as que não correm continuam nomeadas com a razão |
| `composer check` | phpcs e phpstan sem erros; **618 testes, 2133 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**810 testes**, 51 suites); build compila |
| Varredura de integração | **77 harnesses, 1796 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **Nenhum adapter de gateway é enviado.** É deliberado e é o mesmo princípio da fase anterior: sem o
  gateway à frente não há como provar a chamada. O que a loja tem é o serviço, o contrato, a
  idempotência e o fallback — e a prova de que tudo isso funciona, feita com um adapter de prova que
  conta as chamadas.
- **Um resultado desconhecido pára a loja e não se repete automaticamente.** É o gate a decidir o
  desenho: uma captura que pode ter acontecido não se repete, e quem resolve é uma pessoa com o
  registo do gateway à frente. Fica registado como decisão e não como lacuna.
- **A ação não é oferecida no ecrã do pedido.** O §13.4 põe a decisão no ecrã de automação —
  aprovar, solicitar correção, reprovar — e é aí que ela corre a ação. Um botão «capturar» no painel
  do pedido seria uma segunda porta para a mesma cobrança, e a §20 regra 7 é sobre exatamente isso.
- **`authorize_now` executa a captura, não a autorização.** A §13.2 descreve-a como «autorizar agora e
  capturar após aprovação», o que são duas chamadas separadas por uma decisão humana: a primeira no
  checkout, a segunda quando o comerciante aprova. Idempotentemente separar as duas exige que a
  autorização aconteça no momento da compra, o que é a fase do checkout customizado; hoje a
  estratégia corre a captura no momento da aprovação, que é o passo que conclui a venda. Fica
  registado em vez de fingido.
