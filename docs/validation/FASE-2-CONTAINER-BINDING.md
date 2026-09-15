# Fase 2 — Container e Binding (modelo e migração)

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §3, §18, §23 · **Fase 2**
**Pergunta:** o documento passou a ter um modelo final — container com um destino, binding por
uso do campo — e os documentos guardados por todas as versões anteriores foram lidos nele sem
perder nada?

## 1. O que foi implementado

**`ContainerDefinition`** (§3.2) — o *container* deixou de ser uma "seção do checkout" e passou a
ter: `id`, `name`, `destination` (**um** destino), `presentation`, `position`, `enabled`,
`show_title`, `display_title`, `description`, `icon`, `target`, `settings`.

- `SectionDefinition` continua a existir como **o mesmo container** sob o nome histórico
  (`extends ContainerDefinition`): os 22 leitores que ainda o pedem recebem o objeto novo, e o
  código novo pede `ContainerDefinition`.
- `title`, `areas` e `location` continuam a ser **escritos como projeção derivada** de `name`,
  `destination`/`destinations()` e `target()`. São derivados no momento de escrever, nunca estado
  independente: é isso que impede as duas formas de discordarem enquanto os leitores migram.
- Um container oferecido em vários destinos (o modelo antigo) é lido com o primeiro como destino e
  a lista completa em `destinations()`; `for_destination()` produz a variante de um destino, com o
  identificador derivado (`<id>__<destino>`) para as que não são a primeira.

**`FieldBinding`** (§3.3) — cada *uso* de um campo passou a ser um objeto próprio: `id`,
`field_id`, `container_id`, `destination`, `position`, `visible`, `editable`,
`required_override`, `label_override`, `description_override`, `permissions`, `conditions`.

- `FieldDefinition::bindings()` lê o modelo final quando o documento o tem e **deriva** de
  `destinations` quando não tem — um vínculo habilitado por destino, com `mode: view` a significar
  visível e não editável, e as `actions` do vínculo a serem as permissões do uso.
- `bindings_for()` responde por destino e é o que `shows_in()` passou a usar: **o mesmo campo pode
  ser usado duas vezes no mesmo destino**, que é a razão de existir o conceito.
- O identificador de um uso é derivado (`<campo>@<destino>[/<container>]`), para a migração ser
  idempotente.

**`DocumentMigrator`** (§23) — a camada que lê um documento guardado na forma final, aplicada em
`SchemaRepository::decode()`, portanto em **todas** as leituras e sem reescrever o que está no
banco até a próxima gravação:

| Conversão | Porque é segura |
|---|---|
| `admin_customer` → `admin_customer_profile` | a chave foi escrita por uma versão interina, nunca publicada, e nomeava exatamente o painel da equipa no perfil do cliente |
| container em N destinos → **um por destino** | o container pertence a um destino no modelo final; duplicá-lo é o que "o mesmo grupo aparece nos dois sítios" sempre significou, e o primeiro mantém o id |
| `destinations[destino] = link` → `bindings[]` | um vínculo habilitado é um uso; o container de cada binding é apontado para a **variante do seu próprio destino** |
| `title` → `name`, `location` → `target`, `presentation.show_title` → `show_title`, `presentation.account.*` → `display_title`/`icon` | as chaves canônicas passam a ser as guardadas |

O que ele **não** converte: `customer_profile` (e `my_account`) continuam a ser ambíguos, chegam ao
validador e o editor pergunta (§23.1) — a migração não escolhe por ninguém. Ids de campo, ids de
container, valores, referências de upload e snapshots de pedido não são tocados.

**As projeções passaram a ler bindings** (§13, §14) — o mapa por destino é derivado, e o que uma
superfície desenha é **um uso**, não um campo:

- `AreaProjection::group()` percorre `bindings_for( $destination )` em vez do mapa: o mesmo campo
  usado duas vezes no mesmo destino aparece **duas vezes**, cada um no seu container, com o seu
  título, a sua posição e o seu bindings no resultado. Um uso invisível é saltado; a posição cai
  para a do campo quando o uso não configurou nenhuma; empates são desfeitos pelo id do uso, para a
  projeção não mudar de ordem entre leituras.
- `CustomerSectionFields::entries()` devolve **uma linha por uso** (com `binding`), `writable()`
  responde "algum uso aceita?" e `entry_writable()` responde pelo uso que está a ser desenhado. Numa
  submissão, um campo usado duas vezes é validado e escrito **uma vez** (`$seen`), pela editabilidade
  do uso escolhido — a mesma definição, o mesmo valor, uma escrita.
- `FilePermissions` distingue a **área** do **uso**: `actions()` é a união das permissões dos usos
  visíveis daquele destino ("esta área pode fazer isto com este valor?"), `actions_for_binding()` é a
  resposta do uso que está a ser desenhado. Ambas intersectam com o que o destino pode desempenhar,
  para um documento escrito à mão não dar a um ecrã de cliente o direito de aprovar. Um uso que não
  declara ações recebe as omissões (`show_metadata`, `view`, `download`); um uso invisível não
  permite nada.
