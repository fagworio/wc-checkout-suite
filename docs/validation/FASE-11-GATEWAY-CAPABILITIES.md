# Fase 11 — Gateway Capability Registry

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §13.2, §13.7, §17, §20, §27 · **Fase 11**
**Gate:** «nenhuma action aparece na UI sem capability comprovada.»

## 1. O que a fase encontrou

Havia uma matriz de pagamento — `PaymentMatrix` + `resources/payments/homologation.json` — que
registra **apresentação**: se este plugin pode decorar a área de pagamento de um gateway, com que
modo e que cenários testados. Estava bem feita e fazia o que prometia.

O que não existia era a outra matriz. A §20 lista as ações transacionais (`authorize`, `capture`,
`void_authorization`, `refund`, `tokenized_payment`, e do lado do PIX/boleto
`create_after_approval`, `regenerate`, `expire`) e diz, três vezes, que elas dependem de
**capability homologada** — «Não exibir "capturar depois" quando gateway não suporta», «Somente
oferecer: Gerar PIX após aprovação se o gateway possuir integração homologada». Não havia onde essa
prova vivesse, e portanto não havia como uma ação deixar de aparecer por falta dela.

A §17 diz também o que **não** fazer, e é a razão de esta fase ser um objeto novo e não um campo
novo: «Manter para compatibilidade visual/homologação da apresentação. Não transformar esse objeto
em matriz de capture/authorize. Criar uma matriz separada de capacidades transacionais.»

## 2. O que foi mudado

### 2.1 A matriz transacional, separada

- **`GatewayCapabilities`** — o vocabulário fechado das ações, nas duas famílias da §20 (cartão e
  PIX/boleto), o vocabulário dos cenários (um por ação, cada um uma execução que alguém pode
  repetir), os dois modos de prova (`sandbox` e `live`) e os quatro campos que uma prova tem de
  trazer.
- **`GatewayCapability`** — uma ação **com a prova que a torna uma capability**: modo, versão,
  cenário e data. `missing()` diz o que falta, peça por peça; `is_proven()` é falso sem todas;
  `is_stale()` compara a versão da prova com a instalada, e uma versão instalada desconhecida **não**
  é staleness (uma loja que não sabe que versão corre não pode ser informada de que a sua prova é
  velha).
- **`GatewayCapabilityRegistry`** — lê `resources/payments/capabilities.json`, recolhe o que as
  extensões declaram pelo ponto de extensão e responde ao que um ecrã pergunta: `supports()`,
  `offerable()` e `fallback()`. Uma declaração sem prova é **recusada por nome**, não entra no
  registo, e é reportada — uma alegação recusada em silêncio é uma alegação que alguém volta a fazer.
- **O `pay_for_order` é o fallback, e não precisa de prova nenhuma.** Não é uma ação de um gateway: é
  a página «Pagar pedido» da própria WooCommerce, que existe para todo o pedido que ainda tem de ser
  pago. A §13.2 passo 4 nomeia-a como o que a loja faz quando o gateway não consegue gerar um PIX ou
  capturar depois, e modelá-la como uma capability que o gateway teria de provar seria modelar a
  plataforma como se fosse um plugin.

### 2.2 O que a fase recusa

O registo recusa, com o campo em falta nomeado: `mode`, `version`, `scenario`, `proven_at` — e uma
ação fora do vocabulário, um cenário que ninguém escreveu como se corre, ou um modo que o registo
não descreve. A consequência é o gate: como o ecrã lê a lista do registo, uma ação sem prova não tem
por onde aparecer. A interface não tem de se lembrar da regra, porque nunca vê a ação.

### 2.3 O ecrã

- **`SettingsController::state()`** passou a levar as duas matrizes no mesmo payload, de dois objetos:
  o `mode` de apresentação por gateway e as ações transacionais oferecíveis com a sua prova. É a
  separação visível em vez de só declarada.
- **O ecrã de diagnóstico** ganhou a tabela «What each gateway proved»: por gateway, as ações que
  podem ser oferecidas e a prova de cada uma; quando não há nenhuma, di-lo em palavras — «nenhuma ação
  comprovada; só o link «Pagar pedido»» — em vez de deixar uma célula vazia. E, quando há alegações
  recusadas, elas aparecem com a razão.

