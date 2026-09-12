# WCCS-074 · Permissões de arquivo por destino

**Fase:** F14 · **Depende de:** WCCS-071, WCCS-072
**Aceite:** "Matriz mostrar/ver/baixar/aprovar/reenviar independente por destino; cliente vê apenas o próprio pedido; nada vira público."

## 1. O que foi entregue

A matriz da secção 12 do roadmap passou a ser **dados**, e não um parágrafo:

- **`FilePermissions`** responde, para um campo e um destino, o que aquele destino pode fazer com o arquivo.
  Duas regras: as ações declaradas são **intersetadas** com as que o destino pode executar (um documento
  escrito à mão não alarga um destino para além da própria lista), e **habilitar não concede nada** — um
  vínculo sem ações concede mostrar o nome, abrir e baixar, e nada mais. Aprovar e reenviar são actos, e um
  acto escolhe-se.
- **O tipo é quem diz que é um arquivo**: `FileFieldType` declara `file` em `supports()`, e `FilePermissions`
  lê a capacidade em vez de uma lista de chaves. Um tipo de terceiros que declare `file` recebe as mesmas
  permissões sem este código o conhecer.
- **Só os tipos que guardam um arquivo declaram ações.** O validador recusa acções de arquivo num tipo que
  não guarda nenhum (`actions_not_supported`) e o inspetor deixou de as oferecer — a aba Vínculos mostra
  habilitar, secção, título e ordem a qualquer campo, e as acções só onde há um documento.
- **As superfícies que listam o documento obedecem à mesma matriz**: o nome do arquivo **é** metadados, por
  isso um destino que não pode mostrar o nome não lista o documento — nem no painel do pedido
  (`CustomerOrderFields`) nem no e-mail (`OrderEmailFields`).
- **A porta que serve bytes passou a decidir em dois passos.** Primeiro quem é (`DownloadPolicy`, inalterado);
  depois **de que superfície** o pedido vem. Um destino não habilitado ou um vínculo que não permite baixar
  recusa os bytes, e o destino **não se herda por ser nomeado**: as superfícies de equipa são para quem gere a
  loja, e as do cliente para o dono daquele pedido.
- **Nada vira público**: a resposta continua a ser um anexo, com `nosniff`, `Cache-Control: private, no-store`
  e sem caminho nenhum para o diretório privado.

**Um campo que a loja já não declara não fica ilegível.** Se o documento publicado não declara o campo, não há
vínculo a consultar e quem decide é a identidade: tirar um campo da configuração não pode tornar ilegíveis os
arquivos de pedidos antigos para quem é dono deles. É o que mantém a prova de identidade de WCCS-044 verde.

## 2. Achados desta tarefa

1. **O primeiro erro foi do harness, não do código.** O harness afirmava que um documento não podia conceder
   `resubmit` a uma superfície do cliente. A matriz diz o contrário — «permitir enviar nova versão: conforme
   política administrativa | configurável, por exemplo só após pedido de correção» — e só `approve` é de
   equipa. A asserção passou a provar a intersecção nas duas direcções: `approve` cai, `resubmit` sobrevive.
2. **A guarda que faltava era a que o próprio teste apontava.** `CustomerOrderFields::visible()` não filtrava
   pelo `show_metadata`; a linha que aquele painel imprime **é** o nome do arquivo, portanto não havia nada
   para imprimir e o documento continuava a aparecer. Corrigido com a mesma guarda que o e-mail já tinha.
3. **A publicação recusou um documento que o rascunho tinha aceitado** — e isso está certo. O harness publicava
   um campo `file` sem `maxFiles` nem `allowedExtensions`; a gravação do rascunho devolveu 200 e a publicação
   devolveu `missing_required_setting`. É a secção 13 do roadmap a funcionar: salvar rascunho não altera o
   checkout, publicar faz a validação integral. O fixture passou a declarar os limites que um campo de arquivo
   exige.
4. **O destino não podia ser a autoridade sobre si próprio.** O parâmetro `destination` é escrito pelo cliente;
   sem mais nada, um cliente que pode ler o arquivo nomeava a superfície de equipa e herdava o que ela permite
   — exactamente o risco que o gate da fase nomeia («um destino não herda permissões de outro»). Passou a haver
   uma verificação de papel, e o harness prova-a: o mesmo pedido, do mesmo dono, é servido na superfície do
   cliente e recusado na da equipa.
5. **Os fixtures de WCCS-071 e WCCS-072 declaravam acções de arquivo em campos de texto.** A regra nova
   (`actions_not_supported`) apontou-os; passaram a declarar um tipo que guarda um arquivo, o que também os
   torna mais honestos — acções de arquivo num campo de texto nunca foram uma configuração com sentido.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 414 testes, 1479 asserções (phpcs, phpstan, phpunit) |
| `tests/Integration/F14-wccs-074-file-permissions-proof.php` | **18 passaram, 0 falharam** |
| `tests/Unit/Domain/Fields/DefinitionValidatorTest.php` | um caso novo (acções num tipo sem arquivo são recusadas; no tipo de arquivo são aceites) e o caso da acção que o destino não pode executar passou a usar um campo de arquivo |
| `tests/js/views/FieldProperties.test.js` | 14 especificações, uma nova: um tipo sem arquivo não recebe a lista de acções |
| `tests/Integration/F08-wccs-044-order-binding-proof.php` | continua verde: a identidade não mudou, e um campo não declarado continua a ser decidido por ela |
| varredura de integração | 62 harnesses, 1420 asserções, zero falhas |

## 4. Limites

- **`order_received` e `customer_profile` ainda não têm superfície.** Estão modelados, e um campo vinculado a
  eles não aparece em lugar nenhum — o que é seguro, mas não é o que o destino promete. Provar cada área,
  com vínculo e sem ele, é WCCS-076; a ligação dessas duas superfícies pertence à mesma tarefa.
- **Aprovar e reenviar ainda não executam nada.** A matriz concede as acções e o inspetor permite escolhê-las;
  o fluxo que muda estado (e o que ele exige estar configurado) é WCCS-075.
- **O vínculo não é um controle de acesso à identidade.** Quem já pode ler o arquivo pela política de
  identidade continua a poder lê-lo; o que a matriz decide é qual superfície o entrega. A verificação de papel
  fecha a herança entre destinos, não substitui a política.
- **A ordem de exibição e o título por destino** estão no modelo e no inspetor desde WCCS-072; nenhuma
  superfície os usa ainda para ordenar — a auditoria por área de WCCS-076 é o sítio para o provar.
- **Trocar o tipo de um campo é uma migração**, e o inspetor di-lo («Trocar tipo ou normalização exige
  migração»). Uma migração que passe um campo de arquivo a texto tem de limpar as acções dos vínculos: o
  servidor recusa-as (`actions_not_supported`), tal como já recusa uma máscara num tipo que não a suporta.
  Reportar em vez de limpar em silêncio é a regra do projecto; a limpeza pertence a quem migra.
