# Registro de validação — WCCS-063

**Tarefa:** WCCS-063 · "Executar QA visual e a11y"
**Fase:** F12 · Hardening, acessibilidade e matriz final
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "320/375/768/1280/1440px, zoom, foco e reduced motion revisados."

**Resultado:** **50 asserções de browser na loja** (0 falhas) nas cinco larguras, **37 asserções sobre o componente do plugin** (0 falhas) nas cinco larguras mais foco, teclado, reduced motion, tokens e zoom, **13 asserções de integração** numa prova nova da entrega, e os gates verdes.

## 2. Os dois instrumentos

| instrumento | o que observa |
|---|---|
| `tests/browser/checkout-observation.mjs` | a página real da loja: as cinco larguras, overflow, o payload e o bundle entregues, o campo nativo que o documento produziu no checkout renderizado, foco, reduced motion e zoom |
| `tests/browser/field-component-observation.mjs` | o componente do próprio plugin: a região que ele desenha, num documento que o script possui, com o bundle construído e as folhas de estilo que a loja serve |

A separação não é conveniência. O plugin desenha os campos controlados através de um bloco que regista no checkout de blocos, e o WooCommerce só renderiza os blocos internos que a **página** contém. A página desta loja não contém esse bloco, e colocá-lo lá seria editar a página do lojista. O componente é, por isso, exercitado num documento próprio — e a ausência na loja é registada com a sua razão, não contada como defeito.

## 3. As cinco larguras

| largura | largura de loja | componente |
|---|---|---|
| 320px | 200, sem overflow, checkout servido | região 320px, controlo 118px de altura, rótulo associado |
| 375px | 200, sem overflow | região 375px, 118px |
| 768px | 200, sem overflow | região 768px, 118px |
| 1280px | 200, sem overflow | região 1280px, 118px |
| 1440px | 200, sem overflow | região 1440px, 118px |

O controlo tem 118px de altura em todas — acima do mínimo de 40px que a asserção exige para um alvo táctil.

## 4. Foco, teclado, reduced motion, zoom e tokens

- **Foco:** o controlo é o elemento ativo e conserva um anel visível (`outline: 3px solid`), medido no componente. Na loja, o primeiro controlo do checkout focado também mantém 2px de outline.
- **Teclado:** a partir do controlo, `Tab` sai e `Shift+Tab` volta — um controlo que não se abandona é uma armadilha de teclado, e é assim que se encontra uma. O `Tab` foi enviado pelo browser, não por um evento sintético.
- **Reduced motion:** com `prefers-reduced-motion: reduce`, nenhum elemento da região declara transição acima de 0,5s (0 elementos, dos dois lados).
- **Zoom:** a 200%, sem overflow horizontal (0px), medido no documento do componente. O `zoom` é aplicado por CSS porque o Playwright não conduz a interface de zoom do browser; o que se afirma é o **reflow**, que é o que o leitor ampliado vive.
- **Tokens:** `--wccs-ink` resolve na região (`#202334`) e **não** resolve no `:root` — os tokens vivem no escopo que o plugin renderiza, e é isso que impede o plugin de reestilizar uma página onde não está.

## 5. O que a observação apanhou — dois defeitos reais

Nenhum dos dois era visível para a suíte que existia, e os dois apareceram só porque o browser foi aberto.

**1. O plugin nunca enfileirava nada num pedido real.** `add_action( 'wp_enqueue_scripts', [ ... , 'enqueue' ] )` registava o callback com um argumento aceito, e o WordPress chama `do_action( 'wp_enqueue_scripts' )` com **zero** argumentos — mas `do_action()`, no próprio core, acrescenta uma string vazia quando não recebe nenhum (`if ( empty( $arg ) ) { $arg[] = ''; }`). O callback declarado `enqueue( ?bool $is_blocks = null )` recebia `''`, que em modo coercivo vira `false`, e voltava antes de enfileirar. Nos oito rounds anteriores de observação isto era invisível porque **todas** as provas chamavam o gate com os booleanos já decididos — e o gate respondia corretamente quando perguntado diretamente. Corrigido registando as duas portas com **zero argumentos aceitos**, que é o que faz o core chamar `call_user_func( $fn )`. Provado por `tests/Integration/F12-wccs-063-delivery-proof.php`, que dispara a ação como o `wp_head` a dispara.