### 2.4 O que **não** mudou

As estratégias de pagamento do ecrã de workflow continuam a oferecer só `none`, e é deliberado: a
§13.2 passo 4 desenha-as por gateway e só onde a capability existe, e **executá-las** é a fase
seguinte. O que esta fase acrescenta é a razão precisa — o registo sabe, por gateway, o que está
provado e o que não está.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE11-gateway-capabilities-proof.php` (novo) | **21/0**, 3 notas, contra a loja real: o registo que ela traz não prova nada e portanto cada um dos seus **sete** gateways (ppcp, três do Mercado Pago, bacs, cheque, cod) oferece apenas `pay_for_order`; uma declaração a que falta qualquer parte da prova é recusada por nome, com as três peças em falta listadas e uma razão que diz o que isso significa; com prova, a capability passa a oferecível — para a versão provada e não para outra, onde é reportada como **desatualizada** em vez de apenas ausente; uma ação que ninguém declarou é reportada como não declarada com a lista inteira da prova em falta; o ponto de extensão `wccs_register_gateway_capabilities` funciona como um adapter o usa, e a alegação sem prova que ele faz é recusada; o fallback é sempre a página da plataforma; e o payload de diagnóstico leva as duas matrizes com vocabulários que não se cruzam |
| `tests/Unit/Domain/Payments/GatewayCapabilityRegistryTest.php` (novo) | 13 testes, 60 asserções: o vocabulário da §20 completo nas duas famílias, o fallback, cada peça da prova em falta nomeada, ação/cenário/modo desconhecidos, só uma capability provada ser oferecível, prova de outra versão, versão instalada desconhecida, o relatório a dizer **porque** uma ação não é oferecida, o registo que a loja traz não provar nada — e **as duas matrizes serem objetos diferentes**, com vocabulários disjuntos e ficheiros diferentes |
| `tests/js/screens/SettingsScreen.test.js` | o ecrã mostra o que cada gateway provou: a ação que ninguém provou não está na lista oferecível, o fallback está, e a regra é dita na página |
| `tests/browser/fase11-gateway-capabilities.mjs` (novo) | **8/0** num browser real: as duas matrizes no ecrã que as reporta, a de apresentação sobre modos e a transacional sobre ações com prova; os sete gateways da loja a oferecer só `pay_for_order` e nenhum a oferecer `authorize` ou `capture`; e a ausência explicada em palavras em vez de uma célula vazia |
| `composer check` | phpcs e phpstan sem erros; **610 testes, 2094 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos; build compila |
| Varredura de integração | **76 harnesses, 1764 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **Nada executa.** O registo diz o que um gateway provou; quem executa é o `PaymentActionService`
  da fase seguinte. `pay_for_order` é a única ação disponível hoje, e é a da própria WooCommerce.
- **O registo da loja está vazio, e isso é o gate a funcionar.** Nenhum gateway está ativo nesta
  loja e nenhum sandbox correu, portanto nada está provado e nenhuma ação pode aparecer. É o mesmo
  bloqueio que o registo de apresentação já nomeia (SANDBOX-PAYMENT): não há credenciais neste
  ambiente. Uma capability entra no ficheiro quando alguém a correr — e o ficheiro diz isso.
- **A versão de um gateway vem do objeto do gateway.** `isset( $gateway->version )` é o que a
  WooCommerce expõe; um gateway que não a declare faz com que a versão instalada seja desconhecida, e
  nesse caso a prova **não** é julgada desatualizada. É a resposta conservadora na direção certa:
  não se inventa um facto para recusar nem para aceitar.
- **Duas provas no mesmo registo não são comparadas.** Duas declarações da mesma ação para o mesmo
  gateway coexistem; `supports()` responde com a primeira que serve. Uma loja que declare duas vezes
  está a descrever duas execuções, e a última a ser lida é a que responde — fica registado em vez de
  recusado, porque recusar exigiria decidir qual das duas o comerciante queria.
