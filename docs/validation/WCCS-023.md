# Registro de validação — WCCS-023

**Tarefa:** WCCS-023 · "Implementar OrderFieldsService"
**Fase:** F04 · Classic Checkout e persistência canônica
**Prioridade:** `required_v1` · **Dependências:** F03
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Valores tipados, zeros e false preservados; autoridade única por campo."

**Resultado:** **160 testes unitários PHP** (10 novos) · **564 asserções de integração** em 19 provas, 0 falhas (56 novas) · 325 testes de JS · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Orders/OrderFieldsService.php` | O único ponto que conhece o formato de `_wccs_fields` |
| `src/Domain/Orders/OrderFieldValues.php` | O conjunto de valores tipados, e a regra de autoridade |
| `src/Checkout/Classic/ClassicOrderFields.php` | A fiação: escrever no pedido quando o WooCommerce o cria |
| `tests/Unit/Domain/Orders/OrderFieldValuesTest.php` | 10 testes das decisões |
| `tests/Integration/F04-wccs-023-order-fields-proof.php` | 56 asserções contra pedidos reais, nos dois backends |

## 3. "Valores tipados, zeros e false preservados" — ✅

O ADR-0001 pede explicitamente esta prova: *"teste de valores tipados preservando `0`, `false` e array vazio, em HPOS **on e off**"*. A prova faz as duas metades.

**Como a perda acontece.** São quatro maneiras banais de destruir a promessa, e o conjunto de valores é escrito contra todas:

| O reflexo | O que destrói |
|---|---|
| `(string) $value` | `false` vira `''`, `0` vira `'0'` |
| `empty( $value )` | descarta `0`, `'0'`, `false` e `[]` de uma vez |
| `if ( $value )` | não distingue `false` de ausente |
| `assertEquals` | passa com `0 == '0'` e não prova nada |

Por isso tudo neste caminho compara com `===`, testa presença com `array_key_exists()` e afirma tipos com `gettype`. Os valores provados, em ambos os backends: `'12345678909'`, `false`, `0` (inteiro), `0.0` (float), `''` (presente, não ausente), `[]` (presente, não vazio-e-descartado).

### O defeito que a prova encontrou — e que a revisão não encontraria

`json_encode( 0.0 )` produz **`0`**. Sem flag, um zero de tipo float é gravado como um zero inteiro e lido de volta como `int`. A prova falhou exatamente aí, nos dois backends, com `expected double(0) got integer(0)`.

É precisamente o que o critério de aceite nomeia: *zeros preservados*. Um zero cujo **tipo** mudou em silêncio é a perda que a frase existe para impedir, e nenhum `assertEquals` a teria visto — `0 == 0.0` é verdadeiro.

Corrigido com `JSON_PRESERVE_ZERO_FRACTION` na codificação, documentado no sítio onde a flag vive, porque a próxima pessoa a reescrever aquela linha tem de saber porque está lá.

**Onde a prova estava errada, e não o código.** Três asserções minhas falharam por razões que não eram defeitos do produto, e ficam registradas porque a distinção importa:

1. afirmei `1` linha de meta na tabela HPOS quando o pedido tem as meta do próprio WooCommerce — a asserção passou a contar pela **chave**, não pela tabela;
2. o mesmo na tabela legada;
3. afirmei que o `billing_first_name` do pedido continuava preenchido, mas o harness só dispara a ação de criação; quem escreve os campos nativos é o `WC_Checkout::create_order()`, que não está em execução. O harness passou a preencher o campo nativo como aquele método faz — e a asserção agora prova o que eu queria: **a Suite escreve no pedido sem tocar no campo que o WooCommerce possui**.

## 4. "autoridade única por campo" — ✅

O ADR-0001 dá uma autoridade de escrita por origem de campo. A regra está no **ponto de escrita**, não no chamador: `OrderFieldValues::from_definitions()` só deixa passar definições `origin: custom`, habilitadas e presentes no documento publicado. Como `write()` é o único caminho para `_wccs_fields`, não há como contornar a regra sem contornar a classe.

Provado:

- uma chamada com um campo da Suite e um campo core grava **só o da Suite** — o valor do core estava no input e não aparece em lado nenhum do payload;
- um campo que só existe no rascunho não é gravado, e a revisão registrada é a **publicada** (7), não a do rascunho (9) — mesmo padrão da WCCS-021/022;
- **nenhuma meta no namespace do WooCommerce** é escrita: o Suite não duplica `_wc_other/`, que é o risco Alto do `§25` e o critério de aceite nº 10 da V1;
- com HPOS autoritativo, **zero linhas** em `wp_postmeta` para o pedido, e o valor em `wc_orders_meta`. Nenhum acesso direto a tabelas de posts: tudo passa por `WC_Order`.

Sobre `instanceof WC_Order`: a prova usa `wc_get_order()`, que devolve `Automattic\WooCommerce\Admin\Overrides\Order` — e a asserção confirma que é um `WC_Order`, que é a razão pela qual a regra 2 do ADR existe.

## 5. HPOS on **e** off

O HPOS é autoritativo nesta loja. O backend legado é exercido sobrepondo o **próprio filtro do WooCommerce**, `woocommerce_order_data_store`, com prioridade 1000 — acima do `CustomOrdersTableController`, que o define em 999.

É uma sobreposição local ao plugin, revertida na mesma execução, e a prova afirma no fim que o HPOS voltou a ser o store. O que ela **não** faz, e é importante que fique dito: não altera a *autoridade* do store. Isso é uma configuração do site, fora da raiz do plugin, e trocá-la numa loja com pedidos é uma migração — não um teste. A sobreposição seleciona o mesmo caminho de dados que a configuração HPOS-off usa para meta de pedido; não é a configuração.

Nos dois backends: `wc_get_order()` re-lê o pedido do store, e todos os valores, com os seus tipos, voltam idênticos. No legado, a linha é um `shop_order` real e os valores ficam em `wp_postmeta`; no HPOS, em `wc_orders_meta`.

## 6. O slot de armazenamento sabe dizer o que tem

A WCCS-020 fechou com um item de checklist: *todo slot de armazenamento novo tem de conseguir reportar o seu próprio estado*. Este é o primeiro slot construído depois disso, e nasceu com ele.

| Estado | Quando |
|---|---|
| `absent` | nenhum valor gravado — pedido anterior à Suite, ou sem campos da Suite |
| `readable` | o payload desta versão |
| `unsupported_format` | um payload escrito por uma versão mais nova |
| `corrupt` | JSON inválido, ou sem a chave de formato |

`unsupported_format` e `corrupt` **não** são "sem valores": são valores que existem e não podem ser lidos, e uma interface que os mostre como um pedido vazio está a mostrar a coisa errada. `read()` responde vazio nos dois casos, e `read_status()` é o que os distingue.

O `format` dentro do payload existe pela mesma razão: um leitor que adivinha é pior do que um que recusa.

## 7. Uma prova da WCCS-022 teve de ser atualizada

A prova da WCCS-022 afirma que a metade de storefront do plugin é **exatamente três hooks**, e quais são. Esta tarefa acrescentou um quarto (`woocommerce_checkout_create_order@20:persist`), e a asserção da WCCS-022 falhou — como devia.

Isso é o preço de fixar um conjunto exato, e é também a sua utilidade: o crescimento ficou **deliberado** em vez de silencioso. A asserção foi atualizada para quatro, e a WCCS-023 afirma o mesmo conjunto do seu lado, para que os dois não possam divergir.

A afirmação central da WCCS-022 não é afetada: o hook novo também é do servidor. Nada foi acrescentado ao lado do navegador.

## 8. Uma divergência de formato que fica registrada

A prova de F00 (WCCS-002) gravou um payload exploratório com a forma `{ campo: { value, type, preset, schema_version } }`. Essa forma foi um teste de que a meta sobrevivia ao CRUD, não um desenho, e **está superada** pelo formato desta tarefa — `{ format, values: { campo: valor } }`.

O `value` por campo com o `type` e o `preset` ao lado é exatamente o *snapshot mínimo de labels/opções/formatadores* que o `§13` pede, e que pertence à **WCCS-024** ("histórico de valores"). Guardar o tipo por valor agora seria duplicar o que as definições e a revisão já dizem.

## 9. O que NÃO foi provado, e porquê

**Um checkout completo.** Os pedidos foram criados com `wc_create_order()` e os valores escritos disparando o `woocommerce_checkout_create_order`, que é o hook que o WooCommerce dispara a seguir a `wc_create_order()` e antes de `$order->save()`. Chegar lá a partir de um `POST` real precisa de uma página de checkout Classic — a decisão aberta `CLASSIC-TEST-SURFACE`, que continua a ser a única coisa a bloquear o gate da F04.

**O caminho dos additional fields nativos do Blocks.** A autoridade é do core e a Suite não escreve neles; a leitura separada que o ADR-0001 exige é F07.

**O snapshot histórico.** Os valores estão gravados e a revisão também; ler um pedido antigo depois de o campo ser renomeado, arquivado ou ter o tipo mudado é a WCCS-024.

**A projeção em API do `_wccs_fields`.** O `§13` avisa que chave com underscore não é controle de acesso. Nada nesta tarefa expõe estes valores; a autorização e o teste por projeção pertencem a F09/F10.

**O achado da WCCS-022 continua aberto:** `SchemaRepository::read()` constrói um validador que nunca usa, e dentro do filtro de campos isso lê e memoiza um inventário de campos core a meio caminho. Sem incorreção hoje, com custo real; segue como seguimento do gate.

## 10. Próxima tarefa

**WCCS-024 — "Implementar histórico de valores"** (Snapshots, Migrations). Aceite: *"Renomear/arquivar campo não torna pedido antigo ilegível."*

Esta tarefa deixa-lhe as duas peças de que precisa: os valores tipados gravados sob a chave estável do campo, e a revisão do schema gravada ao lado. O que falta é o snapshot que permite **formatar** um valor cujo campo mudou de label, de opções ou de tipo depois de o pedido existir — e é aí que a regra 5 do ADR-0001 ("renomear label não renomeia a chave de armazenamento") tem de ser provada contra um pedido real.
