# Registro de validação — WCCS-007

**Tarefa:** WCCS-007 · "Implementar schema e registries"
**Fase:** F01 · Core, schema e contratos de extensão
**Prioridade:** `required_v1` · **Dependências:** F00, WCCS-006
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Tipos, valores e settings validados; registro externo funciona sem editar factory."

**Resultado:** `RESULT: 38 passed, 0 failed` — exit 0.
**Regressão:** a prova da WCCS-006 continua em `33 passed, 0 failed`.
**Qualidade:** `phpcs --standard=WordPress` → **exit 0**; `phpstan --level=5` com stubs WP → **`[OK] No errors`**.

## 2. Código criado

| Arquivo | Papel |
|---|---|
| `src/Domain/Fields/FieldTypeInterface.php` | **Contrato público de extensão** — 8 métodos, incluindo `key`, `valueSchema`, `settingsSchema`, `normalize`, `validate` |
| `src/Domain/Fields/AbstractFieldType.php` | Base com padrões; inclui `is_absent()` |
| `src/Domain/Fields/FieldContext.php` | Contexto confiável do servidor **e** as settings resolvidas |
| `src/Domain/Fields/ValidationResult.php` | Resultado de validação com códigos estáveis |
| `src/Domain/Fields/AbstractRegistry.php` | Registro base com diagnóstico de colisão |
| `src/Domain/Fields/FieldTypeRegistry.php` · `PresetRegistry.php` · `ValidatorRegistry.php` | Os três registries |
| `src/Domain/Fields/Preset.php` | Preset como **dado**, nunca código |
| `src/Domain/Fields/SchemaValidator.php` | Validação de settings (subconjunto pequeno de JSON Schema) |
| `src/Domain/Fields/FieldDefinition.php` | Definição canônica do `§4` com `from_array`/`to_array` |
| `src/Domain/Fields/DefinitionValidator.php` | Valida a definição contra tipo, preset e validadores |
| `src/Domain/Fields/CoreTypes.php` | Registra os 21 tipos e 15 presets **pela API pública** |
| `src/Domain/Fields/Types/*.php` (8 classes) | Implementações de comportamento |
| `src/Domain/Registries.php` | Fachada que dispara `wccs_register_field_types`, `wccs_register_presets`, `wccs_register_validators` |
| `tests/Integration/F01-wccs-007-schema-registries-proof.php` | Prova: 38 asserções |

## 3. Como cada cláusula foi cumprida

### "Tipos … validados" — ✅

Os **21 tipos** do `§5` estão registrados (`text, textarea, email, tel, url, number, select, multiselect, radio, checkbox-group, checkbox, date, time, datetime, hidden, heading, paragraph, html, file, country, state`), e a prova confirma que todos expõem o contrato completo.

**Decisão de projeto:** os 21 tipos são implementados por **8 classes de comportamento**, não por 21 classes quase idênticas. `TextFieldType` cobre 5 chaves por formato; `ChoiceFieldType` cobre 4 por multiplicidade; `TemporalFieldType` cobre 3 por formato canônico. Isso respeita o `§3` ("não acrescente camadas sem necessidade; contrato público pequeno") sem reduzir capacidade — cada chave tem seu próprio schema, normalização e validação.

Valores são validados por tipo, com destaques provados:
- e-mail malformado rejeitado; valor **ausente** aceito (obrigatoriedade é regra da definição, não do tipo);
- `select` rejeita valor fora das opções declaradas — a revalidação server-side que impede um select adulterado;
- `checkbox` mantém `false` como valor real;
- `date` aceita `Y-m-d` e rejeita formato solto;
- tipo de conteúdo declara `supports()['value'] === false` e não normaliza valor;
- **`0`, `"0"`, `false` e `[]` são distintos de ausente** — exatamente o que o `§5` exige, e o oposto de usar `empty()`.

### "settings validados" — ✅

`SchemaValidator` valida as settings contra o `settingsSchema()` do tipo, e a prova confirma:
- definição bem formada aceita;
- **setting não declarado rejeitado** (`unknown_setting`) — o guarda contra *mass assignment*;
- setting com tipo errado rejeitado;
- setting obrigatório ausente rejeitado;
- tipo não registrado, largura de layout fora de 1–12 e preset construído sobre outro tipo rejeitados.

