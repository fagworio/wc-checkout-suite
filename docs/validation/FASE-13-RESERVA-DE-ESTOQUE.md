# Fase 13 — Reserva de estoque

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §13.2 (passo 3), §21, §27 · **Fase 13**
**Gate:** «quantidade disponível correta em todas as transições.»

## 1. O que a fase encontrou

A Fase 10 deixou o motor que move estados e recusou as três estratégias de estoque **por nome**: nada
reservava. Esta fase é o serviço que faltava, e o gate é um número — a quantidade disponível tem de
estar certa quando o pedido é criado, quando espera, quando é reprovado, quando expira, quando é
pago, quando é repetido e quando é apagado.

Antes de escrever código, um *spike* contra a instalação real. Encontrou três coisas, e duas delas
mudaram o desenho.

### 1.1 A WooCommerce 11.1 já reserva estoque

`wc_reserve_stock_for_order()` escreve uma linha em `{prefix}wc_reserved_stock` com uma expiração, a
disponibilidade é `estoque − guardado`, e a instrução que verifica e a que escreve são **a mesma**:
um `INSERT … SELECT … FOR UPDATE … LOCK IN SHARE MODE` que faz a aritmética e a escrita num passo.
Logo, a §21 («nunca atualizar estoque via SQL direto») não é um obstáculo: a tabela da plataforma é o
livro-razão e a API dela é o único escritor. Não há segundo livro.

### 1.2 O buraco: a plataforma só conta a reserva enquanto o pedido está `pending`

`ReserveStock::get_query_for_reserved_stock()` filtra por estado:

```sql
WHERE orders.status IN ( 'wc-checkout-draft', 'wc-pending' ) AND expires > NOW()
```

Uma automação move o pedido para um estado próprio — «Análise pendente» — e a linha da reserva
**continua lá mas deixa de ser contada**: a unidade volta a parecer disponível e o cliente seguinte
pode comprá-la. É exatamente o overselling que esta fase existe para impedir, e não se resolve
devolvendo o pedido a `pending` — o ponto da automação é que o cliente veja outro estado.

### 1.3 A armadilha do `ON DUPLICATE KEY UPDATE`

A reserva é escrita com `ON DUPLICATE KEY UPDATE expires, stock_quantity`, e a plataforma lê `! $result`
como falha. Mas o MySQL devolve **zero linhas afetadas** quando a atualização não muda nada — e zero é
falso. Pedir duas vezes a mesma janela dentro do mesmo segundo levanta
`woocommerce_product_not_enough_stock`, e o `catch` da própria plataforma **apaga as reservas do
pedido** antes de relançar. Como o checkout da WooCommerce já reserva com a mesma janela que a
automação pede (é o filtro desta fase que a define), uma segunda escrita com os mesmos valores perdia
a reserva e dizia ao comerciante uma coisa falsa. O §13.2 pede que «toda reserva seja idempotente»;
esta fase descobriu porquê.

## 2. O que foi mudado

### 2.1 `InventoryReservationService` — a loja responde, a plataforma escreve

**Um valor, uma autoridade (ADR-0001), portanto a correção é alimentar o número da plataforma e não
competir com ele.** `woocommerce_query_for_reserved_stock` é o filtro que a plataforma aplica àquela
consulta — e `get_query_for_reserved_stock()` serve **os dois leitores** do número:

- `get_reserved_stock()`, que o carrinho, a Store API, os limites de quantidade e
  `wc_get_held_stock_quantity()` perguntam;
- a cláusula de disponibilidade do `INSERT` de `reserve_stock_for_product()`, que é a verdadeira
  guarda contra dois pedidos a levar a mesma unidade.

Responder a essa consulta responde a todas as perguntas de uma vez, **incluindo em simultaneidade**.

O que o filtro devolve é a mesma consulta **sem a cláusula de estado**, e a razão é curta: a
plataforma exclui as reservas cujo pedido já não está pendente porque, para ela, essa reserva ou foi
convertida em redução real ou foi libertada — e em ambos os casos **a linha é apagada**
(`woocommerce_payment_complete`, `woocommerce_order_status_cancelled`,
`woocommerce_order_status_completed`, `woocommerce_order_status_processing`,
`woocommerce_order_status_on-hold` libertam-na). Uma linha que ainda existe e não expirou é, por isso,
uma reserva viva, qualquer que seja o estado do pedido — que é precisamente o que a §21 quer que a
loja guarde. Devolver o nosso texto em vez de editar o deles é deliberado: não há padrão para casar,
portanto isto não deixa de funcionar em silêncio quando a WooCommerce reescrever o SQL.

