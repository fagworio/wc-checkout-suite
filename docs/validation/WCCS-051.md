# Registro de validação — WCCS-051

**Tarefa:** WCCS-051 · "Criar editor de dados do pedido"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "CRUD, edição autorizada e validação idêntica nos dois backends de pedidos."

**Resultado:** **392 testes unitários PHP** · **1113 asserções de integração** em 46 provas, 0 falhas (25 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Admin/Orders/OrderFieldsPanel.php` | O painel na edição do pedido: mostrar, editar, validar, gravar |
| `src/Plugin.php` | Registo dos dois hooks que os dois backends disparam |
| `phpcs.xml.dist` | As capabilities de pedido que o WooCommerce regista em runtime |
| `tests/Integration/F10-wccs-051-order-editor-proof.php` | As três cláusulas do critério |

## 3. A frase que dá forma ao painel

> Administradores: bloco "Campos do checkout" na edição do pedido, origem, label, valor formatado, **edição autorizada** e trilha de alterações sem logs desnecessários do conteúdo pessoal.

O painel mostra, para cada valor que o pedido carrega: a **label como estava quando o pedido foi feito** (não a de hoje — um comerciante que renomeia um campo não renomeou o que um pedido passado diz), a origem, e o valor. E deixa **corrigir** os campos cuja correção é do pedido — que é a segunda autorização, e a que interessa.

## 4. "Edição autorizada" são duas perguntas, não uma

1. **A pessoa pode editar este pedido?** `manage_woocommerce` **e** `edit_shop_order` para o pedido concreto. Um utilizador sem elas não é desenhado nem grava, **mesmo com um nonce válido** — afirmado com uma submissão que traz o nonce certo e não escreve nada.
2. **O pedido é o sítio onde o valor desse campo vive?** A definição decide. É editável no pedido o campo que o comerciante **mostra ao staff no pedido** (`visibility.admin_order`), está **ativo**, e cujo **storage scope é o pedido**.

A segunda é a que a secção 14 escreve em duas direções: *"separar valor do pedido de preferência atual do perfil; editar perfil não reescreve pedido passado"*. Lida ao contrário, é o que impede este painel: um campo que a loja guarda no **cliente** não se edita a partir do pedido, porque mudá-lo reescreveria a preferência atual de uma pessoa a partir do retrato de um pedido. A prova publica os quatro casos e afirma que **só um** é editável: o do pedido; o do cliente, o que não se mostra ao pedido e o arquivado ficam de fora.

## 5. "Validação idêntica" — uma só validação, não duas iguais

Um valor escrito aqui passa pelo **mesmo `ValueProcessor`** que o checkout corre, com as mesmas definições e o mesmo `FieldContext`. Duas validações que hoje concordam discordam na primeira vez que uma delas for editada; há uma.

E a prova não compara duas implementações — compara as **duas respostas do mesmo código** a quatro casos reais, exigindo que o valor aceite e o valor canónico sejam iguais:

```
verdicts={"um documento que a fixture diz ser válido":true,
          "um documento que a fixture diz ser inválido":false,
          "outro que a fixture recusa":false,
          "um valor vazio num campo não obrigatório":true}
```

O valor aceite chega ao pedido **canonicalizado** (`111.444.777-35` → não; `11144477735` → sim, com a pontuação que o checkout remove removida aqui também). Um valor recusado **não sobrepõe** o que o pedido tinha.

## 6. "Nos dois backends" é um contrato, não dois caminhos

- o painel regista-se em `add_meta_boxes`, que o WooCommerce dispara com o identificador do **ecrã de posts** (`shop_order`) e do **ecrã HPOS** (`woocommerce_page_wc-orders`), e grava em `woocommerce_process_shop_order_meta`, que **ambos** os ecrãs disparam — os dois factos foram lidos no WooCommerce instalado;
- `order_from()` resolve o pedido a partir de qualquer das formas que os ecrãs entregam (um `WC_Order` no HPOS, o post no legado);
- e **toda a leitura e escrita passa pelo CRUD do `WC_Order`** — `get_meta`, `update_meta_data`, `save` — sem um único ramo por backend, porque um ramo é um segundo caminho e um segundo caminho é a coisa que deixa de concordar.

Há uma asserção que existe só para o dia em que alguém "corrigir" isto com `update_post_meta()`: **zero linhas em `wp_postmeta`** para a chave da Suite depois de gravar. Com o HPOS autoritativo, o postmeta é a tabela errada, e um painel que escrevesse lá parecia funcionar até a loja desligar a sincronização.

## 7. O defeito que a prova apanhou — no próprio painel

Um `<label for="...">` era emitido em **todas** as linhas, incluindo as de leitura, onde não há controle nenhum. O `for` apontava para um elemento que não existe: um leitor de ecrã anuncia um controle que o teclado não alcança. Apanhado pela asserção que exigia que o campo do cliente fosse mostrado **sem** controle — a linha de leitura passou a ser um `<span>` e o `for` só existe quando existe o input.

## 8. E um defeito na própria prova, que valia a pena registar

A primeira versão destas asserções usava a chave de validador `br_cpf`; o registo declara **`br.cpf`**. As duas metades concordaram perfeitamente — sobre uma definição que nenhum comerciante poderia ter guardado, porque nenhuma validação existia. Era uma asserção de concordância **quase vazia**, e passava.

Duas correções, ambas estruturais:

- os casos passaram a usar as chaves do registo e os documentos da fixture partilhada;
- foi acrescentado um **guarda**: os casos têm de ser decididos **nos dois sentidos** antes de concordar significar alguma coisa. *"Concordam"* que fosse *"ambos recusam tudo"* não prova nada, e agora há uma asserção que o diz.

E um terceiro detalhe, na mesma família: os casos eram um mapa com o documento como **chave** — e o PHP converte uma chave string numérica em `int`. A validação estava a ser perguntada sobre um inteiro e recusava-o por não ser uma string. Passaram a ser pares explícitos.

## 9. O que NÃO foi provado, e porquê

**Um browser.** O painel é afirmado renderizando-o e conduzindo o `save` que regista, não abrindo um pedido no wp-admin. A aparência e o comportamento com o recálculo de totais por JavaScript são a WCCS-063.

**Os dois backends a correr.** O caminho é o mesmo contrato de CRUD neste ambiente; reexecutar as mesmas asserções com o HPOS **desligado** é a matriz funcional da WCCS-062 — que é onde "os dois backends" passa de propriedade do código a observação.

**A trilha de alterações.** A secção 14 pede "trilha de alterações sem logs desnecessários do conteúdo pessoal": o painel grava e o `OrderFieldsService` mantém o histórico do que foi capturado no checkout, mas **registar quem alterou o quê no admin ainda não existe**, e fica nomeado em vez de dado como feito.

## 10. Fecho

A F10 tem oito tarefas e esta é a primeira. O gate da fase — *"auditoria por papel/endpoint/e-mail confirma ausência de exposição indevida e leitura histórica"* — exige as tarefas que faltam (exibição ao cliente, e-mails, APIs) e é ele próprio uma auditoria.

**Próxima tarefa:** **WCCS-052**, a exibição ao cliente.
