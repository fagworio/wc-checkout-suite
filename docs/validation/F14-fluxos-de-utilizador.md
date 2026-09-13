# F14 · Fluxos de utilizador — a passagem pelo browser e pelo checkout

**Fase:** F14 · **Tarefas:** WCCS-071 a WCCS-076
**Pergunta:** as modificações fazem, na loja, o que os registros de tarefa dizem que fazem?

## 1. Como foi corrido

Os harnesses das tarefas desenham as superfícies a partir das classes. Esta passagem fez o
contrário: usou a loja como um lojista e como um cliente, num browser a sério, e leu o
resultado. Quatro instrumentos, todos reproduzíveis:

| Instrumento | O que faz | Resultado |
|---|---|---|
| `tests/browser/f14-links-observation.mjs` | Entra no admin, abre o inspetor, percorre a aba Vínculos de um campo de arquivo e de um campo de texto, lê o bloco de aprovação, o diálogo de seções, e publica pelo próprio botão | **18 passaram, 0 falharam** |
| `tests/browser/f14-customer-flow.mjs` (`WCCS_MODE=place`) | Entra como cliente, esvazia o carrinho, preenche o checkout Blocks da loja — com os campos que o plugin regista lá — escolhe o meio de pagamento e faz o pedido | **6 passaram, 0 falharam** |
| `tests/browser/f14-customer-flow.mjs` (`WCCS_MODE=observe`) | Abre a página de agradecimento e o detalhe do pedido na conta, para o mesmo pedido | **10 passaram, 0 falharam** |
| `tests/Integration/support/f14-observe-order.php` | Desenha o painel do pedido e as duas projeções de e-mail do pedido real, e pergunta a cada área se mostrou o que lhe pertence | **tudo passou** |
| `tests/browser/f14-admin-order-screen.mjs` | Abre o ecrã do pedido no admin (HPOS) como equipa | **falhou — achado F-3** |
| `curl` com sessão autenticada (cookie + nonce) | A **porta de download** e a **projeção de integração** por HTTP, como um cliente ou uma integração as pede | 200 na superfície que o vínculo permite (anexo, `nosniff`, `private`), 404 recusado nas outras, **401** sem autenticação |

Sementes usadas: `tests/Integration/support/seed-f14-links.php` (documento com vínculos por
destino, seções por área e um fluxo de aprovação). A prova reprodutível do achado F-4 ficou em
`tests/Integration/F14-native-values-proof.php`, dentro da varredura.

O que a loja teve de ceder para o cliente poder comprar, e que ficou reposto: o modo
"em breve" (`woocommerce_coming_soon`) foi desligado durante a corrida e voltou a `yes`;
um meio de pagamento offline (`bacs`) foi ligado e a opção foi apagada no fim — sem ele
não há como um cliente fechar um pedido, porque os gateways reais estão desconfigurados
(`SANDBOX-PAYMENT`); um cliente temporário foi criado e removido; dois pedidos de teste
foram apagados.

## 2. O que a passagem confirmou

- **A aba Vínculos é a que foi implementada.** Os sete destinos, cada um com o seu
  interruptor, seção, título, ordem e ações; as ações oferecidas são as do próprio destino
  (`approve` só na equipa); os valores gravados voltam para os controlos; o seletor de
  seção de um destino só oferece as seções oferecidas ali; um campo de texto não recebe
  nenhuma ação de arquivo; o diálogo de seções oferece as sete áreas e não oferece a API
  pública.
- **O fluxo de aprovação é opcional como prometido.** Desligado por omissão; ligá-lo
  escreve um estado que o lojista vê e edita; o rascunho incompleto é recusado pelo
  servidor em vez de completado.
- **A publicação pelo ecrã funciona.** O diálogo diz "9 alterações prontas" e o store fica
  na revisão publicada (conferido na base).
- **Cada área mostra o que lhe foi vinculado, e só isso.** No pedido real: a página de
  agradecimento mostrou o campo vinculado a `order_received` (com a seção e o título
  configurados) e não o vinculado a `customer_order`; a conta mostrou o contrário; os
  campos sem vínculo e o vinculado só ao perfil não apareceram em lado nenhum; o painel do
  pedido agrupou pela seção e respeitou a ordem configurada; os dois e-mails (cliente e
  loja) mostraram o seu campo com o título que a loja deu, e mais nada.
- **A situação da análise chega ao cliente** na página que ele vê, e o pedido espera no
  estado que o lojista nomeou, com uma nota.
