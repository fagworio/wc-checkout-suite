# WCCS-076 · Provar a ausência de inserção automática

**Fase:** F14 · **Depende de:** WCCS-073, WCCS-074, WCCS-075
**Aceite:** "Em cada área, um campo sem vínculo não aparece; com vínculo, aparece na seção, título e ordem configurados."

## 1. O que foi entregue

Esta tarefa é a auditoria que o gate da fase pede, e a auditoria encontrou trabalho: as superfícies liam os
vínculos, mas **não obedeciam à seção, ao título nem à ordem** que o vínculo configura. O que existia era
«este campo aparece nesta área», e o que a secção 14 promete é «na seção, com o título e na ordem
configurados». Foi isso que a tarefa fechou.

- **`AreaProjection`** — uma leitura do documento por área, partilhada pelas três superfícies que desenham:
  agrupa as entradas pela **secção** que o vínculo nomeia, usa o **título** que ele dá, ordena pela
  **posição** que ele fixa, e ordena as secções pela posição delas no documento. Um vínculo que só liga o
  destino (sem secção, título ou ordem) continua a comportar-se como antes: a secção do próprio campo, o
  rótulo gravado com o valor, a posição do campo.
- **As três superfícies passaram a usá-la**: o painel do pedido (`admin_order`), a página do cliente
  (`customer_order` e `order_received`) e os dois e-mails (`customer_email`, `admin_email`). O painel e os
  e-mails ganharam um cabeçalho por secção, e as linhas passaram a mostrar o título do vínculo.
- **A página decide a área.** O mesmo hook cobre a página de agradecimento e o detalhe do pedido em Minha
  Conta, e a secção 14 nomeia-os como dois destinos. `CustomerOrderFields::area()` lê a página
  (`is_order_received_page()`) e a superfície passa a respeitar o destino certo: um campo vinculado só à
  página de agradecimento deixa de aparecer (ou de faltar) na conta, e vice-versa.
- **A auditoria por área**, no harness: cada área é desenhada como a loja a desenha e depois lida de volta —
  o campo sem vínculo não está em lado nenhum, o campo vinculado a *outra* área não vaza para esta, a área
  sem vínculos não desenha painel nenhum, e o campo vinculado aparece com a secção, o título e a ordem
  configurados.

## 2. Achados desta tarefa

1. **A promessa estava por cumprir em todas as superfícies.** Nenhuma das três lia `link.position` nem
   `link.title`, e nenhuma agrupava por secção: o painel do pedido imprimia as linhas na ordem do documento,
   com o rótulo do campo. A auditoria foi escrita primeiro como afirmação e falhou — que é a única forma de
   saber que a afirmação tinha conteúdo.
2. **A página de agradecimento era a mesma superfície que a conta.** WCCS-052 tratou-as como uma só porque o
   WooCommerce dispara o mesmo hook nas duas, e isso era verdade enquanto havia um destino. A partir de
   WCCS-071 são dois, e um campo vinculado a `order_received` não aparecia em lado nenhum enquanto um
   vinculado a `customer_order` aparecia também no agradecimento — inserção onde não foi vinculado, que é
   exatamente o risco que o gate da fase nomeia. O harness prova a distinção fingindo a página (as três
   globais que o `is_order_received_page()` do WooCommerce lê) e restaurando-as depois.
3. **`customer_profile` não tinha superfície, e essa lacuna foi tratada depois desta auditoria.** Quando
   esta tarefa correu, o destino existia no vocabulário desde WCCS-071 mas o *escopo de armazenamento*
   `customer` — «o valor é lembrado para o cliente e oferecido de novo no próximo pedido» — estava declarado
   e **nada o escrevia**: não havia valor de perfil para mostrar, e a auditoria provou a direção segura (um
   campo vinculado ao perfil não aparecia em lugar nenhum) em vez de tapar o buraco com uma caixa vazia. A
   superfície foi construída a seguir: uma seção oferecida na área do perfil passa a ser uma página de Minha
   Conta — endpoint, entrada no menu, formulário, validação pelo mesmo `ValueProcessor` e armazenamento no
   cliente — e o documento é recusado (`account_section_requires_customer_storage`) quando o campo vinculado
   a essa página não guarda no cliente. O harness desta auditoria passou a exercer a área em vez de afirmar a
   ausência, e a prova da página (com o vaivém de um valor submetido) está em
   `tests/Integration/ACCOUNT-my-account-sections-proof.php` e em
   `docs/validation/RECUPERACAO-POS-AUDITORIA-WCCS.md` §2.2.
4. **`public_api` já era uma projeção com portão.** O controlador de integração só publica o campo que
   declara `public_api` (WCCS-054), e a auditoria confirma-o do lado do modelo: um campo declara-o, o outro
   não. A API expõe o **valor**; secção, título e ordem são propriedades de um painel, e ali não há painel.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 427 testes, 1534 asserções (phpcs, phpstan, phpunit) |
| `tests/Integration/F14-wccs-076-areas-proof.php` | **19 passaram, 0 falharam**, uma nota (o perfil) |
| `tests/Unit/Domain/Orders/AreaProjectionTest.php` | seis casos novos (seção, título e ordem do vínculo; ordem dentro da secção; vínculo sem secção; só o que está vinculado àquela área; definição que já não existe; leitura pelo modelo) |
| varredura de integração | 64 harnesses, 1460 asserções, zero falhas |

## 4. Fecho da fase

O gate da F14 pede três coisas, e as três estão provadas por um harness cada:

1. **Só aparece o que foi vinculado** — `F14-wccs-076-areas-proof.php`, área por área.
2. **Um destino não herda permissões de outro** — `F14-wccs-074-file-permissions-proof.php`: o mesmo pedido é
   servido na superfície do cliente e recusado na da equipa.
3. **Nenhuma área recebe painel sem configuração explícita** — o mesmo harness de 076: sem vínculos, a página
   do cliente e os dois e-mails não imprimem nada, e o painel do pedido mostra o estado vazio em vez do campo.

**Limites que ficam registados:**

- **`customer_profile`** tem superfície e dados desde a passagem de recuperação (§2.3): a área era servida por
  páginas de Minha Conta que gravam no cliente. Esse destino foi depois dividido em `customer_account` (a
  página do cliente) e `admin_customer` (o painel no perfil do utilizador, para a equipa), com a migração
  assistida descrita em `docs/validation/DESTINOS-MINHA-CONTA-E-PERFIL-ADMIN.md`. O que ainda não existe ali é
  upload (o ciclo de vida do ficheiro privado da conta) e o reuso de uma página de conta que já exista.
- **As ações por destino** (aprovar, pedir correção, reenviar) estão modeladas, validadas e aplicadas como
  *permissões* (WCCS-074); o acto que as executa não pertence a nenhuma tarefa do roadmap e não é prometido em
  superfície nenhuma (WCCS-075, §4).
- **A posição das seções** é a do documento e não por área: o roadmap não pede uma ordem por área, e esta
  tarefa não a inventou. O que a área decide é a ordem *dentro* da seção, que é a que o vínculo configura.
- **A auditoria é feita desenhando as superfícies**, não abrindo um browser: o percurso visual é a WCCS-063 e a
  matriz funcional é a WCCS-062, ambas registadas na F12.
