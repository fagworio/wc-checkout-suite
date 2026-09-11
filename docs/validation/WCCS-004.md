# Registro de validação — WCCS-004

**Tarefa:** WCCS-004 · "Provar layout e gateways"
**Fase:** F00 · Escopo, inventário e provas técnicas
**Prioridade:** `required_v1` · **Dependências:** nenhuma declarada
**Data da execução:** 11/09/2026

## ⚠ Status: PARCIALMENTE VALIDADA — não concluída

> O aceite tem duas cláusulas. **Uma foi cumprida; a outra está bloqueada por configuração externa.**
> Esta tarefa **não** é declarada concluída.

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Pagamento de sandbox, refresh e retorno verificados no conjunto inicial; slots permitidos documentados."

## 2. Cláusula "slots permitidos documentados" — ✅ CUMPRIDA

Entregue em `docs/api/checkout-extension-points.md`, com evidência derivada do código e do registro em runtime
da instalação (não da documentação pública):

| Ponto de extensão | Evidência |
|---|---|
| 4 slots React `SlotFill` | `ExperimentalOrderMeta`, `ExperimentalDiscountsMeta`, `ExperimentalOrderShippingPackages`, `ExperimentalOrderLocalPickupPackages` — extraídos de `assets/client/blocks/checkout.js` |
| Localizações de additional field | `contact`, `address`, `order` (verificado em WCCS-003) |
| **24 blocos** de checkout registrados com sua topologia `parent` | `WP_Block_Type_Registry::get_instance()->get_all_registered()` em runtime |
| Bloco de checkout expresso | `woocommerce/checkout-express-payment-block` registrado, filho de `checkout-fields-block` |
| Blocos de confirmação de pedido | 14 blocos `woocommerce/order-confirmation-*` disponíveis para F10 |
| Lista explícita do que **não** é permitido | 6 proibições derivadas de `ROADMAP.md §8`/`§15` |

Verificação executada:

```bash
docker exec -u devilbox devilbox-php-1 php -d error_reporting=0 -d display_errors=0 \
  /usr/local/bin/wp --path=/shared/httpd/wpagf/htdocs eval '
    $reg = WP_Block_Type_Registry::get_instance();
    $all = $reg->get_all_registered();
    $co = array_filter( array_keys($all), fn($n) => str_starts_with($n, "woocommerce/checkout") || "woocommerce/checkout" === $n );
    echo count($co);
  '
# observado: 24
```

## 3. Cláusula "pagamento de sandbox, refresh e retorno" — ❌ BLOQUEADA

**Condição concreta:** não existe gateway habilitado nem credencial de sandbox neste ambiente.

Evidência em runtime:

```
WC()->payment_gateways->get_available_payment_gateways()  =>  0 gateways  []
gateways com suporte expresso declarado                    =>  0
todos os 7 gateways instalados                             =>  enabled = no
```

Gateways instalados (todos desabilitados): `ppcp-gateway` (PayPal Payments 4.1.3),
`woo-mercado-pago-custom`, `woo-mercado-pago-pix`, `woo-mercado-pago-basic` (Mercado Pago 8.9.3),
`bacs`, `cheque`, `cod` (core).

**O que seria necessário:** habilitar ao menos um gateway em modo sandbox com credenciais válidas.
**Por que não foi feito:** habilitar um gateway altera configurações da loja, **fora da raiz do plugin**.
Nenhuma credencial foi solicitada, lida ou registrada. Esta é uma pendência de configuração externa,
não um defeito do produto.

Registrado como bloqueio `SANDBOX-PAYMENT` em `docs/compatibility.json`, com impacto declarado em
**WCCS-049** (homologação de gateways e express) e **WCCS-062** (matriz funcional por versão de gateway),
que dependem da mesma configuração.

## 4. Achados desta tarefa

1. **Todos os slots React do checkout são `Experimental`.** Não há slot estável. Qualquer uso exige
   *feature detection* e degradação segura — o que reforça a decisão do `§8` de exibir estado de
   compatibilidade real no admin em vez de prometer posicionamento.
2. **O bloco de checkout expresso está registrado mas não renderiza nada** sem gateway expresso habilitado.
   Isso é diretamente relevante ao gate de segurança de F09: enquanto não houver gateway configurado,
   o caminho expresso não é exercitável nem bloqueável de forma verificável.
3. **O resumo do pedido já existe na página Blocks** (`checkout-totals-block` → `checkout-order-summary-block`).
   Ele **não** é o Checkout Sidebar futuro — reforça a distinção do `ROADMAP.md §1`.
4. **A topologia de blocos é restritiva por `parent`.** Um bloco da Suite só entra onde a lista de pais permite;
   não há inserção livre. Isso deve aparecer na matriz de capacidades do admin (WCCS-039).

## 5. O que NÃO foi provado

- Qualquer pagamento, em sandbox ou produção: criação de pedido por gateway, redirecionamento, retorno,
  3DS, retry ou token salvo.
- Comportamento de *refresh* do checkout Blocks após alteração de endereço, frete ou método de pagamento.
- Renderização em navegador, responsividade ou acessibilidade da página.
- Se o bloco `checkout-express-payment-block` respeita um campo crítico obrigatório — não há gateway para testar.

## 6. Próxima tarefa

**WCCS-005 — "Congelar decisões arquiteturais"** (F00, última da fase). Aceite: "ADRs aprovadas para storage,
upload privado, CNPJ alfanumérico e exclusão de Sidebar." Totalmente executável: as quatro decisões já estão
posicionadas no `ROADMAP.md` e agora contam com a evidência coletada em WCCS-001 a WCCS-004.

A conclusão de F00 permanece condicionada a WCCS-005 **e** à resolução externa de `SANDBOX-PAYMENT`.
