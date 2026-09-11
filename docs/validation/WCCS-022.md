# Registro de validação — WCCS-022

**Tarefa:** WCCS-022 · "Integrar normalização e validação final"
**Fase:** F04 · Classic Checkout e persistência canônica
**Prioridade:** `required_v1` · **Dependências:** F03
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "POST adulterado falha mesmo sem JS; erros apontam campos corretos."

**Resultado:** **150 testes unitários PHP** (12 novos) · **508 asserções de integração** em 18 provas, 0 falhas (34 novas) · 325 testes de JS · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/Classic/ClassicSubmission.php` | As decisões, puras: o que vira o valor e o que vira erro |
| `src/Checkout/Classic/ClassicValidation.php` | A fiação: os dois hooks que WooCommerce realmente chama |
| `src/Checkout/Classic/PublishedDocument.php` | O leitor único do documento publicado |
| `tests/Unit/Checkout/Classic/ClassicSubmissionTest.php` | 12 testes das decisões |
| `tests/Integration/F04-wccs-022-validation-proof.php` | 34 asserções contra o fluxo real do WooCommerce |

## 3. "POST adulterado falha **mesmo sem JS**" — ✅

Esta metade foi provada de duas formas, e a segunda é a que importa.

**A prova direta.** O harness corre sob WP-CLI, sem navegador nenhum, e submete valores crus por `$_POST`. Quatro valores que nenhum navegador enviaria:

| Campo | Enviado | Recusado com |
|---|---|---|
| `wccs_size` (select) | `xxl`, fora das opções | `invalid_choice` |
| `wccs_qty` (number) | `not a number` | `not_a_number` |
| `wccs_phone` (tel) | `call me maybe` | `invalid_phone` |
| `wccs_long` (texto) | 10 caracteres com `maxLength` 5 | `too_long` |

Cada um é recusado e cada um nomeia o seu próprio campo. Se a recusa dependesse de qualquer coisa no cliente, nada disto seria recusado.

**A prova estrutural, que é a que dá sentido à primeira.** O harness lê o registro de hooks do próprio WordPress e afirma que a metade de storefront deste plugin é **exatamente três hooks**, e quais são:

```
woocommerce_checkout_fields@20:filter_fields
woocommerce_checkout_posted_data@20:normalize_posted_data
woocommerce_after_checkout_validation@20:collect_errors
```

Não há um quarto. Não há script registrado por este plugin na requisição (asserção sobre `wp_scripts()`/`wp_styles()`). "Mesmo sem JS" não é uma promessa sobre o que o navegador faz — é a constatação de que **não existe metade no navegador cuja ausência possa importar**. Um `POST` forjado chega ao mesmo código que um `POST` honesto, porque é o único código que existe.

## 4. "erros apontam campos corretos" — ✅

O mecanismo não foi inventado aqui; é o do WooCommerce, e a prova segue-o até ao HTML.

1. `$errors->add( '<campo>_wccs_<código>', $mensagem, array( 'id' => '<campo>' ) )`
2. `process_checkout()` percorre esse erro e chama `wc_add_notice( $mensagem, 'error', $data )`
3. O template `notices/error.php` renderiza `wc_get_notice_data_attr()`, que transforma cada chave em `data-<chave>="<valor>"`
4. O próprio `checkout.js` do WooCommerce lê `li[data-id]`: embrulha a mensagem num link para `#<campo>` e insere-a **em linha, ao lado do campo**, com `aria-invalid="true"` e `aria-describedby`

As asserções cobrem os passos 1 a 3 com o HTML real (`wc_add_notice` + `wc_print_notices`): as quatro mensagens existem e cada uma traz `data-id="<campo>"`. O passo 4 é do WooCommerce, versão verificada 11.1.0, e está registrado como não exercitado — é a fronteira honesta desta prova.

