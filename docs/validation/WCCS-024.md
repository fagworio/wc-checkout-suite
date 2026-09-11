# Registro de validação — WCCS-024

**Tarefa:** WCCS-024 · "Implementar histórico de valores"
**Fase:** F04 · Classic Checkout e persistência canônica
**Prioridade:** `required_v1` · **Dependências:** F03
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Renomear/arquivar campo não torna pedido antigo ilegível."

**Resultado:** **178 testes unitários PHP** (18 novos) · **594 asserções de integração** em 20 provas, 0 falhas (30 novas) · 325 testes de JS · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Orders/OrderFieldSnapshot.php` | O registo mínimo que viaja com o pedido |
| `src/Domain/Orders/OrderFieldEntry.php` | Uma entrada legível: valor + o que é preciso para o entender |
| `OrderFieldsService::history()` | O leitor histórico |
| `docs/adr/ADR-0010-order-snapshot-preserves-history.md` | A decisão que a tarefa obrigou a tomar |
| `tests/Unit/Domain/Orders/OrderFieldSnapshotTest.php` · `tests/Unit/Domain/Fields/FieldDefinitionTest.php` | 18 testes das decisões puras |
| `tests/Integration/F04-wccs-024-order-history-proof.php` | 30 asserções contra pedidos reais |

## 3. Como a prova funciona

A prova coloca **um** pedido e depois move o schema por baixo dele **cinco vezes**, lendo o pedido de novo em cada passo. O pedido nunca volta a ser escrito.

| Passo | O que muda no schema | O que o pedido antigo responde |
|---|---|---|
| 1 | — | o snapshot é gravado ao lado dos valores |
| 2 | todos os labels renomeados, uma opção renomeada, um campo retipado de `text` para `number` | lê `Size`, não `Tamanho`; a opção `m` ainda é **Medium**, não *Médio*; o tipo é `text`, e o retipo é **reportado** |
| 3 | `wccs_consent` arquivado (`enabled: false`) | o valor continua lá, com o label `Consent`; um pedido **novo** já não o recolhe |
| 4 | `wccs_size` **removido** do documento | lê-se pelo snapshot: label `Size`, opção `Medium`, tipo `select` |
| 5 | — (payload do formato 1, sem snapshot) | um campo que o schema conhece lê pelo schema; um que ninguém conhece é devolvido com o identificador e tipo **vazio** |

E a asserção que dá sentido a todas: **depois dos passos 2, 3 e 4, o payload armazenado do pedido é byte a byte o mesmo.** O schema mudou três vezes e nenhum pedido histórico foi reescrito — que é o `§13` a dizer *"Não modificar os pedidos históricos em massa para apenas trocar label"*, agora afirmado em vez de prometido.

## 4. A decisão que a tarefa obrigou a tomar — ADR-0010

O `§5` e o `§13` dizem que *"mudar o tipo ou a normalização exige uma operação de migração, não apenas um select no painel"*. Lida ao pé da letra, a frase admite duas implementações opostas:

**(A)** recusar a mudança de tipo na publicação — o lojista não consegue mudar um campo de texto para número, porque a migração que a frase exige não existe;
**(B)** permitir a mudança e garantir que ela não estraga o histórico, deixando a migração como a operação que **reescreve** valores.

Escolhi **(B)**, e não por conveniência: o snapshot torna a mudança segura. Um pedido antigo lê-se com o tipo com que foi capturado, e a divergência com o tipo atual é **reportada** ao leitor (`is_current() === false`), nunca resolvida em silêncio. Recusar a mudança seria impor um custo real ao lojista para evitar um problema que já não existe.

A decisão está registrada como **ADR-0010** porque restringe trabalho futuro de duas maneiras concretas: **nada neste plugin reescreve um pedido**, e **um valor nunca é formatado com um tipo adivinhado** — se ninguém sabe o que o campo era, o tipo vem vazio, porque assumir `text` formataria um número como texto e inventaria uma leitura que ninguém autorizou.

## 5. O que o snapshot é — e o que deliberadamente não é

**É** label, tipo e os labels das opções. É o mínimo que responde à pergunta "o que é que este valor era?".

**Não é** a definição copiada. Nada que só afete comportamento futuro — obrigatoriedade, condições, largura, validadores, política de armazenamento — está lá, e a prova afirma a lista de chaves de uma entrada (`label`, `type`, `options`) precisamente para que um acrescento futuro seja uma decisão e não um deslize.

**Não é** formatador. Máscara e preset são regra de *renderização* e pertencem a F05. Gravá-los agora seria uma chave que nada lê — e o formato foi construído para poder crescer: subir `OrderFieldsService::FORMAT` e manter a leitura do anterior é o precedente que esta tarefa deixa.

**Não é** autoridade sobre o schema. Descreve com o que *este* pedido foi capturado; um pedido posterior traz o seu.

## 6. Um detalhe que valeu a decisão da tarefa anterior

A WCCS-023 introduziu um marcador de formato no payload e um estado `unsupported_format`, com um comentário a dizer que "um leitor que adivinha é pior do que um que recusa". Esta tarefa precisou de mudar o formato, e a decisão pagou-se: **o formato 1 continua a ser lido** — `READABLE_FORMATS = [1, 2]` — e lê-se como um pedido que lembra os valores mas não os nomes.

Sem o marcador, um payload antigo teria sido lido com um `snapshot` ausente e nada distinguiria "este pedido não tem registo" de "este pedido foi gravado por uma versão que não sabia fazer registos". Com ele, a diferença é explícita e a prova cobre os dois sentidos: formato 1 lê-se, formato 3 é recusado.

Um efeito colateral honesto: a prova da WCCS-023 afirmava `format === 1` e passou a afirmar `2`. Tal como o conjunto de hooks na WCCS-022, uma asserção que fixa um valor tem de ser atualizada quando o valor muda deliberadamente — e é isso que a torna útil.

## 7. Uma divergência de duplicação que foi fechada

O mapa `valor => label` das opções era construído dentro do `ClassicAdapter`. Esta tarefa precisava do mesmo mapa para o snapshot, e havia um terceiro leitor (`ChoiceFieldType::allowed_keys`) a interpretar a mesma lista por conta própria. Três leitores de um só facto.

Passou a haver um accessor, `FieldDefinition::options()`, e os três leem o mesmo. O `ClassicAdapter` deixou de ter o seu próprio construtor de mapa — a prova da WCCS-021, que compara o array de campos real, continua verde, o que é a confirmação de que a troca foi neutra.

## 8. O que NÃO foi provado, e porquê

**Uma migração que reescreve valores históricos.** O `§13` exige dry-run, backup, relatório e confirmação explícita, e nada disso está construído. O que a prova afirma é o contrário: que nada reescreve.

**A exibição no painel do pedido.** O bloco "Campos do checkout" na edição do pedido é F10; esta tarefa entrega a leitura que ele vai usar.

**O formatador de máscara.** Um CPF guardado como `12345678909` lê-se com o tipo `text` correto, mas ainda não com a pontuação. Isso é F05, e o ADR-0010 diz como o snapshot cresce para o acomodar.

**Um pedido criado por um checkout.** Continua a ser a `CLASSIC-TEST-SURFACE`: os pedidos desta prova foram criados com `wc_create_order()` e o hook de criação disparado diretamente.

**O achado da WCCS-022 continua aberto:** `SchemaRepository::read()` constrói um validador que nunca usa, e dentro do filtro de campos isso lê e memoiza um inventário de campos core a meio caminho.

## 9. Próxima tarefa

**WCCS-025 — "Tratar lifecycle e refresh"** (Classic JS). Aceite: *"Refresh preserva valores e cria apenas uma instância por componente."*

É a última tarefa da F04. Ao contrário das quatro anteriores, esta tem uma metade **no navegador** — o refresh do bloco de revisão do pedido no checkout clássico — e portanto será a primeira tarefa da fase cujo critério não se prova inteiramente a partir de PHP. Vale a pena decidir antes de começar se ela avança só com a metade do servidor provada e a do cliente registrada como não observada, ou se espera pela `CLASSIC-TEST-SURFACE`.
