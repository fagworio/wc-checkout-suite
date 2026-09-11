# Design tokens, field states and buttons

**Artefato da tarefa WCCS-011** (F02) · referência: `ROADMAP.md §17` e `§18`

## 1. Fonte canônica e garantias

| Arquivo | Papel |
|---|---|
| `tokens.json` | **Fonte canônica.** Temas claro e escuro, tipografia, espaçamento, raios, tamanhos, movimento e os requisitos de contraste |
| `tokens.css` | Custom properties CSS consumidas pelo admin e pelo checkout |

Três garantias são verificadas por teste, não por disciplina:

1. **Nenhum par documentado reprova.** `tests/Unit/Design/DesignTokensTest.php` calcula o contraste WCAG de cada par e falha se qualquer um ficar abaixo do seu piso.
2. **O CSS não pode divergir do JSON.** O teste lê `tokens.css`, extrai as custom properties por tema e compara valor a valor.
3. **Nenhuma cor entra sem decisão de contraste.** Um token de cor precisa aparecer em um requisito (como frente **ou** como fundo) ou estar numa lista de isenção com justificativa escrita.

## 2. Duas decisões que divergem do protótipo, de propósito

1. **Todas as custom properties são prefixadas `--wccs-`.** Os tokens são injetados no `wp-admin`, onde outros plugins já definem nomes genéricos como `--brand`. Reutilizar esses nomes faria a aparência deste plugin depender da ordem de carregamento.
2. **Nada é declarado em `:root`.** Os tokens vivem apenas nos dois escopos que o plugin realmente renderiza, `.wccs-admin` e `.wccs-checkout`, de modo que ativar o plugin não altera nenhuma outra tela do admin.

O protótipo usava os nomes sem prefixo e em `:root`; ali isso é inofensivo, porque é uma página isolada. Nenhum **valor** de cor foi alterado — a paleta do `PROTOTIPO.html` e a tabela do `ROADMAP.md §17` coincidem integralmente.

## 3. Contraste medido

Pisos: **4.5:1** para texto normal, **3:1** para texto grande e para componentes de interface (WCAG 2.2 AA).

| Theme | Pair | Measured | Floor | Margin |
|---|---|---|---|---|
| light | `ink` on `surface` | **15.54:1** | 4.5 | +11.04 |
| light | `ink` on `app_background` | **14.52:1** | 4.5 | +10.02 |
| light | `ink_muted` on `surface` | **5.43:1** | 4.5 | +0.93 |
| light | `ink_muted` on `app_background` | **5.07:1** | 4.5 | +0.57 |
| light | `ink_muted` on `brand_subtle` | **4.75:1** | 4.5 | +0.25 |
| light | `brand` on `surface` | **7.10:1** | 4.5 | +2.60 |
| light | `brand` on `brand_subtle` | **6.21:1** | 4.5 | +1.71 |
| light | `ink` on `brand_subtle` | **13.59:1** | 4.5 | +9.09 |
| light | `on_brand` on `brand` | **7.10:1** | 4.5 | +2.60 |
| light | `on_brand` on `brand_hover` | **8.98:1** | 4.5 | +4.48 |
| light | `on_brand` on `danger` | **6.49:1** | 4.5 | +1.99 |
| light | `success` on `surface` | **4.91:1** | 4.5 | +0.41 |
| light | `warning` on `surface` | **5.43:1** | 4.5 | +0.93 |
| light | `danger` on `surface` | **6.49:1** | 4.5 | +1.99 |
| light | `border_control` on `surface` | **3.24:1** | 3.0 | +0.24 |
| light | `focus_ring` on `surface` | **7.10:1** | 3.0 | +4.10 |
| light | `on_sidebar` on `sidebar_surface` | **14.51:1** | 4.5 | +10.01 |
| dark | `ink` on `surface` | **14.14:1** | 4.5 | +9.64 |
| dark | `ink` on `app_background` | **16.24:1** | 4.5 | +11.74 |
| dark | `ink_muted` on `surface` | **7.94:1** | 4.5 | +3.44 |
| dark | `ink_muted` on `app_background` | **9.12:1** | 4.5 | +4.62 |
| dark | `brand` on `surface` | **6.88:1** | 4.5 | +2.38 |
| dark | `brand` on `app_background` | **7.90:1** | 4.5 | +3.40 |
| dark | `ink` on `brand_subtle` | **12.99:1** | 4.5 | +8.49 |
| dark | `brand` on `brand_subtle` | **6.32:1** | 4.5 | +1.82 |
| dark | `on_brand` on `brand` | **7.83:1** | 4.5 | +3.33 |
| dark | `on_brand` on `brand_hover` | **9.97:1** | 4.5 | +5.47 |
| dark | `success` on `surface` | **9.85:1** | 4.5 | +5.35 |
| dark | `warning` on `surface` | **9.34:1** | 4.5 | +4.84 |
| dark | `danger` on `surface` | **7.93:1** | 4.5 | +3.43 |
| dark | `border_control` on `surface` | **4.38:1** | 3.0 | +1.38 |
| dark | `focus_ring` on `surface` | **6.88:1** | 3.0 | +3.88 |
| dark | `on_sidebar` on `sidebar_surface` | **15.35:1** | 4.5 | +10.85 |

