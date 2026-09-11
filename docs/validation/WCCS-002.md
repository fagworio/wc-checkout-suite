# Registro de validação — WCCS-002

**Tarefa:** WCCS-002 · "Provar fluxo Classic e HPOS"
**Fase:** F00 · Escopo, inventário e provas técnicas
**Prioridade:** `required_v1` · **Dependências:** nenhuma declarada
**Data da execução:** 11/09/2026
**Escopo:** prova de viabilidade (F00). **Não** é o adaptador Classic da F04 nem a suíte de integração.

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Um campo de teste é validado e persistido com HPOS ligado e sincronização desligada."

## 2. Artefato executável

`tests/Integration/F00-wccs-002-classic-hpos-proof.php` — harness executado por `wp eval-file`, sem ativar o plugin. Cria **exatamente um** pedido, verifica-o e o exclui ao final. Sai com código 0 somente se todas as asserções passarem.

```bash
docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
  /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval-file \
  /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite/tests/Integration/F00-wccs-002-classic-hpos-proof.php
```

## 3. Resultado

**`RESULT: 17 passed, 0 failed, 3 notes` — exit code 0.**

| # | Asserção | Resultado observado |
|---|---|---|
| 1 | HPOS: tabela de pedidos é o store autoritativo | `custom_orders_table_usage_is_enabled=true` |
| 2 | HPOS: sincronização post/orders **desligada** | `data_sync_is_enabled=false`, opção `<absent>` |
| 3 | Classic: campo registrado via `woocommerce_checkout_fields` | `billing_wccs_test_document` presente |
| 4 | Classic: propriedades do campo preservadas | `type=text required=true` |
| 5 | Campo vazio gera `<key>_required` pelo core do WooCommerce | `billing_wccs_test_document_required` |
| 6 | Valor válido não gera erro de core para o campo | nenhum código |
| 7 | A fase real de validação alcançou o validador da Suite | `phase=validate_checkout()` |
| 8 | Validador da Suite bloqueou o valor inválido no servidor | `billing_wccs_test_document_wccs_invalid` |
| 9 | Pedido criado por `wc_create_order()` | `order_id=2780` |
| 10 | Pedido relido por `wc_get_order()` | `Automattic\WooCommerce\Admin\Overrides\Order` |
| 11 | Data store do pedido é o store HPOS | `OrdersTableDataStore` |
| 12 | Meta faz round-trip inalterada pelo CRUD | JSON idêntico ao gravado |
| 13 | Linha existe em `wp_wc_orders` | `rows=1` |
| 14 | Valor gravado em `wp_wc_orders_meta` | `rows=1` |
| 15 | Valor **não** gravado em `wp_postmeta` | `rows=0` |
| 16 | Limpeza: pedido removido de `wp_wc_orders` | `rows=0` |
| 17 | Limpeza: meta removida de `wp_wc_orders_meta` | `rows=0` |

**Verificação de resíduo após a execução** (consultas independentes ao banco): `wp_wc_orders` = 0 · `wp_wc_orders_meta` = 0 · `shop_order_placehold` = 0 · `wp_postmeta` com `_wccs_fields` = 0. O banco ficou no estado anterior à prova.

**Nota de transparência:** a primeira execução terminou com 16/17 por um **erro da minha asserção**, não do ambiente — `WC_Order::get_data_store()` devolve o proxy `WC_Data_Store`, e o store concreto é obtido por `get_current_class_name()`. A asserção foi corrigida no harness e a execução limpa é a registrada acima.

## 4. Decisão aberta resolvida

**`HPOS-SYNC-SEMANTICS` — resolvido por observação, não por suposição.**

`DataSynchronizer::data_sync_is_enabled()` é implementado literalmente como `'yes' === get_option( self::ORDERS_DATA_SYNC_ENABLED_OPTION )`. A opção **existe como constante no código** (`woocommerce_custom_orders_table_data_sync_enabled`), mas **nunca foi gravada** no banco. Consequência: a sincronização avalia como **desligada**. Em runtime, `custom_orders_table_usage_is_enabled()` = `true` e o data store ativo é `OrdersTableDataStore`.

Portanto o estado exigido pelo aceite — **HPOS ligado e sincronização desligada** — está comprovadamente satisfeito. A decisão foi removida de `open_decisions` em `docs/compatibility.json` e substituída pela resolução registrada.

## 5. Achados novos que afetam tarefas seguintes

1. **Placeholder de post continua sendo criado.** Mesmo com a sincronização desligada, o WooCommerce cria uma linha em `wp_posts` com `post_type = shop_order_placehold` para o pedido, embora o valor resida apenas em `wp_wc_orders_meta`. Qualquer listagem baseada em posts (relatórios, exportações, consultas de terceiros) **não pode** tratar esse placeholder como pedido. Relevante para F04, F10 e F12.
2. **`wc_get_order()` não devolve `WC_Order` exato**, e sim `Automattic\WooCommerce\Admin\Overrides\Order`. Verificações de tipo devem usar `instanceof WC_Order`, nunca comparação exata de classe.
3. **`validate_checkout()` roda em contexto CLI** desde que `wc_load_cart()` seja chamado antes; nenhum erro não relacionado ao campo de teste foi produzido por esta loja (o `NOTE` correspondente veio vazio).
4. **`process_checkout()` não foi exercitado.** Criar o pedido por `wc_create_order()` prova o CRUD e o store; submeter o checkout completo (nonce, carrinho, gateway) permanece não verificado e depende da F04.

## 6. O que NÃO foi provado

- Submissão ponta a ponta de um checkout Classic real (`process_checkout()`), com carrinho, frete, cupom, gateway e nonce.
- Comportamento do HPOS com o store legado de posts ativo.
- Qualquer fluxo de pagamento — nenhum gateway está habilitado neste ambiente.
- Validação de CPF/CNPJ real (o validador da prova apenas exige 11 dígitos); vetores oficiais são WCCS-028.
- Qualquer fluxo de Blocks (é WCCS-003).

## 7. Próxima tarefa

**WCCS-003 — "Provar fluxo Blocks"** (F00). Aceite: "Text, campo customizado controlado e erro server-side chegam ao pedido sem manipulação do DOM core." Componentes: Blocks, Store API. Observação relevante: a página de checkout desta loja **é** Blocks, então esta é a prova com maior superfície real disponível — mas o contrato de additional fields do WooCommerce 11.1.0 ainda precisa ser inspecionado em execução antes de qualquer promessa de suporte.
