# Fase 14 — Checkout customizado

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §6.1–§6.5, §6.11, §16, §27 · **Fase 14**
**Gate:** «funcionalidade equivalente ao checkout padrão nos gateways homologados.»

## 1. O que a fase encontrou

A apresentação customizada já existia: `CheckoutSettings` é o opt-in (desligado por omissão) que
decide se o plugin apresenta ou se afasta, `Presentation` entrega a folha de estilo com os tokens
declarados como dependência, e as fases anteriores deixaram provado que o plugin **não desenha o
formulário** — filtra-se para dentro do array de campos da própria WooCommerce, não regista gateway
nenhum e não substitui a engine transacional (§6.1, ponto 8). O que a Fase 7 tinha deixado nomeado e
por fazer era a outra metade do §6.4:

> Editar os contentores **dentro** de cada checkout — o painel esquerdo a mudar de dono quando muda o
> separador — é a composição por checkout da §6.4, e fica para a fase que trata do checkout
> customizado.

O modelo e o runtime já respondiam: `CheckoutProfile` carrega as suas `sections` e
`CheckoutProfileResolver::compose()` serve ao carrinho a composição do perfil escolhido, deixando os
contentores das outras áreas com o documento. O que faltava era o **editor**: criar, renomear, mover e
remover secções dentro de um checkout alternativo sem tocar no checkout da própria loja.

## 2. O que foi mudado

### 2.1 O editor passa a ter dono (§6.4)

`schema/profiles.ts` ganhou três funções puras, e a regra é uma só, escrita uma só vez:

- **`isCheckoutSection()`** — o predicado que decide se um contentor é do checkout. Era privado do
  `checkoutSections()` e passou a ser exportado, porque duas perguntas precisam da mesma resposta: o
  checklist do mínimo e a composição. Um contentor sem `areas` é do checkout, que é como
  `sectionGroups()` já o lia e como todo o contentor que este ecrã escreve vem.
- **`compositionOf()`** — o documento como o editor o mostra enquanto um checkout está selecionado:
  as secções do perfil, seguidas das do documento que não são do checkout. É o espelho de
  `CheckoutProfileResolver::compose()`, e é deliberado que seja: o ecrã tem de mostrar a composição
  que o carrinho vai receber.
- **`withComposition()`** — escreve o documento editado de volta ao seu dono: as secções do checkout
  vão para o perfil, e mais nada se move. Sem perfil, o documento editado **é** o rascunho — que é o
  que o §6.2 sempre descreveu.

`FieldsScreen` passa a derivar `composition` de `activeProfile`, e as sete operações de secção
(criar, renomear, mudar local, mover, remover, remover com dependências, e a apresentação de conta de
uma secção partilhada) passam por um único funil, `applyComposed()`, que comita o resultado através de
`withComposition()`. A lista à esquerda, o inspector da secção e o painel do checkout da loja leem
todos a mesma composição; o `CheckoutProfilesPanel` deixou de dizer que a lista é a da loja e passa a
dizer de quem é.

**Uma coisa que a implementação apanhou.** A criação de secção comitava com `apply()` em vez de
`applyComposed()`: com um perfil selecionado, o documento composto (que tem as secções do perfil **e**
as outras) era gravado como o documento inteiro, substituindo as secções do checkout da própria loja.
É o defeito exato que o §6.4 existe para evitar, e ficou corrigido — a prova de browser falha se ele
voltar.

### 2.2 A lista de estratégias executáveis continua escrita por extenso

A Fase 13 deixou `executable_strategies()` escrito à mão em vez de derivado dos vocabulários, e esta
fase não mudou isso. A apresentação customizada segue a mesma regra ao contrário: não oferece nem
retira gateways, e a prova verifica que nenhum callback deste plugin entra em
`woocommerce_available_payment_gateways`.

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE14-custom-checkout-proof.php` (novo) | **18/0**, 2 notas: o plugin não regista gateway nenhum e nenhum gateway registado é dele; ligar a apresentação não muda a lista de gateways que o checkout oferece (é a resposta da própria WooCommerce à mesma pergunta), não muda os campos que a WooCommerce recebe, não muda o **total do carrinho**, e não filtra a lista de gateways — a apresentação muda a apresentação e mais nada; um checkout com a sua própria lista de secções é aceite, o carrinho recebe a composição do checkout que o escolheu (`contato, conta_extra`) enquanto o checkout da própria loja mantém a dele (`contato, documentacao, conta_extra`), e os campos são os mesmos nos dois; e um checkout que reivindique um contentor que não é do checkout é recusado por nome (`profile_section_not_a_checkout_container`) |
| `tests/js/schema/profiles.test.js` | 5 testes novos (33 no total): o predicado do checkout, a composição sem perfil a ser o próprio documento, o perfil a possuir as secções do checkout, a escrita de volta a tocar **só** no perfil escolhido, e sem perfil a edição a ser o rascunho |
| `tests/browser/fase14-custom-checkout.mjs` (novo) | **11/0**: o ecrã abre no checkout da própria loja com a lista dele; criar um checkout não escreve nada (o ecrã salva numa só ação) e o separador aparece com o aviso de que a lista à esquerda é a composição **daquele** checkout; uma secção criada com ele selecionado fica guardada **nele** e não no documento, e o ecrã lista-a; voltando ao checkout da loja, a secção do outro não está lá; e remover o checkout criado devolve o documento ao que era |
| `composer check` | phpcs e phpstan sem erros; **619 testes, 2148 asserções** (o harness novo não é analisado, como todos os de integração) |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**818 testes**, 51 suites); build compila (a prova de browser exige `npm run build:admin`, porque é o bundle servido que ela lê) |
| Varredura de integração | **79 harnesses, 1844 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **A cláusula «nos gateways homologados» continua sem poder ser fechada nesta loja.** Um pedido real
  num cenário homologado exige um gateway ativo e credenciais de sandbox, e esta loja não tem nenhum
  dos dois — é o mesmo bloqueio `SANDBOX-PAYMENT` já registado nas Fases 11 e 12 e nos registos
  WCCS-047/048/050. O que fica provado é a **equivalência estrutural**: os mesmos campos, a mesma
  lista de gateways (a da própria WooCommerce, sem filtro deste plugin), a lista de gateways
  registados inalterada, gateway nenhum deste plugin, e o total do carrinho a não se mover. Uma
  compra com cartão não está provada, e não é fingida.
- **O editor compõe contentores, não campos.** Um campo continua a ser do documento e a aparecer onde
  o seu contentor estiver: um checkout que não declare o contentor de um campo *implicitamente* deixa
  de o mostrar porque a composição não o oferece, mas o campo não é apagado nem copiado. É a regra da
  §3.3 («um campo, vários usos») e a razão pela qual remover uma secção de um perfil não remove
  campos.
- **`CheckoutProfileResolver::compose()` considera um contentor sem `areas` como não sendo do
  checkout**, e o predicado do editor considera-o do checkout. A diferença não é atingível por
  contentores escritos por este ecrã (escreve sempre `areas`) e fica registada em vez de resolvida
  nesta fase, porque mexer no servidor mudaria o comportamento já publicado da Fase 7.
- **A prévia do §6.10 continua a do protótipo.** A prévia contextual com perfil, cenário, produto e
  larguras é da fase de usabilidade; o que existe é a prévia administrativa atual e o botão «Abrir
  checkout real».
