# Fase 5 — Perfil do cliente (equipa)

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §10, §27 · **Fase 5**
**Gate:** «editar perfil não altera pedido antigo.»

## 1. O que já existia

- **O destino `admin_customer_profile`** (Fase 2), com `customer_account` a ser o outro lado da
  mesma moeda: um painel para a equipa no perfil do cliente, uma página para o cliente.
- **O painel** (`Admin\Customers\CustomerProfilePanel`): as secções oferecidas a
  `admin_customer_profile` desenham-se no ecrã do perfil, cada campo sob o título e na ordem que o
  **uso** configurou, com um campo vinculado a outro destino fora do painel e um uso `view` a
  desenhar valor sem controlo. A submissão valida com o mesmo processador do checkout e escreve no
  armazém do cliente — **um valor, duas superfícies**.
- **A migração da chave retirada**: um documento que ainda diga `customer_profile` é recusado pelo
  nome (`ambiguous_destination`), porque essa chave significava duas superfícies ao mesmo tempo, e o
  editor pergunta qual delas antes de reescrever.

Tudo isto está provado em `tests/Integration/CUSTOMER-admin-profile-panel-proof.php`, que era já o
harness da fase.

## 2. O que esta fatia acrescenta — o fluxo do valor, direção a direção

§10.4 lista as direções separadamente e diz para **não assumir sincronização bidirecional**. Faltava
o que faz o valor viajar até ao checkout — não existia *nenhum* prefill no código.

- **O modelo**: a definição ganhou `sync` (`to_checkout`, `from_checkout`), com
  `prefills_checkout()` e `writes_back_to_customer()`. Um documento escrito antes desta chave
  comporta-se exactamente como antes: as duas direções ficam desligadas.
- **A terceira direção já existia e não ganhou interruptor.** «Minha Conta ↔ perfil» não é uma chave
  do campo: as duas superfícies lêem e escrevem **um** valor guardado, portanto quem pode escrever é
  decisão de cada **uso** — a editabilidade do vínculo. Um uso só-de-leitura é uma superfície que não
  escreve. Inventar um segundo interruptor para isto seria uma segunda resposta à mesma pergunta.
- **O prefill** (`ClassicCheckout::prefill()` + `ClassicAdapter`): o checkout do Classic começa com o
  valor que o cliente já tem no perfil, e **só** para os campos que o pediram. O adaptador continua a
  ser uma função pura sobre o documento — quem sabe quem está a comprar é o *caller*, que resolve os
  valores e os passa; um visitante sem conta não tem perfil para ler e não recebe nada. O valor lido é
  o **atual** do cliente, nunca o snapshot de um pedido, que pertence ao pedido que o tirou.
- **A validação**: um fluxo exige recolha no checkout (`sync_requires_checkout_collection`) e um
  valor para levar (`sync_requires_value`); e a direção que o runtime **ainda não desempenha** é
  recusada (`sync_direction_not_available`) em vez de aceite e ignorada — aceitá-la guardaria uma
  promessa que nada cumpre, e o lojista não teria como distinguir um fluxo que ninguém usa de um
  fluxo que nunca corre.
- **O editor**: um bloco «Fluxo do valor» na aba Vínculos, com o interruptor **Perfil → checkout** e
  a frase que diz onde se decide a outra direção. O interruptor da direção não implementada **não é
  desenhado**: um controlo que não faz nada é o que §30.1 proíbe.

## 3. Prova

| Prova | Resultado |
|---|---|
| `FieldDefinitionTest` (2 testes novos) | os fluxos estão desligados até serem pedidos, e a chave é escrita para o documento dizer o que significa; um fluxo guardado volta igual, e uma direção não implica a outra |
| `DefinitionValidatorTest` (4 testes novos) | um fluxo precisa da recolha no checkout; precisa de um valor para levar; a direção desempenhada é aceite; a que o runtime não desempenha é recusada pelo nome |
| `ClassicAdapterTest` (4 testes novos) | um campo que pediu o fluxo começa com o valor do cliente; um que não pediu mantém o default do seu tipo; um que pediu e não encontrou nada cai no default; e prefills não implica escrever de volta |
| `FieldProperties.test.js` (2 testes novos) | o inspector decide o fluxo e mostra o que o documento guarda |
| `CUSTOMER-admin-profile-panel-proof.php` | **30/0**, com duas secções novas: **§10.6** (o campo que pediu o fluxo começa com o valor do cliente; o que não pediu não; escrever de volta não prefill; um visitante sem conta não tem de onde preencher) e **o gate** — depois de a equipa alterar o valor atual do cliente, **o pedido já feito continua a dizer o que foi feito com ele** (`before=11.111.111/0001-11 after=11.111.111/0001-11`), e o cliente passou a ter o valor novo |
| `composer check` | phpcs e phpstan sem erros; **527 testes, 1893 asserções** |
| Varredura de integração | **70 harnesses, 1599 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**690 testes**) |

## 4. Limites que ficam registados

- **`checkout → perfil` está no modelo e é recusado na validação.** Escrever de volta para o perfil o
  que o cliente escreveu no checkout é a fatia seguinte — precisa de um gancho no pedido criado, dos
  dois checkouts, e da decisão do que acontece quando o valor falha a validação do perfil. Até lá o
  documento não pode prometê-lo, e o editor não o oferece.
- **O prefill é do checkout Classic.** É onde o `default` de `woocommerce_checkout_fields` é o
  mecanismo. No Blocks, a API de campos adicionais que a loja corre não tem um default documentado
  para um campo nativo, e prefill ali precisa dos dados de cliente no Store API — fica registado.
  Como esta loja corre Blocks, o caminho provado é o do adaptador Classic e o do `prefill()` lido do
  armazém do cliente, ambos exercidos no harness.
- **Sincronizar com Minha Conta não tem interruptor próprio, por desenho.** É a editabilidade de cada
  uso. Um lojista que queira o painel só-de-leitura para a equipa desliga o uso em
  `admin_customer_profile`; um que queira o valor a chegar ao checkout liga o fluxo aqui. Duas
  perguntas, dois sítios, e nenhuma resposta escondida numa terceira.
- **A privacidade do valor continua a ser a do campo** (`storage.sensitivity` e a política de valor
  oculto), e o painel nunca cria um segundo armazém: o que a equipa escreve é o que o cliente lê.
