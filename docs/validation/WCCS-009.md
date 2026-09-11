# Registro de validação — WCCS-009

**Tarefa:** WCCS-009 · "Criar normalizadores e contratos"
**Fase:** F01 · Core, schema e contratos de extensão
**Prioridade:** `required_v1` · **Dependências:** F00, WCCS-006, WCCS-007, WCCS-008
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Interfaces versionadas; mesmo contrato serve Classic/Blocks/pedidos."

**Resultado:** `RESULT: 36 passed, 0 failed` — exit 0.
**Regressão:** WCCS-006 `33/33` · WCCS-007 `38/38` · WCCS-008 `38/38`.
**Qualidade:** PHPCS **exit 0** · PHPStan level 5 com stubs **`[OK] No errors`**.
**Estado:** nenhuma opção criada, nenhum pedido, plugin desativado ao final.

## 2. Código criado

| Arquivo | Papel |
|---|---|
| `src/Domain/Validation/NormalizerInterface.php` | Contrato de normalizador |
| `src/Domain/Validation/NormalizerRegistry.php` | Registry + hook |
| `src/Domain/Validation/Normalizers/GenericNormalizer.php` | Primitivas: `trim`, `digits`, `uppercase`, `lowercase`, `single_spaces` |
| `src/Domain/Validation/Mask.php` | Máscara **declarativa** com guarda anti-código |
| `src/Domain/Validation/MaskRegistry.php` | Registry + hook `wccs_register_masks` |
| `src/Domain/Validation/RendererInterface.php` | Contrato de renderizador por adapter |
| `src/Domain/Validation/RendererRegistry.php` | Registry + hook `wccs_register_renderers` + consultas de capacidade |
| `src/Domain/Validation/CoreProcessing.php` | Registro das primitivas de normalização e máscara |
| `src/Domain/Conditions/ConditionEvaluatorInterface.php` | Contrato de avaliação de condições |
| `src/Domain/Conditions/PermissiveConditionEvaluator.php` | Implementação segura até a F06 |
| `src/Domain/Conditions/ConditionEvaluatorRegistry.php` | Registry + hook |
| `src/Domain/Validation/ProcessedValue.php` | Resultado do pipeline |
| `src/Domain/Validation/ValueProcessor.php` | **Pipeline adapter-agnóstico** |
| `tests/Integration/F01-wccs-009-contracts-proof.php` | Prova: 36 asserções |

Alterados: `AbstractRegistry` (guarda de versão de contrato + `add_diagnostic`), `Registries` (4 registries novos, 4 hooks), `DefinitionValidator` (valida a chave `normalizer`).

## 3. Como cada cláusula foi cumprida

### "Interfaces versionadas" — ✅

Os **sete** registries declaram `contract_version()`, e o `AbstractRegistry` agora **recusa** qualquer item cujo major declarado não corresponda:

> *"The normalizer "proof.v2" from future-plugin declares contract version 2.0, but this build accepts 1.0."*

Isso significa que uma extensão escrita contra um contrato futuro **não carrega em silêncio** — vira diagnóstico em vez de corromper valores armazenados. Todos os contratos públicos (`FieldTypeInterface`, `NormalizerInterface`, `RendererInterface`, `ConditionEvaluatorInterface`) expõem `contract_version()`.

### "Mesmo contrato serve Classic/Blocks/pedidos" — ✅

O `ValueProcessor` recebe `FieldContext` e **nada mais**. A prova processa a mesma definição e o mesmo valor nos três adapters e confirma **normalização idêntica** (`123.456.789-01` → `12345678901`) e **validade idêntica** — e depois repete com `$_POST` e `$_REQUEST` vazios, obtendo o mesmo resultado.

A verificação de independência é **estrutural**, por reflexão sobre as dependências do construtor, não por leitura de texto.

### Pipeline (§10/§11) — ✅

Ordem implementada: normalizar → avaliar visibilidade → aplicar política de valor oculto → validar tipo → validadores nomeados → obrigatoriedade.

