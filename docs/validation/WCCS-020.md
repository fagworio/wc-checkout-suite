# Registro de validação — WCCS-020

**Tarefa:** WCCS-020 · "Criar estados e ações em lote"
**Fase:** F03 · Field Manager, seções e publicação (última tarefa da fase)
**Prioridade:** `required_v1` · **Dependências:** F01, F02
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Vazio, erro, rede, conflito e permissão tratados; desfazer local e revisão separados."

**Resultado:** **325 testes de JS** (21 suítes, 95 novos) · **452 asserções de integração** em 16 provas, 0 falhas (28 novas) · **122 testes unitários PHP** · os **7 gates** verdes.

> **Adendo (mesma data).** Depois de a tarefa ser dada por cumprida, o lojista relatou que a aba **Fields** não
> exibia nada. Era uma regressão introduzida nesta própria tarefa. As secções 11 e 12 registam a causa, a
> correção e o endereço de aba que foi pedido na mesma altura. O corpo abaixo descreve a tarefa como foi
> originalmente entregue.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/admin/app/schema/failureState.js` | Os estados como resultados distintos, cada um com o que fazer |
| `resources/admin/app/schema/useDocumentHistory.js` | Desfazer/refazer local, limitado, separado da revisão |
| `resources/admin/app/schema/fieldOperations.ts` (alterado) | Operações em lote puras + relatório de impacto |
| `resources/admin/app/components/BulkActions.js` | Barra de lote com confirmação de impacto |
| `resources/admin/app/FieldsScreen.js` (alterado) | Estados, desfazer, seleção, extensão ausente, versão não suportada |
| `src/Domain/Schema/SchemaRepository.php` (alterado) | `read_status()`: um slot ilegível diz isso em vez de parecer vazio |

## 3. Como cada palavra do aceite foi provada

### "Vazio, erro, rede, conflito e permissão tratados" — ✅

Antes desta tarefa, quatro destes cinco chegavam à tela como `error.message` — uma string, que tem a mesma forma para todos. O classificador transforma-os em resultados distintos, com **o que fazer** em cada um:

| Estado | Como é reconhecido | O que diz ao lojista | Provado |
|---|---|---|---|
| **Vazio** | `read_status` = `absent` | nada a fazer; convite a começar | rota real |
| **Erro** | qualquer outra falha | tente de novo; a secção de diagnóstico reporta o que o site suporta | 7 casos |
| **Rede** | `isTransient` (status 0 ou 5xx) | "a requisição não obteve resposta; as suas alterações continuam aqui" | 3 casos |
| **Conflito** | `isConflict` (409) | "alguém gravou primeiro, revisão %d; as suas alterações **não** se perderam" | rota real |
| **Permissão** | `isForbidden` (403) | sessão expirada ou sem permissão; recarregue | rota real, 401 e 403 |

Cada estado tem um teste que verifica a mesma regra, porque é a regra em que a tarefa assenta: **uma falha nunca descarta trabalho**. O `§428` diz "falha não descarta trabalho", e um estado cuja recomendação fosse recomeçar quebrá-la-ia.

A prova do servidor fecha a outra metade — uma escrita recusada **não escreve nada**:

| Asserção | Resultado |
|---|---|
| Uma escrita contra uma revisão que avançou é recusada com 409 | sim |
| A recusa nomeia a revisão que venceu | `current_revision=2` |
| A escrita recusada não alterou **nada** | `before == after` |
| O campo de quem perdeu não entrou, o de quem venceu continua lá | sim |
| O lojista consegue gravar aceitando a revisão nova | 200 |

Não simulei falha de rede no servidor — não é possível. Está provada no lado do cliente pelo ramo de transporte do classificador, e a prova regista isso explicitamente em vez de o omitir.

### "desfazer local e revisão separados" — ✅

Dois mecanismos com a mesma palavra são um convite ao engano, então a separação está no tipo, não num comentário:

| | Desfazer local | Histórico de publicação |
|---|---|---|
| Cobre | edições ainda não gravadas | versões que a loja correu |
| Vive | nesta aba | no servidor |
| Responde | "põe isso de volta" | "o que foi publicado, e quando" |
| Desfazê-lo | esquece uma edição | publica de novo, como revisão nova |

Três asserções que provam que não se tocam:

1. Gravar **limpa** o histórico local (`reset`), porque o servidor passou a ser a autoridade — deixar desfazer para um estado que o servidor nunca teve seria uma mentira.
2. Desfazer depois de gravar não está disponível.
3. O histórico de publicação **cresce** com um restauro (`3 → 4`), enquanto o desfazer local continua vazio.

A pilha é limitada a 50 edições e verificada: um histórico sem limite de documentos inteiros é memória sem limite, e o problema do lojista são as últimas edições.

### "ações em lote" (do título) — ✅

Quatro operações com impacto reportado **antes** de aplicar: habilitar, arquivar, mover para secção, mostrar/ocultar para um público.

A distinção que dá valor à confirmação: arquivar dez campos onde três pertencem ao WooCommerce **consegue sete**. Dizer "10 campos serão arquivados" e depois fazer sete em silêncio é o tipo de resposta que custa confiança. A confirmação nomeia os três que ficam de fora e por quê.

Também corrigi uma incoerência minha: mover **um** campo acrescenta-o ao fim da secção, mas mover **vários** intercalava-os onde estavam. Passaram a fazer o mesmo.

## 4. Uma lacuna de honestidade fechada

Um documento escrito por uma **versão mais nova** do plugin descodificava como **vazio** — indistinguível de uma instalação nova. A tela diria "ainda sem campos" enquanto os campos existiam, invisíveis. São duas situações que pedem acções opostas.

`read_status()` distingue agora `absent`, `readable`, `unsupported_version` e `corrupt`, e o relatório publica-o. A prova afirma as duas coisas lado a lado: o estado diz `unsupported_version` **e** `read()` continua a responder com um documento vazio — porque é isso que os outros chamadores querem e o que o lojista não pode ver.

**É a terceira vez nesta fase que o mesmo princípio resolve um problema**: o ADR-0007 (o publicado e o exigido são o mesmo), o ADR-0008 (incompatibilidade não é validação) e agora isto. O princípio é sempre *duas situações diferentes não podem parecer a mesma*. Não abri um ADR para esta instância — é um caso particular do mesmo, e diluir o conjunto com repetições enfraquecê-lo-ia. Fica registado como item de verificação para as fases seguintes: **todo slot de armazenamento novo tem de saber reportar-se**.

## 5. Um defeito real, e o pior momento possível para ele acontecer

`ApiError` expõe a sua classificação como **getters**, não como métodos. O código da WCCS-016 chamava-os como funções:

```js
if ( failure?.isValidationFailure?.() ) {   // isValidationFailure é um booleano
```

Chamar um booleano como função lança `TypeError`. O ramo que trata de um salvamento falhado **lançava ele próprio uma exceção** — exactamente no momento em que um save falha, que é quando o lojista mais precisa de saber o que aconteceu.

Nenhum teste o apanhou porque nenhum teste exercia um salvamento falhado na tela. Encontrei-o ao escrever o classificador, ao reparar que os testes do cliente usavam `error.isForbidden` (propriedade) e o meu código usava `isForbidden?.()`.

Corrigido em quatro sítios, com o classificador a documentar a distinção, e os testes do classificador passaram a construir o `ApiError` pelo construtor real — o meu primeiro fixture também estava errado (`new ApiError(status, body)` em vez de `new ApiError({ status, data })`), o que fazia o erro classificar-se como genérico. Um fixture que constrói o objeto de forma diferente da produção testa outra coisa.

## 6. O terceiro harness seguido a testar o que sobrou

A prova da WCCS-020 falhou primeiro em "a escrita recusada não alterou nada", com `current_revision=0`. A causa: a secção 3 apaga o rascunho, e a secção 5 assumia que havia um. O passo "alguém grava primeiro" também falhou em silêncio, pelo mesmo motivo.

É a mesma família de erro das WCCS-018 e WCCS-019: **o estado é herdado entre secções, e uma prova que não o carrega explicitamente está a testar o que sobrou**. As três vezes o sintoma apareceu numa asserção diferente da que causava o problema.

Corrigido estabelecendo o estado no início da secção, e a prova é agora verificada **idempotente**: duas execuções seguidas dão 27/27 e deixam `wccs_* = 0`.

## 7. Estado do gate da fase: F03 permanece aberto

O gate é: *"Lojista monta e publica um formulário completo, restaura revisão e não perde trabalho em conflito."*

Cada parte está provada ao nível da costura: montar (seções, campos, ordenação, inspector), publicar (diferenças, validações, incompatibilidades), restaurar revisão, e não perder trabalho num conflito. **Mas nenhum lojista fez isso, e eu não abri um navegador.** O gate descreve uma acção ponta a ponta que ninguém executou.

Marco-o como **não cumprido**, pela mesma razão que deixei o gate da F02 aberto: "provado em cada junta" e "alguém o fez" são afirmações diferentes, e a segunda é a que o gate pede. O que o fecha está identificado: a revisão em navegador da **WCCS-063** e a prova ponta a ponta da **F12**.

A F04 pode avançar: depende dos contratos do schema, que estão provados, e não da aprovação visual.

## 8. O que NÃO foi feito

- **Arrastar com o ponteiro.** Continua deliberadamente ausente; os botões e o teclado são o caminho, e acrescentar arrasto não pode removê-los.
- **Salvar automaticamente.** O risco principal declarado da fase é "drag-and-drop e salvar automático podem alterar produção sem intenção". Não existe, e a tela diz que é preciso gravar.
- **Aplicar o schema ao checkout.** F04.
- **Componentes da Suite para o Block.** A lista de incompatibilidades é a lista da F07.
- **Contexto Classic/Blocks como definição guardada** e nome do ficheiro de configuração no topo. O `§428` pede ambos; o contexto é reportado por adapter, e escolhê-lo como estado guardado é da F09.
- **"Recurso incompatível" como estado próprio.** É apresentado no painel de publicação (WCCS-019), não como um estado da tela de campos. A distinção é razoável e fica registada.

## 9. Observação honesta

**Não abri um navegador.** Não há captura de tela de nenhum destes estados.

O que sustenta a aceitação é o comportamento no DOM (288 testes) e o comportamento do servidor pela rota real (451 asserções). O que falta é ver os estados — e um estado mal desenhado pode estar correcto e ainda assim não ser compreendido, que é precisamente o modo de falha que nenhum teste apanha.

## 10. Próxima tarefa

**F04** — aplicar o schema ao checkout clássico. A F03 encerra com o schema configurável, publicável e restaurável; a F04 começa a torná-lo visível para o comprador.


---

## 11. Adendo — a regressão que impedia a aba de exibir

O relato foi directo: a aba **Fields** não exibia nada. A causa era minha e desta tarefa.

`useDocumentHistory` devolvia **um objeto novo a cada render**. Eu pus esse objeto nas dependências do
`load`, e o efeito de montagem depende do `load`:

```
carrega → reset muda o documento → edits muda → load muda → o efeito corre outra vez → laço
```

O React aborta num laço de atualizações deste tipo, e a secção não renderizava. O ecrã não estava vazio por
falta de dados — estava vazio por não chegar a desenhar.

**Duas correções, porque eram dois defeitos:**

1. **O hook passou a ter operações estáveis.** Lê o documento corrente de um `ref` em vez de fechar sobre o
   estado, e o objeto devolvido é memoizado. Um hook que devolve *callbacks* novos a cada render não pode ser
   usado numa lista de dependências, e este era.
2. **O `load` passou a depender da operação estável, não do objeto.** Mesmo com o objeto memoizado, a sua
   identidade muda quando o documento muda — e o `load` muda o documento. Depender de `resetDocument`, que é
   estável, quebra o ciclo.

**A razão de fundo é a mais incómoda:** esta tela **nunca teve teste**. As 18 suítes cobriam componentes e
funções puras, e nenhuma renderizava a tela. Um laço de render é invisível numa captura do DOM e óbvio numa
contagem de chamadas.

Foi por isso que `tests/js/screens/FieldsScreen.test.js` (14 testes) foi escrito, e a sua primeira asserção é
exactamente o guarda de regressão: **a tela carrega uma vez**. Verifiquei que o teste apanha mesmo o bug
reintroduzindo a dependência má — **5 testes falham**, todos passam com a correção. Um teste de regressão que
nunca falhou não é um teste de regressão.

O mesmo ficheiro cobre o que faltava cobrir na tela: o estado de carga, a lista, o estado vazio, o agrupamento
por secção, desfazer, marcar como alterado sem gravar, gravar, e as três falhas classificadas (rede, conflito,
permissão) cada uma a **preservar o trabalho**.

## 12. Adendo — o endereço da aba

Pedido na mesma altura: um parâmetro para abrir uma aba directamente, de modo a poder copiar o endereço.

`resources/admin/app/sectionUrl.js` faz as duas metades, e ambas importam:

- **Abrir:** `?page=wccs-checkoutsuite&section=fields` abre a aba. A forma abreviada
  `?page=wccs-checkoutsuite&fields` também é aceite, porque é o que uma pessoa escreve à mão.
- **Manter em passo:** o endereço é reescrito quando a aba muda, de modo que copiar a barra de endereço dá
  sempre a aba que está no ecrã. Um link que abre a aba errada é pior do que não haver link, porque quem o
  segue acredita no que vê.

Usa `replaceState` e não `pushState`: mudar de aba não é navegação, e um botão "voltar" cheio de cliques em
abas torna a saída do ecrã uma tarefa.

Verificado contra o servidor real, com sessão autenticada: a página responde **200** e serve o nó de montagem,
o bootstrap e o bundle com os três endereços (`&section=fields`, `&fields`, `&section=rules`).

23 testes de JS cobrem a leitura e a escrita do endereço, incluindo o caso que mais importa: um valor
desconhecido abre a primeira secção em vez de uma tela em branco.

## 13. Observação sobre o que a verificação mostrou, e o que não mostrou

Buscar a página real autenticada mostrou que o **servidor** estava correcto o tempo todo: o nó de montagem, o
payload de bootstrap e o bundle estavam lá. O defeito era inteiramente do lado do cliente, e nenhum teste do
lado do servidor poderia tê-lo apanhado — como nenhum dos 451 testes anteriores o apanhou.

Continuo **sem ter aberto um navegador**. O que mudou é que agora a tela tem um teste que a renderiza, e foi
um relato humano que revelou a lacuna. Isso é, em si, o registo mais útil desta secção: a cobertura estava
alta e a tela mais importante do plugin não estava montada em nenhum teste.
