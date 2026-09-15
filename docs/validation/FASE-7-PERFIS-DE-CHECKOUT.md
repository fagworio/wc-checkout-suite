# Fase 7 — Perfis de checkout: modelo, repositório, validação e runtime

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §3.4, §6.3, §6.9, §6.11, §1844, §27 · **Fase 7**
**Gate:** «dois perfis alternam correctamente pelo carrinho.»

## 1. O que a fase encontrou

O modelo final tinha um único documento e um único checkout. A §3.4 pede **perfis**: uma composição
completa de checkout (`id`, `name`, `enabled`, `source`, `priority`, `fallback`, `conditions`,
`sections`, `presentation`), a §6.9 diz como se decide qual deles corre (prioridade entre os que
casam, e o checkout da própria loja como *fallback*) e a §1844 diz **quando**: antes de a schema
efectiva ser montada.

Nada disso existia. O documento não tinha sequer onde guardar perfis: `SchemaDocument` era
`revision, schema_version, updated_at, updated_by, fields, sections, settings, migration_history` e
os métodos de cópia (`bumped`, `with_fields`, `with_sections`, `with_settings`,
`with_migration_history`) reconstruíam-no posicionalmente — acrescentar um campo sem os tocar
perderia a lista em qualquer gravação seguinte.

## 2. O que foi mudado

### 2.1 O documento transporta os perfis

- **`SchemaDocument`**: nova propriedade `profiles` (9.º parâmetro, opcional), `profiles()`,
  `with_profiles()`, e `profiles` em `from_array()`, `to_array()` e em **todos** os métodos de cópia.
  O `hash()` passa a incluir os perfis, porque um perfil é conteúdo: duas revisões que só diferem
  neles não são a mesma revisão.
- **`SchemaRepository::publish()`** leva os perfis do *draft* para o documento publicado, ao lado dos
  campos e das secções.
- **`Http\Admin\SchemaController::update_draft()`** só substitui a lista quando a chave vem no corpo
  do pedido. Um ecrã que não fala de perfis não pode apagá-los: «ausente» é «não sei», não «apaga».
- **`SchemaTransfer`**: exportar e importar leva os perfis, com o limite `MAX_PROFILES = 20` e a
  mesma recusa `too_many_things`. Uma loja que exporta a configuração e a importa noutra não perde os
  checkouts que escreveu.

### 2.2 O modelo e o resolvedor

- **`Domain\Checkout\CheckoutProfile`** (§3.4): id, nome, ligado, `source` numa lista fechada
  (`woocommerce_current`, `duplicate_profile`, `minimal`), prioridade, `fallback`, condições,
  contentores e apresentação. `source` diz **de onde veio**, não o que é agora.
- **`Domain\Checkout\CheckoutProfileResolver`**:
  - `candidates()` — só perfis **ligados**, com a regra avaliada pelo **motor partilhado**
    (`TreeConditionEvaluator`), ordenados por prioridade e, em empate, pela ordem em que o
    comerciante os declarou. Sem segundo dialecto de regras (§14).
  - `resolve()` — o primeiro candidato; sem candidatos, o `fallback`; sem fallback, `null`, que
    significa **o checkout da própria loja**. É isso que «o checkout padrão existe sempre» quer dizer.
  - `compose()` — troca **os contentores oferecidos no checkout** pelos do perfil e mantém os
    restantes. Um perfil é uma composição *do checkout* (§3.4): se substituísse a lista inteira,
    todos os carrinhos que ele servisse perdiam o painel do pedido e os blocos de e-mail sem que
    ninguém o tivesse pedido. Os **campos não se movem** — um vínculo nomeia um contentor, e é isso
    que permite partilhar uma biblioteca de campos por vários checkouts.
  - `overlaps()` — os pares que o mesmo carrinho poderia seleccionar. A §6.9 pede que a
    sobreposição seja **mostrada**, não recusada: a prioridade resolve-a, e é o comerciante que
    decide se é intencional.

### 2.3 A validação

- **`Domain\Checkout\ProfileValidator`** com códigos estáveis: `invalid_profile_entry`,
  `profile_id_required`, `duplicate_profile_id`, `profile_name_required`, `profile_source_unknown`,
  `profile_priority_not_an_integer`, `multiple_fallback_profiles`,
  `profile_section_not_a_checkout_container`. Os erros das regras e dos contentores são os
  **partilhados** — `ConditionValidator` e `SectionValidator` — com o contexto re-apontado de
  `field` para `profile`.
