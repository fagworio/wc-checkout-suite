# Registro de validação — WCCS-053

**Tarefa:** WCCS-053 · "Criar e-mails HTML/texto"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Públicos distintos; documentos sem anexos por padrão; links autorizados."

**Resultado:** **392 testes unitários PHP** · **1152 asserções de integração** em 48 provas, 0 falhas (20 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Checkout/OrderEmailFields.php` | As quatro projeções: dois públicos × dois formatos |
| `src/Plugin.php` | Um hook, o que todos os templates de e-mail de pedido disparam |
| `tests/Integration/F10-wccs-053-email-projection-proof.php` | As três cláusulas |

## 3. O hook já traz as duas respostas

Os templates de e-mail do WooCommerce disparam `woocommerce_email_order_meta` com `( $order, $sent_to_admin, $plain_text, $email )` — e o WooCommerce **usa** esse hook para as notas do cliente (`class-wc-emails.php:247`). Não é um hook inventado, é a costura documentada, e traz **público** e **formato** como argumentos. As duas asserções que o dizem são lidas dos ficheiros instalados.

Isso decide o desenho: as quatro projeções não vêm de uma configuração que possa discordar do que está a ser enviado — vêm do que a plataforma entrega.

## 4. "Públicos distintos" — dois conjuntos, não um

| campo | visibilidade | e-mail ao cliente | e-mail à loja |
|---|---|---|---|
| `wccs_note` | `customer_email` + `admin_email` | sim | sim |
| `wccs_greeting` | `customer_email` | sim | **não** |
| `wccs_internal` | `admin_email` | **não** | sim |
| `wccs_onpage` | `customer_order` | **não** | **não** |

A última linha é a que vale a pena afirmar: **uma superfície não é uma política** — a chave que abre a página do cliente não abre o e-mail. E o mesmo pedido pode legitimamente dizer uma coisa ao cliente e outra à loja, o que as duas renderizações afirmam.

## 5. "HTML/texto" — duas projeções, não uma com as tags tiradas

O HTML escapa e usa estrutura; o texto é **escrito como texto**. A diferença é o defeito que a separação existe para evitar: construir o texto tirando as tags do HTML é como um e-mail chega com `&amp;` onde o cliente escreveu um `&`.

A prova usa o valor `Salt & pepper <b>bold</b>` e afirma:

- o HTML contém `Salt &amp; pepper` e **não** contém `<b>bold</b>`;
- o texto **não** contém marcação do plugin (`<h2`, `<p `, `<strong`), **não** contém `&amp;`, e contém o valor **tal como foi escrito** — os `<` do cliente são o texto do cliente, e o que tem de estar ausente é a marcação que este plugin emitiu.

## 6. "Documentos sem anexos por padrão" e "links autorizados"

- **Nada é anexado.** O plugin não acrescenta nada a `woocommerce_email_attachments` — afirmado com uma verificação **por hook e por autor do callback**, porque o filtro tem outros interessados e "o filtro está vazio" seria uma pergunta sobre o WooCommerce, não sobre este plugin (a quarta vez nesta sessão; já está na lista de padrões de erro).
- **Sem configuração, não há link.** `links_enabled()` devolve `false` até uma loja pedir o contrário pelo filtro que esta classe publica — *"apenas se configurado"* lido como omissão, não como menu. O documento é **nomeado** ("A document was provided with this order.") e o **token não é impresso** em nenhuma das partes: o token é um endereço para o armazenamento privado.
- **Configurado, o link é a rota de autorização do plugin** — `…/uploads/<token>/download`, a mesma que decide por pedido se esta pessoa pode ler este ficheiro (WCCS-044) — e **nunca** um caminho, um URL público ou `wp-content`.

## 7. O defeito que a prova apanhou

Com os links ligados, um valor que **não é um token** produzia `<a href="">Open your document</a>`: uma âncora com `href` vazio. Parece um caminho para o documento, é focável, e não vai a lado nenhum. Apanhado pela asserção "um valor que não é um token não produz endereço nem valor" — que a primeira versão escreveu de forma confusa e que, arrumada, mostrou o defeito.

A correção foi na ordem das decisões: **endereço primeiro, link depois**. Sem endereço não há link — apenas a frase que nomeia o documento.

## 8. Uma anotação que mente, e quem a apanhou

O `@return` de `entries()` dizia `array<int, OrderFieldEntry>` e a função devolve **pares** (a entrada e a definição que a autorizou — porque um documento é renderizado pela sua definição e não pelo seu valor). O PHPStan recusou com cinco erros em cascata. O docblock foi corrigido, não silenciado.

## 9. O que NÃO foi provado, e porquê

**Nenhum e-mail foi enviado.** As projeções são afirmadas **renderizando o callback do hook** para as quatro combinações, não entregando uma mensagem. O que um cliente de correio mostra, e como um tema que sobrepõe os templates coloca o hook, é a WCCS-063; enviar a mensagem a sério é o percurso ponta-a-ponta da F12.

**A configuração que liga os links.** O filtro existe e é exercitado; o ecrã onde um comerciante o liga não existe, e fica nomeado. É a mesma decisão por campo que a F03 (editor) teria de expor.

## 10. Fecho

Três das oito tarefas da F10 estão feitas, e as três fecham superfícies de exposição: o ecrã do staff, a página do cliente e agora os e-mails. O gate da fase — a auditoria por papel, endpoint e e-mail — continua a precisar das APIs (WCCS-056 a WCCS-058) e da própria auditoria.

**Próxima tarefa:** **WCCS-054**.
