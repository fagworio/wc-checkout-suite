# Registro de validação — WCCS-014

**Tarefa:** WCCS-014 · "Implementar client REST"
**Fase:** F02 · Design system e shell administrativo
**Prioridade:** `required_v1` · **Dependências:** F01, WCCS-008 (endpoints), WCCS-012, WCCS-013
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Nonce, erro 403/409/422, retry seguro e estado não salvo tratados."

**Resultado:** **59 testes de JS** (7 suítes, 18 novos nesta tarefa) · **16 asserções PHP** · **283 asserções de integração** em 10 provas, 0 falhas · 57 testes unitários PHP · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Http/Admin/SchemaController.php` (alterado) | As rotas passaram a ser **constantes** com um mapa `routes()`, fonte única para o controlador e para o bootstrap |
| `src/Admin/Assets.php` (alterado) | O bootstrap passou a publicar `rest.root`, `rest.namespace`, `rest.nonce` e `rest.routes` |
| `resources/admin/app/api/client.js` | Cliente REST com classificação de erro, repetição segura e descarte de resposta fora de ordem |
| `resources/admin/app/api/useUnsavedChanges.js` | Guarda de trabalho não salvo |
| `resources/admin/app/api/index.js` | Superfície do módulo |
| `tests/js/api/client.test.js` · `useUnsavedChanges.test.js` | 18 testes de comportamento |
| `tests/Integration/F02-wccs-014-rest-client-proof.php` | 16 asserções do lado do servidor |

## 3. Como cada palavra do aceite foi provada

### "Nonce" — ✅

O bootstrap publica um nonce `wp_rest` real, **verificado** com `wp_verify_nonce()` no harness, e a prova confirma que um nonce de **outra ação é rejeitado**. Uma requisição REST autenticada com esse nonce devolve **200**; a mesma rota sem usuário devolve **401**.

O cliente envia `X-WP-Nonce` e `credentials: 'same-origin'` em toda requisição — asserção direta sobre as opções passadas ao `fetch`.

**Nonce não é autorização**, e o código diz isso: quem autoriza é a checagem de capability em cada rota, provada desde a WCCS-008 (403/401).

### "Erro 403/409/422" — ✅

Cada status é **classificado**, não achatado:

| Status | Classe | O que o cliente expõe |
|---|---|---|
| 403 | `isForbidden` | código e mensagem do servidor preservados |
| 409 | `isConflict` | `currentRevision` lido de `data.current_revision` |
| 422 | `isValidationFailure` | `fieldErrors` lido de `data.errors` |
| transporte / 5xx | `isTransient` | status `0` com código `network_error` |

Um cliente que devolvesse "deu erro" para os três obrigaria a tela a reinterpretar o corpo da resposta — e é assim que um conflito de revisão acaba exibido como erro de validação.

### "Retry seguro" — ✅

A regra implementada é literal: **só a leitura é repetida.** Escritas nunca.

| Cenário | Repete? |
|---|---|
| `GET` com 500 e depois 200 | **sim** — repete e conclui |
| `GET` com falha de transporte | **sim** |
| `GET` esgotando as tentativas | para e reporta o status do servidor |
| `PUT` com 500 | **não** — uma tentativa, e o erro sobe |
| `POST` com falha de transporte | **não** |
| Requisição abortada pelo chamador | **não** |
| 409 já decidido pelo servidor | **não** |

O motivo de a escrita não ser repetida está no comentário do módulo e num teste com nome explícito: *"never repeats a write, because a lost answer would look like a conflict"*. Uma escrita com `expected_revision` que tenta de novo depois de uma resposta perdida encontraria a revisão já avançada e produziria um **409 que o usuário não causou**. Repetir automaticamente seria criar um conflito falso.

O cliente também **descarta resposta fora de ordem**: se uma requisição mais nova para a mesma chave já respondeu, a antiga lança `StaleResponseError` em vez de sobrescrever o resultado — o mesmo princípio que o `§10` exige do endpoint de validação.

### "Estado não salvo" — ✅

`useUnsavedChanges` registra `beforeunload` **enquanto há trabalho não salvo**, chama `preventDefault()` para pedir confirmação, e **remove o guarda assim que o trabalho é salvo ou descartado**. Os três comportamentos são testados, incluindo a remoção do listener ao desmontar.

## 4. Fonte única para as rotas

O bootstrap publicava caminhos que o controlador registrava — duas listas que podiam divergir. Agora as rotas são constantes com um mapa, e a prova verifica que:

- o bootstrap publica **exatamente** `SchemaController::routes()`;
- **cada** rota publicada está de fato registrada em `rest_get_server()->get_routes()`.

Um caminho renomeado no PHP quebra o teste em vez de deixar o cliente apontando para o vazio.

## 5. Observação honesta: o cliente ainda não está no bundle

A prova registra que o `AppShell` **não importa** o cliente, então o webpack o deixa fora do bundle (3.200 bytes de JS no total). Isso é esperado nesta fase: o editor que consome o cliente é a **F03**. O código existe, está testado e tem contrato verificado, mas ainda não é exercitado por uma tela. Registrado como observação, **não** como resultado positivo.

## 6. Atrito de tipagem, e o que ficou decidido

Anotar o cliente em JSDoc exigiu quatro correções reais: forma de objeto explícita no construtor de erro, `Function|null` estreitado para `Function` depois do guarda, `catch` sem binding para o ESLint, e `transportError` tratado como `unknown` (o TypeScript moderno não presume `Error`). Cada uma é uma melhoria, não um contorno.

Isso reforça a decisão da WCCS-013: **a migração dos componentes e do cliente para TypeScript está prevista para F03**, onde o custo se paga. O `§18` do planejamento já pede React/**TypeScript** no admin.

## 7. O que NÃO foi feito

- **Nenhuma tela usa o cliente.** Sem editor não há "salvar rascunho", "publicar" nem "conflito" visíveis ao usuário. A integração real é F03/F04.
- **Nenhum teste contra o servidor real a partir do navegador.** Os testes de JS usam `fetch` simulado; o lado PHP é provado com `rest_do_request()`. Um pedido HTTP verdadeiro do navegador para o WordPress pertence às provas de integração E2E de F12.
- **Nenhuma retomada automática após conflito.** O cliente **reporta** a revisão vencedora; a tela que oferece "recarregar e revisar as diferenças" é F03.
- **Nenhuma prévia visual** — `WCCS-015`, a última da fase.

## 8. Próxima tarefa

**WCCS-015 — "Criar preview visual"** (F02, última). Aceite: *"Desktop/tablet/mobile identificados como prévia; nenhum dado real necessário."* Fecha a fase do design system e do shell administrativo.