- **`ConditionValidator::validate_tree()`** (novo): valida uma árvore de regras **sem o envelope**
  `{ visible: … }` que um campo usa. O envelope nomeia o que a regra decide; um perfil não decide
  visibilidade, decide qual composição corre. É a mesma árvore, o mesmo `walk()` e os mesmos
  vocabulários.
- **Aplicada em dois momentos**, como as regras de secção: no *write* do draft
  (`SchemaRepository::check_profiles()`, ao lado de `check_sections()`/`check_destinations()`) e na
  publicação (`validate()`). Um perfil com dois fallbacks não é trabalho a meio — é configuração que
  o comerciante acredita estar a decidir; recusá-lo só na publicação seria dar a razão três ecrãs
  depois.

### 2.4 O runtime

- **`Checkout\Classic\PublishedDocument::for_cart( $adapter )`** — o leitor de carrinho: resolve o
  perfil contra o contexto confiável que o servidor já constrói (`CheckoutConditionContext`, §11) e
  devolve a composição. `read()` continua a ser o leitor da loja inteira, para tudo o que **não**
  serve um carrinho: o painel do pedido, os e-mails, os ecrãs de administração.
- **`ClassicCheckout::filter_fields()`**, **`BlocksRenderer::fields()`** e
  **`StoreApiExtension::fields()`** passaram a ler `for_cart()`. O registo do checkout Blocks
  (`BlocksCheckout::apply()`) continua a ler a loja inteira, porque um campo só se regista **uma vez**
  na API de campos adicionais: o que o perfil decide é a composição — que contentores existem e onde
  cada campo cai —, não a existência do campo.
- **`CheckoutConditionContext::context()`** aceita o checkout que pergunta (`classic`/`blocks`), para
  que a mesma regra possa ser respondida para o checkout certo.

### 2.5 O editor

- **`schema/profiles.ts`** — as operações, puras, sobre a lista de perfis: criar de uma das três
  origens, duplicar, renomear sem mudar o id, marcar o fallback (que **desmarca o anterior**),
  excluir (recusando **o único fallback**, que é o que responde aos carrinhos que nenhuma regra
  cobre), reordenar por prioridade, e `overlapsFor()` — os pares que o mesmo carrinho poderia
  seleccionar, resolvidos pelo **preview** do mesmo motor de regras com os valores que o comerciante
  escreveu.
- **`components/CheckoutProfilesPanel.js`** — a faixa §6.3: `[ Checkout padrão ] [ … ] [ + Novo
  checkout ]` com `Condições de exibição` à direita, o modal de criação (nome + as três origens), e o
  que o checkout activo diz de si: a regra, a prioridade, se é o fallback, a sobreposição e a
  verificação do checkout mínimo. O `Checkout padrão` é apresentado pelo que é — a composição da
  própria loja, que existe sempre — e não como um perfil entre outros.
- **`ConditionBuilder`** ganhou `envelope`: um campo guarda `{ visible: <regra> }`, um perfil guarda
  a **árvore** (§3.4). O envelope nomeia o que a regra decide; a árvore, os operadores e as fontes
  são os mesmos, que é o que §14 quer dizer com um só dialecto de regras.