Comportamentos provados:
- **Obrigatoriedade é da definição, não do tipo.** Vazio gera `required`; um `text` sozinho trata vazio como válido.
- **`0`, `false` e `[]` têm semânticas distintas.** `0` satisfaz obrigatoriedade; `[]` conta como ausente para obrigatoriedade, mas não é o mesmo que `null`.
- **Campo oculto descarta o valor residual** (`hidden_value_policy: discard`), **não** gera erro de `required` e **nunca** é armazenável — exatamente o `§11`.
- **Tipo não registrado falha fechado** (`unknown_type`, não armazenável).
- **O normalizador nomeado roda antes da validação.**

### Máscaras declarativas — ✅

O `MaskRegistry` **recusa** definição que pareça código (`javascript:alert(1);` foi rejeitado) e **recusa** chave de configuração fora da lista permitida (`onComplete`). O `§6` proíbe colar PHP/JavaScript executável; agora isso é uma propriedade verificada, não uma recomendação.

## 4. Escopo deliberadamente vazio (e por quê)

**Nenhum renderer de core é registrado.** Declarar que um tipo é renderizável em Classic ou Blocks é uma **afirmação de capacidade**; os renderizadores só existem em F04 (Classic) e F07 (Blocks). O `§6` diz explicitamente que "ausência de componente Blocks não pode ser disfarçada com `supports: true`". Hoje a consulta de capacidade responde corretamente **"não suportado"** para todos os adapters, e a prova verifica isso.

**Nenhuma máscara brasileira.** `br.cpf`, `br.cnpj` e `br.cep` são WCCS-026/027/028. Registrei apenas as primitivas genéricas (`numeric`, `alphanumeric`) e o `DefinitionValidator` **rejeita hoje** um `normalizer: br.cnpj` — em vez de aceitar como se existisse.

## 5. Adições ao `§6` registradas

O `§6` lista 9 hooks e **não** inclui normalizadores nem avaliadores de condição — mas o `§4` define `normalizer` como chave de primeira classe da definição. Adicionei dois hooks seguindo exatamente o padrão dos existentes, com a justificativa no docblock:

- `wccs_register_normalizers`
- `wccs_register_condition_evaluators`

`wccs_register_masks` e `wccs_register_renderers` estavam previstos e agora têm destino.

## 6. Quinta ocorrência do mesmo erro de método — corrigido na raiz

A primeira execução falhou **1 de 35**: a asserção "o pipeline nunca lê a requisição, o DOM ou `WC_Order`" varria o **texto bruto** do arquivo e encontrou `WC_Order` no **meu próprio comentário** explicando que o pipeline não usa `WC_Order`.

É a **quinta vez** no projeto que uma asserção minha lê texto em vez de estrutura (WCCS-002, 003, 006, 008 e agora 009). A correção não foi só local: troquei a verificação por **reflexão sobre as dependências do construtor** mais uma **verificação comportamental** com os globais de requisição vazios.

**Convenção que passa a valer para este projeto:** asserção verifica **estrutura ou comportamento**; nunca `file_get_contents` + `strpos`. Vale registrar também que, das 5 ocorrências, **nenhuma** foi falha do ambiente.

## 7. O que NÃO foi feito

- **AST de condições, operadores e paridade PHP/JS** — F06 (WCCS-031 a WCCS-033). O que existe é o *seam* com uma implementação permissiva segura.
- **Renderizadores reais, assets e templates** — F04/F07.
- **Máscaras e validadores brasileiros, integração IMask** — WCCS-026/027/028.
- **Formatters de texto/e-mail** citados no `§6`: entram com as projeções de pedido (F10) e serão um contrato próprio; não criei um registry vazio só para constar.
- `phpcs.xml.dist`, `phpstan.neon` e CI — **WCCS-010**, a última tarefa da fase.

## 8. Próxima tarefa

**WCCS-010 — "Ativar CI desde o início"** (F01, última). Aceite: "PHPCS, análise estática, lint, typecheck e testes mínimos obrigatórios no merge." É onde finalmente entram `phpcs.xml.dist` (com as duas supressões justificadas: `WordPress.Files.FileName` para PSR-4 e `custom_capabilities` para `manage_woocommerce`), `phpstan.neon` com os stubs, e o workflow de CI. **Fecha a F01.**
