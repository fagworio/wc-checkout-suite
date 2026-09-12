# Registro de validação — WCCS-049

**Tarefa:** WCCS-049 · "Homologar gateways e express"
**Fase:** F09 · Página customizada e pagamento
**Prioridade:** `required_v1` · **Dependências:** F02, F07, F08
**Data da execução:** 11/09/2026

## ⚠️ Status: PARCIALMENTE CUMPRIDA — bloqueada pelo ambiente

O critério de aceite é uma lista de **observações** — *"Sandbox, 3DS/redirect, tokens salvos, retry e dados required verificados"* — e nenhuma delas pode ser feita aqui: **nenhum gateway está habilitado e não existem credenciais de sandbox**. É o bloqueador `SANDBOX-PAYMENT`, registado desde a WCCS-004, e resolvê-lo exige habilitar um gateway nas configurações da loja, **fora da raiz do plugin**. A tarefa **não é marcada como concluída**.

O que foi entregue, e porque é a metade que tem de existir **antes** da observação, é a **matriz de homologação** e a regra que a impede de ser uma lista de desejos.

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Sandbox, 3DS/redirect, tokens salvos, retry e dados required verificados."

**Resultado:** **392 testes unitários PHP** (9 novos) · **1064 asserções de integração** em 44 provas, 0 falhas (21 novas) · **601 testes de JS** em 36 suítes (2 novos) · os gates verdes, verificados por código de saída. **As cinco observações do critério: não feitas**, com o motivo nomeado.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/payments/homologation.json` | O registo: gateway, versão, modo, cenário, decoração retirada |
| `src/Domain/Payments/PaymentMode.php` | O vocabulário fechado dos modos |
| `src/Domain/Payments/PaymentMatrix.php` | A decisão, e a regra de que uma afirmação precisa de evidência |
| `src/Checkout/Classic/ClassicAssets.php` | Publica a decisão de cada gateway no checkout |
| `resources/checkout/payment.js` | Consome-a: uma decoração retirada nunca é aplicada |
| `tests/Unit/Domain/Payments/PaymentMatrixTest.php` | As três formas de um registo não ser uma promessa |
| `tests/Integration/F09-wccs-049-payment-matrix-proof.php` | O registo contra os gateways que a loja tem |

## 3. A frase que decide a tarefa

A secção 15 do roadmap escreve três exigências numa linha, e são elas que dão forma a tudo:

> Modo compatível deve existir quando um gateway homologado não tolerar determinada decoração. **Registrar exatamente gateway, versão, modo e cenário testados. Não prometer compatibilidade com "todos os gateways".**

Uma tabela de compatibilidade falha sempre da mesma maneira: alguém escreve um modo e ninguém pergunta onde está a prova. Por isso a regra central deste código é **uma afirmação precisa de evidência**:

- uma linha que declara `decorated` e **não nomeia um cenário passado** responde `undecided`, seja qual for o modo que declara;
- um cenário que não está no vocabulário do próprio registo é **descartado**, porque um teste que passou contra um cenário que ninguém escreveu como correr não é evidência;
- uma linha registada para a versão **1.0.0** não diz nada sobre a **2.0.0** instalada hoje — e a decisão explica-o por palavras, em vez de promover silenciosamente uma observação antiga a promessa sobre código diferente;
- um registo que não se consegue ler é `undecided` para tudo, e não um erro nem uma permissão: um checkout que não consegue ler o registo tem de recuar para **não prometer nada**, não para prometer tudo.

Estas quatro estão afirmadas contra um registo forjado dentro da prova, além do registo que é servido — porque a regra tem de valer para o ficheiro que alguém vai escrever amanhã, não só para o que está lá hoje.

## 4. Esta loja: sete gateways, nenhum homologado — e isso é publicado

Esta loja tem **sete gateways instalados** (PayPal, quatro do Mercado Pago, transferência, cheque e pagamento na entrega) e **nenhum habilitado**. A matriz dá uma resposta para cada um, e a resposta é, para os sete, `undecided`:

```
modes={"undecided":7}
promised=[]
```

E a resposta **chega ao checkout**: o payload leva a decisão de cada gateway que a página vai realmente apresentar, mais a contagem dos que ninguém correu, para que a diferença entre "testámos" e "não olhámos" seja visível a quem tem de agir sobre ela.

Uma subtileza que a prova apanhou: o payload responde pelos gateways **disponíveis**, não pelos instalados. Uma loja com sete configurados e nenhum habilitado **não tem nada para apresentar**, e um payload que listasse os sete estaria a descrever um checkout que o cliente não recebe. A asserção passou a afirmar exatamente isso (`installed=7 offered=0`), em vez da igualdade cómoda com a lista instalada.

## 5. O consumidor, para o registo não ser um arquivo morto

Um registo que ninguém lê é uma promessa por escrever. O caminho completo existe e tem uma ponta em cada sítio:

**registo → decisão → payload → marcador no marcador do WooCommerce → folha de estilo.**

Quando o registo retira a decoração `panel`, o módulo **não escreve o atributo de que a folha de estilo desenha a caixa**. Não é "aplicar e desfazer": é **nunca aplicar**, que é a diferença entre um registo honrado e um registo negociado. E a o módulo continua a ligar tudo o resto — o `id` do painel, o `aria-controls` no radio, o modo na linha (`data-wccs-mode`) — porque o que é retirado é a **decoração**, não a acessibilidade nem a ligação que o leitor de ecrã lê. Há dois testes de jsdom para estas duas metades.

**O vocabulário de decorações lista só o que o código sabe retirar hoje** (`panel`), e a prova afirma-o com essa razão escrita: uma entrada que nada honra leria como uma promessa. Alargá-lo é uma alteração ao registo **na mesma mudança** que ensina a apresentação a retirar outra decoração.

## 6. O que a matriz deixou de fora, deliberadamente

- **Não há integração de express checkout nesta tarefa.** A secção 15 é explícita — *"Uma flag no campo não cria integração automaticamente"* — e o gate de segurança que ela descreve (*um fluxo não pode concluir sem um campo crítico obrigatório*) só pode ser verificado contra um gateway expresso a correr. O cenário `express` **está no vocabulário do registo**, marcado como o que falta, em vez de tratado como feito.
- **Não há "modo compatível" automático.** O modo é registado por gateway e por versão, nunca inferido: inferir seria a promessa que a tarefa proíbe.
- **Não se promete nada sobre gateways que não estão instalados.** Uma loja com outro gateway recebe `undecided` e o motivo, e o checkout continua a funcionar como sempre funcionou — porque a apresentação não toca nos campos de nenhum gateway (WCCS-048).

## 7. O que falta para fechar, exatamente

1. Habilitar **um** gateway em modo sandbox com credenciais válidas — configuração da loja, fora da raiz do plugin, e por isso registada como bloqueador e não executada.
2. Correr os cenários e **escrever as linhas**: `id`, `version`, `mode`, `tested[]`, e `withheld[]` quando for o caso.
3. Repetir por gateway. Só aí o `decided` deixa de ser `undecided`, e a apresentação passa a aplicar o que foi observado tolerar.

Enquanto isso, a loja fica no estado correto: **nada é prometido, e diz-se que nada é prometido.**

## 8. Fecho

O gate da fase continua aberto — agora com uma quarta razão nomeada além das tarefas que faltam: a homologação não pode ser executada neste ambiente. As duas cláusulas que não dependem dele continuam afirmadas: o plugin **não regista gateway nenhum** e **não declara campo de cartão nenhum**.

**Próxima tarefa:** **WCCS-050** (opt-in, diagnóstico e fallback), que não depende de gateway habilitado — e que é também onde a contagem de gateways não homologados deve aparecer para o comerciante.
