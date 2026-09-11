# Registro de validação — WCCS-012

**Tarefa:** WCCS-012 · "Criar shell dentro do WordPress"
**Fase:** F02 · Design system e shell administrativo
**Prioridade:** `required_v1` · **Dependências:** F01, WCCS-011
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Navegação e assets restritos à tela da Suite; responsividade funcional."

**Resultado:** `RESULT: 36 passed, 0 failed` — exit 0.
**Suíte completa:** 8 provas de integração = **250 asserções, 0 falhas**; suíte unitária **57 testes / 322 asserções**.
**Gates:** PHPCS exit 0 · PHPStan `[OK] No errors` · build, `lint:js` e `check-types` verdes.
**Estado:** plugin desativado, `active_plugins` idêntico ao inicial, nenhuma opção criada.

## 2. Código criado

| Arquivo | Papel |
|---|---|
| `src/Admin/AdminMenu.php` | Submenu em `WooCommerce → WC CheckoutSuite`, as 8 seções do `§16`, o nó de montagem |
| `src/Admin/Assets.php` | Gate por tela, manifesto de build, tokens, traduções do bundle, payload de bootstrap |
| `resources/admin/index.js` | Monta o shell no nó de montagem |
| `resources/admin/app/AppShell.js` | Shell React: navegação, cabeçalho e região de conteúdo |
| `resources/admin/app/app.css` | Layout e responsividade, 100% sobre tokens |
| `tests/Integration/F02-wccs-012-admin-shell-proof.php` | Prova: 36 asserções |

## 3. Como cada cláusula foi cumprida

### "Assets restritos à tela da Suite" — ✅

O gate é uma única comparação de screen id, e a prova o exercita nos dois sentidos:

| Tela | Resultado |
|---|---|
| `woocommerce_page_wccs-checkoutsuite` | **enfileirado** (script, tokens e CSS) |
| `edit-post`, `plugins`, `index`, `woocommerce_page_wc-settings` | **nada enfileirado** |
| `woocommerce_page_wccs-checkoutsuite-extra` | **nada** — o gate é igualdade, não prefixo |
| vazio | **nada** |

Dependências e versão de cache vêm do **manifesto do build** (`index.asset.php`), não de uma lista escrita à mão: `react-jsx-runtime, wp-element, wp-i18n`, versão `9f517fa8241a5b95df98`.

### "Navegação" — ✅

As **8 seções do `§16`** na ordem exata: `fields, sections, rules, appearance, checkout-page, import-export, diagnostics, settings`. O menu aparece sob `woocommerce` com a capability `manage_woocommerce` — verificado no `$submenu` real após disparar `admin_menu`.

O shell tem link de salto, `aria-current` na seção ativa e navegação por `<button>` (acionável por teclado e por leitor de tela).

### "Responsividade funcional" — ✅

Verificado estruturalmente no CSS, porque medição visual é WCCS-063:

- os três breakpoints do CSS **coincidem com os tokens** (760 / 1020 / 1550);
- em ≤760px o shell colapsa para **uma coluna** e a navegação vira **faixa horizontal**;
- em ≤1020px a rail encolhe para 64px em vez de comprimir o conteúdo, e os rótulos ocultos continuam **expostos a tecnologia assistiva** (`clip: rect(0,0,0,0)`, não `display: none`);
- `prefers-reduced-motion: reduce` desliga transições;
- **31 tokens distintos** são usados e **todos** existem em `tokens.css`;
- o shell **não redefine nenhum seletor global** — todo seletor contém `.wccs-shell`.

Essa última verificação é a que impede o plugin de contaminar o `wp-admin`, que o `§17` proíbe explicitamente.

## 4. Bug de projeto que a prova encontrou

Eu havia envolvido o registro do menu e dos assets num guarda `if ( is_admin() )`, raciocinando que "o storefront não deve pagar por código de admin". Parecia economia; era um defeito:

- `admin_menu` e `admin_enqueue_scripts` **nunca disparam** fora do `wp-admin`, então o guarda não economizava nada;
- ele tornava o código administrativo **impossível de registrar e de exercitar fora do admin** — inclusive por teste.

O gate que realmente importa é o de **tela**, e esse continua no lugar. Removi o guarda com a justificativa registrada no código. A prova passou de 2 falhas para verde com essa única mudança.

## 5. Regressão detectada e corrigida

Depois de mudar o `Plugin::boot()`, a prova da **WCCS-006 caiu de 33 para 32**. A asserção quebrada era *"No copy of React or @wordpress packages is bundled"*, que fazia `stripos( $bundle, 'react' )`.

O bundle agora contém `ReactJSXRuntime` — **o nome do externo ao qual ele se liga**, exatamente o que se espera de um pacote corretamente externalizado. A asserção estava errada desde que foi escrita; só passava porque o bundle era minúsculo.

Substituída por verificação **estrutural**: o manifesto declara `wp-element` e `wp-i18n` (um externo, por definição, não é empacotado) **e** o bundle lê `window.wp.element`. Sem varrer o texto em busca de uma palavra.

Sem a suíte de regressão, isso teria passado despercebido.

## 6. Outros achados

1. **`add_submenu_page()` retorna cedo sem a capability.** No WP-CLI não há usuário, então o menu simplesmente não era registrado. O teste precisa autenticar como administrador antes de disparar `admin_menu`.
2. **Um plugin de terceiro mata `do_action('admin_enqueue_scripts')` em CLI** (saída 255, sem mensagem). A prova passou a chamar o **nosso** callback diretamente — o que testa melhor o nosso código e não os plugins alheios. O vínculo com o hook continua provado por `has_action()`.
3. **`PHPStan` estourou 512 MB** com os workers paralelos. O `composer analyse` passou a declarar `--memory-limit=1G`, para que local e CI concordem.
4. **Sétima ocorrência do mesmo erro de método:** a verificação de "nenhum seletor global" incluía os **comentários** do CSS, cujas vírgulas viravam separadores de seletor. A correção foi remover comentários antes de analisar — mesma família dos casos anteriores, nenhum deles falha do ambiente.

## 7. O que NÃO foi feito

- **Nenhuma verificação visual ou de navegador.** As asserções de responsividade são estruturais: provam que as regras existem, usam tokens reais e estão no escopo certo. Medir larguras reais, foco e leitor de tela é **WCCS-063** (F12).
- **Nenhum componente de interface.** O conteúdo do shell declara em texto que o editor chega na próxima etapa, em vez de exibir uma interface falsa. `Button`, `Dialog`, `FieldShell` e os demais do `§17` são **WCCS-013**.
- **Nenhuma chamada REST.** O payload de bootstrap carrega apenas navegação e versão; nonce, cliente HTTP e tratamento de 403/409/422 são **WCCS-014**.
- **Nenhuma prévia visual.** É **WCCS-015**.

## 8. Próxima tarefa

**WCCS-013 — "Criar componentes básicos"** (F02). Aceite: *"Botões, diálogos, tabs, mensagens, badges e formulários têm teclado e foco."* É a biblioteca visual sobre os tokens da WCCS-011, e a primeira vez que o produto terá controles reutilizáveis de verdade.
