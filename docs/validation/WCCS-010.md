# Registro de validação — WCCS-010

**Tarefa:** WCCS-010 · "Ativar CI desde o início"
**Fase:** F01 · Core, schema e contratos de extensão (última tarefa da fase)
**Prioridade:** `required_v1` · **Dependências:** F00, WCCS-006 a WCCS-009
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA — gate da F01 fechado

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "PHPCS, análise estática, lint, typecheck e testes mínimos obrigatórios no merge."

## 2. Resultado dos gates, executados

| Gate | Comando | Resultado |
|---|---|---|
| Padrões de código | `composer lint` → `phpcs` | **exit 0** |
| Análise estática | `composer analyse` → `phpstan` level 5 | **`[OK] No errors`** |
| Testes mínimos | `composer test` → `phpunit` | **OK, 41 testes, 92 asserções** |
| Build | `npm run build` | **compiled successfully**, 211 bytes |
| Lint de JS | `npm run lint:js` | **0 problemas** |
| Typecheck | `npm run check-types` → `tsc --noEmit` | **exit 0** |
| Agregado | `composer check` | **os três gates verdes em sequência** |

## 3. Arquivos criados

| Arquivo | Papel |
|---|---|
| `phpcs.xml.dist` | Padrão WordPress + PHPCompatibilityWP; `WordPress.Files.FileName` excluído pelo PSR-4; `custom_capabilities` declara `manage_woocommerce`; prefixos `wccs`/`WCCS`/`WCCheckoutSuite`/`WCCheckoutSuiteExample`; dois text domains |
| `phpstan.neon.dist` | Level 5, PHP 8.2, stubs de WordPress **e** WooCommerce, `tests/Integration` fora da análise |
| `phpunit.xml.dist` · `tests/bootstrap-unit.php` | Suíte unitária sem WordPress, com shims mínimos e documentados |
| `tests/Unit/**` (7 classes + 2 stubs) | Testes reais do domínio |
| `tsconfig.json` · `resources/types/globals.d.ts` | Typecheck de JavaScript (`checkJs`) |
| `.github/workflows/ci.yml` | 3 jobs: `php-quality`, `php-unit`, `frontend` |
| `examples/custom-field-type/` | **Extensão de exemplo mínima** exigida pelo gate da F01 |

## 4. O gate da F01 está fechado

O gate literal da fase é *"Plugin-base instalável com schema versionado, concorrência e extensão de exemplo mínima"*:

| Requisito do gate | Onde |
|---|---|
| Plugin-base instalável | WCCS-006 — ativação real verificada, `33/33` |
| Schema versionado | WCCS-008 — `schema_version` + histórico de migração |
| Concorrência | WCCS-008 — CAS atômico e conflito **409** |
| **Extensão de exemplo mínima** | `examples/custom-field-type/` — provado agora |

A extensão de exemplo registra `example.membership-code` pelo hook público, com origem `wccs-example`, normalizando e validando com **o mesmo contrato do core** e sem editar um único arquivo do plugin. Ela **não** declara renderer, então a consulta de capacidade continua respondendo "não suportado" — a resposta honesta até F04/F07 existirem.

## 5. O gate de lint não é decorativo — foi testado por violação deliberada

Um arquivo com `var_dump()` e variável global sem prefixo foi inserido em `src/`:

- **PHPCS** → `exit 1`, 2 erros e 1 aviso, nomeando arquivo e linha;
- **PHPStan** → `[ERROR] Found 1 error`;
- arquivo removido → **PHPCS exit 0**.

Sem isso, "PHPCS verde" poderia significar apenas que o sniff não está olhando para nada.

## 6. Duas supressões, ambas codificadas no ruleset

O projeto agora tem **zero** `phpcs:ignore` para capacidades — a supressão pontual em `SchemaController` foi **removida** e substituída por `custom_capabilities` no ruleset, que é a correção correta. Restam apenas duas supressões, ambas de contrato, não de conveniência:

1. `WordPress.Files.FileName` excluído no ruleset — PSR-4 é mandato do `§18` e o WooCommerce core faz a mesma exclusão.
2. `valueSchema()` / `settingsSchema()` — nomes fixados pelo contrato publicado no `§6`.

## 7. Achados

1. **`phpunit` não estava instalado.** Adicionado como dependência de desenvolvimento (`^9.6`). Sem ele "testes mínimos obrigatórios" seria uma promessa vazia.
2. **`@wordpress/i18n` era dependência implícita.** O ESLint recusou o import até ser declarado explicitamente em `devDependencies`. Dependência extraída no build não significa dependência implícita no manifesto.
3. **Falta a configuração `tsc` para globais do navegador.** `window.wccsAdminBootstrap` precisava de declaração ambiente (`resources/types/globals.d.ts`) — o typecheck pegou isso na primeira execução.
4. **O `WordPress.WP.I18n` recusou o text domain do exemplo.** A extensão de exemplo é um plugin separado e deve ter o **próprio** text domain (`wccs-example`); o ruleset agora declara os dois, o que mantém a regra ativa em vez de silenciá-la.
5. **`tests/Integration` não é lintado nem analisado.** São scripts executados por `wp eval-file` que imprimem em stdout e consomem hooks não prefixados do WooCommerce. Ficam fora do padrão por escopo, com a razão escrita no próprio ruleset — não por conveniência.

## 8. O que NÃO foi executado

- **O workflow de CI nunca rodou.** Não existe repositório Git para o plugin (`GIT-REPOSITORY` continua aberto), portanto não há GitHub Actions. O YAML foi **validado sintaticamente** (3 jobs, 15 passos) e cada comando que ele invoca foi executado **localmente** e passou. Isso é diferente de dizer que o workflow funciona, e a distinção fica registrada.
- **Cobertura de código não foi medida.** O `phpunit.xml.dist` declara quais arquivos contar, mas nenhum relatório de cobertura foi gerado nem há limite mínimo definido.
- **As provas de integração não rodam em CI.** Um runner não tem WordPress, WooCommerce nem sandbox de pagamento; um gate que se auto-ignora seria pior que gate nenhum. Elas rodam contra o Devilbox local — e a razão está escrita no cabeçalho do workflow.

## 9. Próxima fase

**F02 — Design system e shell administrativo** (WCCS-011 a WCCS-015). Primeira tarefa: **WCCS-011 — "Formalizar tokens e estados"**, aceite *"Tokens claros/escuros, campos e botões documentados sem contraste reprovado"*.

**Pendências que seguem abertas e agora bloqueiam trabalho real:** `GIT-REPOSITORY` impede o CI de existir de fato; `PHP-BASELINE` e `NODE-RUNTIME` estão em uso provisório documentado; e o `DESIGN-TOKENS.json` citado pelo planejamento continua **ausente**, o que faz de WCCS-011 a tarefa que precisa produzi-lo a partir dos tokens extraídos do `PROTOTIPO.html`.
