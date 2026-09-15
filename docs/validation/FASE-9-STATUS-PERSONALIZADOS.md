# Fase 9 — Status personalizados

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §3.8, §4, §12, §27 · **Fase 9**
**Gate:** «status não altera pagamento sem workflow.»

## 1. O que a fase encontrou

Havia **um** estado próprio, e só quando um fluxo de aprovação o pedia. `ApprovalFlow::status()`
derivava-o de `md5('wccs-approval-' . $field_id)`: um identificador opaco, por campo, sem nome para o
cliente, sem cor, sem lista e sem ecrã. `ReviewStatus` registava-o diretamente com
`register_post_status`, e nada mais existia.

Três consequências, todas do mesmo tipo — uma decisão que não estava escrita em lado nenhum:

- **Nada impedia um estado de ser um comando.** Não havia campo de pagamento, mas também não havia
  regra que o proibisse: o próximo a acrescentar `payment_strategy` a um estado fá-lo-ia sem
  encontrar resistência. A §12.4 pede o contrário, por escrito.
- **A §12.5 não estava garantida.** `Análise pendente` devia permanecer **não pago**; como nenhum
  estado era acrescentado à lista de pagos, isso era verdade por acidente e não por regra.
- **Renomear não tinha onde acontecer.** Um estado sem nome editável não pode ser renomeado sem
  tocar no documento — e §12.7 passo 8 exige que renomear deixe os pedidos no mesmo estado interno.

## 2. O que foi mudado

### 2.1 O modelo, e a ausência que o define

- **`Domain\Statuses\OrderStatus`** — nome, slug interno permanente, cor, ativo, exibir na área do
  cliente, exibir em e-mails, permitir ações manuais, estado antes do pagamento, label para o cliente
  e descrição. **Nada que um gateway leia**, e um teste unitário afirma a lista de chaves inteira:
  acrescentar um campo de pagamento ao modelo falha onde a razão está escrita.
- **O identificador é permanente e não é o label** (§12.6). É atribuído **uma vez** — a partir do
  nome, quando o estado é criado, e a partir do fluxo, quando é migrado — e renomear muda o nome e
  mais nada. `unique_id()` recusa-se a usar `remove_accents()` (que só existe em PHP) e traz a sua
  própria tabela: a regra de identidade tem de dar a mesma resposta nos dois motores, e um
  identificador proposto pelo browser e outro guardado pelo servidor seria um estado com duas chaves.
- **O ecrã não propõe identificadores.** Envia o nome com `id` vazio e é o servidor que os atribui
  (`OrderStatusRepository::identify()`): a tabela de acentos, o comprimento que cabe ao lado de `wc-`
  e a numeração de uma colisão vivem num sítio só.

### 2.2 A validação e o repositório

- **`StatusValidator`** — identificador obrigatório, único, no formato de chave, com o comprimento
  que cabe no `post_status` (17 caracteres, porque o prefixo é `wc-`), e nunca um dos estados que a
  WooCommerce tem; nome obrigatório; cor hexadecimal de seis dígitos. Um estado que sombreasse
  `processing` seria um estado a tomar o lugar de um que a plataforma decide.
- **`OrderStatusRepository`** — uma **opção** e não o documento da schema, e a razão está escrita:
  um estado não é composição do checkout, é um estado em que um pedido entra depois dele, pertence ao
  vocabulário do workflow (§3.8) e tem ecrã próprio (§12, «Status e automações» na §4). E é durável
  de propósito: um pedido registado num estado continua num estado registado mesmo que o fluxo que o
  nomeou seja desligado, e restaurar uma revisão anterior da schema não pode apagar os estados em que
  os pedidos estão.
- **`ensure()`** é a migração do estado de revisão: acrescenta os estados que os fluxos pedem **com o
  identificador que já têm**, para que um pedido existente fique no mesmo estado interno — e só
  acrescenta: um estado que o comerciante renomeou, pintou ou apagou fica como está.

### 2.3 O registo, e o guarda do pagamento

- **`OrderStatusRegistry`** — regista com a WooCommerce apenas os estados **ativos** (§12.6): um
  estado inativo não é um estado com um nome que ninguém vê, é um estado que não existe, e um pedido
  não pode ser registado num estado que a loja não tem. O `wc_order_statuses` é substituído em bloco,
  para que desativar um estado o tire da lista em vez de deixar o antigo lá.
- **O guarda** — `guard_paid_statuses()` corre em `woocommerce_order_is_paid_statuses` e **só
  retira**: um estado que se declara antes do pagamento é removido da lista de pagos mesmo que outra
  extensão o acrescente. §12.5 deixa de ser um hábito e passa a ser uma propriedade que a loja mantém.
- **`ReviewStatus`** deixou de registar: migra os seus estados e o registo passa a ser um só caminho.
  Os hooks de retenção continuam exatamente onde estavam.

### 2.4 O ecrã