### "registro externo funciona sem editar factory" — ✅

A prova registra um tipo de um plugin terceiro hipotético (`example.membership-code`) **pelo hook público** `wccs_register_field_types` e confirma que ele:
1. aparece no registry com sua origem declarada (`source=proof-plugin`);
2. tem sua própria definição validada sem nenhuma alteração no core;
3. normaliza e valida seus próprios valores.

Confirma também que **re-registrar a mesma chave é rejeitado e diagnosticado** — nunca sobrescrita silenciosa:

> *"The field type "example.membership-code" is already registered by proof-plugin. The registration from other-plugin was rejected."*

**Não existe factory fechada.** Os tipos do próprio core passam pela mesma API pública (`CoreTypes::register_types()`), sem caminho privilegiado — que é a correção explícita que o `§2` exige em relação ao plugin de referência.

## 4. Decisões de projeto registradas

1. **As settings viajam no `FieldContext`, não na assinatura.** O `§6` fixa `validate( mixed $value, FieldContext $context )`; sem settings o tipo não conseguiria honrar `maxLength` ou `min`. Colocá-las no contexto mantém o contrato literal e evita inventar um parâmetro extra.
2. **`Preset` é dado, não código.** Um preset nomeia um tipo e pré-preenche settings. Nada executável pode ser colado.
3. **Validadores e máscaras deliberadamente ausentes.** `br.cpf`, `br.cnpj` e as máscaras IMask pertencem a **WCCS-026/027/028**. Por isso os presets brasileiros registrados **não** referenciam validador algum, e a prova confirma que uma definição apontando para `br.cpf` é **rejeitada hoje** (`unknown_validator`) em vez de passar como se estivesse validada.

## 5. Conflitos com o próprio planejamento

1. **`§6` usa camelCase, o WordPress Coding Standards exige snake_case.** `valueSchema()` e `settingsSchema()` estão literalmente no contrato conceitual do roadmap. Renomear quebraria toda implementação de terceiros; renomear o padrão seria reescrever o planejamento. **Decisão:** manter os nomes publicados e aplicar `phpcs:ignore` **pontual e justificado** nas duas declarações, citando o `§6`. É a única supressão do projeto.
2. **`§22` não prevê `src/Domain/Registries.php` como fachada.** A estrutura lista `Domain/{Fields,Sections,Validation,Conditions}`; a fachada que dispara os hooks de registro é infraestrutura de ligação, não um módulo de domínio. Mantida em `Domain/` e registrada aqui.

## 6. Nota de método — erro meu na ferramenta de documentação

Ao corrigir as violações de docblock, um script meu foi **agressivo demais**: inseriu linhas em branco entre tags consecutivas e **duplicou** `@param` sempre que o tipo continha espaços (p. ex. `array<int, array{code: string, ...}>`), porque a expressão de detecção não reconhecia tipos compostos. Detectei pelo próprio PHPCS, escrevi um reparo determinístico (remove duplicatas, colapsa separadores indevidos) e reconferi **sintaxe + PHPCS + PHPStan + as duas provas** depois.

Lição registrada no projeto: transformações em massa sobre código exigem verificação por ferramenta **e** leitura de amostra — o `php -l` sozinho não detecta docblock corrompido.

## 7. O que NÃO foi feito

- Registries de **máscaras e renderizadores** e o contrato de condições: são **WCCS-009** (`Validation`, `Conditions`, `Renderers`) e WCCS-006/§6 hooks correspondentes.
- `SectionDefinition`: não é componente desta tarefa; a modelagem de seções é **WCCS-018**.
- Rascunho, publicação, revisões e conflito 409 são **WCCS-008**.
- `phpcs.xml.dist`, `phpstan.neon` e o pipeline de CI são **WCCS-010**. Hoje o PHPStan só produz resultado correto quando apontado para os stubs do WordPress por linha de comando; sem isso ele reporta 133 falsos "Function not found" — a configuração que resolve isso é justamente WCCS-010.

## 8. Próxima tarefa

**WCCS-008 — "Implementar draft, publicação e revisões"** (F01). Aceite: "Gravação atômica e conflito 409 testados; publicar não perde histórico." Componentes: Repository, Migrations. É onde o `expected_revision` e o CAS atômico do `§13` precisam sair do papel.
