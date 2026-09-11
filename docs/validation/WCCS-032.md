# Registro de validação — WCCS-032

**Tarefa:** WCCS-032 · "Criar editor de regras"
**Fase:** F06 · Conditional Logic e política de valores
**Prioridade:** `required_v1` · **Dependências:** F03, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Regras legíveis, preview de resultado e mensagens de contradição."

**Resultado:** **239 testes unitários PHP** (2 novos) · **793 asserções de integração** em 28 provas, 0 falhas (27 novas) · **475 testes de JS** em 29 suítes (62 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/admin/app/schema/conditions.ts` | O modelo: forma, leitura em frase, contradições e o preview |
| `resources/admin/app/components/ConditionBuilder.js` | O editor: árvore `all`/`any`, frase, avisos e "Experimente" |
| `resources/admin/app/components/FieldInspector.js` | A aba *Conditions* passa a abrir sobre o editor |
| `resources/admin/app/FieldsScreen.js` | Publica a aba quando a catalogue traz o vocabulário, e os campos que uma regra pode ler |
| `src/Http/Admin/CatalogController.php` | O vocabulário publicado: operadores, fontes e limites |
| `src/Domain/Conditions/ConditionValidator.php` | O par operador↔fonte passa a ser recusado (ver §4) |
| `tests/Integration/F06-wccs-032-rule-editor-proof.php` | A metade servidor do contrato do editor |

## 3. Três decisões que deram forma ao componente

**O vocabulário é lido, nunca escrito.** Os operadores e as fontes vêm do servidor, que os lê das classes que o próprio validador lê. Uma segunda lista aqui seria a lista que discorda da primeira na primeira vez que uma delas for editada — é exatamente o que a ADR-0007 proíbe.

**O preview é preenchido pelo lojista.** Adivinhar que o carrinho tem R$ 200 mostraria o preview de uma loja que não existe. Nada é pré-preenchido, e só são pedidas as fontes que a regra realmente lê.

**O que não se pode saber aqui, não se adivinha.** Se a regra nomeia um campo existente e se dois campos dependem um do outro são propriedades do documento inteiro: continuam sendo respondidas onde o documento é validado, e o editor informa o que consegue ver sem inventar uma segunda opinião.

## 4. O defeito que a prova do editor apanhou

O aceite da WCCS-031 diz "operadores tipados", e metade disso não estava a ser cumprida. A verificação de tipo existia **apenas para referências a campos**, no nível do documento. Para uma fonte do catálogo, nada verificava o par: a sonda abaixo, executada antes da correção, mostra quatro regras sem sentido **aceitas**.

```
string source + greater_than     valid=yes codes=[]
list source + greater_than       valid=yes codes=[]
boolean source + contains        valid=yes codes=[]
number source + contains         valid=yes codes=[]
```

O valor era o tipo que o operador aceita — `5` para `greater_than` — portanto a única verificação que existia passava. "O país é maior que 5" era uma regra gravável.

Apareceu porque a prova escrita para esta tarefa afirma a igualdade entre **o que o editor oferece e o que o validador aceita**, e uma igualdade afirmada é uma igualdade que falha quando um dos lados está errado. A correção é uma cláusula em `walk_leaf`, com a mesma mensagem e o mesmo código (`condition_source_incompatible`) que o caminho das referências já usava, e o par passou a ser recusado na gravação:

```
string source + greater_than     valid=no  codes=["condition_source_incompatible"]
list source + greater_than       valid=no  codes=["condition_source_incompatible"]
boolean source + contains        valid=no  codes=["condition_source_incompatible"]
number source + contains         valid=no  codes=["condition_source_incompatible"]
```

Duas consequências, ambas deliberadas: o teste unitário novo percorre **todos** os pares fonte×operador (8 fontes diretas × 10 operadores) em vez de uma amostra, e a prova de integração percorre os mesmos pares através do validador real, mais os quatro tipos de valor do lado das referências.

## 5. O segundo defeito: `removeAt` não fazia o que o seu próprio docblock dizia

O `removeAt()` afirmava, em comentário, que remover o último filho de um grupo remove o grupo — e só o fazia quando o grupo estava aninhado. Na raiz, remover a última condição deixava `{ "all": [] }`, que o servidor recusa com `empty_condition_group`. O lojista ficaria com uma regra que não consegue gravar, sem saber porquê.

A cascata agora chega à raiz: remover a última condição **limpa a regra**, ou seja, o campo volta a ser sempre visível. É o que o editor faz, e o que a prova de integração confirma do outro lado (o grupo vazio é mesmo recusado).

## 6. O que ficou por decidir: `CONDITION-VALUE-TYPE`

Ao escrever a prova das referências, as duas metades do tipo de um valor apontaram para lados diferentes:

- `FileFieldType::valueSchema()` publica `array` — um campo de ficheiros guarda uma lista;
- `ConditionValidator::definition_type()` mapeia `file` para `string`.

Não é uma diferença cosmética: decide se `contains` sobre um campo de ficheiros é uma regra com sentido. Corrigir isto exige escolher **qual das duas fontes é a autoridade** — um registo de tipos que o validador passaria a consultar, ou uma tabela que se mantém à mão e tem de acompanhar os tipos — e essa é uma decisão de arquitetura, não um `if` a mais. Ficou registada como a decisão aberta `CONDITION-VALUE-TYPE` em `docs/compatibility.json`, com a evidência acima, e **deliberadamente não foi assertada em nenhum sentido**.

É também a razão pela qual o editor não estreita a lista de operadores depois de a referência nomear um campo: fazê-lo exigiria uma terceira cópia deste mapeamento. O editor oferece todos os operadores a uma referência, o servidor decide quando o campo é conhecido, e o painel de publicação diz o que recusou — com o código e a mensagem de sempre.

## 7. Os três pedidos do aceite, um a um

**Regras legíveis.** A regra é lida de volta como **uma frase**, com os rótulos que o servidor publicou ("Country is not equal to BR and Cart total is greater than 100"), e cada comparação dentro de um grupo diz o que lê ao lado dos seus próprios controlos. Uma folha na raiz não repete a frase: mostrá-la duas vezes seriam duas respostas à mesma pergunta. **Negação não é um nó** — é o operador negado — e é por isso que qualquer regra cabe numa frase só.

**Preview de resultado.** O lojista preenche só as fontes que a regra lê e a frase muda enquanto escreve: *com estes valores, a regra combina, o campo é mostrado*. Cada comparação aparece decidida, na ordem em que foi lida. O preview é **do editor e não o motor**: quem decide num checkout real é a WCCS-033; o que este faz é responder "com estes valores, a minha regra combina?" enquanto a regra está a ser escrita — pergunta que ninguém consegue responder a partir do servidor antes de a regra existir.

**Mensagens de contradição.** O editor diz o que consegue ver sem o documento: um grupo sem nada dentro, uma comparação sem valor, um valor onde o operador não pede nenhum, uma fonte ou um operador que o vocabulário não tem, um campo que lê a si próprio, uma referência a um campo que já não existe, um operador que não consegue ler a fonte que lhe foi apontada, e os limites de profundidade e de número de nós. O que precisa do documento inteiro continua a ser respondido onde o documento é validado. Nenhuma mensagem é inventada localmente para depois discordar do servidor: a mensagem do par operador↔fonte é a mesma dos dois lados.

## 8. O que NÃO foi provado, e porquê

**Avaliação.** Nada decide nada num checkout: o motor é a WCCS-033. O preview do editor é uma leitura da mesma árvore, não a implementação de produção.

**A execução de uma regra sobre um carrinho real.** Depende da WCCS-033 e da decisão `CLASSIC-TEST-SURFACE`, que continua a bloquear a observação de todos os gates desde F04.

**O comportamento do editor num browser.** A suíte de JS renderiza o componente em jsdom e opera-o com eventos reais, como nas tarefas anteriores; abrir um browser é a WCCS-063.

**A referência tipada pelo campo nomeado.** Registada em §6 como decisão aberta, e não como esquecimento.

**A aba *Conditions* num ecrã a sério.** O `FieldsScreen` passa a publicar a aba quando o catálogo traz o vocabulário, e o `FieldInspector` abre-a sobre o editor — ambos verificados em jsdom. Nenhum browser foi aberto.

## 9. Próxima tarefa

**WCCS-033 — "Implementar evaluators PHP/JS"** (aceite: *paridade em vazio, zero, false, arrays, contexto e negações*). É onde a árvore desta tarefa passa a decidir: um avaliador PHP e um JavaScript a concordar sobre as mesmas fixtures, com as mesmas respostas para `0`, `'0'`, `false`, listas vazias e negações — que é a lista exata onde o preview de §7 e o motor podem divergir se forem escritos duas vezes.