Consequência: a loja **não responde a nenhuma pergunta de disponibilidade sozinha**. `held()` e
`available()` leem o número da plataforma e existem para os ecrãs e para o diagnóstico o poderem
reportar.

### 2.2 A janela: `null` não é o mesmo que zero

`woocommerce_order_hold_stock_minutes` é onde a loja diz **quanto tempo** guardar: `não reservar` é
zero (que a WooCommerce lê como «não reservar de todo»), `reservar por X horas` são as horas
escolhidas, e `reservar até decisão` é o relógio da própria automação — 72 horas em vez da hora da
plataforma, que é a diferença entre guardar uma unidade para uma decisão e perdê-la para o cliente
seguinte enquanto a decisão é tomada.

Um pedido que **nenhuma automação levou** fica com o número da plataforma. O spike apanhou isto: a
primeira versão devolvia zero para um pedido sem automação, e zero naquele filtro **desliga a reserva
que a WooCommerce faz para todos os pedidos normais da loja** — uma regressão disfarçada de reserva.
Por isso `workflow_minutes()` devolve `null` (a loja não tem opinião) e `hold_minutes()` devolve zero
só quando a automação o diz.

### 2.3 Reservar uma vez, e a armadilha contornada

`reserve()` pergunta primeiro se o pedido já guarda o que precisa para o tempo que precisa
(`already_holds()`), com **um minuto de tolerância** — a reserva que o checkout tomou foi escrita
segundos antes com a mesma janela, e reescrevê-la com valores idênticos é a armadilha de 1.3. Quando
o pedido já guardava algo e a escrita ainda assim falha, tenta **uma segunda vez**: nessa altura as
linhas do pedido já foram apagadas pela plataforma, portanto a segunda tentativa são inserções puras
e não pode repetir a armadilha. Uma escassez verdadeira falha também a segunda vez, e é por isso que
a segunda tentativa **nunca inventa uma reserva**.

### 2.4 Libertar: a matriz, e a regra que nunca se quebra

| Transição | Quem liberta | Porquê |
|---|---|---|
| Reprovação (`reject`) | **esta loja** | a plataforma nunca ouviu falar desta decisão |
| Expiração (`expire`) | **esta loja** | idem, e é o relógio que decide |
| Cancelamento | a plataforma | `woocommerce_order_status_cancelled` |
| Pagamento, conclusão, processamento, `on-hold` | a plataforma | `woocommerce_payment_complete` e os estados |
| Lixo e eliminação | **esta loja** | a consulta da plataforma esconde a linha pelo *join*; a nossa conta todas as linhas vivas, portanto tem de as apagar |
| **Pedido pago** | **ninguém** | depois do pagamento a plataforma já converteu a reserva em redução real; tocar outra vez seria o movimento duplo que a §21 proíbe |

A aprovação **não toca na reserva**: quem a converte é o pagamento, e a Fase 12 já provou que a
conclusão passa por `payment_complete()`.

### 2.5 As estratégias passam a ser executáveis, e a terceira ganha o seu número

- **`Workflows::executable_strategies()` passa a estar escrito por extenso** em vez de derivado dos
  vocabulários. É o ponto: um vocabulário pode descrever uma estratégia que esta versão não executa, e
  então o ecrã não a pode oferecer e o validador tem de a recusar **por nome com a razão** (§30.1).
  Derivar um do outro tornaria essa recusa impossível de exprimir — e as Fases 10 e 13 levantaram cada
  uma a sua lista quando o serviço por trás chegou.
- **`WorkflowValidator` recusa `hours` sem número** (`workflow_inventory_hours_required`): sem ele a
  reserva seria de zero minutos, ou seja o contrário do que o comerciante escolheu, em silêncio.