Escolhi `id` e mais nada no `data` do erro. O código de máquina já está no *nome* do erro (`wccs_size_wccs_invalid_choice`), que é onde ele serve para diagnóstico; acrescentar `data-code` ao HTML seria uma chave que nada lê.

## 5. Os quatro defeitos que a integração podia introduzir — e não introduziu

Esta tarefa não é difícil por escrever código. É difícil porque a coisa óbvia a fazer em cada junta está errada. Quatro decisões foram ao contrário do óbvio.

### 5.1 Não escrever o booleano de volta nos dados do WooCommerce

A armadilha mais séria, e ela teria passado. O `CheckboxFieldType::normalize()` transforma "não marcado" em `false`. Escrever esse `false` de volta em `$data` parece exatamente o ponto de "integrar normalização". Mas o WooCommerce representa "não marcado" como string vazia e decide obrigatoriedade com uma comparação estrita:

```php
if ( $validate_fieldset && $required && '' === $data[ $key ] ) {
```

`'' === false` é falso. Escrever o booleano faria o WooCommerce **deixar de ver** uma caixa de consentimento obrigatória não marcada — e o pipeline também não a veria, porque `is_absent( false )` é falso por desenho (`false` é um valor real, preservado para persistência). Os dois lados deixariam passar. **Um POST forjado com a caixa vazia passaria a aceitar o pedido**, que é exatamente o ataque que o critério de aceite nomeia.

A correção é uma regra, não um caso especial: **só um valor canônico textual é levado de volta.** O WooCommerce carrega os seus dados numa representação própria; a Suite contribui normalização de *texto* (máscaras, dígitos, caixa, espaço) e validação, e a forma tipada canônica é assunto da WCCS-023, onde o pedido é escrito. Um teste unitário afirma as duas metades: o pipeline normalizou (`value()` é `false`) **e** o que viaja de volta continua a ser `''`.

### 5.2 Não reportar obrigatoriedade

A tentação é reportar tudo o que o pipeline encontra. Mas o WooCommerce já aplica `required`, a partir do **mesmo array de campos** de onde o formulário foi construído, e conhece uma coisa que esta camada não conhece: **quais fieldsets esta requisição valida.** `maybe_skip_fieldset()` desliga o fieldset de entrega quando o cliente não está a enviar para outro endereço. Um campo obrigatório de entrega nessa situação é deliberadamente ignorado pelo WooCommerce; uma segunda opinião aqui recusaria um cliente correto.

Então a obrigatoriedade é calculada e **não** reportada. O que impede isto de ser omissão silenciosa é a prova afirmar os dois lados:

- o WooCommerce recusa o campo obrigatório vazio, com `wccs_doc_required` e `id => wccs_doc` — provado chamando o `validate_posted_data()` **real** do WooCommerce, através de uma subclasse que expõe o método protegido, porque reimplementar o comportamento não provaria nada sobre ele;
- a Suite não acrescenta uma segunda mensagem — `wccs_doc_wccs_required` não existe;
- e exatamente **uma** mensagem é produzida para aquele campo.

### 5.3 Só examinar os campos que o formulário carregou

O teste é `array_key_exists( $id, $data )`, não a lista de definições. Um campo que o adapter não conseguiu renderizar — um upload, um bloco de conteúdo — não está no checkout, e portanto não está nos dados publicados. Consequências, ambas provadas:

- um `POST` forjado com a chave `wccs_attachment` não a faz existir: o WooCommerce constrói os dados a partir do formulário, e a chave forjada desaparece;
- um campo obrigatório que ninguém consegue preencher não bloqueia ninguém — nem por esta camada nem pelo WooCommerce (`wccs_attachment_required` não existe).

Usar a lista de definições como fonte de verdade teria criado uma segunda opinião sobre "o que está no formulário", que é a mesma classe de erro do ADR-0007.

### 5.4 Julgar o valor normalizado uma vez, não duas

