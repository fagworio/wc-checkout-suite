# Registro de validação — WCCS-062

**Tarefa:** WCCS-062 · "Executar matriz funcional"
**Fase:** F12 · Hardening, acessibilidade e matriz final
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ⚠️ Status: PARCIALMENTE CUMPRIDA — bloqueada

O bloqueio é externo e está registado: `SANDBOX-PAYMENT`. A tarefa não fica por fazer; fica por
**poder** fazer-se nesta loja.

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Classic/Blocks/HPOS, tipos de carrinho, clientes e gateways registrados por versão."

## 2. O que foi entregue

`docs/operations/functional-matrix.md` — a matriz publicada, com **três estados e nunca dois**:
executado, falhado e **não testado**, sendo "não testado" o mais frequente. Isso não é uma lacuna do
documento, é o resultado: a matriz diz o que se sabe, e o que não se sabe aparece nomeado em vez de
ficar em branco.

A forma da matriz foi decidida pelo próprio critério da tarefa, que acrescenta uma regra explícita
(ROADMAP §24): **código compilado, badge HPOS ou ausência de erro no console não constituem homologação
de pagamento.** Uma matriz que pintasse de verde tudo o que compila seria uma matriz que mente.

## 3. O que está coberto

| grupo | cobertura |
|---|---|
| Classic | coberto **na costura** (adaptador, validação, persistência, projeções) e pela suíte unitária; não observado num browser, porque esta loja não serve o checkout clássico (`CLASSIC-TEST-SURFACE`) |
| Blocks | coberto na costura e **observado num browser** (WCCS-063), incluindo entrega de payload, apresentação, tokens e acessibilidade |
| HPOS | coberto com HPOS autoritativo (leitura e escrita pela API de pedidos) |
| Tipos de carrinho | cobertos nos testes unitários e de integração; os cenários que precisam de produtos variados ficam limitados aos nove produtos publicados |
| Clientes | visitante e logado cobertos |
| Gateways | **nenhum** — ver §4 |

## 4. O que não foi testado, e por quê

1. **Todos os gateways.** Mercado Pago, PayPal Payments e os gateways offline estão ativos na loja e
   **nenhum tem credenciais de sandbox**. Nenhuma linha de pagamento foi executada: nem crédito
   aprovado/recusado, nem Pix, nem boleto, nem 3DS/redirect, nem duplicidade de submissão. O registo de
   homologação (`resources/payments/homologation.json`) está vazio, e por isso todos os gateways
   respondem `undecided` — e a apresentação personalizada **recusa assumir** o checkout enquanto houver
   um gateway não homologado, o que é o comportamento seguro: a loja segue com o checkout da WooCommerce,
   inteiro, com os campos deste plugin.
2. **HPOS desligado.** Alternar o armazenamento de pedidos da loja é uma configuração do site, fora da
   raiz deste plugin (registado na matriz e em `docs/compatibility.json`).
3. **Uma segunda loja**, para o exercício de staging que o critério implica.
4. **O checkout clássico num browser**, pela ausência da página (`CLASSIC-TEST-SURFACE`).

## 5. O que fecharia a tarefa

Uma credencial de sandbox por gateway, um toggle de HPOS e uma segunda instalação. Nenhuma das três é
uma alteração que este plugin possa fazer à loja, e as três estão registadas como bloqueio ou como
decisão aberta — não descobertas aqui. Com WCCS-063, 064 e 065 executadas, a F12 tem quatro das cinco
tarefas cumpridas e esta é a única que depende de fora.

## 6. Fecho

Uma matriz com "não testado" em quase todas as linhas de pagamento é menos agradável de ler do que uma
matriz verde, e é a única versão desta matriz que é verdadeira. É essa que fica publicada.