- **O ecrã de automação passou a pedir o número.** «Reservar por X horas» existia no select e não
  tinha campo nenhum — um controlo que não pode funcionar (§30.1). O campo aparece com a estratégia, e
  o ecrã diz a mesma coisa que o validador antes de o comerciante carregar em salvar.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE13-stock-reservation-proof.php` (novo) | **30/0**, 4 notas: um pedido sem automação fica com a janela da plataforma e o serviço di-lo em vez de reservar zero em silêncio; a reserva dura as 72 horas da automação e as 6 horas escolhidas; `hours` sem número é recusada por nome sem substituir o que estava guardado; **o buraco medido** — com o filtro da loja removido a unidade parece disponível (`0`) e com ele não está (`1`), e a loja e a WooCommerce reportam o mesmo número; um segundo pedido é recusado **pela mesma instrução que escreve a reserva** (`woocommerce_product_not_enough_stock`), a unidade continua guardada uma só vez e a recusa fica na auditoria; o carrinho diz ao cliente que há `0` unidades; aprovar não toca no estoque nem na reserva; reprovar liberta e só depois a unidade volta a estar disponível; o relógio (`WorkflowScheduler::expire()`) expira e liberta no mesmo passo; o pagamento converte (estoque `1 → 0`, linha apagada) e a loja **recusa** libertar um pedido pago; correr a automação duas vezes reserva uma só, e voltar a pedir a mesma janela **confirma a linha em vez de a reescrever**, sem a perder; uma janela maior é aplicada à mesma linha; o lixo e a eliminação não deixam reserva órfã; e a prova não deixa reservas, produtos nem opções atrás |
| `tests/Unit/Domain/Workflow/WorkflowDefinitionTest.php` | a regra nova afirmada com e sem número e para `until_decision`; e **todas** as estratégias dos dois vocabulários passam a ser executáveis, com o seam (`can_execute` falso) afirmado à parte |
| `tests/js/screens/WorkflowsScreen.test.js` | o select de estoque oferece as três estratégias, o campo das horas aparece só com `hours`, e uma estratégia que a loja não executa continua ausente do select **e nomeada** |
| `tests/js/schema/workflows.test.js` | `workflowIssues` diz que a reserva por horas precisa do número |
| `tests/browser/fase10-workflows.mjs` | **16/0**: o select de estoque oferece as três, nada é retido (a nota do que falta já não é desenhada), o campo das horas não existe antes da estratégia e existe depois, e o que o comerciante escolheu — estratégia e número — é o que a loja guarda |
| `composer check` | phpcs e phpstan sem erros; **619 testes, 2148 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**813 testes**, 51 suites); build compila |
| Varredura de integração | **78 harnesses, 1826 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **Duas passagens verdadeiramente simultâneas precisam de dois processos.** O que a prova estabelece
  é que a recusa vem da **mesma instrução que escreve** — o `INSERT … SELECT … FOR UPDATE … LOCK IN
  SHARE MODE` — e é isso que torna a simultaneidade segura, não uma verificação feita antes. Fica
  registado como o que foi provado e o que não foi.
- **A diferença deliberada de semântica, para os estados que a plataforma considera mortos sem apagar
  a linha.** Para `failed` e `refunded` a WooCommerce deixa de contar a reserva mas não apaga a linha;
  esta loja continua a contar até a janela da automação expirar. É escolha, e é a mais segura: guardar
  uma unidade um pouco mais de tempo é menos mau do que vendê-la duas vezes, e a janela é limitada.
  Lixo e eliminação são a exceção, porque aí não há pedido nenhum a guardar a unidade e a linha tem de
  desaparecer — é o que a fase faz.
- **A armadilha do `ON DUPLICATE KEY UPDATE` é da plataforma, não desta loja.** A fase contorna-a e
  deixa-a dita aqui e na nota do harness; não a corrige, porque corrigi-la seria escrever a reserva
  por SQL próprio, e a §21 diz exatamente para não o fazer.
- **A reserva não aparece no ecrã do pedido.** `status()` existe e é o que um painel de diagnóstico
  pode ler, mas o ecrã do pedido ainda não mostra «unidades guardadas». É polimento de ecrã e pertence
  à fase de apresentação, não a esta.
- **`authorize_now` continua a correr a captura na aprovação** (registado na Fase 12) e nada disto
  muda essa decisão.
