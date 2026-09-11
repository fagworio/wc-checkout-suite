# Registro de validação — WCCS-036

**Tarefa:** WCCS-036 · "Implementar native additional fields"
**Fase:** F07 · Checkout Blocks nativo e tipos próprios
**Prioridade:** `required_v1` · **Dependências:** F04, F05, F06
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Text/select/checkbox/date com feature detection e políticas de localização/storage."

**Resultado:** **321 testes unitários PHP** (11 novos) · **862 asserções de integração** em 32 provas, 0 falhas (23 novas) · **541 testes de JS** em 31 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/Blocks/BlocksAdapter.php` | A tradução: o que o Blocks pode ser mandado desenhar, e o que não pode |
| `src/Checkout/Blocks/BlocksCheckout.php` | O registo, no hook certo, com o relatório disparado |
| `src/Plugin.php` | Liga o adapter ao arranque |
| `tests/Unit/Checkout/Blocks/BlocksAdapterTest.php` | As traduções e as recusas |
| `tests/Integration/F07-wccs-036-blocks-adapter-proof.php` | O registo lido do registo do próprio WooCommerce |

## 3. A lista de tipos foi lida da plataforma, não de um comentário

`BlocksAdapter::NATIVE_TYPES` declara `text`, `select` e `checkbox`, e a prova **não confia nessa declaração**: pede ao WooCommerce que registe um campo de um tipo que ele não tem e lê a resposta dele — "The supported types are: text, select, checkbox" — comparando as duas listas. Um array privado e sem filtro não tem API para ser consultado; a única forma honesta de o afirmar é confrontá-lo com o próprio comportamento.

**`date` não está na lista.** O `§8` do planeamento e o aceite desta tarefa listam-no como nativo, e o WooCommerce 11.1.0 não o tem. O campo é **recusado** com o código `needs_controlled_component` e o motivo nomeia a WCCS-037, em vez de ser registado como `text` e descoberto por um cliente. É o mesmo defeito que a WCCS-003 registou e que agora deixa de poder ser esquecido.

## 4. As três políticas

**Localização: resolvida, nunca adivinhada.** Faturação e envio são uma só localização `address` no Blocks; contato e conta são `contact`; pedido é `order`. Um campo cuja secção não está publicada é **recusado** com `unknown_location`, e não assumido como `order` — um campo que cai no lugar errado em silêncio é um campo que recolhe os dados errados em silêncio, que é exatamente a família de erro que a WCCS-018 teve de corrigir.

**Armazenamento: o que a plataforma faz tem de ser o que foi declarado.** Um additional field nativo é persistido no pedido. Uma definição com escopo `none` pediu para não ser guardada e uma com `customer` pediu um lugar onde a plataforma não escreve: ambas são recusadas com `storage_not_native`. É a regra da ADR-0001 — uma autoridade por campo — aplicada antes de o campo existir.

**Tipo: só o que é nativo.** Texto, select e checkbox passam; tudo o resto é recusado com motivo. Um select sem opções também é recusado: um select sem opções não tem nada para escolher.

## 5. O compilador da WCCS-035 deixa de ser uma biblioteca

Uma regra de visibilidade representável chega ao WooCommerce como **as duas palavras-chave nativas ao mesmo tempo**: `required` recebe o schema compilado e `hidden` recebe a sua negação. Juntas significam "obrigatório exatamente quando é mostrado", que é o que o `visible` desta extensão quer dizer — e o WooCommerce trata os dois: `is_required_field()` devolve falso quando `is_hidden_field()` é verdadeiro, portanto um campo escondido nunca é exigido.

A prova lê as duas regras **do registo do WooCommerce**, não do que o adapter devolveu, e valida-as com o validador de schema da própria plataforma.

Uma regra que o compilador não consegue exprimir **não impede o registo**: o campo é registado sem ela e o motivo é relatado (`condition_not_native`). É a ADR-0008 na direção certa — uma incompatibilidade avisa e nunca bloqueia — e é o que distingue "a plataforma não sabe exprimir isto" de "o lojista não pode usar este campo".

## 6. O que a prova estabelece

- A API é detetada, o serviço de checkout fields é alcançável, e a lista de tipos coincide com a da plataforma;
- text, select e checkbox registam-se e **aterram na localização certa**, lidos de `CheckoutFields::get_additional_fields()` — `contact`, `address` (com as opções do select) e `order`;
- `date`, escopo `none`, escopo `customer` e secção inexistente são recusados, cada um com o seu código, e **nenhum deles impede os outros quatro de registarem** — uma recusa não é um erro;
- um campo desativado não é registado nem relatado (não é uma falha: não foi pedido);
- a regra compilada chega como `required` e `hidden`, e uma regra não compilável é relatada sem travar o registo.

## 7. O que NÃO foi provado, e porquê

**Um cliente a preencher o formulário.** O registo foi provado no registo do WooCommerce dentro de um pedido WP-CLI. Não foi aberto nenhum browser, e a página de checkout desta loja é de Blocks — mas ninguém a submeteu. É a WCCS-040 (lifecycle e remount no Blocks) e a WCCS-063.

**O valor a chegar ao pedido.** A persistência de additional fields é a mesma API que a WCCS-003 provou escrever em `_wc_other/` no pedido. O que ainda não existe é a **validação** dos valores desta extensão nesse caminho: é a WCCS-038 (Store API e validação).

**A largura e a ordem.** O adapter não emite classes de largura nem posição — o Blocks decide o layout, e a matriz de capacidades do admin é a WCCS-039.

**Tipos que não são nativos.** Ficam explicitamente recusados, com a tarefa que os entrega; nenhum modo é anunciado além do testado, que é o gate da fase.

## 8. Próxima tarefa

**WCCS-037 — "Implementar campos controlados próprios"** (aceite: *textarea, radio, multiselect, time/datetime e presets mascarados nos slots permitidos*). É a resposta direta às recusas desta tarefa: tudo o que não cabe na API nativa passa a ter componente próprio, e o `date` que aqui foi recusado é lá entregue.
