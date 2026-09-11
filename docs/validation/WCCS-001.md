# Registro de validação — WCCS-001

**Tarefa:** WCCS-001 · "Inventariar requisitos e ambiente"
**Fase:** F00 · Escopo, inventário e provas técnicas
**Prioridade:** `required_v1` · **Status no backlog:** `planned` → trabalho executado, aguardando revisão
**Dependências:** nenhuma
**Data da execução:** 11/09/2026
**Modo:** somente leitura contra o ambiente; escrita restrita à raiz do plugin

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Requisitos rastreados; versões de WordPress/Woo/PHP e gateways alvo registradas."

## 2. O que foi entregue

| Artefato | Caminho | Papel |
|---|---|---|
| Matriz de compatibilidade | `docs/compatibility.json` | Versões reais verificadas com proveniência, estado de WordPress/WooCommerce/HPOS, gateways alvo, decisões abertas, itens **não verificados** e comandos de evidência. |
| Rastreabilidade | `docs/requirements-traceability.md` | 27 fontes/seções do anexo → fases → **70 tarefas** com seus critérios de aceite literais. |
| Este registro | `docs/validation/WCCS-001.md` | Evidência da validação realizada e limites do que **não** foi validado. |

Nenhum arquivo fora da raiz do plugin foi criado ou alterado. `roadmap/` não foi modificado.

## 3. Como o aceite foi cumprido

**"Requisitos rastreados"** — `docs/requirements-traceability.md` desce a rastreabilidade de `roadmap/MATRIZ-REQUISITOS.md` até o nível de tarefa. Regra de derivação determinística: uma tarefa é coberta por uma fonte quando a fase da tarefa está entre as fases declaradas por aquela fonte, com expansão inclusiva de ranges (`F00–F04`) e `Todo o roadmap` → `F00–F13`.

**"versões de WordPress/Woo/PHP … registradas"** — seção `versions_verified` de `docs/compatibility.json`, cada valor com o comando que o produziu.

**"gateways alvo registradas"** — seção `target_gateways`, com os 7 gateways instalados, seus provedores e versões, o conjunto-alvo proposto para a F09 e as lacunas encontradas.

## 4. Validação executada (com resultado observado)

| # | Verificação | Resultado |
|---|---|---|
| 1 | `json.load` de `docs/compatibility.json` | **Válido** |
| 2 | 17 chaves obrigatórias presentes | **Nenhuma ausente** |
| 3 | Cobertura de tarefas na rastreabilidade | **70/70**, zero tarefas sem fonte |
| 4 | Contagem de linhas `\| WCCS-nnn \|` na tabela de tarefas | **70** |
| 5 | Consistência `MATRIZ-REQUISITOS.md` ↔ `BACKLOG.json` | **Nenhuma divergência** (27 fontes, 70 tarefas) |
| 6 | Revalidação ao vivo de fatos contra o artefato | WP real `7.1` = artefato `7.1` · Woo real `11.1.0` = artefato `11.1.0` · PHP real `8.2.1` = artefato `8.2.1` · HPOS real `yes` = artefato `true` · slug real `finalizar-compra` = artefato `finalizar-compra` |
| 7 | Varredura de segredos no artefato | Único casamento é a frase de política `secrets_policy`; **0** valores de credencial, salt, chave ou token reproduzidos |

## 5. Decisão registrada nesta tarefa

**Política de identificadores técnicos** (confirmada pelo responsável do produto durante a execução): **seguir a proposta do `ROADMAP.md`**.

- Diretório físico: `wc-checkout-suite`
- Arquivo principal: `wc-checkoutsuite.php`
- Namespace PHP: `WCCheckoutSuite\` · Prefixo: `wccs_`
- Text domain: `wc-checkoutsuite` · REST: `wc-checkoutsuite/v1`

Divergência conhecida e documentada: o *slug* de distribuição derivado do diretório será `wc-checkout-suite` enquanto o text domain é `wc-checkoutsuite`. A F13 (WCCS-066) precisa registrar isso explicitamente. Os valores devem ser declarados uma única vez no bootstrap para que uma futura mudança seja uma edição única.

## 6. Achados materiais que afetam tarefas seguintes

1. **Nenhum gateway está habilitado.** Os 7 gateways estão com `enabled = no`. Nenhum fluxo de pagamento é exercitável sem configuração (afeta WCCS-004 e WCCS-049).
2. **Boleto não existe neste ambiente.** O `ROADMAP.md §24` cita "Pix e boleto pelos gateways testados"; Pix está disponível (`woo-mercado-pago-pix`), boleto/ticket **não está instalado**.
3. **Não existe página de Checkout Classic.** A página 2755 usa **Checkout Blocks** (23 blocos `wp:woocommerce/checkout-*`, zero shortcodes). A F04 não é exercitável sem criar ou trocar uma página de checkout — mudança **fora da raiz do plugin**, que exige autorização.
4. **A opção de sincronização do HPOS não existe** no WooCommerce 11.1.0 (`woocommerce_custom_orders_table_data_sync_enabled` ausente). O aceite da WCCS-002 exige "sincronização desligada": o significado disso nesta versão precisa ser apurado na própria WCCS-002, e não presumido.
5. **PHP 8.3 não está disponível** no Devilbox (config vai até 8.2; imagens presentes: 8.1 e 8.2). O baseline proposto pelo `ROADMAP.md §18` não tem runtime local.
6. **O plugin não tem versionamento.** `/data/www/*` é ignorado pelo repositório do Devilbox; não há `.git` no plugin. Bloqueia WCCS-010 e WCCS-066.
7. **Dois artefatos citados pelo planejamento não existem em disco:** `DESIGN-TOKENS.json` e `fontes.json`. Os tokens existem apenas embutidos em `roadmap/PROTOTIPO.html`.

## 7. O que NÃO foi validado

Nenhum pagamento, pedido, gateway, upload, validação de documento, máscara, condição, renderização em navegador ou medição de performance foi exercitado. A lista completa está em `not_verified` no `docs/compatibility.json`. A ausência de teste **não** é resultado positivo.

## 8. Riscos abertos

Ver `open_decisions` em `docs/compatibility.json`: `PHP-BASELINE`, `HPOS-SYNC-SEMANTICS`, `CLASSIC-TEST-SURFACE`, `NODE-RUNTIME`, `GIT-REPOSITORY`.

## 9. Próxima tarefa

**WCCS-002 — "Provar fluxo Classic e HPOS"** (F00, sem dependências declaradas). Aceite: "Um campo de teste é validado e persistido com HPOS ligado e sincronização desligada." Antes de reivindicar esse aceite é obrigatório resolver `HPOS-SYNC-SEMANTICS` por observação, não por suposição.
