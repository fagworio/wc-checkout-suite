# Registro de validação — WCCS-021

**Tarefa:** WCCS-021 · "Implementar Classic adapter"
**Fase:** F04 · Classic Checkout e persistência canônica
**Prioridade:** `required_v1` · **Dependências:** F03
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Tipos básicos, seções e larguras refletem schema; campos core mantêm contratos."

**Resultado:** **138 testes unitários PHP** (16 novos) · **474 asserções de integração** em 17 provas, 0 falhas (22 novas) · 325 testes de JS · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/Classic/ClassicAdapter.php` | A tradução: schema → `woocommerce_checkout_fields`, pura |
| `src/Checkout/Classic/ClassicCheckout.php` | A fiação: o filtro, lendo **só o publicado** |
| `tests/Unit/Checkout/Classic/ClassicAdapterTest.php` | 16 testes da tradução |
| `tests/Integration/F04-wccs-021-classic-adapter-proof.php` | 22 asserções contra o array real do WooCommerce |

## 3. Como cada palavra do aceite foi provada

### "Tipos básicos refletem schema" — ✅

| Tipo do schema | No checkout | Provado |
|---|---|---|
| `text`, `textarea`, `email`, `tel`, `select`, `radio`, `checkbox`, `country`, `state`, `hidden` | o mesmo tipo | unitário |
| `number`, `url`, `date`, `time`, `datetime` | `text`, **e reportado** como reduzido | unitário + integração |
| `file`, `heading`, `paragraph`, `html`, `multiselect`, `checkbox-group` | **não é adicionado**, e reportado | unitário + integração |

A terceira linha é a decisão que dá valor às outras duas. Renderizar um upload de arquivo como campo de texto produziria um campo que aceita algo que a loja não guarda. O adapter **recusa e reporta**, e o relatório sai por `wccs_classic_adapter_report` para a secção de diagnóstico ler.

As opções declaradas chegam como o mapa `valor => rótulo` que o checkout espera, e as configurações declaradas (`placeholder`, `maxLength`, `default`) chegam ao campo.

### "seções refletem schema" — ✅

`billing`, `shipping`, `account` e `order` vão para os seus homónimos. `contact` vai para **billing**, e isso é uma decisão registada: o `§4` avisa que os cinco conceitos "não correspondem automaticamente a slots idênticos em todos os checkouts", e o checkout clássico não tem passo de contacto — os campos que falam com o cliente vivem no endereço de cobrança. Uma secção declarada usa a **sua localização**, não o seu id.

### "larguras refletem schema" — ✅

A grelha do WooCommerce só conhece largura total e metades, então:

- 12 → `form-row-wide`
- 6 → `form-row-first` / `form-row-last`, **alternando dentro de cada secção**
- 3 e 4 → `form-row-wide` + `wccs-col-<n>`, porque o `§8` põe larguras em "CSS escopado" em vez de prometer controlo da grelha

A alternância é por secção e por ordem de posição, não por ordem de array — provado com dois campos enviados fora de ordem.

### "campos core mantêm contratos" — ✅

Esta é a afirmação mais forte, e a prova é contra o **array real** que o WooCommerce e os outros plugins activos construíram nesta loja, não contra um fixture:

| Chave | Antes | Depois |
|---|---|---|
| `label` | First name | **Primeiro nome** |
| `priority` | 10 | **5** |
| `class` | — | **`form-row-first`** |
| `required` | `true` | `true` |
| `autocomplete` | `given-name` | `given-name` |

O adaptador preserva o contrato **por não o escrever**: não pode quebrar um contrato que nunca atribui. A prova compara cada chave que existia antes e exige igualdade exacta, e confirma que nenhuma chave de contrato apareceu do nada.

## 4. A asserção que liga F03 a F04

O adapter lê o documento **publicado**. Um rascunho que cria um campo e renomeia um campo central deixa o checkout **byte a byte** como estava — as três asserções que o provam são:

1. um campo que só existe no rascunho **não** está no checkout;
2. o array filtrado é idêntico ao da execução anterior;
3. o rótulo que o cliente vê continua a ser o publicado.

Sem isto, editar um rótulo mudaria o que um cliente vê antes de o lojista publicar — e toda a separação rascunho/publicação construída na F03 seria decorativa no único sítio onde importa.

## 5. Um erro meu, e o achado de processo que ele revelou

Escrevi **14 vezes** `} );` onde devia estar `}` no ficheiro de testes. Um erro de sintaxe.

O que interessa é o que aconteceu a seguir. O PHPUnit, invocado como eu o invocava:

```
php -d error_reporting=0 -d display_errors=0 vendor/bin/phpunit
```

**saiu com código 255 e imprimiu zero bytes.** O portão falhava — o `composer check` devolve não-zero — mas **a causa era invisível**. Passei vários minutos a olhar para uma saída vazia antes de me lembrar de correr o `php -l`.

A causa era minha: adotei `-d error_reporting=0 -d display_errors=0` para silenciar as deprecations do WP-CLI, e arrastei as flags para **todas** as invocações de PHP — incluindo as ferramentas de qualidade, onde elas escondem exactamente o que é preciso ver.

Corrigido como prática, não como remendo: **a supressão fica só no WP-CLI**, onde as deprecations vêm do próprio `wp`. O PHPUnit, o PHPCS e o PHPStan correm sem ela. A primeira execução sem supressão imprimiu o rasto completo do `ParseError` com ficheiro e linha.

Vale registar porque é o segundo problema desta natureza na sessão: um portão que falha em silêncio é pior do que um portão ausente, porque parece estar a funcionar.

## 6. O que NÃO foi provado, e porquê

**A página renderizada do Checkout Classic.** Esta loja não tem página com o shortcode `[woocommerce_checkout]` — é a decisão aberta `CLASSIC-TEST-SURFACE`, que passou de teórica a bloqueadora nesta fase.

O que **foi** provado é o array de campos que o WooCommerce constrói, que é exactamente o input que o template recebe. O que falta é a camada acima: que o template renderize esses campos, e que um pedido real seja criado. Isso exige a página, e criar ou trocar uma página é uma alteração fora da raiz do plugin, que não faço sem autorização.

O gate da F04 — *"pedidos Classic válidos e inválidos testados em HPOS on/off, visitante/logado e carrinho físico/virtual"* — permanece aberto por isso e pelas quatro tarefas seguintes.

## 7. O que NÃO foi feito

- **Validação server-side dos valores.** É a WCCS-022. Hoje o adapter adiciona campos com `required`, e o WooCommerce valida isso; as regras próprias dos tipos chegam na tarefa seguinte.
- **Persistência no pedido.** WCCS-023.
- **Máscaras e condições.** F05 e F06. O adapter não as lê, e o relatório não as menciona — não há nada a reportar sobre algo que ainda não é aplicado.
- **CSS das larguras não nativas.** As classes `wccs-col-3` e `wccs-col-4` são emitidas; a folha de estilos que as interpreta pertence à apresentação, na WCCS-046.
- **Renderers próprios.** O `RendererRegistry` existe desde a F01; ligá-lo é da F07.

## 8. Próxima tarefa

**WCCS-022 — "Integrar normalização e validação final"**. Aceite: *"POST adulterado falha mesmo sem JS; erros apontam campos corretos."* A WCCS-009 já provou o pipeline `ValueProcessor` como adapter-agnóstico; esta tarefa liga-o ao ciclo de validação do checkout clássico.

**Antes dela, a decisão `CLASSIC-TEST-SURFACE`** continua a precisar de resposta para o gate da fase — mas, como esta tarefa mostrou, três das cinco tarefas da F04 são prováveis sem ela.