- **`Http\Admin\StatusController`** — `GET /statuses` (o inventário, com o que a WooCommerce considera
  pago) e `POST /statuses` (a lista inteira). Uma lista e não um estado de cada vez, porque um estado
  é uma linha de uma lista e não uma entidade com endereço próprio: dois pedidos que mudassem uma
  linha cada seriam duas oportunidades de a lista ficar a meio. A resposta usa o mesmo validador, e a
  recusa viaja com os códigos que o ecrã mostra.
- **`schema/statuses.ts`** e **`StatusesScreen.js`** — a lista à esquerda (o que o comerciante
  configurou, e o que a WooCommerce tem, mostrado e não editável) e as configurações à direita. Onde
  o mockup desenha comportamento, pagamento e transições, o ecrã **diz qual é a fase que os traz**:
  uma coluna vazia deixaria o comerciante à procura de um controlo que não cobra nada, e §30.1 diz
  que um controlo que não pode funcionar não se desenha.
- **§4** — o `Status e automações` do menu passa a existir como secção `statuses`.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE9-order-statuses-proof.php` (novo) | **26/0**, 3 notas: o teste de utilizador da §12.7 passo a passo — criar `Análise pendente` com o identificador atribuído pelo servidor, mostrar ao cliente, guardar, criar um pedido, aplicá-lo, confirmar que o WooCommerce **não** o considera pago, renomear o label e confirmar que o pedido continua no mesmo estado interno — mais: a lista de pagos da WooCommerce é filtrada mesmo quando algo acrescenta o estado lá; um identificador reservado e um demasiado longo são recusados e a recusa não altera o guardado; um estado inativo não é registado e volta quando é ligado; e o estado de revisão anterior a este ecrã é migrado **com o id que os pedidos já têm**, renomeável sem os mover |
| `tests/Unit/Domain/Statuses/OrderStatusTest.php` (novo) | 12 testes, 41 asserções: identidade legível e estável, colisão numerada, comprimento que cabe, renomear sem mudar de estado, o label do cliente com recuo para o nome, **a lista de chaves inteira do modelo** (nenhum comando de pagamento), chaves desconhecidas preservadas, e as recusas do validador |
| `tests/js/schema/statuses.test.js` | 13 testes: separação entre o que é do comerciante e o que é da WooCommerce, criação, renomear sem mudar o id, remoção, os problemas que o ecrã vê, o payload com `id` vazio para um estado novo, e a ausência de qualquer campo de pagamento |
| `tests/js/screens/StatusesScreen.test.js` | 10 testes: a lista com as duas origens separadas, o aviso de que um estado não cobra nada, onde vive o comportamento/pagamento, o slug permanente e só de leitura, criar um estado sem identificador, recusar guardar sem nome, o payload enviado, a recusa do servidor mostrada, a lista de pagos, e a cor como escolha |
| `tests/browser/fase9-order-statuses.mjs` (novo) | **15/0** num browser real: o ecrã diz que um estado não cobra nada e que quem cobra é uma transição de workflow; a lista separa os estados do comerciante dos oito da WooCommerce; criar um estado diz que o identificador é criado ao guardar e recusa gravar sem nome; gravar atribui `analise_pendente`, regista-o, guarda o label do cliente e mostra que não está na lista de pagos; renomear muda o label e não o identificador; e o estado criado é removido outra vez, deixando a loja como estava |
| `composer check` | phpcs e phpstan sem erros; **583 testes, 2001 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**786 testes**, 49 suites); build compila |
| Varredura de integração | **74 harnesses, 1701 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **A coluna do meio do mockup é a Fase 10.** Comportamento do pedido, regras que aplicam o estado,
  transições automáticas e a prévia do fluxo pertencem ao **motor de workflow** (§3.8), onde a decisão
  de pagamento é uma propriedade de uma **transição** e não de um estado. O ecrã di-lo onde o
  comerciante os procuraria. Isto é o gate levado a sério: enquanto não houver workflow, não há nada
  que possa cobrar, e nada no ecrã sugere o contrário.
- **Os estados não viajam no ficheiro de transferência.** O envelope de exportação/importação leva o
  documento da schema, e os estados vivem numa opção própria. Uma loja que exporte a configuração e a
  importe noutra fica sem os estados — e com os fluxos que os nomeiam. Acrescentá-los ao envelope é
  uma decisão da fase do workflow, onde o que viaja passa a ser a automação inteira; fica nomeado em
  vez de silencioso.
- **O identificador tem 17 caracteres**, porque o `post_status` guarda vinte e a WooCommerce escreve
  `wc-` à frente. Um nome longo produz um slug truncado (`aguardando_aprova`), o que é visível no
  ecrã e reversível criando o estado com um nome mais curto.
- **Um estado é global à loja, não à loja no Blocks ou no clássico.** É um `post_status` da
  WooCommerce e não uma decisão de apresentação: quem o vê ou não vê é a `show_customer`, e não o
  checkout que a loja corre.
- **A ordem na lista é a ordem de gravação.** O mockup mostra um arrastar para reordenar; a lista é
  guardada na ordem em que o ecrã a mostra e o `wc_order_statuses` a respeita, mas o arrastar em si
  ainda não existe no ecrã — acrescentá-lo não muda o que o servidor guarda.
