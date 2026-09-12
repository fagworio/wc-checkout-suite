# Registro de validação — WCCS-054

**Tarefa:** WCCS-054 · "Criar API pública de integração"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Contrato autenticado; nenhum dado pessoal aparece em Store API pública."

**Resultado:** **392 testes unitários PHP** · **1175 asserções de integração** em 49 provas, 0 falhas (23 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Http/Integration/OrderFieldsController.php` | O contrato: rotas tipadas, autenticadas e autorizadas por pedido |
| `src/Plugin.php` · `src/Admin/Routes.php` | Registo e composição das rotas |
| `tests/Integration/F10-wccs-054-integration-api-proof.php` | As duas cláusulas, com pedidos reais |

## 3. A frase que o contrato cumpre, cláusula a cláusula

> REST de integrações: **schema documentado**, **tipos**, **autenticação**, **permissão por pedido/campo** e **paginação onde couber**. **Não expor automaticamente todos os order metas.**

| cláusula | como está |
|---|---|
| schema documentado | cada rota declara os seus `args`, com descrição e tipo, no registo da rota |
| tipos | `page`/`per_page`/`status` são `integer`/`string` com mínimo e **máximo**; o `order_id` é inteiro e é validado antes de chegar a uma consulta |
| autenticação | é a do WordPress — cookie com nonce ou application password, ambas terminam num utilizador com sessão |
| permissão por pedido | `edit_shop_order` **para aquele pedido**; um utilizador que pode ler pedidos e não aquele é recusado |
| permissão por campo | só viaja o campo que declara `public_api`, e o vocabulário **omite-o por defeito** |
| paginação | página e tamanho limitados, com `X-WP-Total` e `X-WP-TotalPages`; pedir 100000 devolve o máximo |
| nunca todos os metas | a resposta é construída a partir dos campos publicados e do payload do pedido — **não enumera meta**, não inclui chaves de meta, e nenhum `_wccs_` aparece |

## 4. Os pedidos são reais

As autorizações são exercidas **contra o servidor REST**, com utilizadores a sério — não como valores de retorno de uma função:

| quem | resposta |
|---|---|
| anónimo | **401** `wccs_unauthenticated` |
| cliente autenticado, sem capability | **403** `wccs_forbidden` — *autenticação não é autorização* |
| utilizador com `manage_woocommerce` e sem este pedido | **403** `wccs_order_not_allowed` |
| um id que não existe | **403** `wccs_order_not_allowed` — **o mesmo par** |

A ação de ficheiro é o que importa aqui: `rest_do_request()` devolve a recusa **como resposta**, e a primeira versão do harness esperava um `WP_Error` e morreu em silêncio (a quinta vez nesta sessão). As asserções passaram a ler o estado e o código, que é o que o servidor realmente devolve.

## 5. O defeito que a prova apanhou — e que era meu

A primeira versão devolvia `wccs_unknown_order` para um id inexistente e `wccs_forbidden_order` para um pedido alheio: **dois códigos diferentes**, apesar de o próprio docblock da classe prometer o contrário. Qualquer pessoa que possa ler pedidos podia contar os pedidos da loja pedindo identificadores e a ver qual das duas respostas voltava.

A correção é uma só recusa (`wccs_order_not_allowed`) para os dois casos, decidida antes de o pedido existir sequer — e a asserção que a apanhou é a que exige que as duas respostas sejam **iguais**. O compromisso fica escrito: quem pede um pedido apagado recebe "não lhe é permitido" em vez de "não existe", e é isso que impede a contagem.

## 6. "Nenhum dado pessoal em Store API pública"

Duas metades, e a segunda é a que o critério pede:

1. **O plugin não acrescenta nenhuma rota à superfície pública.** As rotas de integração vivem no `wc-checkoutsuite/v1`, e há uma asserção que procura por qualquer rota do plugin dentro de `/wc/store/`.
2. **Os valores não aparecem na resposta pública.** A prova cria um pedido que carrega um valor exposto e um privado, pede-o através da **rota do Store API instalado** (`/wc/store/v1/order/…` com a chave do pedido, como um visitante faria) e afirma que o corpo **não contém** o id de nenhum campo, nenhuma chave `_wccs_`, e **nenhum dos dois valores**.

E há uma asserção que impede a segunda de ser vazia: o Store API **responde**, com bytes no corpo — se ele tivesse recusado, "não contém" seria uma afirmação sobre nada. É o mesmo guarda que a WCCS-051 aprendeu a exigir.

A única extensão que o plugin faz ao Store API é a que a WCCS-038 registou: o **contrato de tipos** contra o qual o checkout de blocos valida — uma forma, não um valor — e a prova lê o ficheiro para o afirmar.

## 7. O que NÃO foi provado, e porquê

**Um cliente externo.** Os pedidos passam pelo servidor REST **no mesmo processo**, portanto a autenticação exercida é o modelo de autorização do WordPress e não uma application password a sério, por rede, com os seus próprios modos de falha.

**A matriz completa de papéis por endpoint.** É exatamente a auditoria com que a fase fecha, e continua a precisar dos webhooks (WCCS-057) e da tarefa de privacidade (WCCS-058).

**Um contrato versionado e publicado.** Os `args` estão declarados e são legíveis no servidor; um documento de API para quem integra (OpenAPI ou equivalente) não existe e fica **nomeado**, não improvisado.

## 8. Fecho

Quatro das oito tarefas da F10 estão feitas, e esta é a primeira que expõe **dados** a um consumidor externo — motivo pelo qual as três autorizações e o oráculo de enumeração foram tratados aqui e não deixados para a auditoria.

**Próxima tarefa:** **WCCS-055**.
