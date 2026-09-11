# Registro de validação — WCCS-034

**Tarefa:** WCCS-034 · "Implementar discard/preserve"
**Fase:** F06 · Conditional Logic e política de valores
**Prioridade:** `required_v1` · **Dependências:** F03, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Valor residual oculto é descartado por padrão; required segue estado no servidor."

**Resultado:** **296 testes unitários PHP** (3 novos) · **831 asserções de integração** em 30 provas, 0 falhas (18 novas) · **541 testes de JS** em 31 suítes (10 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Validation/ValueProcessor.php` | A política: o que acontece a um valor que ficou oculto |
| `src/Domain/Conditions/ConditionEvaluatorRegistry.php` | `rules` passa a ser o motor padrão |
| `src/Checkout/Classic/ClassicAssets.php` | Publica as regras que a página consegue responder |
| `resources/checkout/conditional.js` | Mostra, oculta e baixa `required` no browser |
| `resources/checkout/index.js` | Liga o componente ao ciclo do checkout |
| `tests/Integration/F06-wccs-034-hidden-value-policy-proof.php` | A política através de uma submissão real |
| `tests/js/checkout/conditional.test.js` | A metade cliente, em jsdom |

## 3. Discard é o padrão em três níveis

A frase do aceite tem uma palavra que decide tudo: **por padrão**. Não é uma das duas opções — é aquela que acontece quando nada foi dito, e está afirmada nos três sítios onde poderia ser esquecida:

1. `DefinitionVocabulary::DEFAULT_HIDDEN_VALUE_POLICY` é `discard`.
2. Uma definição que nunca menciona a política é gravada como `discard`.
3. Uma submissão real, com o campo obrigatório e o valor preenchido, mas oculto pela regra: nada é guardado, nada é escrito no pedido e **nenhum erro é levantado**.

O terceiro é o que importa. Um campo que o cliente não vê não pode recusar o pedido: nem por obrigatoriedade, nem pelas regras do próprio valor. Era onde o comportamento antigo estava incompleto — o valor de um campo oculto ainda era validado e os seus erros subiam para o checkout, bloqueando a compra atrás de uma mensagem sem campo a que se agarrar.

## 4. `required` segue o servidor, e a prova tenta forçá-lo

A obrigatoriedade não é reavaliada: chegar à linha que a decide já significa que o campo é visível. O que a prova demonstra é a direção contrária — que o browser não consegue desligá-la:

- campo obrigatório visível, submetido vazio → `required`, recusado;
- o **mesmo pedido forjado**, com o campo escondido no formulário e o valor ausente → `required`, recusado;
- campo cuja regra depende do **país**: com o cliente em `PT`, um `country=BR` publicado no pedido não muda nada — a regra lê o objeto do WooCommerce, que diz `PT`, o campo fica oculto e não há erro;
- e o mesmo campo, com o cliente em `BR`, volta a ser obrigatório.

É a diferença entre ler o formulário e ler o contexto: um pedido pode dizer o que quiser sobre o país, e nada do que diz é contexto.

## 5. Preserve, que ninguém recebe por acidente

`preserve` só acontece por configuração explícita, e faz duas coisas em vez de uma:

- **guarda o valor que a loja aceita** — o que o cliente tinha escrito sobrevive ao campo ficar oculto, e chega ao pedido;
- **não guarda o valor que a loja recusaria** — e não levanta o erro. O campo não está no formulário, portanto não há nada que o cliente possa corrigir; levantar o erro bloquearia a compra por uma mensagem que não aponta para campo nenhum. O valor é descartado em vez de guardado por validar.

O `§11` pede uma "política de privacidade" para esta escolha, e a razão está no nome: o valor sobrevive a uma pergunta que o cliente acreditou ter respondido. Fica registado o que isso significa na prática: com `preserve`, um documento pessoal que o cliente escreveu num campo que depois se ocultou continua a ser guardado no pedido.

## 6. O motor passou a ser o padrão, e porquê

A WCCS-033 registou o motor sem o tornar padrão, para que a loja não mudasse de comportamento como efeito secundário de um motor existir, e deixou a decisão para esta tarefa. A decisão foi tomada, e não por preferência:

**com metade cliente a avaliar a mesma árvore, o servidor e o browser têm de concordar sobre o mesmo documento.** Se o servidor continuasse a responder `always`, um campo que o browser ocultou seria exigido pelo servidor como obrigatório — um pedido bloqueado por um campo que ninguém consegue ver. O avaliador permissivo continua registado e alcançável por nome, e a prova mostra as duas coisas: um documento que não nomeia motor é respondido pelo que está ativo, e um documento que fixa `always` continua a ser respondido por ele.

A prova da WCCS-033 afirmava `active() === 'always'`. Passou a afirmar o que é verdade hoje, em vez de fixar um padrão já substituído.

## 7. O que a página recebe

`ClassicAssets` publica apenas as regras cujas **todas** as fontes a página possui — país, estado, método de envio, método de pagamento e o valor de outro campo —, com a política ao lado. Uma regra que lê o carrinho ou se o cliente está autenticado fica no servidor, que a recalcula com contexto confiável: publicá-la seria convidar o browser a responder a uma pergunta que não consegue responder, e as duas respostas discordariam na direção que bloqueia o pedido. A prova publica um documento com os dois tipos de regra e afirma que uma viaja e a outra não.

Do lado da página, três decisões:

- **ocultar baixa `required`, e nunca o levanta.** O atributo que o servidor escreveu é lembrado ao esconder e restaurado ao mostrar; um campo que o servidor não marcou como obrigatório nunca passa a obrigatório no browser.
- **o valor segue a política**: `discard` limpa o campo ao escondê-lo, `preserve` deixa o que o cliente escreveu.
- **o input nunca é desativado.** Desativar um campo retira-o da submissão, e quem decide o que é guardado é o servidor: ele tem de ver sempre o que o campo contém, mesmo depois de o browser o ter ocultado.

## 8. O que NÃO foi provado, e porquê

**Um checkout a sério.** Nada disto foi visto num browser. A política foi provada através do pipeline real, com contexto real, dentro do WP-CLI; o componente cliente foi provado em jsdom. A observação do gate — *"fluxo PF/PJ funciona após múltiplas trocas"* — continua a precisar de uma página de checkout clássico (`CLASSIC-TEST-SURFACE`) e de um browser (WCCS-063).

**A troca repetida de PF/PJ.** As fixtures e as provas exercitam cada estado, não uma sequência de idas e voltas com um carrinho a mudar pelo meio. É o que o gate pede e o que falta observar.

**A ordem das definições.** Uma regra que lê outro campo só o vê se ele vier antes na submissão. Ficou registado na WCCS-033 e continua verdadeiro aqui; a política não o altera.

**O valor residual no pedido.** A prova mostra o que o pipeline entrega e o que a camada de adapter transporta para os dados do checkout. A gravação num `WC_Order` a sério para um campo oculto preservado é a mesma que a WCCS-023 provou para um campo visível.

## 9. Próxima tarefa

**WCCS-035 — "Criar compilador de condições nativas"** (aceite: *somente condições representáveis são compiladas; demais capacidades ficam explícitas*). É onde o mesmo documento passa a ser traduzido para os mecanismos nativos de `hidden/required` do checkout Blocks, e onde a regra que o Blocks não consegue exprimir tem de dizer isso em vez de ser ignorada em silêncio.
