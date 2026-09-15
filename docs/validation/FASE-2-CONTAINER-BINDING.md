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

**Os usos passaram a ser validados um a um** (§3.3, §23) — o mapa por destino tem uma entrada por
destino, portanto um segundo uso no mesmo destino era o sítio onde a configuração não podia estar
errada:

- `DefinitionValidator::validate_bindings()` valida a lista quando o documento a guarda: cada uso
  tem de pertencer ao campo onde está listado (`binding_field_mismatch`), ter um identificador único
  (`duplicate_binding_id`), declarar só ações que o seu destino desempenha
  (`invalid_destination_action`), nenhuma ação de ficheiro num tipo que não guarda ficheiro
  (`actions_not_supported`) e uma ordem não negativa (`invalid_binding_position`); uma entrada que não
  é um mapa é recusada (`invalid_binding`) em vez de desaparecer na leitura. O mapa continua a
  responder pelo que ele decide — chave desconhecida, chave ambígua, a entrada bem formada — e deixa
  de ser interrogado sobre ações, que só o uso sabe.
- `SectionValidator::placements()` lê a mesma autoridade para as duas perguntas que precisam do
  documento inteiro: o container que a área oferece (`destination_section_not_offered`) e o storage
  que a superfície de cliente exige (`account_section_requires_customer_storage`). Um uso que não é
  visível não oferece formulário nenhum, portanto não promete nada.
- `DocumentMigrator::field_with_bindings()` passou a respeitar a lista em vez de a reconstruir do
  mapa — reconstruí-la apagava o segundo uso, que é a razão de a lista existir. As duas vistas
  reconciliam-se por uma regra explícita: um destino com **um** uso cabe no mapa, e aí o mapa é a
  vista mais recente (é o que o editor escreve hoje) e o uso segue-o; um destino com **mais do que
  um** uso só a lista o pode dizer, e aí a lista decide e o mapa é a sua projeção.
- `FieldDefinition::raw_bindings()` expõe a lista como foi guardada, e `bindings_from()` deixou de
  filtrar entradas que não são mapas: ler pode ignorar o que não compreende, validar não pode.

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
| `DefinitionValidatorTest` (7 testes novos) | um uso responde pelas suas ações; sem ações de ficheiro num tipo sem ficheiro; dois usos não partilham identificador; um uso pertence ao campo onde está listado; um uso que não é mapa é recusado; ordem negativa recusada; um mapa guardado continua a ser validado ligação a ligação |
| `SectionValidatorTest` (3 testes novos) | um uso num container que a área não oferece é recusado; o segundo uso da área é perguntado por si; cada uso visível numa superfície de cliente é perguntado sobre o storage (e um uso invisível não) |
| `DocumentMigratorTest` (2 testes novos) | a lista não é reconstruída do mapa (dois usos sobrevivem, a projeção é a do último); um mapa editado chega ao uso que descreve, e não decide onde há dois usos |
| `tests/Integration/FASE2-binding-validation-proof.php` (novo) | **8/0** pela rota real: dois usos do mesmo campo no mesmo destino são aceites e voltam os dois; um uso com `approve` no destino do cliente é recusado (`invalid_destination_action`); um uso num container da equipa dentro do destino do cliente é recusado (`destination_section_not_offered`); a recusa não substitui o documento guardado |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (647 testes) |
| `composer check` | phpcs e phpstan sem erros; **500 testes, 1818 asserções** |
| Varredura de integração | **68 harnesses, 1550 asserções, 0 falhas** |
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
- **O validador conhece a lista, mas o editor ainda escreve o mapa.** `DefinitionValidator` e
  `SectionValidator` já validam `bindings[]` uso a uso (e é isso que a rota real prova), mas o ecrã de
  configuração continua a gravar `destinations` e ainda não oferece um segundo uso do mesmo campo no
  mesmo destino. A regra de reconciliação do migrador existe precisamente para os dois conviverem: um
  destino com um uso segue o mapa (a edição do editor), um destino com dois usos segue a lista. É o
  item (a) do passo seguinte, a par do (b) `target`.
- **`target` livre por destino** ainda não é validado por lista fechada: hoje vale para o checkout
  (onde é a localização lógica) e não é usado pelas outras superfícies.
- **Duas superfícies ainda são indexadas por campo, não por uso.** `CustomerOrderFields::visible()`
  e `OrderEmailFields::visible()` devolvem um mapa `id do campo → definição`, porque o que desenham a
  seguir é a lista de valores do pedido (um por campo). Um campo usado duas vezes no mesmo destino
  aparece portanto **uma vez** nessa lista, e quem decide se aparece é a **união** dos usos da área
  (`FilePermissions::allows()`): é a resposta correta para "esta área pode mostrar este valor?", mas
  não é a resposta por uso que o `AreaProjection` já dá. Migrar estas duas listas para bindings faz
  parte do passo do editor.
