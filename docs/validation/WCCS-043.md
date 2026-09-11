# Registro de validação — WCCS-043

**Tarefa:** WCCS-043 · "Criar componente único/múltiplo"
**Fase:** F08 · Upload privado nos dois checkouts
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Progresso, cancelar, remover, retry e erros acessíveis sem upload duplicado."

**Resultado:** **347 testes unitários PHP** · **951 asserções de integração** em 38 provas, 0 falhas · **581 testes de JS** em 34 suítes (13 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/upload/uploader.js` | A máquina de estados: a aceitação vive aqui |
| `resources/upload/transport.js` | O único ficheiro que conhece o pedido |
| `resources/upload/classic.js` | O componente do checkout clássico |
| `src/Checkout/Classic/ClassicUploads.php` | O campo de ficheiro, que o clássico não tem |
| `tests/js/upload/uploader.test.js` | As cinco palavras do aceite |

## 3. "Sem upload duplicado" são quatro acidentes diferentes

Tratá-los como um só é como a cláusula costuma falhar, por isso a máquina fecha os quatro e a suíte afirma cada um:

1. **Dois cliques no botão** — o mesmo documento escolhido duas vezes dá **um registo e uma transferência**. Sem isto a loja guardaria duas cópias e referenciaria uma.
2. **Um `retry` a cair enquanto a primeira tentativa ainda viaja** — um booleano de estado, não um atributo `disabled`: um botão desativado pode ser reativado por quem o desenha, e uma máquina que se recusa a começar responde igual venha de onde vier.
3. **Um ficheiro que a loja já aceitou nunca é reenviado.** O token é o registo de que a loja tem aqueles bytes; reenviá-los deixaria a primeira cópia órfã.
4. **Um ficheiro diferente com o mesmo nome é aceito** — `invoice.pdf` de 10 bytes e `invoice.pdf` de 20 são documentos distintos, e a identidade é nome+tamanho+modificação, que é o que o browser dá.

E um `retry` **não** cria um segundo registo: a lista continua com um, e a suíte afirma-o.

## 4. O resto do aceite, um a um

- **Progresso** — vem do `XMLHttpRequest` (o `fetch` não o sabe dar) e é limitado a 0–100, porque uma percentagem fora do intervalo é uma barra que não se consegue desenhar. A live region anuncia-o, porque uma barra é invisível para quem não está a olhar.
- **Cancelar** — aborta e **guarda o ficheiro**, para o cliente tentar outra vez sem o escolher de novo; e não fica nada no servidor, porque um token só é escrito a partir de uma resposta que chegou.
- **Remover** — pede ao servidor quando há token e **não pede** quando não há (nada a remover é uma pergunta que não se faz).
- **Retry** — reenvia o mesmo ficheiro; só o que parou pode ser repetido.
- **Erros acessíveis** — o código e a mensagem que o servidor mandou, não um genérico; escritos ao lado do campo com `role="alert"`.

## 5. O campo de ficheiro no checkout clássico

A API de campos do WooCommerce não tem tipo `file` — e não precisa: um tipo que ela não conhece é entregue ao filtro `woocommerce_form_field_{type}`, que é a via documentada para uma extensão desenhar um. É essa a costura usada, e é por isso que o adapter clássico passou a poder traduzir `file`.

O que se desenha é deliberadamente simples: um `<input type="file">` com rótulo e os marcadores que o bundle procura, e uma frase a dizer que os uploads não estão disponíveis quando a loja não consegue manter um ficheiro privado — um campo vazio e uma razão honesta em vez de um controlo que silenciosamente não faz nada.

## 6. Uma decisão de desenho que a varredura obrigou a tomar

Ao dar o tipo `file` ao clássico, **seis provas de fases anteriores falharam** — todas por uma razão legítima e nenhuma por um defeito:

- cinco usavam `file` como *o* exemplo de "tipo que o clássico não consegue desenhar"; passaram a usar um tipo estrutural (`heading`), que é o que a asserção sempre quis dizer;
- quatro enumeram **exatamente** os hooks da loja e tinham de passar a nomear `woocommerce_form_field_file@10:render`.

É o custo honesto de mudar uma capacidade: as asserções que fixavam o mundo antigo foram atualizadas para dizer o novo, deliberadamente, e não enfraquecidas.

## 7. O gate deixou de poder fazer pedidos HTTP a partir de uma página

A varredura apanhou algo mais sério do que asserções obsoletas: `enabled()` **observava** quando não havia observação, e observar é um pedido HTTP ao endereço da própria loja. Com o componente a chamar o gate, isso punha uma ida à rede à frente do checkout — e deixava um servidor lento decidir se um cliente pode enviar um documento. Pior: tornava a construção dos dados da página uma **escrita**.

Corrigido na raiz: `enabled()` **lê** a observação e nunca a faz; um ambiente por observar está simplesmente "ainda não disponível" e di-lo. A observação acontece quando alguém a pede — a superfície de diagnóstico, a administração ou uma verificação agendada. Nenhum pedido de loja faz um pedido de rede nem escreve uma opção.

## 8. O que NÃO foi provado, e porquê

**Um browser a enviar um ficheiro.** A máquina é exercitada com um transporte controlado (é a única forma de produzir uma transferência a meio, cancelada e falhada de forma fiável) e o componente clássico é desenhado sobre o DOM; o `multipart` real a partir de uma página pertence à revisão em browser (WCCS-063).

**O componente do checkout Blocks.** A máquina é partilhada e está pronta para ele, mas o campo de upload em React **não foi entregue nesta tarefa** — fica registado como o que falta, e não como feito. Uma metade de uma afirmação é a afirmação que este projeto tem corrigido.

**A privacidade.** Inalterada: o bloqueador `UPLOAD-PRIVACY-ENV` mantém o recurso desligado nesta loja.

## 9. Próxima tarefa

**WCCS-044 — "Vincular ao pedido e proteger download"** (aceite: *vínculo atômico/idempotente; dono autorizado acessa e terceiro recebe negação*): onde um token desta tarefa passa a ser um documento que um pedido pode ler — e o componente do Blocks é entregue com ele.
