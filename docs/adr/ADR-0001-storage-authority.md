# ADR-0001 — Autoridade única de persistência por origem de campo

- **Status:** Aceito
- **Data:** 11/09/2026
- **Tarefa:** WCCS-005 (F00) · **Fase de impacto:** F04, F07, F10
- **Decisão já fixada em:** `ROADMAP.md §13` ("Valores" — tabela de autoridade de persistência)
- **Evidência de apoio:** WCCS-002 (HPOS), WCCS-003 (Blocks)

## Contexto

O produto escreve valores de campo em **dois backends de pedido** (HPOS e o store legado de posts) e em
**três origens diferentes** (campos nativos do WooCommerce, additional fields nativos do Blocks e campos
próprios da Suite). O `ROADMAP.md §25` lista "Gravação duplicada Classic/Blocks" como risco **Alta**.

A verificação de F00 mostrou que o WooCommerce já possui um caminho de persistência próprio para os
additional fields nativos do Blocks: o valor vai para metadados com prefixo de grupo
(`_wc_other/`, `_wc_billing/`, `_wc_shipping/`), e a constante `_wc_additional/` está **descontinuada**.
Sem uma autoridade declarada, a Suite poderia gravar o mesmo dado em `_wccs_fields` **e** deixar o core
gravar em `_wc_other/`, criando duas verdades divergentes.

## Decisão

**Uma única autoridade de escrita por origem de campo. Nenhum outro componente grava valores do mesmo campo.**

| Origem do campo | Autoridade de persistência |
|---|---|
| Campo padrão do WooCommerce | Fluxo/CRUD nativo. **Não** criar meta paralela. |
| Additional field nativo do Blocks | Helpers e política da própria API do WooCommerce (meta de grupo, id `namespace/name`). A Suite **não** duplica em `_wccs_fields`. |
| Campo próprio da Suite (Classic ou Store API) | CRUD de pedido (`WC_Order`) + armazenamento canônico da Suite: `_wccs_fields` (valores tipados) e `_wccs_schema_revision`. |
| Perfil de cliente explicitamente persistente | CRUD/serviço apropriado do cliente. |
| Arquivo | Referência de token/registro. **Nunca** bytes no order meta. |

Regras decorrentes, todas obrigatórias:

1. **Proibido** `get_post_meta()` / `update_post_meta()` para pedidos. Todo acesso passa por `WC_Order`.
2. Verificação de tipo de pedido **somente** com `instanceof WC_Order`. `wc_get_order()` devolve
   `Automattic\WooCommerce\Admin\Overrides\Order`, não `WC_Order` exato.
3. O placeholder de post (`shop_order_placehold`), que existe mesmo com a sincronização desligada,
   **não** pode ser tratado como pedido em listagens, exportações ou consultas próprias.
4. Renomear label não renomeia a chave de armazenamento. Mudar tipo ou normalização exige migração.
5. Excluir um campo significa **arquivar a definição** para novas compras, preservando o histórico.
6. Chave com underscore **não** é controle de acesso: toda projeção em API exige autorização e teste.

## Consequências

- O `OrderFieldsService` (WCCS-023) é o único ponto que conhece o formato de `_wccs_fields`; o resto do
  código nunca lê o meta diretamente. Isso mantém Classic e Blocks com o mesmo significado de dados.
- Campos nativos do Blocks exigem um caminho de leitura separado, porque a autoridade é do core.
  A matriz de capacidades do admin (WCCS-039) precisa refletir isso.
- Testes de igualdade Classic × Blocks (critério de aceite nº 10 da V1) só fazem sentido para campos da
  Suite; para campos nativos do Blocks, o esperado é **não** haver cópia.
- Migração destrutiva exige dry-run, backup, relatório e confirmação explícita.

## Alternativas consideradas e rejeitadas

- **Espelhar tudo em `_wccs_fields`** para simplificar leitura: rejeitada — cria a dupla verdade que o
  `§25` classifica como risco Alto e quebra o critério de aceite nº 10.
- **Ler/escrever direto em `wp_postmeta`** por familiaridade: rejeitada — com HPOS autoritativo isso lê e
  grava a fonte errada (`ROADMAP.md §13`, referência S4).
- **Uma tabela própria para todos os valores** (incluindo campos nativos): rejeitada — contradiz a
  autoridade nativa e exigiria reimplementar o ciclo do pedido.

## Como verificar conformidade

- Regra estática em revisão/CI: nenhuma ocorrência de `post_meta` para pedidos no código da Suite.
- WCCS-023: teste de valores tipados preservando `0`, `false` e array vazio, em HPOS **on e off**.
- WCCS-024: renomear/arquivar um campo não torna pedido antigo ilegível.
- WCCS-062: matriz funcional Classic × Blocks comparando o significado dos dados.
