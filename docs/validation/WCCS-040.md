# Registro de validação — WCCS-040

**Tarefa:** WCCS-040 · "Testar edição e lifecycle Blocks"
**Fase:** F07 · Checkout Blocks nativo e tipos próprios
**Prioridade:** `required_v1` · **Dependências:** F04, F05, F06
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Troca de endereço/frete, remount e rerender preservam valores sem DOM hacks."

**Resultado:** **336 testes unitários PHP** · **914 asserções de integração** em 36 provas, 0 falhas (8 novas) · **568 testes de JS** em 33 suítes (11 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/blocks/values.js` | O store dos valores e o lifecycle que os governa |
| `resources/blocks/index.js` | Os componentes passam a ser vistas sobre o store |
| `src/Checkout/Blocks/BlocksRenderer.php` | Publica a política de valor oculto com cada campo |
| `tests/js/blocks/values.test.js` | O lifecycle, o remount, o re-render e a política |
| `tests/Integration/F07-wccs-040-blocks-lifecycle-proof.php` | O contrato com o servidor |

## 3. A resposta do Blocks não é a do clássico, e o código di-lo

O checkout clássico é substituído por fragmentos vindos do servidor, e a WCCS-025 resolveu isso capturando antes do AJAX e restaurando depois. Aqui **não há fragmento para reler nem evento para escutar**: o React desmonta e monta subárvores quando o endereço, o frete ou o pagamento mudam, e o que sobrevive a um remount é o estado que **nunca esteve no componente**.

Por isso os valores vivem num **store** que pertence ao lifecycle da página, e um componente é uma vista sobre ele: renderiza o que o store tem e reporta o que o cliente escreveu. **Nada lê o DOM para recuperar um valor** — um valor recuperado do markup é um valor recuperado daquilo que o React renderizou por último, que é precisamente o que a aceitação proíbe.

## 4. A política de valor oculto é aplicada nos dois lados, pela mesma razão

O servidor re-decide a visibilidade e re-aplica a política quando o pedido é colocado, e **continua a ser a autoridade**. A página mantém o valor por uma razão só: o cliente escreveu-o, e um checkout que o esquece sempre que o método de envio muda é um checkout que perde trabalho. As duas metades concordam porque leem a mesma política do mesmo documento — e para isso a política teve de passar a viajar no payload (`policy`, herdada do `hidden_value_policy` da definição, com `discard` por omissão). Era a peça que faltava: sem ela o cliente não tinha como aplicar regra nenhuma.

## 5. `sem DOM hacks` é uma asserção, não uma frase

O último spec **espia o construtor `MutationObserver`** e afirma que nunca foi chamado enquanto o lifecycle corre. É a asserção que impede as outras de passarem pela razão errada: um módulo que recuperasse valores a observar o documento pareceria preservá-los, quando o que fazia era reler o que o React tinha renderizado por último.

E o resto do conjunto fixa o aceite palavra a palavra:

- o valor sobrevive a um **unmount e a uma montagem nova** (a troca de endereço/frete);
- sobrevive a um **re-render** sobre o mesmo store;
- é registado **a cada tecla** através de um harness que renderiza o que o store tem — sem a segunda metade, um input controlado voltava ao princípio a cada tecla (e foi assim que a primeira versão deste spec falhou: esperava `h`, recebeu `i`, porque faltava o cliente a escrever de volta);
- ao esconder, `discard` **descarta** e `preserve` **guarda**; um campo sem política é tratado como `discard`, que é o que o servidor faz;
- um campo visível não é tocado, seja qual for a política.

## 6. O que a prova estabelece

- Cada campo que um componente desenha leva a sua política no payload, com `discard` por omissão e `preserve` a viajar como tal;
- o bundle é construído, continua a declarar só dependências que o WordPress fornece, o payload é escrito **antes** do bundle correr, e leva a política de cada campo — que é o que a página espelha.

## 7. O que NÃO foi provado, e porquê

**Um checkout Blocks a sério.** Continua a ser a mesma lacuna honesta das tarefas anteriores: os componentes são renderizados e operados em jsdom, o store é exercitado, e nenhum browser foi aberto (WCCS-063). O que a aceitação pede — remount, re-render e valores preservados sem DOM hacks — está exercitado ao nível do componente.

**O registo dos componentes no checkout.** A costura existe e responde `no_blocks_checkout_api` quando não há onde os pôr; colocá-los é o passo de integração que a WCCS-038 deixou nomeado, e depende de onde o Blocks permite inserir campos de terceiros. Fica registado como o trabalho que falta para a F07 deixar de ter esta costura por fechar.

## 8. Fecho da fase F07

As cinco tarefas estão concluídas: os campos nativos (036), os componentes próprios (037), a Store API e a validação (038), a matriz no admin (039) e o lifecycle (040).

O gate — *"todos os tipos não-file da V1 possuem renderer homologado ou restrição explícita coerente; nenhum modo é anunciado além do testado"* — está **cumprido na costura e não está fechado**, pela mesma razão que todos os outros gates deste ficheiro: falta a observação.

O que está feito e verificado de forma exaustiva é a metade da classificação: a prova percorre o registo de tipos e nenhum fica sem modo (3 nativos, 7 controlados, 11 restritos, cada um com motivo), as respostas do Blocks vêm do adapter que regista os campos e não de uma segunda tabela, e *"nenhum modo é anunciado além do testado"* é agora propriedade de três sítios ao mesmo tempo — a classificação, o registo e a matriz que o lojista vê antes de publicar.

O que falta é a palavra *homologado*: um componente exercitado em jsdom não é um componente que alguém viu num checkout, e os componentes controlados ainda não estão colocados numa página. Registá-lo como cumprido seria a mesma afirmação a mais que este projeto já teve de corrigir na WCCS-028 — e é por isso que a F07 fica aberta em `docs/compatibility.json`, com o caminho de fecho escrito ao lado.

**Próxima tarefa:** **WCCS-041**, a primeira da F08 — upload privado nos dois checkouts.
