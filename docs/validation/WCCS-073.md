# WCCS-073 · Seções por área

**Fase:** F14 · **Depende de:** WCCS-072
**Aceite:** "Seção escolhida por destino; mesma seção em mais de uma área sem cópia de dados; área sem vínculo não recebe painel."

## 1. O que foi entregue

Uma seção passou a declarar **em que áreas é oferecida** (`areas`), e a escolha deixou de ser implícita:

- **`SectionDefinition`** ganhou `areas`, com `checkout` por defeito. Um documento escrito antes desta tarefa
  lia-se como o que era — uma seção de checkout — e continua a funcionar sem migração.
- **O vocabulário das áreas** é `checkout` mais os destinos que desenham um painel. A **API pública não é
  uma área**: é uma projeção de valores, não um sítio onde se insere um painel, e oferecê-la prometeria uma
  interface que não existe.
- **A validação** recusa uma seção sem área (`section_without_area`) e uma área que não existe
  (`section_unknown_area`).
- **O vínculo de um destino a uma seção passou a ser verificado** — e esta era a lacuna real que a tarefa
  expôs: até aqui `destinations.admin_order.section = 'nao_existe'` era aceite, porque o campo `section` de
  um vínculo nunca era cruzado com as seções declaradas. Agora a seção tem de existir **e** de estar
  oferecida na área daquele destino (`destination_section_not_offered`).
- **No admin**, a aba Vínculos só oferece, em cada destino, as seções oferecidas nessa área; e os dois
  diálogos de seção (criar e editar) ganharam o controlo das áreas, com o checkout pré-selecionado.

**A mesma resposta em duas áreas é uma só secção.** O harness escreve uma seção oferecida em `admin_order` e
`customer_order`, liga os dois destinos a ela, e verifica que ficou **uma** seção com as duas áreas e que os
dois vínculos apontam para o mesmo id. Não há cópia de dados por tela — é o mesmo campo, apresentado duas
vezes.

## 2. Achados desta tarefa

1. **A lacuna que a tarefa devia fechar não era a que eu esperava.** O modelo já permitia a mesma seção em
   dois destinos (nada o impedia), mas **nada verificava** que a seção referida por um vínculo existisse, e
   menos ainda que fosse oferecida naquela área. Escrever o harness foi o que o mostrou: o primeiro caso
   «link para uma seção oferecida noutra área» passou com 200 antes de eu escrever a verificação.
2. **Os harnesses das duas tarefas anteriores quebraram — e estavam errados.** Os fixtures ligavam
   `admin_order` a uma seção que o documento não declarava. A recusa nova
   (`destination_section_not_offered`) apontou-os; passaram a declarar as seções que referenciam, o que
   também torna esses fixtures mais honestos: um vínculo para uma seção inexistente nunca foi uma
   configuração válida.
3. **`public_api` não é uma área**, e dizê-lo no vocabulário é melhor do que o deixar implícito: uma seção
   oferecida ali não teria painel nenhum para desenhar.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 413 testes, 1477 asserções |
| `tests/Integration/F14-wccs-073-section-areas-proof.php` | **12 passaram, 0 falharam** |
| `tests/Unit/Domain/Sections/SectionValidatorTest.php` | seis casos novos (sem área, área desconhecida, várias áreas, vínculo para seção não oferecida, vínculo para seção inexistente, leitura de documento antigo) |
| `tests/js/views/FieldProperties.test.js` | 13 especificações, uma nova: só as seções daquela área são oferecidas |
| varredura de integração | 61 harnesses, zero falhas |

## 4. Limites

- A **ordem** entre seções continua a ser a do documento, não por área; o roadmap não pede uma ordem por
  área e não inventei uma.
- **Mover uma seção de área** não move os campos dela: um vínculo que aponte para uma seção que deixou de
  ser oferecida ali passa a ser recusado na gravação seguinte. É o comportamento correto — a configuração
  fica inconsistente à vista — mas não há ainda um aviso no momento em que a área é retirada. Fica registado.
- A prova de que **uma área sem vínculos não recebe painel nenhum** é WCCS-076; aqui provou-se a parte que
  lhe pertence: a seção é por área e o vínculo tem de apontar para uma seção oferecida ali.