- A projeção **não lista um ficheiro cujo uso retirou `show_metadata`**: o valor de um ficheiro é o
  ficheiro, e o uso que não quer o nome nem os detalhes está a pedir para não ser listado. É a razão
  de o mesmo documento poder mostrar o documento no ecrã do pedido e omiti-lo no e-mail do cliente.

## 2. Prova

| Prova | Resultado |
|---|---|
| `tests/Unit/Domain/Sections/ContainerDefinitionTest.php` (novo) | 7 testes: chaves finais, leitura de uma seção guardada, `show_title` por omissão, "oferecida em lugar nenhum", divisão sem perder nada, as duas formas no array, e o nome histórico |
| `tests/Unit/Domain/Fields/FieldBindingTest.php` (novo) | 7 testes: link→binding (modo e permissões), id determinístico, defaults, projeção de volta a link, título com fallback, chaves canônicas |
| `tests/Unit/Domain/Schema/DocumentMigratorTest.php` (novo) | 6 testes: divisão do container, bindings apontados à variante certa, **idempotência**, documento canônico intocado, chave ambígua preservada, e nada além de configuração alterado |
| `tests/Unit/Domain/Customers/CustomerSectionFieldsTest.php` (novo) | 6 testes: um campo usado duas vezes é desenhado duas vezes, só os usos do container, posição com fallback, link guardado lido como um uso, submissão escreve uma vez, uso só-de-leitura não aceita nada |
| `tests/Unit/Domain/Uploads/FilePermissionsTest.php` (novo) | 7 testes: link guardado, união dos usos da área, cada uso responde por si dentro do que o destino desempenha, omissões de um uso vazio, uso invisível não permite nada, tipo sem ficheiro não tem permissões, um binding responde sozinho |
| `AreaProjectionTest` (4 testes novos) | um campo usado duas vezes aparece duas vezes; dois usos no mesmo container mantêm ordem estável; uso invisível é saltado; uso de ficheiro sem `show_metadata` não é listado |
| `FieldDefinitionTest` (3 testes novos) | mapa guardado → bindings; dois bindings no mesmo destino; escrita consistente das duas formas |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (647 testes) |
| `composer check` | phpcs e phpstan sem erros; **488 testes, 1788 asserções** |
| Varredura de integração | 67 harnesses, 1542 asserções, **0 falhas** |
| `tests/browser/f14-links-observation.mjs` | **22/0** com o documento migrado (o editor lê a seção da variante do destino, que é o comportamento novo) |
| `tests/browser/my-account-sections-flow.mjs` | **6/6** com o documento migrado: o campo aparece sem pedido, o cliente grava o próprio valor, o valor persiste no acesso seguinte, e o campo de Minha conta não aparece no checkout |

`tests/Integration/F14-wccs-073-section-areas-proof.php` passou a provar a **divisão**: uma seção
oferecida em duas áreas é lida como dois containers, com o mesmo nome, e cada vínculo apontado ao
container da sua área (13/0).

## 3. Limites que ficam registados

- **A escrita ainda não é canônica em todos os caminhos.** Um documento guardado na forma antiga
  mantém a forma antiga até a gravação seguinte (a migração corre na leitura), e o editor ainda
  escreve `destinations` por dentro. `to_array()` escreve `bindings` só quando o documento é
  canônico, para não haver duas verdades: a lista e o mapa não podem discordar. A migração dos
  ecrãs para bindings (editor e formulários) é o passo seguinte da fase.
- **`presentation.account` continua guardado dentro de `presentation`** enquanto o renderer de
  Minha Conta o lê; as chaves canônicas `display_title`/`icon` já são preenchidas a partir dele.
- **O validador ainda não conhece `bindings[]`.** `DefinitionValidator` valida o mapa `destinations`
  (que continua a ser a projeção derivada), e um documento canônico passa pela validação do mapa —
  onde um segundo uso no mesmo destino não cabe, porque o mapa tem uma entrada por destino. Escrever
  pela rota um documento com dois usos no mesmo destino só é honesto depois de o validador validar a
  lista; até lá, o comportamento **por uso** está provado por testes de unidade
  (`AreaProjectionTest`, `CustomerSectionFieldsTest`, `FilePermissionsTest`) sobre o modelo final, e a
  passagem de browser prova o caminho derivado na loja (22/0 e 6/6). É o item (c) do passo seguinte,
  a par do (a) editor e do (b) `target`.
- **`target` livre por destino** ainda não é validado por lista fechada: hoje vale para o checkout
  (onde é a localização lógica) e não é usado pelas outras superfícies.
- **Duas superfícies ainda são indexadas por campo, não por uso.** `CustomerOrderFields::visible()`
  e `OrderEmailFields::visible()` devolvem um mapa `id do campo → definição`, porque o que desenham a
  seguir é a lista de valores do pedido (um por campo). Um campo usado duas vezes no mesmo destino
  aparece portanto **uma vez** nessa lista, e quem decide se aparece é a **união** dos usos da área
  (`FilePermissions::allows()`): é a resposta correta para "esta área pode mostrar este valor?", mas
  não é a resposta por uso que o `AreaProjection` já dá. Migrar estas duas listas para bindings faz
  parte do passo do editor.
