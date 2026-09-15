# Fase 10 — Workflow Engine

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §3.8, §12.4, §13, §27 · **Fase 10**
**Gate:** «workflow totalmente idempotente sem payment action ainda.»

## 1. O que a fase encontrou

Havia um motor de aprovação — `ApprovalFlow` + `ReviewStatus` — que prendia um pedido num estado à
espera de documentos. Era uma automação, e era a única: não havia gatilho configurável, nem
condições, nem decisões, nem expiração, nem registo do que aconteceu, e nada no plugin impedia que
uma alteração de estado fosse lida como uma ordem de cobrança.

O gate desta fase é uma **ausência** — «sem payment action ainda» — e uma ausência não se prova
escrita num comentário: prova-se com o motor a correr contra um pedido real e a WooCommerce a
responder que nada foi pago. Foi assim que a fase foi construída, e foi isso que encontrou algo que
o roadmap diz e que faltava implementar: a §13.8 diz, nos critérios de sucesso, «nenhum status 'pago'
é aplicado antes do pagamento».

## 2. O que foi mudado

### 2.1 O modelo e o vocabulário

- **`WorkflowDefinition`** (§3.8): id, nome, ligado, prioridade, gatilho, condições, estado inicial,
  estratégia de estoque, estratégia de pagamento, comunicação, transições, expiração e fallbacks. A
  **transição** vive no definition e não no motor: uma decisão é «para onde o pedido vai», e é isso
  que torna o mesmo workflow simulável sem executar nada (§13.6).
- **`Workflows`** — os vocabulários fechados: gatilhos (o `checkout_submitted` da §13.2), decisões
  (aprovar, solicitar correção, reprovar, expirar), eventos de comunicação (os sete da §13.3) e as
  duas listas de estratégias que a §13.2 desenha. Como em todos os vocabulários deste plugin, o
  editor e o validador leem a **mesma** lista.

### 2.2 O que a loja recusa, por nome

Três regras, e são o gate escrito como validação:

- `payment_strategy_not_available` — «autorizar agora», «capturar após aprovação», «gerar PIX após
  aprovação» e as restantes são **recusadas**. Nada executa uma ação de pagamento nesta versão, e uma
  configuração que prometesse uma seria uma promessa que a loja não cumpre.
- `inventory_strategy_not_available` — reservar até decisão ou por X horas é recusado; a reserva é da
  fase do estoque.
- `workflow_paid_status_without_payment` — um workflow que move um pedido para um estado que a
  WooCommerce considera pago é recusado **enquanto não houver ação de pagamento**, e é a §13.8 ao pé
  da letra. `Workflows::payment_actions_available()` é o único interruptor: a fase que construir a
  ação de pagamento vira-o, e a regra passa a ser a da §13.4 — «um estado pago é alcançável por uma
  transição que executa uma ação de pagamento».

A alternativa — aceitar e registar como intenção — foi rejeitada pelo princípio que o resto do
plugin segue: configuração que não pode funcionar é recusada com nome e razão, e não aceite e
ignorada.

### 2.3 O motor, e a idempotência

- **`WorkflowEvaluator`** — só workflows ligados, do gatilho certo e cuja regra casa, ordenados por
  prioridade e, em empate, pela ordem em que o comerciante os declarou. A regra é a árvore
  partilhada (§14), respondida contra o contexto confiável do checkout **e** os valores que o pedido
  carrega: uma regra sobre «a licença foi enviada» pergunta pelo que o cliente submeteu.
- **`WorkflowEngine`** corre nos dois momentos em que a WooCommerce cria um pedido — o checkout
  clássico e o da Store API —, que é o que a §13.2 chama «checkout enviado» e é **antes** de um
  gateway ter tido a sua palavra. É isso que permite a um pedido ficar à espera.
- **A idempotência vive no pedido, não no pedido que corre.** Cada efeito é escrito na meta do pedido
  **antes** de acontecer, com a chave `workflow:o que aconteceu`. Um webhook repetido, um pedido
  repetido e um botão premido duas vezes chegam com a mesma chave, e o segundo chega encontra a
  entrada. A garantia sobrevive a um reinício, a uma limpeza de cache e a um segundo worker, porque
  quem a guarda é o pedido. Escrever **antes** é deliberado: uma entrada sem efeito é uma transição
  que alguém repete à mão, e um efeito sem entrada é uma captura que acontece duas vezes.
- **`WorkflowRepository`** guarda a lista numa opção (como os estados, e pelas mesmas razões) e o
  registo numa meta do pedido: o registo é append-only, viaja com o pedido num export, é apagado com
  ele e é lido na mesma consulta que o ecrã do pedido já faz.

### 2.4 O relógio

- **`WorkflowScheduler`** — um evento único para o momento exato em que o workflow disse que o pedido
  expira, e uma ronda horária atrás dele como rede: o evento perde-se numa limpeza da tabela, num
  deploy ou numa loja que estava em baixo, e a ronda responde à mesma pergunta a partir dos próprios
  pedidos. Os dois passam pelo **mesmo** `decide()`, portanto o pedido que o evento expirou e a ronda
  também encontrou é expirado **uma vez**.