- **A porta que serve bytes obedece ao vínculo, por HTTP.** Com sessão de equipa e o
  documento publicado: `destination=admin_order` devolveu **200** com o ficheiro como anexo,
  `X-Content-Type-Options: nosniff` e `Cache-Control: … private`; `destination=customer_order`
  (o vínculo daquele destino não permite baixar) devolveu **404 `not_allowed`**; sem sessão,
  **404**; e `destination=public_api`, que um browser não pode reclamar, **404**.
- **A projeção de integração respeita o seu destino.** Autenticada, devolveu **200** com um
  só campo — o que declara `public_api` — e o valor veio da meta do WooCommerce, o que prova
  o leitor do F-4 também nesta superfície; sem autenticação, **401 `wccs_unauthenticated`**.

## 3. Achados

### F-1 — o adaptador de Blocks lia o documento em bruto (corrigido)

`BlocksCheckout::apply()` entregava ao adaptador os arrays **como estavam gravados**. Um
campo sem `storage` explícito é lido como `order` pelo modelo — é o que o renderer e a
extensão da Store API fazem, e é o que o próprio validador de seções já registra como
lição — mas o adaptador lia a chave em bruto, via `(none)`, e recusava o campo com
`storage_not_native`.

Medido antes da correção, com o documento desta passagem:

```
registered=[]
refused=[documento_fiscal: storage_not_native, codigo_retirada: storage_not_native,
         campo_sem_vinculo: storage_not_native, preferencia_perfil: storage_not_native, …]
```

O resultado na loja era o pior possível e o mais silencioso: **os campos do plugin não
apareciam no checkout**. Depois da correção os quatro são registados e o cliente vê-os na
página (`CPF ou CNPJ`, `Código de retirada`, `Campo sem vínculo`, `Preferência do perfil`)
— é o que o instrumento do cliente passou a observar.

Correção: `BlocksCheckout::apply()` lê o documento pelo modelo (`FieldDefinition` e
`SectionDefinition`), com o comentário que explica por quê. `tests/Unit/.../BlocksAdapterTest.php`
continua a exercitar o adaptador pelas suas próprias fixtures.

### F-2 — o WooCommerce imprimia os campos por conta própria (corrigido)

Um additional field registado é impresso pelo **próprio WooCommerce** na confirmação do
pedido e no detalhe do pedido na conta, com o rótulo dele, a menos que o registo diga o
contrário (`show_in_order_confirmation`, que por omissão é `true`). O plugin não o dizia,
e o efeito era o contrário do que a fase promete: o campo aparecia nas duas páginas mesmo
vinculado a uma só — ou a nenhuma.

Medido antes da correção, no pedido real: `Código de retirada` (vinculado só a
`order_received`) aparecia também na conta, e `Campo sem vínculo` e `Preferência do perfil`
apareciam em ambas as páginas, inseridos pelo WooCommerce.

Correção: `show_in_order_confirmation => false` no registo nativo, com o motivo escrito ao
lado, e um teste de unidade que o exige. Depois dela, a mesma observação dá **10 passaram,
0 falharam**.

### F-3 — o painel do pedido não aparece no ecrã da equipa (aberto)

No ecrã de pedido do admin (HPOS, WooCommerce 11.1, WordPress 7.1) o painel do plugin
**não é desenhado**, embora o plugin o registe corretamente. A evidência, recolhida com
instrumentação temporária já removida:

- a caixa está registada — o "Mostrar opções" do ecrã lista `wccs-order-fields`, e um dump
  em `shutdown` mostra-a em `woocommerce_page_wc-orders/normal/default`;
- o `add()` recebe o ecrã certo (`woocommerce_page_wc-orders`) e o pedido certo, com
  `may_edit=yes`;
- **`render()` nunca é chamado** (um marcador no início do método não escreve nada);
- duas caixas extra só para depuração — uma com callback de closure, outra registada na
  ação específica do ecrã (`add_meta_boxes_woocommerce_page_wc-orders`, que *dispara*) —
  também não são desenhadas, enquanto as caixas do próprio WooCommerce aparecem.

Ou seja: nesta combinação de plataforma, as caixas registadas durante as ações
`add_meta_boxes` não chegam ao ecrã, e a área `admin_order` fica sem superfície para a
equipa. Não foi introduzido pela F14 (o painel é da WCCS-051) e o conteúdo do painel está
provado — `f14-observe-order.php` desenha o callback e lê o resultado certo. O que falta é
a plataforma desenhá-lo.

### F-4 — os valores captados nativamente nunca chegavam às projeções (corrigido)