O pipeline normaliza **e depois** valida. Se a fiação corresse o pipeline outra vez no valor já normalizado — que é o que aconteceria naturalmente, com um hook a normalizar e outro a validar — um valor forjado de 3000 caracteres que uma máscara encurta seria julgado duas vezes, sob dois comprimentos, e o erro de comprimento que devia ser levantado desapareceria. A mesma instância guarda o resultado de uma única passagem, e o hook de validação reporta esse resultado.

## 6. Um achado sobre o leitor do documento — registrado, não corrigido

Ao extrair `PublishedDocument::read()`, que agora serve dois chamadores, verifiquei o que ele constrói. **`SchemaRepository::read()` nunca usa o validador** — só `decode()`, `SchemaMigrations::migrate()` e `SchemaDocument::from_array()`. Mas o construtor exige um `DefinitionValidator`, e obtê-lo obriga a ler o inventário de campos que o WooCommerce possui.

O problema não é o custo (embora seja: uma leitura completa de `get_checkout_fields()`, que corre os filtros de terceiros, a cada requisição de checkout, agora a partir de dois sítios). É onde essa leitura acontece. `ClassicCheckout::filter_fields()` corre **dentro** de `apply_filters( 'woocommerce_checkout_fields' )`, e o `initialize_checkout_fields()` do WooCommerce atribui `$this->fields` **antes** de aplicar o filtro:

```php
$this->fields = array( 'billing' => ..., 'shipping' => ..., ... );   // linha 260
$this->fields = apply_filters( 'woocommerce_checkout_fields', $this->fields );   // linha 303
```

Portanto a leitura aninhada devolve o array **não filtrado**. Isso é o que impede a recursão infinita que eu esperava encontrar — e é também o que faz com que `Registries::core_field_ids()` memoize um inventário a meio caminho para o resto da requisição.

**Impacto hoje: nenhum comportamento incorreto.** No storefront essa memoização só é lida por `validate_origin()`, que só corre em escrita de schema. O custo é real; a incorreção não é.

**Recomendação:** uma leitura não devia precisar de um validador. Corrigir isso significa um caminho de construção orientado a leitura em `SchemaRepository`, que é API verificada desde F01 — motivo pelo qual fica registrado aqui em vez de alterado dentro desta tarefa. É o primeiro item a considerar no fecho do gate da F04.

## 7. O que NÃO foi provado, e porquê

**O pedido criado.** Chegar a `create_order()` exige uma página de checkout Classic e um carrinho, e esta loja não tem a primeira — é a decisão aberta `CLASSIC-TEST-SURFACE`, já bloqueadora desde a WCCS-021. O que está provado é a validação que o WooCommerce corre **antes** disso, através dos filtros e da coleção de erros dele.

**A metade do navegador na exibição do erro.** As asserções param no `data-id` renderizado, que é o contrato que o `checkout.js` lê. Que o script faça o resto é comportamento do WooCommerce 11.1.0, lido no código-fonte e não executado.

**Condições reais.** O motor é o `PermissiveConditionEvaluator` (F06), que nunca oculta nada. O descarte do valor residual está implementado e testado com um avaliador que oculta, e a política `preserve` continua por exercitar porque nada ainda a produz.

**As regras brasileiras.** `digits`, `uppercase` e `single_spaces` são primitivas; CPF, CNPJ, CEP e telefone por país são WCCS-026/027/028. A prova usa `digits` como normalizador declarado e nada mais.

## 8. Próxima tarefa

**WCCS-023 — "Implementar OrderFieldsService"**. Aceite: *"Valores tipados, zeros e false preservados; autoridade única por campo."*

Esta tarefa deixa-lhe o trabalho bem delimitado pela regra 5.1: o valor canônico tipado — incluindo `false` e `0` — é calculado aqui e **não** é o que o checkout guarda. Escrevê-lo no pedido é onde essa forma tipada passa a existir, e é lá que "zeros e false preservados" tem de ser provado.