**2. O registo dos campos controlados era recusado pelo WooCommerce.** `registerCheckoutBlock` exige `metadata.parent` — uma das áreas internas do checkout — e **lança** quando falta. O bundle passava apenas `{ name, title }`, portanto o campo nunca se registava e cada carregamento da página do checkout produzia um erro não apanhado. Corrigido com um mapa da localização do campo para a área correspondente (`address`, `contact`, `order`), em `resources/blocks/index.js`, com teste de JS novo que repete a lista de áreas da plataforma para não concordar com um erro de escrita no código.

Um terceiro achado, este de ambiente: **a loja está em modo "coming soon"** (`woocommerce_coming_soon=yes` e `woocommerce_store_pages_only=yes`), portanto a visita anónima ao checkout recebe o ecrã de aviso e não o checkout. É por isso que nenhuma observação anónima anterior encontrou a região do plugin. O script autentica-se com o cookie de um utilizador que pode `manage_woocommerce` — o desvio que a própria WooCommerce define — e a condição fica **registada** em vez de alterada.

E uma correção de leitura: o conteúdo da página de checkout (4.216 bytes) contém **46** comentários `wp:woocommerce/checkout`; a conclusão anterior de que "não contém o bloco" veio de um `grep` errado, e a página é a de blocos.

## 6. O que não foi produzido, e é dito

- **Nenhum leitor de ecrã real** (NVDA, VoiceOver) foi conduzido. O que é afirmado são as propriedades de que um leitor depende: um controlo, com nome acessível (`label[for]` = id), sem `aria-hidden`, `display:none` ou `visibility:hidden`, e uma ordem de foco que se abandona e se retoma.
- **O bloco controlado não está colocado na página do checkout desta loja**, portanto a região do plugin não aparece nessa página. Colocá-lo exigiria editar a página do lojista. O que a loja prova é que o payload, o bundle, a apresentação e os tokens chegam lá, e que o campo **nativo** do documento (desenhado pela API de campos adicionais da WooCommerce) aparece no checkout renderizado.
- **O servidor é HTTP simples**, e `crypto.randomUUID` só existe em contexto seguro: o browser é lançado com `--unsafely-treat-insecure-origin-as-secure`, que é um interruptor do instrumento e não uma alteração do ambiente.

## 7. Fecho

A observação de WCCS-063 deixa a F12 com dois defeitos corrigidos e com o caminho de entrega finalmente exercitado ponta a ponta: o servidor enfileira, o browser executa, o payload chega, o componente desenha e a acessibilidade da região foi medida. O que resta na fase são a performance (064) e a recuperação (065).

## 8. Como reproduzir

O estado que a observação lê é escrito pelo *fixture*, que passa pelo repositório — o mesmo caminho do editor — e é limpo no fim, porque a loja é partilhada com a varredura de integração, que espera não encontrar opções deste plugin:

```bash
# 1. o fixture: publica um campo nativo e um controlado, liga o opt-in
wp eval-file tests/Integration/support/browser-fixture.php seed

# 2. uma sessão de um utilizador com manage_woocommerce (a loja está em "coming soon")
wp eval-file tests/Integration/support/admin-session.php create admin   # imprime cookie_name, cookie_value e token

# 3. as duas observações
WCCS_PRODUCT=2777 WCCS_COOKIE="<cookie_name>=<cookie_value>" node tests/browser/checkout-observation.mjs
WCCS_PRODUCT=2777 WCCS_COOKIE="<cookie_name>=<cookie_value>" node tests/browser/field-component-observation.mjs

# 4. limpar o que o fixture escreveu
wp eval-file tests/Integration/support/browser-fixture.php clear
```

O auxiliar do passo 2 emite o cookie no formato que o `admin-session.php` também sabe destruir (`destroy <token>`), e não escreve nada além do token de sessão que o WordPress guarda em qualquer login. Qualquer sessão válida de um administrador serve; o cookie pode igualmente ser obtido pelo próprio browser. O que **não** é feito em passo nenhum: alterar o modo "coming soon", a página do checkout, o tema ou qualquer configuração da loja.
