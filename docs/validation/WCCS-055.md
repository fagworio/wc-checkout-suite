# Registro de validação — WCCS-055

**Tarefa:** WCCS-055 · "Implementar exportação/eliminação de dados"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Fluxos por titular e política da loja testados, com retenção explicável."

**Resultado:** **392 testes unitários PHP** · **1199 asserções de integração** em 50 provas, 0 falhas (24 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Privacy/DataSubjectOrders.php` | Quais pedidos são de uma pessoa |
| `src/Privacy/DataSubjectCustomer.php` | Qual conta é daquela pessoa (valores guardados em Minha Conta) |
| `src/Privacy/OrderFieldsExporter.php` | "O que guardam sobre mim" |
| `src/Privacy/OrderFieldsEraser.php` | "Esqueçam-me", e o que fica com motivo |
| `src/Privacy/PrivacyPolicy.php` | O texto da política, gerado do vocabulário |
| `tests/Integration/F10-wccs-055-privacy-flows-proof.php` | Os dois fluxos e a política |

## 3. Os fluxos estão onde a pessoa os procura

O exportador e o eliminador são registados nos filtros **do próprio WordPress** (`wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`), e a prova **passa por esses filtros** — um exportador registado num filtro que ninguém lê é um exportador que não existe para quem pede. A loja continua a usar as ferramentas que já tem; este plugin passa a responder por elas.

## 4. O que é dado pessoal é decidido pelo vocabulário, e uma só vez

A regra não é uma lista escrita aqui: é a **sensibilidade de armazenamento** que o comerciante configurou.

- `personal` — *"identifica uma pessoa"* → é dado da pessoa;
- `sensitive` — *"um documento ou outro dado que precisa de cuidado extra"* → também;
- `public` — *"não identifica ninguém sozinho"* → **não é dado de ninguém pedir**, e fica fora do export.

E **os dois fluxos leem a mesma decisão** (`personal_fields()`), porque uma pessoa a quem se diz que os seus dados são uma coisa quando pede uma cópia e outra quando pede que desapareçam foi informada de duas coisas diferentes pela mesma loja.

## 5. Quais pedidos são daquela pessoa — e qual conta

O caso que uma busca por endereço deixa passar: um cliente que fez um pedido como visitante com um endereço **e** outro com sessão iniciada com outro endereço de faturação. `DataSubjectOrders` procura **pelos dois caminhos** — `billing_email` e `customer_id` — e a prova constrói exatamente esse caso e afirma que o segundo pedido **também** foi apagado. Um instrumento de privacidade que lesse a tabela errada (com o HPOS autoritativo) diria à pessoa que os seus dados não existem, e isso é pior do que os contar duas vezes.

**E um valor que não tem pedido nenhum.** Um campo preenchido numa página de Minha Conta pertence ao cliente e vive na conta, não em pedido algum: uma busca por pedidos, por mais completa que seja, nunca o encontra. O export passou a incluir o grupo «Checkout fields (customer account)» — com a mesma definição de dado pessoal e o mesmo formatador dos pedidos — e o apagamento remove os valores pessoais da conta, preservando os que o vocabulário não chama pessoais. A prova cobre os dois fluxos e afirma que o valor existe antes, desaparece da conta depois, e que o valor não pessoal fica.

## 6. "Retenção explicável" é a mensagem, não uma promessa

O eliminador devolve `items_retained` com o motivo escrito, e a prova lê as mensagens:

```
3 checkout values were erased from 2 orders.
The orders themselves are kept: they are the store's record of the purchase, and section 14
of the planning states that a refund or a cancellation does not automatically delete what
the history needs. The store decides when an order is deleted.
A document attached to order X is kept while that order exists and is removed with it by
the store's own upload retention; ...
```

Três decisões, e cada uma é um sítio onde um plugin costuma ser demasiado zeloso ou demasiado silencioso:

1. **Apaga os valores e não o pedido** — o pedido é o registo da venda (totais, imposto, referência do gateway), e a secção 14 di-lo para o caso vizinho: *"reembolso/cancelamento não deve apagar automaticamente dados necessários ao histórico"*.
2. **Diz o que ficou e porquê** — um fluxo que guarda algo em silêncio é um fluxo que ninguém consegue explicar a quem perguntou.
3. **Um documento é nomeado, não apagado daqui** — o ficheiro de um upload preso a um pedido existente faz parte do registo enquanto o pedido existir, e a retenção da loja (WCCS-045) remove-o com o pedido. A decisão fica onde foi tomada.

E o fluxo é **repetível**: a segunda execução não encontra nada para remover, que é o que uma pessoa que repete a ferramenta precisa.

## 7. A política é gerada, e por isso não pode divergir

`wp_add_privacy_policy_content()` recebe um texto construído a partir de:

- o **vocabulário de sensibilidade** (o que cada tipo de valor significa);
- o **vocabulário de visibilidade** (onde cada valor aparece);
- as **constantes que a retenção usa** — o TTL de um upload temporário e o limite de tamanho, lidos de `UploadService::TTL` e `UploadRules::DEFAULT_MAX_BYTES`, para que o número na política seja o número que o trabalho usa.

Uma política escrita à mão é o documento que ninguém atualiza: divergiria do código na primeira vez que uma lista aqui ou um significado ali mudasse — e divergiria na direção que interessa, porque a política é o que a pessoa lê.

O último parágrafo é deliberado: diz que **não é aconselhamento jurídico** e não reivindica conformidade com lei nenhuma — a secção 14 proíbe *"uma promessa genérica de conformidade"*. Há uma asserção que recusa as frases "gdpr compliant" e "lgpd compliant".

## 8. O que NÃO foi provado, e porquê

**Um pedido a partir dos ecrãs da loja.** Os callbacks são alcançados **pelos filtros que o WordPress lê**, que é o mesmo caminho que as ferramentas tomam — mas os ecrãs de privacidade, o e-mail de confirmação e o ficheiro exportado não foram exercitados. É a auditoria com que a fase fecha.

**O documento em si.** O export diz que um documento foi entregue e não entrega o token; obter uma cópia passa pelo pedido à loja. Se a pessoa tem direito ao **ficheiro**, e por que via, é uma decisão de política da loja que continua em aberto — nomeada, não resolvida por omissão.

**A retenção do próprio pedido.** Quanto tempo um pedido efetivo é guardado é uma decisão que não existe como configuração (fica registado desde a WCCS-045); a política di-lo com essas palavras.

## 9. Fecho

Cinco das oito tarefas da F10 estão feitas. Esta é a primeira que trata o dado pessoal como algo que a pessoa pode **pedir e retirar**, e não apenas como algo que se mostra ou não.

**Próxima tarefa:** **WCCS-056**.