**33 pares, nenhum reprovado.** As margens mais apertadas são deliberadas e ficam registradas:

- `light.border_control` (**+0.24**) — é a borda que identifica o input; o piso é 3:1, não 4.5:1.
- `light.ink_muted` sobre `brand_subtle` (**+0.25**) — texto de ajuda dentro de uma linha selecionada.
- `light.success` sobre `surface` (**+0.41**) — mensagem de sucesso.

Um teste falha se qualquer margem cair abaixo de `0.2`, para que um ajuste futuro que deixe um par "passando por pouco" apareça como decisão, não como surpresa.

### Isenção registrada

| Token | Motivo |
|---|---|
| `border_decorative` | Separação puramente decorativa. Nunca é o único meio de identificar um controle — esse papel é de `border_control`, que **é** testado em 3:1 |

## 4. Campos

Altura fixa de **48px** (`--wccs-size-field-height`), raio **10px** (`--wccs-radius-field`), label sempre visível, placeholder **nunca** substitui label, e o erro nunca depende só de cor.

| Estado | Como é expresso | Tokens |
|---|---|---|
| Vazio | Borda de controle sobre a superfície | `border-control` (3.24:1) |
| Hover | Borda de controle, sem mudança de cor de texto | `border-control`, `ink` |
| Foco | Contorno de **3px** com `focus-ring`, `outline-offset` preservado | `focus-ring` (7.10:1) |
| Preenchido | Texto principal | `ink` (15.54:1) |
| Válido | Borda de sucesso **e** texto de confirmação | `success` (4.91:1) + ícone/palavra |
| Inválido | Borda de perigo **e** mensagem sob o campo | `danger` (6.49:1) + ícone/palavra |
| Desativado | Opacidade reduzida, `cursor: not-allowed`; permanece legível | `ink`, `ink-muted` |
| Somente leitura | Mesmo contraste do preenchido, sem aparência de editável | `ink` |
| Validação remota | Estados `idle`/`checking`/`valid`/`invalid`/`unavailable`; `checking` **nunca** é apresentado como válido | `ink-muted`, `success`, `danger` |

**Regra transversal:** onde a cor comunica estado (válido, inválido, aviso), ela vem sempre acompanhada de palavra ou ícone. Cor nunca é o único sinal — é o que o `§17` determina e o que torna as isenções acima defensáveis.

## 5. Botões

| Variante | Aparência | Contraste medido |
|---|---|---|
| Primário | `brand` de fundo, `on_brand` no texto | **7.10:1** (claro) · **7.83:1** (escuro) |
| Primário (hover) | `brand_hover` de fundo | **8.98:1** (claro) · **9.97:1** (escuro) |
| Destrutivo | `danger` de fundo, `on_brand` no texto | **6.49:1** |
| Secundário | `surface` de fundo, `ink` no texto, `border-control` de contorno | **15.54:1** texto |
| Somente ícone | **Obrigatoriamente** com nome acessível; área de toque de 44px | Herda a variante |

Alturas: ações primárias entre **44px** e **48px**; área de toque preferencial de **44px** (`--wccs-size-touch-target`).

## 6. Tipografia, espaçamento e forma

- **Família:** stack de sistema, **sem download de fonte externa**. O admin nunca espera por um terceiro e nenhuma licença de fonte é implicada.
- **Tamanhos:** corpo do admin 14px · formulário público 16px · label 14px · ajuda 13px · legenda 12px · títulos 15/19/28px.
- **Pesos:** 400 / 550 / 650 / 750.
- **Espaçamento:** escala 4 / 8 / 12 / 16 / 24 / 32 / 48.
- **Raios:** controle 9 · campo 10 · linha 11 · painel 14 · card 16 · diálogo 18 · pill 99.
- **Movimento:** `0.14s` e `0.15s`; `prefers-reduced-motion: reduce` desliga transições, animações e rolagem suave.

## 7. O que ainda não existe

Nenhum componente foi construído. Este artefato entrega **tokens e regras**; `AppShell`, `FieldShell`, `Button` e os demais componentes do `§17` chegam em **WCCS-012** e **WCCS-013**. O CSS aqui publicado é apenas a camada de variáveis — não há ainda uma única regra de componente, o que é intencional: sem os componentes, qualquer regra seria adivinhação.

O tema escuro se aplica **somente ao admin**. A preferência do admin nunca muda o checkout da loja, como o `§17` exige.
