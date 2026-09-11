# Registro de validação — WCCS-003

**Tarefa:** WCCS-003 · "Provar fluxo Blocks"
**Fase:** F00 · Escopo, inventário e provas técnicas
**Prioridade:** `required_v1` · **Dependências:** nenhuma declarada
**Data da execução:** 11/09/2026
**Escopo:** prova de viabilidade (F00). **Não** é o adaptador Blocks da F07.

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Text, campo customizado controlado e erro server-side chegam ao pedido sem manipulação do DOM core."

## 2. Artefato executável

`tests/Integration/F00-wccs-003-blocks-proof.php` — harness executado por `wp eval-file`, sem ativar o plugin. Cria **exatamente um** pedido, verifica-o e o exclui ao final.

```bash
docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
  /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval-file \
  /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite/tests/Integration/F00-wccs-003-blocks-proof.php
```

## 3. Resultado

**`RESULT: 28 passed, 0 failed, 3 notes` — exit code 0.**

Resíduo após a execução: `wp_wc_orders` = 0 · `wp_wc_orders_meta` = 0 · `shop_order_placehold` = 0 · `wp_postmeta` com chaves `wccs`/`_wc_other` = 0.

### Como cada cláusula do aceite foi satisfeita

| Cláusula | Evidência |
|---|---|
| **"Text … chega ao pedido"** | Campo nativo `wc-checkoutsuite/test-document` (`type=text`, `location=contact`, `required=true`) registrado por `woocommerce_register_additional_checkout_field()`; valor persistido via `CheckoutFields::persist_field_for_order()` e relido do pedido em `_wc_other/wc-checkoutsuite/test-document`, com linha confirmada em `wp_wc_orders_meta` e **ausente** de `wp_postmeta`. |
| **"campo customizado controlado … chega ao pedido"** | Duas provas independentes: (a) o namespace `wc-checkoutsuite` foi registrado no endpoint `checkout` pela API oficial `woocommerce_store_api_register_endpoint_data()`, aparecendo no schema com a propriedade `controlled_document` como objeto anulável, e o `data_callback` devolvendo o valor; (b) o valor do campo customizado `wc-checkoutsuite/controlled-document`, que **não** é um tipo nativo, chegou ao pedido pelo mesmo caminho canônico de persistência e foi confirmado em `wp_wc_orders_meta`. |
| **"erro server-side"** | `validate_field()` devolveu `WP_Error` com o código `wccs_invalid_test_document` para valor inválido e ficou limpo para valor válido; o hook de localização `woocommerce_blocks_validate_location_contact_fields` produziu `wccs_location_blocked`; e o `CheckoutSchema` do Store API possui o callback `validate_additional_fields`. |
| **"sem manipulação do DOM core"** | Todo o caminho usado é API oficial de servidor: filtros/registro PHP e o namespace `extensions` da Store API. Nenhum `MutationObserver`, nenhum acesso a DOM, nenhuma reescrita de componente core. |

## 4. Achado material — correção de planejamento necessária

**`date` NÃO é um tipo nativo de additional field no WooCommerce 11.1.0 instalado.**

O conjunto suportado é um array privado e **sem filtro**, `CheckoutFields::$supported_field_types = [ 'text', 'select', 'checkbox' ]`. A prova derivou essa lista da própria mensagem de erro do WooCommerce, ao tentar registrar um campo `date` (que foi rejeitado e **não** registrado).

Isso contraria:

- `ROADMAP.md §8`, que afirma que a API de campos adicionais lista `text`, `select`, `checkbox` **e `date`**;
- o entregável de **WCCS-036**, descrito como "Text/select/checkbox/date com feature detection".

**Consequência:** um campo de data depende de componente controlado próprio, ou seja, cai em **WCCS-037** e na matriz de capacidades do admin (WCCS-039), não no caminho nativo. O `ROADMAP.md` **não foi editado** — a correção é uma decisão de planejamento e está registrada em `docs/compatibility.json` (`checkout_blocks_additional_fields.roadmap_correction_required`).

## 5. Outros achados registrados

1. **Localizações válidas:** `contact`, `address`, `order`. A localização `additional` está **descontinuada** e é silenciosamente mapeada para `order`.
2. **`hidden: true` não é suportado** — o campo é registrado como visível, com `_doing_it_wrong`. As regras condicionais de `hidden` só funcionam no formato de *rules*, não como booleano `true`.
3. **`required` aceita regras** (não apenas booleano) — o suporte nativo a condicionais já existe e é relevante para o compilador de condições de WCCS-035.
4. **Prefixo de meta real é `_wc_other/`**, não `_wc_additional/`: `CheckoutFields::get_group_key('other')` devolve `OTHER_FIELDS_PREFIX`; a constante `ADDITIONAL_FIELDS_PREFIX` está descontinuada. Persistir com o prefixo errado criaria dados que o WooCommerce não lê.
5. **Formato do schema de extensão:** `get_endpoint_schema()` devolve um objeto externo, mas cada entrada é um **array** (`description`/`type`/`context`/`properties`) produzido por `format_extensions_properties()`. Consumir como objeto quebra silenciosamente.
6. **Namespace já ocupado no endpoint checkout:** `ppcp_recaptcha` (PayPal Payments) e `woocommerce/order-attribution` também registram dados ali. Não há colisão com `wc-checkoutsuite`.

## 6. Nota de transparência

Duas asserções minhas estavam erradas e foram corrigidas no harness, não no ambiente:

- WCCS-002: `WC_Order::get_data_store()` devolve o proxy `WC_Data_Store`; o store concreto vem de `get_current_class_name()`.
- WCCS-003: o schema de extensão é um array, não um objeto.

Em ambos os casos a execução limpa é a registrada. O ambiente nunca produziu a falha.

## 7. O que NÃO foi provado

- A submissão completa `POST /wc/store/v1/checkout` com carrinho, frete, cupom e método de pagamento. Registro, validação, namespace de extensão e persistência foram provados **independentemente**, mas o round-trip HTTP completo do checkout Blocks não foi executado.
- Renderização do componente no navegador, hidratação React e comportamento de remount/rerender (WCCS-040).
- Compilação de condições para `hidden`/`required` nativos (WCCS-035).
- Qualquer fluxo de pagamento — nenhum gateway está habilitado.

## 8. Próxima tarefa

**WCCS-004 — "Provar layout e gateways"** (F00). Aceite: "Pagamento de sandbox, refresh e retorno verificados no conjunto inicial; slots permitidos documentados."

**Situação prevista: bloqueio parcial.** Os 7 gateways instalados estão com `enabled = no`, nenhuma credencial de sandbox existe neste ambiente e nenhuma foi solicitada. Sem pelo menos um gateway configurado em modo sandbox, a cláusula "pagamento de sandbox" **não pode** ser cumprida. A parte de "slots permitidos documentados" é executável de imediato e será feita; o aceite completo permanece pendente de configuração externa.