O adaptador regista os campos nativos pela API de additional fields do WooCommerce, e é o
**WooCommerce** que os persiste — na meta `_wc_billing/wc-checkoutsuite/<campo>`, como a
tabela da secção 13 manda. O armazenamento da Suite (`_wccs_fields`), que era o único que
todas as projeções liam, nunca recebia esses valores: a extensão da Store API só carrega os
campos que o plugin desenha. Medido no pedido real, com os quatro valores que o cliente
escreveu:

```
suite payload before = {"observacoes_entrega":""}
woocommerce native meta = {"documento_fiscal":"123.456.789-09","codigo_retirada":"RET-42",
                           "campo_sem_vinculo":"NUNCA-MOSTRAR","preferencia_perfil":"PERFIL-NAO-MOSTRAR"}
```

Consequência: num store cujos campos sejam captados nativamente pelo checkout Blocks,
nenhuma superfície da Suite mostrava o valor, e o fluxo de aprovação não retinha o pedido —
porque também ele lia o armazenamento da Suite.

**Correção: ler, não copiar.** `NativeOrderValues` é o leitor dos valores que a plataforma
guardou (as três chaves de prefixo do WooCommerce, com o identificador de integração do
campo), e `OrderFieldsService::read()` e `history()` passam a receber as definições e a
fundir esse resultado **onde o armazenamento da Suite não tem resposta** — uma autoridade por
campo continua a ser uma, e o valor não ganha uma segunda meta. A regra de "isto é uma
resposta" (string vazia, lista vazia, `null` e `false` não são) ficou num sítio só,
`OrderFieldValues::is_answer()`, que o fluxo de aprovação passou a usar em vez de repetir. Os
quatro sítios que liam valores passaram a passar o documento: o painel do pedido, o fluxo de
aprovação, o exportador de privacidade e a projeção de integração.

**Provado sem fixture nenhuma**, no pedido #6197, feito pelo checkout real do cliente depois
da correção:

```
suite payload only = {"observacoes_entrega":""}                     ← nada foi copiado
with definitions   = {… os quatro valores …}                        ← lidos onde vivem
history ids        = observacoes_entrega, documento_fiscal, codigo_retirada, sem_vinculo, preferencia_perfil
status             = wccs-db244f28f8                                ← retido pela aprovação, no checkout
```

E no browser, na mesma corrida: a página de agradecimento mostrou o campo de `order_received`,
a conta mostrou o de `customer_order`, nenhum mostrou o campo sem vínculo nem o do perfil, e a
situação da análise apareceu nas duas — **10 passaram, 0 falharam**. A prova reprodutível
ficou em `tests/Integration/F14-native-values-proof.php` (**12 passaram, 0 falharam**), que
escreve a meta como a plataforma escreve, confirma que o armazenamento da Suite continua
vazio, e lê tudo de volta pelas projeções.

A fixture que existia para contornar isto (`f14-place-suite-values.php`) foi **removida**: com
o leitor no sítio certo, escrever uma cópia seria exatamente a segunda meta que a correção
evita. O observador de superfícies passou a mostrar as duas autoridades lado a lado.

## 4. O estado da fase, corrigido

O gate da F14 foi dado como cumprido na passagem anterior, com base nos harnesses. Esta
passagem mostrou que três dos seus termos não valiam para a loja real: um campo vinculado
não aparecia no checkout (F-1), o WooCommerce mostrava campos não vinculados (F-2) e, num
store com campos nativos, os valores captados não chegavam a superfície nenhuma (F-4). Os
três foram corrigidos e reobservados na loja. Resta um achado:

- **F-3** deixa a área `admin_order` sem superfície no ecrã de pedido desta plataforma,
  embora o plugin registe o painel. Não foi introduzido por esta fase e não foi corrigido
  aqui.

Por isso `docs/compatibility.json` mantém a F14 com `gate_met: false`, agora com um só
motivo escrito. Não é uma regressão do que foi entregue: é a diferença entre o que os
harnesses provam e o que a loja faz.

## 5. Reprogramação

Os dois caminhos a seguir, na ordem em que fazem diferença:

1. **F-3** — descobrir como o WooCommerce 11.1 desenha caixas de terceiros no ecrã de
   pedido (HPOS) e registar o painel por esse caminho, mantendo o registo de metabox para o
   ecrã clássico. Aceite: `tests/browser/f14-admin-order-screen.mjs` passa. O que já se sabe
   está no achado: o registo chega, o callback não é chamado, e duas caixas de depuração
   registadas da mesma forma também não foram desenhadas, enquanto as do WooCommerce foram.
2. **`customer_profile`** continua sem superfície e sem dados (escopo `customer` declarado e
   nada o escreve) — registado desde a WCCS-076 e ainda sem dono no roadmap.