- Um pedido já decidido não é expirado: uma aprovação um minuto antes do prazo não é desfeita por um
  trabalho agendado uma hora antes.

### 2.5 O simulador, e o ecrã

- **`WorkflowSimulator`** (§13.6) — «o simulador não executa cobrança», e não executa **nada**: não
  tem pedido, não chama uma transição e não escreve. Responde a regra casada, o estado inicial, as
  estratégias (com `executable`, que é a mesma lista que o validador lê), a próxima ação, a
  expiração e as sobreposições.
- **O ecrã** (§13) e a secção §4: a lista, o editor em seis passos e o painel de simulação. Onde o
  mockup desenha as estratégias de estoque e de pagamento, os selects oferecem **só o que a loja
  consegue executar** e um aviso nomeia as restantes e diz porquê (§30.1: um controlo que não pode
  funcionar não se desenha). O `ConditionBuilder` é o mesmo dos campos e dos perfis, com
  `envelope=""` porque um workflow guarda a árvore (§14).

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE10-workflow-engine-proof.php` (novo) | **42/0**, 3 notas: um pedido real entra no workflow **uma vez** e correr o motor cinco vezes não o move duas; uma decisão acontece uma vez e uma decisão **diferente** é uma decisão diferente; o relógio expira uma vez e a ronda não expira ninguém duas vezes; um pedido já decidido não é expirado; **nada foi pago** (sem data de pagamento, sem transação, e o estado não é um estado pago pela WooCommerce); a ação de pagamento e a reserva são recusadas por nome e a recusa não altera o guardado; um estado pago é recusado (§13.8); o simulador responde a quatro transições e **não escreve nada**; e uma loja sem automações não muda nada |
| `tests/Unit/Domain/Workflow/WorkflowDefinitionTest.php` (novo) | 14 testes, 33 asserções: identidade legível, decisões respondidas só quando nomeiam um estado, a expiração a precisar das duas metades, as três recusas por nome, a lista paga sem ação de pagamento, o gatilho fechado, a ordenação por prioridade, a sobreposição, e as chaves desconhecidas preservadas |
| `tests/js/schema/workflows.test.js` (novo) | 16 testes: criar sem identificador e sem tocar em estoque nem pagamento, só as estratégias executáveis oferecidas e as outras nomeadas, o que o ecrã vê errado (nome, estado, as duas metades da expiração), o payload inteiro — e a ausência de qualquer campo de cobrança |
| `tests/js/screens/WorkflowsScreen.test.js` (novo) | 7 testes: os seis passos, o aviso de que uma automação não cobra, as estratégias ausentes e nomeadas, uma transição por decisão, guardar o que a loja espera, recusar guardar sem estado, e simular **sem guardar** |
| `tests/browser/fase10-workflows.mjs` (novo) | **13/0** num browser real: o ecrã desenha os seis passos, os selects só oferecem `none` de cada estratégia e o aviso nomeia as outras, gravar atribui `produtos_quimicos`, a simulação responde sem escrever, e a automação criada é removida outra vez |
| `composer check` | phpcs e phpstan sem erros; **597 testes, 2034 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**809 testes**, 51 suites); build compila |
| Varredura de integração | **75 harnesses, 1743 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **A ação de pagamento não existe.** É o gate desta fase: um workflow muda estados, avisa e regista,
  e não toca num gateway. A §13.4 pede `aprovar → PaymentAction → se pagamento completo → Processing`;
  a primeira metade está aqui, a segunda é a fase do registo de capabilities. É por isso que aprovar
  num estado que a WooCommerce considera pago é **recusado**: sem ação de pagamento, esse estado
  diria que o dinheiro chegou.
- **A reserva de estoque não existe.** §13.2 passo 3 e §13.5 pedem reservar e libertar; nenhuma das
  duas acontece, e a estratégia é recusada por nome em vez de aceite e ignorada. A expiração muda o
  estado e regista-o — e nada mais, porque não há reserva para libertar.
- **As comunicações são declaradas e não enviadas por este motor.** O workflow diz **qual** evento
  interessa a cada momento (`communications`), e o e-mail continua a ser o da WooCommerce para a
  mudança de estado, como antes. Um motor que enviasse e-mails próprios seria um segundo sistema de
  comunicação a par do que já existe, e o evento declarado é o que a fase das comunicações vai ler.
- **Um gatilho só.** §13.2 desenha «checkout enviado» e é o único momento que este build observa;
  os restantes gatilhos chegam com os momentos que os podem emitir. A lista é fechada e não vazia, o
  que é o que impede uma regra de se pendurar num momento que ninguém emite.
- **Os workflows não viajam no ficheiro de transferência**, pela mesma razão que os estados: o
  envelope leva o documento da schema. Acrescentar os dois é uma decisão da fase em que o que viaja
  passa a ser a automação inteira.
- **`expires_after_hours` não está na §3.8.** O modelo do documento lista `trigger`, `conditions`,
  `initial_status`, `inventory_strategy`, `payment_strategy` e `communications`; o relógio da §13.5 e
  o `scheduler` da lista da fase exigem as horas, e elas são um campo do workflow. Fica registado
  como acrescento ao modelo do documento e não como invenção silenciosa.