- **`Domain\Checkout\CheckoutFacts`** e `checkoutFacts` no catálogo (`CatalogController`): o que a
  loja responde para o checklist do mínimo — meio de pagamento disponível, impostos, entrega e
  regras legais — lido do WooCommerce e nunca adivinhado pelo browser. A quinta pergunta, «os dados
  exigidos pelas integrações», é sobre a composição e é respondida a partir dela.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE7-checkout-profile-proof.php` (novo) | **24/0**, 3 notas, com **carrinho real**: um carrinho com um produto da categoria restrita recebe a composição do perfil `restrito`, trocando o produto pelo digital passa para a do perfil `digital`, um carrinho vazio cai no `padrao` (o fallback) e voltar ao produto restrito volta à primeira — observado no **sítio onde o adaptador clássico coloca o campo** (`order`, `account`, `shipping`) e não no valor que o resolvedor devolve. O mesmo harness prova: o documento aceita, publica, lê e exporta os três perfis; dois fallbacks e um contentor que não é de checkout são recusados **no write do draft**, e o draft fica onde estava; um perfil substitui os contentores do checkout e **mantém** os das outras áreas; os campos são os mesmos em qualquer composição; sem perfis, a loja responde o que respondia |
| `tests/Unit/Domain/Checkout/CheckoutProfileResolverTest.php` (novo) | 10 testes, 18 asserções: prioridade, empate pela ordem de declaração, perfil desligado que não responde a nada (nem como fallback), fallback só quando nada casa, um perfil que casa vale mais que o fallback, composição que mantém o que não é do checkout, e sobreposição reportada |
| `tests/Unit/Domain/Checkout/ProfileValidatorTest.php` (novo) | 15 testes, 17 asserções: id/nome/source fechado, id único, prioridade fraccionada recusada e `2.0` aceite, um só fallback, contentor fora do checkout recusado, regra noutro dialecto recusada com o código do validador partilhado, e o erro a nomear o perfil |
| `tests/js/schema/profiles.test.js` (novo) | 28 testes: identidade derivada do nome, as três origens, o fallback exclusivo, a recusa de excluir o único fallback, a prioridade e o desempate, a sobreposição com os valores do comerciante, e o checklist do mínimo — incluindo o campo obrigatório que a composição deixou para trás, nomeado |
| `tests/js/components/CheckoutProfilesPanel.test.js` (novo) | 13 testes: a faixa e o `Checkout padrão` apresentado como a composição da loja, a regra do checkout activo, as duas ordens, a recusa do único fallback, o aviso de sobreposição, o modal (nome + origem, e sem nome não cria), e o checklist do mínimo nos três estados |
| `tests/browser/fase7-checkout-profiles.mjs` (novo) | **20/0** num browser real, contra o servidor: a faixa é desenhada e o `Checkout padrão` é o primeiro separador; o modal pede o nome e as três origens; criar faz um separador e selecciona-o; a regra, a prioridade e as duas ordens são mostradas; marcar o fallback marca-o e a aba di-lo; o checklist aparece para o mínimo; **salvar grava os dois perfis** (`checkout_digital`, `checkout_minimo`, um só fallback, `source=minimal` num deles) **e o ecrã deixa a loja como a encontrou** |
| `composer check` | phpcs e phpstan sem erros; **552 testes, 1928 asserções** |
| Varredura de integração | **72 harnesses, 1638 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` | limpos (**731 testes**, 47 suites); `npm run build` compila |

## 4. Limites que ficam registados

- **A apresentação do perfil não entra na composição.** O documento composto leva as secções do
  perfil; `presentation` continua com quem o resolveu. Guardá-la dentro do documento seria um
  segundo sítio onde os mesmos bytes vivem, e um documento é a schema — não é o ecrã.
- **Um contentor com o mesmo id em dois perfis é a forma normal de partilhar campos**, não um erro:
  é assim que o mesmo campo aparece em checkouts diferentes, cada um a decidir onde o contentor cai.
  Ids repetidos **dentro** de um perfil continuam a ser recusados (`duplicate_section_id`).
- **O editor de perfis existe e não é uma segunda fonte.** Tudo o que ele faz passa pelo mesmo
  rascunho, pelo mesmo histórico de desfazer e pelo mesmo «Salvar alterações» que uma edição de
  campo: guardar grava o rascunho e publica, e o servidor valida os perfis no *write* e na
  publicação. Não há um segundo ecrã de checkouts com o seu próprio botão de guardar.
- **O editor compõe a regra, a prioridade e o fallback; a lista de secções continua a ser a do
  documento, e o ecrã di-lo.** Criar um checkout copia os contentores do checkout da loja (§6.3:
  «começa a partir dos campos WooCommerce atuais»), e essa cópia é a composição que o carrinho
  recebe. Editar os contentores **dentro** de cada checkout — o painel esquerdo a mudar de dono
  quando muda o separador — é a composição por checkout da §6.4, e fica para a fase que trata do
  checkout customizado. Um aviso na faixa diz qual das duas listas está à esquerda, porque um ecrã
  que deixasse o comerciante pensar que estava a editar o perfil seria pior do que um ecrã
  incompleto.
- **`checkout → perfil` continua recusado por nome.** O modelo de sincronização existe e a direcção
  ainda não está implementada; o editor não a oferece, e o validador responde
  `sync_direction_not_available` em vez de aceitar e ignorar.
- **Um perfil não acrescenta um campo ao checkout Blocks.** A API de campos adicionais regista cada
  campo uma vez, de forma global. O que um perfil decide é a composição — contentores e onde o campo
  cai —, e é isso que o pedido do Store API e o payload do renderizador passaram a ler por carrinho.
- **O checklist do mínimo lê o que o WooCommerce responde neste pedido.** Nesta loja de
  desenvolvimento `gateway`, `taxes` e `legal` são falsos porque a loja não tem meio de pagamento
  activo, não calcula impostos e não tem página de termos — o aviso está certo. Uma loja com a
  configuração feita vê o aviso desaparecer, e é a mesma pergunta que o ecrã de Pagamentos já faz.
