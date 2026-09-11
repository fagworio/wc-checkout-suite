# Pontos de extensão permitidos — página de checkout

**Artefato da tarefa WCCS-004** (F00) · verificado contra o **WooCommerce 11.1.0** instalado
**Data:** 11/09/2026 · **Referência no planejamento:** `ROADMAP.md §8`, `§15` e `§16`

> Este documento responde à cláusula "slots permitidos documentados" do aceite de WCCS-004.
> A cláusula de pagamento de sandbox **não** foi cumprida — ver `docs/validation/WCCS-004.md`.
>
> Toda evidência abaixo foi derivada do código e do registro em runtime da instalação,
> não da documentação pública do WooCommerce.

## 1. Slots React (`SlotFill`) disponíveis no checkout

Extraídos por varredura de `assets/client/blocks/checkout.js`:

| Slot | Uso |
|---|---|
| `ExperimentalOrderMeta` | Conteúdo adicional próximo ao resumo do pedido |
| `ExperimentalDiscountsMeta` | Conteúdo na área de descontos |
| `ExperimentalOrderShippingPackages` | Conteúdo por pacote de entrega |
| `ExperimentalOrderLocalPickupPackages` | Conteúdo por pacote de retirada local |

Todos carregam o prefixo `Experimental` — ou seja, **a API não é estável** e pode mudar sem aviso de depreciação.
Consequência para o produto: qualquer uso precisa de *feature detection* e de uma degradação segura quando o slot
não existir, exatamente como o `ROADMAP.md §8` exige para capacidades não homologadas.

## 2. Localizações de additional field

Verificadas em WCCS-003: `contact`, `address`, `order`. A localização `additional` está descontinuada e é
mapeada em silêncio para `order`. Tipos nativos: **`text`, `select`, `checkbox`** — `date` **não** é suportado
nesta versão.

## 3. Pontos de inserção de blocos (topologia real)

Derivado do registro em runtime (`WP_Block_Type_Registry`) e dos `block.json`. São **24** os blocos de checkout
registrados. A relação `parent` define onde um bloco **pode** ser inserido:

| Bloco pai | Filhos permitidos |
|---|---|
| `woocommerce/checkout` | `checkout-fields-block`, `checkout-totals-block` |
| `woocommerce/checkout-fields-block` | `checkout-contact-information-block`, `checkout-billing-address-block`, `checkout-shipping-address-block`, `checkout-shipping-method-block`, `checkout-shipping-methods-block`, `checkout-pickup-options-block`, `checkout-payment-block`, `checkout-order-note-block`, `checkout-additional-information-block`, `checkout-terms-block`, `checkout-express-payment-block`, `checkout-actions-block` |
| `woocommerce/checkout-totals-block` | `checkout-order-summary-block` |
| `woocommerce/checkout-order-summary-block` | `checkout-order-summary-cart-items-block`, `checkout-order-summary-coupon-form-block`, `checkout-order-summary-totals-block` |
| `woocommerce/checkout-order-summary-totals-block` | `checkout-order-summary-subtotal-block`, `checkout-order-summary-discount-block`, `checkout-order-summary-shipping-block`, `checkout-order-summary-fee-block`, `checkout-order-summary-taxes-block` |

Observação acessível ao usuário: o `<aside>` de resumo do pedido **já existe** na página Blocks
(`checkout-totals-block` → `checkout-order-summary-block`) e **não** é o Checkout Sidebar futuro.

## 4. Bloco de checkout expresso

`woocommerce/checkout-express-payment-block` **está registrado** e é filho de `checkout-fields-block`.
Ele renderiza, porém, apenas gateways expressos **habilitados**: nesta instalação há **0 gateways disponíveis**
e **0 com suporte expresso declarado**, então o bloco não produz saída. Isso é relevante para o gate de segurança
de F09 (WCCS-049): o caminho expresso não pode concluir sem os campos críticos obrigatórios.

## 5. Blocos de confirmação de pedido (para F10)

Registrados e disponíveis para a apresentação pós-compra:
`woocommerce/order-confirmation-status`, `-summary`, `-totals`, `-totals-wrapper`, `-billing-address`,
`-billing-wrapper`, `-shipping-address`, `-shipping-wrapper`, `-downloads`, `-downloads-wrapper`,
`-create-account`, `-additional-information`, `-additional-fields`, `-additional-fields-wrapper`.

## 6. Explicitamente NÃO permitido

Decorrente de `ROADMAP.md §8` e `§15`, e confirmado como desnecessário pelas provas de WCCS-002 e WCCS-003:

1. **Reescrever o DOM do checkout core.** Nenhum `MutationObserver`, nenhuma manipulação de nós do React do core.
2. **Reordenar arbitrariamente campos core.** A topologia acima define onde blocos **podem** entrar; ela não
   autoriza reposicionar campos internos de um bloco do core.
3. **Clonar ou duplicar inputs de pagamento**, iframes ou SDK de gateway.
4. **Registrar novamente gateways de terceiros** apenas para estilizar. Um gateway só entra se já estiver
   registrado pelo seu próprio plugin.
5. **Conectar máscara diretamente a inputs React controlados pelo core** sem ponto de extensão oficial;
   quando a máscara for necessária sem suporte de renderização, usar o renderer próprio em slot permitido.
6. **Prometer paridade visual irrestrita de posicionamento.** A matriz de capacidades do admin deve mostrar
   "Nativo", "Componente da Suite", "Limitado" ou "Não suportado" com o motivo, conforme `§8`.

## 7. Onde cada fase vai usar estes pontos

| Fase / tarefa | Ponto de extensão |
|---|---|
| F07 / WCCS-036 | Localizações de additional field (text, select, checkbox) |
| F07 / WCCS-037 | Componente controlado próprio nos slots permitidos (inclui `date`) |
| F07 / WCCS-038 | Namespace `extensions` da Store API |
| F09 / WCCS-046–047 | Topologia de blocos e templates |
| F09 / WCCS-048 | `checkout-payment-block` + resumo do pedido (radios e valores reais do Woo) |
| F09 / WCCS-049 | `checkout-express-payment-block` e gate expresso |
| F10 / WCCS-052–053 | Blocos de confirmação de pedido |
