# Registro de validação — WCCS-048

**Tarefa:** WCCS-048 · "Integrar accordion e resumo"
**Fase:** F09 · Página customizada e pagamento
**Prioridade:** `required_v1` · **Dependências:** F02, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Radios reais; totals/cupons/frete vêm do Woo; teclado e focus funcionam."

**Resultado:** **383 testes unitários PHP** (1 novo, sobre as regras novas) · **asserções de integração** em 43 provas, 0 falhas (21 novas) · **599 testes de JS** em 36 suítes (2 suítes, 18 testes novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/checkout/payment.js` | O accordion: liga o radio real ao painel que o gateway escreveu |
| `resources/checkout/summary.js` | O resumo expansível: um controlo próprio sobre o resumo do Woo |
| `resources/checkout/presentation.css` | A caixa do painel, a lista e o controlo |
| `resources/checkout/index.js` | Os dois componentes no ciclo de vida do checkout |
| `src/Checkout/Classic/ClassicAssets.php` | O texto do controlo, traduzível, vindo do servidor |
| `tests/js/checkout/{payment-frame,order-summary}.test.js` | O comportamento, sobre o marcador do próprio WooCommerce |
| `tests/Integration/F09-wccs-048-accordion-summary-proof.php` | As costuras que um componente não pode provar |

## 3. O que a leitura dos templates do WooCommerce mudou

A primeira versão deste módulo **guardava o estado do accordion**: punha `hidden` no painel do método não escolhido e abria o do escolhido. Parecia certo, passava nos testes, e era um defeito grave. O template `checkout/payment-method.php` mostra porquê:

```php
<div class="payment_box payment_method_<?php echo esc_attr( $gateway->id ); ?>"
     <?php if ( ! $gateway->chosen ) : ?>style="display:none;"<?php endif; ?>>
```

O WooCommerce **já** esconde os painéis não escolhidos, com um estilo inline, e o `assets/js/frontend/checkout.js` **já** os desliza quando o radio muda (`slideUp`/`slideDown`, linhas 284–297). Um segundo dono desse estado não é redundância: é um conflito que se resolve **contra o cliente**. O painel do método que ele acabou de escolher ficaria escondido por um atributo que o script do WooCommerce nunca toca — um checkout que não se consegue pagar.

Por isso o módulo passou a **ligar e não a decidir**. O que ele faz é:

- dar um `id` ao painel que o gateway escreveu, se não tiver;
- pôr `aria-controls` no radio a apontar para esse painel, que é a informação que um leitor de ecrã precisa para dizer a que método pertence o conteúdo;
- marcar a linha (`data-wccs-payment-frame`), para a folha de estilo poder estilizar uma caixa que o plugin **não** escreveu.

Nenhum `display`, nenhum `hidden`, nenhum `focus()`. A folha de estilo também não põe `display` no painel — e há uma asserção a dizê-lo, porque é exatamente aí que o defeito voltaria.

**A ordem dos factos importa e está registada:** o módulo ficou correto por se ter lido o template instalado, e não por se ter confiado na forma como os accordions costumam ser feitos.

## 4. "Radios reais" — afirmado por contagem

O teste unitário de JS renderiza o marcador do próprio WooCommerce — `li.wc_payment_method`, `input[name="payment_method"]`, `label[for]`, `div.payment_box` — e afirma:

- o número de `input` na página é o mesmo antes e depois, e **os mesmos nós**: um clone seria um campo ligado ao elemento errado, que não é o que o formulário submete;
- os campos que o gateway pôs dentro do painel (um número de cartão de mentira na fixture, um `hidden` com o nonce, um `iframe` com o `src` do gateway) ficam com os seus valores, os seus atributos e o seu `src`;
- um `input` que não é um radio de método não é tocado;
- o estado de visibilidade dos painéis — o estilo inline que o WooCommerce escreveu — sai **byte a byte igual**;
- o foco nunca se move.

E a prova de integração liga os seletores ao sítio onde eles existem: `payment-method.php` e `form-checkout.php` **instalados** são lidos e afirmados (a lista, o radio, o painel, o `form name="checkout"` e o `id="order_review"`). Um seletor inventado não falha em jsdom — falha em produção, em silêncio.

## 5. "Totals/cupons/frete vêm do Woo" — o módulo não sabe precificar

O `summary.js` **não escreve um número, em nenhum caminho**:

- o resumo é o elemento que a loja renderizou, e o módulo só o abre e fecha;
- o teste conta os nós da página antes e depois: **exatamente um** nó é acrescentado — o controlo do plugin — e o `innerHTML` do resumo sai idêntico;
- os valores da fixture (`R$ 120,00`, `R$ 135,00`) continuam onde o WooCommerce os pôs, depois de abrir e fechar duas vezes, e a tabela de totais continua a ter uma linha;
- a prova de integração afirma que nenhum dos dois módulos chama `toFixed`, `Intl.NumberFormat`, `parseFloat` nem atribui `innerHTML`, e que o payload do servidor não carrega total, cupons nem frete — **um número que viajasse daqui seria uma segunda fonte para ele**, e a segunda fonte é a que fica velha.

O único texto que esta tarefa acrescenta ao checkout é o rótulo do controlo, e ele viaja do servidor em `__()` com o domínio do plugin. O bundle não tem uma única string por traduzir.

## 6. Teclado e foco

- O controlo é um `<button type="button">` real: o Enter e o Espaço são do browser, não do plugin.
- O teste foca o botão, prime-o duas vezes e afirma que `document.activeElement` **continua a ser o botão**. Um disclosure que salta para dentro do painel é um disclosure de onde o teclado tem de encontrar o caminho de volta.
- Os radios são o grupo de radios do formulário, por isso as setas movem-se dentro dele como em qualquer grupo de radios — comportamento da plataforma, preservado por não lhe tocar.
- O botão tem `aria-controls` a apontar para o resumo e `aria-expanded` sincronizado com o estado real, e o `aria-label` muda entre "mostrar" e "esconder" (os dois textos vêm do servidor).
- A folha de estilo dá anel de foco ao botão, aos radios de pagamento e aos campos do painel, e o teste unitário de CSS exige-o.

## 7. As três decisões de layout, e por que são essas

1. **Largo, o resumo fica aberto e o controlo sai da página.** Um botão que esconde o resumo do pedido num desktop é um botão que ninguém pediu. Quando a página volta a ser larga, o `hidden` do resumo é **removido**, e não "lembrado": o resumo não pode ficar fechado atrás de um controlo que já não é renderizado.
2. **Estreito, começa fechado**, que é o motivo de existir. O controlo é inserido como irmão imediatamente antes do resumo — nunca dentro dele, porque um controlo dentro do elemento que esconde esconde-se a si próprio — e o teste afirma as duas coisas.
3. **O espaçamento vem de `gap` no contentor, não de margem.** O `ul` da lista de pagamentos e o painel levam `margin: 0`; a separação entre métodos é o `gap` da lista. A primeira versão pusera `margin-block-start` no painel e foi o **teste unitário de CSS — o da WCCS-046 — que a recusou**, exactamente a regra que a secção 17 regista. O controlo do resumo também ficou sem margem: ele passa a ser filho da grelha do próprio formulário do checkout, que já tem `gap`, e no desktop está `hidden`, por isso não ocupa célula nenhuma e as duas colunas não se mexem.

## 8. A decisão de largura é passada, não lida

`narrow` é uma função que o chamador entrega, e o ponto de entrada entrega a `matchMedia` real. É o mesmo padrão do `ClassicAssets::enqueue( bool, bool )`: um ramo que ninguém consegue exercitar é um ramo que ninguém verificou. Com a decisão entregue, o jsdom exercita **os dois** — e o teste do "volta a ser larga" é o que apanha o modo de falha de guardar o estado em vez de o recalcular.

## 9. O que `npm run format` fez, e o que foi revertido

A formatação automática (`wp-scripts format resources tests/js`) não se limita a JavaScript: reescreveu também `resources/design-tokens/tokens.json` e `resources/fixtures/conditions.json`, com 1046 linhas de ruído num fixture que não tem nada a ver com esta tarefa. **Foi revertido** — o `git checkout` dos dois ficheiros é parte do fecho desta tarefa, e o resto dos gates foi reexecutado depois disso.

Fica registado como uma armadilha do tooling: quem correr o formatador sobre `resources/` mexe em dados versionados de outras tarefas sem querer.

## 10. O que NÃO foi provado, e porquê

**Um gateway a sério.** Esta loja não tem página de checkout clássico e não tem gateway habilitado (`SANDBOX-PAYMENT`); o accordion foi exercitado contra o marcador do WooCommerce em jsdom e contra os templates instalados, **nunca num browser com um gateway real**. A homologação de gateways é a WCCS-049 e depende desse bloqueador.

**O ciclo de refresh do lado do servidor.** A ordem de registo no ciclo de vida é afirmada, e o jsdom cobre o "uma vez por elemento", mas o `updated_checkout` a substituir o `#order_review` de verdade não foi observado aqui — a observação de browser é a WCCS-063.

**A reordenação no mobile.** O resumo expansível funciona na posição em que o WooCommerce o renderiza. A secção 15 pede o resumo *antes* do formulário no mobile, e isso é reordenação de DOM de um elemento do WooCommerce: não foi feita aqui, e **não será fingida com CSS `order`**, que a própria secção proíbe. Fica nomeada como trabalho da apresentação da página (WCCS-063), onde o tema e o template são decididos em conjunto.

## 11. Fecho

O gate da fase — *"Pedidos reais de sandbox nos cenários homologados, sem inputs de cartão próprios e sem Checkout Sidebar"* — continua **aberto**: faltam a WCCS-049 e a WCCS-050, não há credenciais de sandbox, e nenhum browser foi aberto. Duas cláusulas estão, essas, afirmadas sobre o código: o plugin **não regista gateway nenhum** (nem filtra a lista de gateways disponíveis) e **não declara um único tipo de campo de cartão** — os sete gateways que esta loja oferece são todos de outros plugins.

**Próxima tarefa:** **WCCS-049**, a homologação de gateways e express checkout, bloqueada por `SANDBOX-PAYMENT` no que depende do ambiente.
