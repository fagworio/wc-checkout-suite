# ADR-0004 — Checkout Sidebar excluído da versão 1.0

- **Status:** Aceito
- **Data:** 11/09/2026
- **Tarefa:** WCCS-005 (F00) · **Fase de impacto:** todas (F00–F13)
- **Decisão já fixada em:** `ROADMAP.md §1` ("Fora desta versão") e `§27`
- **Evidência de apoio:** WCCS-004 (topologia da página de checkout)

## Contexto

Existe o risco de expansão descontrolada de escopo: o `ROADMAP.md §25` lista
"Expansão descontrolada para Sidebar" com severidade **Média** e mitigação
*"Fora da V1, sem tasks/componentes disfarçados"*, com gate em **F13**. O critério de aceite nº 22 da V1
determina que nenhum Checkout Sidebar seja incluído, "nem como funcionalidade incompleta".

Há duas confusões previsíveis que precisam ser eliminadas por decisão explícita:

1. o **resumo lateral do pedido dentro da página de checkout**, que **já existe** e faz parte do layout
   atual (verificado em WCCS-004: `checkout-totals-block` → `checkout-order-summary-block`);
2. o **painel de propriedades do editor administrativo**, que é o inspetor do editor, não um componente
   de compra.

## Decisão

**Checkout Sidebar, drawer lateral de compra, minicarrinho transacional, floating cart e finalização por
overlay estão fora da versão 1.0.**

Não haverá, em nenhuma forma:

- tela, rota ou endpoint;
- botão, inclusive desabilitado;
- pacote, módulo, serviço ou diretório `sidebar-checkout`;
- componente vazio ou "placeholder para o futuro";
- *feature flag* ou opção de configuração dessa função.

E, explicitamente, **não são** esse recurso futuro:

- o resumo do pedido na coluna da página de checkout;
- o painel de propriedades do editor administrativo.

**Preparação permitida — e somente esta:** manter os serviços de campos, validação, persistência e contexto
**independentes da página**, para que um checkout lateral futuro possa reutilizá-los. Isso é consequência
natural da arquitetura em camadas já adotada (ADR-0001) e não autoriza nenhum artefato novo.

## Consequências

- Nenhuma tarefa, entregável ou componente de sidebar pode ser criado nas fases F00–F13. Se surgir a
  necessidade, ela vira **projeto próprio após a V1 estável**, com nova pesquisa de UX, contexto,
  acessibilidade, checkout expresso e gateways (`§27`).
- Não se assume que uma sidebar futura herdará compatibilidade com todos os gateways.
- A separação arquitetural é a **única** forma de preparação; ela não deve gerar abstracões extras,
  porque o `§3` determina que o contrato público seja pequeno e que não se crie um framework maior que o
  produto.
- WCCS-066 e o critério de aceite nº 22 verificam a ausência no pacote de release.

## Alternativas consideradas e rejeitadas

- **Criar o componente já, atrás de flag desligada**: rejeitada — o `§1` proíbe explicitamente *feature
  flag* dessa função. Código morto e flag desligada não são "escopo zero".
- **Criar apenas os contratos de serviço "preparando" a sidebar**: rejeitada — os contratos já existem por
  necessidade própria (campos, validação, persistência). Criar contratos *adicionais* motivados pela
  sidebar seria antecipar escopo sem demanda.
- **Remover o resumo lateral atual por parecer com a sidebar futura**: rejeitada — o resumo **faz parte do
  layout atual**; removê-lo quebraria o requisito visual [R2].

## Como verificar conformidade

- Busca por `sidebar`, `drawer`, `mini-cart` transacional e `floating` em código, assets e configuração
  do plugin: resultado deve ser vazio.
- Ausência de diretório `sidebar-checkout` na estrutura (o `§22` declara que ele não existe).
- WCCS-050: ativar/desativar a página customizada não altera engine nem cria superfície de sidebar.
- WCCS-066: o ZIP de release não contém artefato algum dessa função.
