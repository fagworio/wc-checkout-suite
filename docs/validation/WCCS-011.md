# Registro de validação — WCCS-011

**Tarefa:** WCCS-011 · "Formalizar tokens e estados"
**Fase:** F02 · Design system e shell administrativo (primeira tarefa da fase)
**Prioridade:** `required_v1` · **Dependências:** F01
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Tokens claros/escuros, campos e botões documentados sem contraste reprovado."

**Resultado:** `OK (57 testes, 322 asserções)` na suíte unitária · **33 pares de contraste medidos, 0 reprovados** · PHPCS exit 0 · PHPStan `[OK] No errors` · as 7 provas de integração (214 asserções) seguem verdes.

## 2. O artefato que faltava desde o primeiro reconhecimento

Na primeira execução registrei que `DESIGN-TOKENS.json` era **citado pelo planejamento e não existia em disco** — o `ROADMAP.md §12` afirmava que ele "contém os tokens de interface". Ele agora existe, no lugar que o `§22` prevê:

| Arquivo | Papel |
|---|---|
| `resources/design-tokens/tokens.json` | **Fonte canônica**: temas claro e escuro, tipografia, espaçamento, raios, tamanhos, movimento e requisitos de contraste |
| `resources/design-tokens/tokens.css` | Custom properties CSS |
| `resources/design-tokens/README.md` | Campos, botões, estados e a tabela de contraste medida |

Os valores vêm do `PROTOTIPO.html` e **coincidem integralmente** com a tabela do `ROADMAP.md §17` — verificado par a par. Nenhuma cor foi inventada.

## 3. "Sem contraste reprovado" é medido, não afirmado

O teste calcula a razão WCAG de cada par documentado e falha abaixo do piso. Resultado: **33 pares, nenhum reprovado.**

As margens mais apertadas ficam registradas em vez de escondidas:

| Par | Medido | Piso | Margem |
|---|---|---|---|
| `light.border_control` sobre `surface` | 3.24:1 | 3.0 | **+0.24** |
| `light.ink_muted` sobre `brand_subtle` | 4.75:1 | 4.5 | **+0.25** |
| `light.success` sobre `surface` | 4.91:1 | 4.5 | **+0.41** |

Um teste falha se qualquer **margem** cair abaixo de `0.2`, para que um ajuste futuro que deixe um par passando por pouco apareça como decisão, não como surpresa.

## 4. Três garantias que impedem o design system de apodrecer

1. **O CSS não pode divergir do JSON.** O teste extrai as custom properties por tema e compara valor a valor com o arquivo canônico.
2. **Nenhuma cor entra sem decisão.** Um token de cor precisa aparecer num requisito — como frente **ou** como fundo — ou estar numa lista de isenção com justificativa escrita.
3. **Os dois temas têm exatamente os mesmos nomes de token.** Uma cor adicionada só ao tema claro quebra o teste.

A única isenção é `border_decorative`, com o motivo registrado no próprio JSON: é separação puramente decorativa e nunca é o único meio de identificar um controle. Esse papel é de `border_control`, que **é** testado em 3:1.

## 5. Duas divergências deliberadas do protótipo

1. **Todas as custom properties são prefixadas `--wccs-`.** Os tokens entram no `wp-admin`, onde outros plugins já definem nomes genéricos como `--brand`. Reutilizá-los faria a aparência deste plugin depender da ordem de carregamento.
2. **Nada é declarado em `:root`.** Os tokens vivem só em `.wccs-admin` e `.wccs-checkout`, então ativar o plugin não altera nenhuma outra tela do admin.

Nenhum **valor** mudou. O protótipo usava nomes sem prefixo em `:root`; ali isso é inofensivo porque é uma página isolada.

## 6. Lacuna real que a verificação encontrou

O teste de cobertura acusou **10 tokens sem decisão de contraste**. Nove eram regra minha mal formulada — eu contava apenas o lado "frente" do par, ignorando que `surface`, `app_background` e `brand_subtle` são fundos legítimos e já testados. **Um era uma lacuna de verdade:** nada validava o texto sobre `sidebar_surface`.

O protótipo usa texto claro (`#e4e8f1`) sobre a sidebar escura em ambos os temas, mas esse valor nunca havia sido tokenizado. Adicionei `on_sidebar` aos dois temas, com requisito próprio — medido em **14.51:1** (claro) e **15.35:1** (escuro). Sem o teste de cobertura, esse buraco passaria.

## 7. Achados

1. **`file_get_contents` é recusado pelo WPCS.** A correção certa não foi suprimir: o WordPress 7.1 tem `wp_json_file_decode()`, que é exatamente a função para ler JSON local. Trocada, com shim fiel no bootstrap dos testes.
2. **Mensagens de exceção também precisam de escape** para o WPCS (`ExceptionNotEscaped`) — aplicado em `Contrast` e `DesignTokens`.
3. **Sexta ocorrência do mesmo erro de método:** minha primeira asserção de "par mais apertado" comparava o par mais apertado com **4.5**, mas `control.boundary` é um par de componente de UI cujo piso é **3.0**. A métrica correta é a margem sobre o piso **de cada par**. Registro que, das 6 ocorrências, **nenhuma** foi falha do ambiente.

## 8. O que NÃO foi feito

- **Nenhum componente foi construído.** O `tokens.css` publicado é apenas a camada de variáveis: não há uma única regra de componente. `AppShell` e o shell do admin são **WCCS-012**; `Button`, `Dialog`, `FieldShell` e os demais do `§17` são **WCCS-013**. Escrever regras de componente antes dos componentes seria adivinhação.
- **Nenhuma medição em navegador.** Os contrastes são calculados a partir dos tokens; a verificação visual e de teclado é **WCCS-063** (F12).
- **O tema escuro existe só como tokens.** Nenhuma tela o aplica ainda; a preferência do admin nunca deve alcançar o checkout, e isso será verificado quando houver telas.

## 9. Próxima tarefa

**WCCS-012 — "Criar shell dentro do WordPress"** (F02). Aceite: *"Navegação e assets restritos à tela da Suite; responsividade funcional."* Componentes: Admin React. É a primeira tela real do produto: menu em `WooCommerce → WC CheckoutSuite`, assets enfileirados **somente** na tela da Suite, e o `AppShell` consumindo os tokens agora formalizados.
