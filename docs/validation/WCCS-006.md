# Registro de validação — WCCS-006

**Tarefa:** WCCS-006 · "Criar bootstrap e build"
**Fase:** F01 · Core, schema e contratos de extensão (primeira tarefa da fase)
**Prioridade:** `required_v1` · **Dependências:** F00
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Ativação segura sem Woo; assets compiláveis; namespace e i18n definidos."

**Resultado da prova:** `RESULT: 33 passed, 0 failed, 3 notes` — exit code 0.

## 2. Código criado (primeira implementação do projeto)

| Arquivo | Papel |
|---|---|
| `wc-checkoutsuite.php` | Arquivo principal: cabeçalho, guarda de PHP, constantes de identidade, autoloader PSR-4 próprio, i18n, hooks de ativação e `plugins_loaded` |
| `src/Plugin.php` | Bootstrap: verificação de requisitos, recusa de carregar sem Woo, hook de extensão `wccs_booted`, ativação/desativação não destrutivas |
| `src/Support/Requirements.php` | Avaliação **pura** de requisitos (recebe as versões, não lê globais) — é o que torna "ativação sem Woo" testável |
| `resources/admin/index.js` | Entrada de build do admin |
| `package.json` / `composer.json` | Toolchain de build e de qualidade |
| `.gitignore` | Exclui `node_modules`, `vendor` e `build` do repositório |
| `languages/wc-checkoutsuite.pot` | Catálogo gerado (6 strings, domínio e versão corretos) |
| `tests/Integration/F01-wccs-006-bootstrap-proof.php` | Prova executável: 33 asserções |

## 3. Como cada cláusula foi cumprida

### "Ativação segura sem Woo" — ✅

- O cabeçalho **não** usa `Requires Plugins` (que bloquearia a ativação). Em vez disso, `Plugin::boot()` avalia os requisitos em `plugins_loaded` e, se faltar algo, exibe aviso e **não carrega mais nada**.
- `Requirements::evaluate()` é uma função pura. A prova alimenta os três cenários e confirma:
  - WooCommerce **ausente** → `{key: woocommerce, reason: missing}`, sem fatal;
  - WooCommerce **desatualizado** (`9.0.0` < `11.1`) → `{reason: outdated, observed: 9.0.0}`;
  - ambiente satisfeito → `[]`.
- **Ativação real verificada no site:** o plugin foi ativado, as constantes, o autoloader sem Composer, `Plugin::is_booted() === true`, `did_action('wccs_booted') === 1` e a idempotência do boot foram observados. Em seguida foi **desativado** e o estado original confirmado (`active_plugins` idêntico ao inicial; opção `_wccs_requirements_unmet` ausente; `wp_wc_orders = 0`).
- Nenhuma classe do WooCommerce é instanciada na verificação: a versão é lida da constante `WC_VERSION`.

### "Assets compiláveis" — ✅

```
webpack 5.110.3 compiled successfully in 648 ms
asset index.js 211 bytes [emitted] [minimized]
asset index.asset.php 93 bytes [emitted]
```

`build/admin/index.asset.php` → `['dependencies' => ['wp-i18n'], 'version' => '11f4da1b95715e74f083']`.

Isso prova o requisito do `ROADMAP.md §18` ("dependências extraídas no build para não embutir outra cópia de React"): o bundle tem **211 bytes** e **zero** ocorrências de `react`.

### "Namespace e i18n definidos" — ✅

- Namespace `WCCheckoutSuite\`, PSR-4 → `src/`, com autoloader próprio registrado no bootstrap. **O plugin funciona sem Composer**, como exige o `§18` ("O lojista instala o ZIP sem executar npm ou Composer"). `vendor/autoload.php` só é carregado se existir.
- Text domain `wc-checkoutsuite` carregado no hook `init`; `language/` com POT gerado por `wp i18n make-pot`.
- **Cadeia de tradução provada de ponta a ponta:** um catálogo `pt_BR` temporário foi compilado com `msgfmt`, a tradução foi aplicada (`"WC CheckoutSuite"` → `"WC CheckoutSuite TRADUZIDO"`, `"%1$s is inactive. %2$s"` → `"X esta inativo. Y"`) e o catálogo temporário foi **removido** depois, deixando apenas o POT no repositório.

## 4. Achados desta tarefa

1. **`NODE-RUNTIME` ganhou evidência conclusiva.** `@wordpress/scripts` 35.0.0 exige Node `^20.19.0 || >=22.13.0`. O container tem **Node 18.13.0** → **não atende**. O build foi executado no **host** (Node 22.21.1 / npm 10.9.4). A alternativa "build só no container" exigiria atualizar o Node da imagem, mudança **fora da raiz do plugin**.
2. **Conflito real entre PSR-4 e o WordPress Coding Standards.** `phpcs --standard=WordPress` acusa 4 erros, **todos** de convenção de nome de arquivo:
   `Expected plugin.php, but found Plugin.php` e `Class file names should be based on the class name with "class-" prepended`.
   O `§18` exige PSR-4; o WPCS espera `class-plugin.php`. O WooCommerce core resolve exatamente isso excluindo `WordPress.Files.FileName`. **Com essa exclusão o lint fica limpo (0 erros).** A configuração `phpcs.xml.dist` que codifica a exclusão pertence a **WCCS-010** e não foi criada aqui para não antecipar escopo.
3. **`is_textdomain_loaded()` não é indicador confiável no WordPress 7.1.** A tradução foi aplicada com `is_textdomain_loaded()` retornando `false` — o carregamento passa pelo registro de text domains. Prova de i18n deve observar a **saída traduzida**, não esse predicado.
4. **Metadados comerciais deliberadamente ausentes.** `Author`, `Author URI`, `Plugin URI`, `License` e `License URI` foram omitidos em vez de inventados; são decisão de distribuição comercial de **WCCS-067**.
5. **Pisos de versão são provisórios.** `PHP 8.2`, `WP 7.1`, `WC 11.1` espelham o único runtime verificável localmente e estão marcados como provisórios no código, a serem ratificados por WCCS-010 e WCCS-062.

## 5. Nota de transparência

A primeira execução deu 32/33 por **erro da minha asserção**: eu procurava o texto `Requires Plugins` no arquivo bruto, e ele aparecia no meu **próprio comentário** explicando por que o cabeçalho está ausente. Corrigi para ler o bloco de cabeçalho com `get_file_data()`. Quarta ocorrência do mesmo padrão no projeto (asserção errada, ambiente correto) — vale registrar como risco de método: **toda asserção deve ler a estrutura, nunca o texto bruto**.

## 6. O que NÃO foi feito

- `phpcs.xml.dist`, `phpstan.neon` e o pipeline de CI — são **WCCS-010**.
- Nenhum módulo de domínio, schema ou registry — são **WCCS-007** em diante. `wccs_booted` é o único ponto de extensão e dispara sem nenhum assinante.
- `uninstall.php` não foi criado: a política de remoção é WCCS-068.
- Nada foi validado em navegador; não há interface ainda.

## 7. Próxima tarefa

**WCCS-007 — "Implementar schema e registries"** (F01). Aceite: "Tipos, valores e settings validados; registro externo funciona sem editar factory." Componentes: Domain, Types, Presets. É a primeira tarefa com domínio real e deve materializar o contrato `FieldTypeInterface` do `§6`, além do critério de extensão do `§6` (tipo registrado por plugin externo sem alterar o core).
