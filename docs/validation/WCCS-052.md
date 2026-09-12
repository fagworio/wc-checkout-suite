# Registro de validação — WCCS-052

**Tarefa:** WCCS-052 · "Criar exibição ao cliente"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Visibilidade por campo respeitada; conta atual não reescreve pedido passado."

**Resultado:** **392 testes unitários PHP** · **1132 asserções de integração** em 47 provas, 0 falhas (19 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/CustomerOrderFields.php` | O painel do cliente, e a política que o limita |
| `src/Plugin.php` | Um hook: o que as duas superfícies já disparam |
| `tests/Integration/F10-wccs-052-customer-view-proof.php` | As duas cláusulas, afirmadas pelo que **não** aparece |

## 3. Um hook, e por que é o hook do WooCommerce

Os componentes desta tarefa são **Minha Conta** e **thank-you**, e a leitura do WooCommerce instalado mostrou que são a **mesma** superfície de renderização:

```
woocommerce/includes/wc-template-hooks.php:275  add_action( 'woocommerce_view_order',  'woocommerce_order_details_table', 10 );
woocommerce/includes/wc-template-hooks.php:276  add_action( 'woocommerce_thankyou',    'woocommerce_order_details_table', 10 );
```

e esse renderizador carrega `templates/order/order-details.php`, que dispara `woocommerce_order_details_after_order_table` (linha 140). Um só hook cobre as duas páginas — e as duas afirmações que o dizem são lidas **dos ficheiros instalados**, não presumidas.

**O plugin não regista rota, página nem shortcode.** O hook só dispara onde a loja já decidiu que este pedido pode ser visto por esta pessoa: a página de agradecimento com uma chave de pedido válida, ou a conta que é dona do pedido. Uma segunda verificação de acesso seria redundante — e, se alguma vez fosse a única, uma falsa sensação de segurança. O que este plugin acrescenta é a **política por campo** por cima da decisão da loja.

## 4. "Visibilidade por campo respeitada" — três formas de não mostrar

1. **O campo não declara `customer_order`.** Um campo mostrado só ao staff, ou só nos e-mails, não aparece — afirmado com os três publicados ao mesmo tempo.
2. **O comerciante deixou de o mostrar ao cliente.** O mesmo campo, mesma definição, com a visibilidade retirada: desaparece.
3. **Já não há política para ler.** O campo foi arquivado ou apagado depois de o pedido ter sido feito; o pedido guarda o valor, e o valor **não é mostrado**. É a direção em que o erro tem de cair: o contrário mostra ao cliente um documento que o comerciante deixou de mostrar, e fá-lo com base num valor que por acaso está lá.

E **sem nada para mostrar, a página não diz nada** — nada de título com zero linhas, porque um cabeçalho vazio é a informação de que algo foi retido, que é precisamente o que não foi dado nem escolhido.

## 5. "Conta atual não reescreve pedido passado"

A secção 14 escreve a frase numa direção (*"editar perfil não reescreve pedido passado"*) e a exibição é a outra direção da mesma regra. A prova constrói o caso inteiro:

- o pedido capturou **"What the order captured."**;
- a conta do cliente tem hoje, **sob a mesma chave**, "What the account holds today.";
- a página mostra o primeiro e **não contém** o segundo;
- e um pedido que **não capturou nada** não mostra nada, mesmo com a conta a ter um valor.

Nenhum caminho desta classe lê o meta do cliente, a sessão ou o perfil para obter um **valor**: os valores vêm de `OrderFieldsService::history()`, que lê o payload que o pedido carrega, e a **label vem do que foi gravado com ele** — um campo renomeado depois não renomeia o que um pedido passado diz.

## 6. O que a página faz com o que mostra

- **O valor é escapado**, e há uma asserção que o prova com `<script>alert(1)</script> & "quotes"` guardado como valor: a página do cliente não é um sítio onde um valor guardado se torna marcador.
- **Um campo sem valor no pedido não é desenhado**: uma linha vazia é uma linha a dizer ao cliente que ele deixou algo em branco.
- **A label vem do pedido**, não de um título inventado por campo.

## 7. O que NÃO foi provado, e porquê

**Um browser.** O painel é afirmado **renderizando o callback do hook**, não abrindo uma página de agradecimento nem um pedido em Minha Conta. A colocação pelo tema, a aparência, e o percurso de agradecimento **sem sessão iniciada** (em que o acesso é provado pela chave do pedido no URL) são a WCCS-063 e a WCCS-062.

**A auditoria de exposição da fase.** Esta tarefa fecha a política de **um** campo num **par** de superfícies. O gate da F10 pede a auditoria por papel, endpoint e e-mail — e os endpoints e os e-mails ainda não existem (WCCS-056 a WCCS-058).

**A exibição do valor formatado por tipo.** Datas, escolhas e listas são hoje impressas como texto simples; um formatador por tipo é uma decisão que a F10 ainda não tomou e que fica nomeada, em vez de improvisada aqui.

## 8. Fecho

A F10 tem oito tarefas; duas estão feitas (o editor do staff e a exibição ao cliente) e ambas fecham a mesma superfície a partir de lados opostos. O que resta — e-mails, APIs e a auditoria — é onde a exposição passa a ter mais do que duas superfícies.

**Próxima tarefa:** **WCCS-053**, os e-mails HTML e texto puro.
