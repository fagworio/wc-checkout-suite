# Matriz funcional — o que passou, o que falhou e o que não foi testado

**Artefato da tarefa WCCS-062** (F12) · **Data:** 11/09/2026
**Referência no planejamento:** `ROADMAP.md` §20 e a linha "Critério de teste" (`§` matriz de compatibilidade)

> O critério do próprio planejamento é este: *"relatório informa o que passou, falhou e não foi testado.
> Código compilado, badge HPOS ou ausência de erro no console **não** constituem homologação de pagamento."*
>
> A matriz abaixo é publicada com três estados e nunca dois. **Não testado** é o estado mais frequente, e
> isso é o resultado honesto: 54 provas de integração exercitam as costuras deste plugin contra o
> WooCommerce instalado, e **nenhuma delas é um cliente num browser**. Onde uma linha está marcada como
> coberta, o nome da prova é a evidência; onde não está, o motivo é a configuração que falta no ambiente.

## 1. Checkout × armazenamento de pedidos

| linha | estado | evidência ou motivo |
|---|---|---|
| Clássico, pedido criado e valores persistidos | **coberto** | `F04-wccs-023-order-fields-proof` |
| HPOS autoritativo, leitura e escrita pelo CRUD | **coberto** | `F04-wccs-023`, e o painel do staff escreve só por `WC_Order` (`F10-wccs-051`) |
| HPOS **desligado** com sincronização desligada | **não testado** | mudar o armazenamento de pedidos é configuração da loja, fora da raiz do plugin; é o que a WCCS-062 nomeia e este ambiente não faz |
| Armazenamento antigo (posts) a ler um pedido já gravado | **não testado** | precisa da alternância acima |
| Migração de um backend para o outro | **não testado** | idem; a ferramenta é do WooCommerce e não deste plugin |

## 2. Checkout × superfície

| linha | estado | evidência |
|---|---|---|
| Clássico: campos, máscaras, condições, validação, uploads, apresentação | **coberto nas costuras** | `F04-021…025`, `F05-026…030`, `F06-031…035`, `F09-046`, `F09-048` |
| Blocks: registo nativo, componentes controlados, Store API, payload | **coberto nas costuras** | `F07-036…040`, `F09-047` |
| Ambos: a mesma validação nos dois | **coberto** | um pipeline (`WCCS-051` compara as duas respostas do mesmo código) |
| **Um cliente a preencher um formulário em qualquer dos dois** | **não testado** | nenhum browser foi aberto em nenhuma tarefa; convergem aqui todas as lacunas registadas de F04 a F11 |

## 3. Tipos de carrinho e cliente

| linha | estado | motivo |
|---|---|---|
| Virtual, físico, misto | **não testado** | esta loja **não tem produtos**; é o mesmo bloqueio que impediu o `POST /wc/store/v1/checkout` na F07 |
| Visitante, cliente com conta | **parcial** | o fluxo de privacidade e o painel do pedido exercitam ambos os casos (`F10-051`, `F10-055`); um checkout não |
| Endereço de entrega separado, países, cupons, impostos, moeda | **não testado** | precisa de produtos e de um carrinho real |
| Frete e métodos de envio | **não testado** | idem |

## 4. Gateways, por versão

| gateway | versão instalada | estado |
|---|---|---|
| PayPal (`woocommerce-paypal-payments`) | 4.1.3 | **não testado** — desabilitado, sem credenciais |
| Mercado Pago (4 gateways) | 8.9.3 | **não testado** — desabilitados, sem credenciais |
| Transferência, cheque, pagamento na entrega | núcleo | **não testado** — desabilitados |

**Nenhuma linha de pagamento foi homologada**, e a matriz de homologação (`resources/payments/homologation.json`)
está vazia por isso: os sete gateways respondem `undecided`, que é a diferença entre "testámos" e "não olhámos".
O bloqueador é `SANDBOX-PAYMENT`, registado desde a WCCS-004.

## 5. O que fecha as linhas em falta

1. **Produtos na loja** — desbloqueia §3 e o percurso ponta-a-ponta de um pedido real (F12/WCCS-065, F13/WCCS-069).
2. **Um gateway em sandbox com credenciais** — desbloqueia §4 e a cláusula de pagamento do gate da F09 e da F12.
3. **Um browser** — desbloqueia §2 e é a WCCS-063, que é a mesma tarefa para todas as fases anteriores.
4. **Alternar o armazenamento de pedidos** — desbloqueia §1, e é configuração da loja.

Nenhuma delas é implementação: são quatro ações externas, e cada uma está registada como bloqueador ou
como decisão aberta em `docs/compatibility.json`.
