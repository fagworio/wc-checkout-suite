# WCCS-075 · Fluxo de aprovação opcional

**Fase:** F14 · **Depende de:** WCCS-074
**Aceite:** "Desligado por padrão; não altera status nem bloqueia sem estar habilitado; configuração incompleta é apontada, não completada em silêncio."

## 1. O que foi entregue

O fluxo da secção 12.1 passou a existir como uma decisão só, lida num só lugar:

- **`ApprovalFlow`** lê a configuração de aprovação de um campo e responde o que ela significa:
  `enabled()`, `complete()`, `missing()`, o estado que o lojista nomeou (`label()`), a área e a secção da
  análise, e os interruptores (correção, reenvio, mostrar situação). A regra de completude vive aqui e é a
  mesma nos dois momentos em que é perguntada — o validador recusa uma configuração incompleta na gravação e
  o runtime não age sobre ela — em vez de duas cópias que podem divergir.
- **Nada existe até a loja pedir.** `ReviewStatus` lê o documento publicado no `init`; sem nenhum fluxo
  completo habilitado não regista estado nenhum, não acrescenta nada à lista de estados do WooCommerce e não
  liga hook nenhum. «Não altera status sem estar configurado» fica provado por construção, e não por promessa:
  o harness mostra que, sem fluxo, não há o que alterar.
- **Configuração incompleta é apontada, chave por chave.** `approval_incomplete` diz o que falta
  (`area`, `section`, `status`), e duas verificações novas recusam o que antes passava: o estado que o
  lojista não nomeou, e uma análise cuja secção não é oferecida naquela área
  (`approval_section_not_offered`) — a mesma lacuna que WCCS-073 fechou para os vínculos, aqui para o fluxo.
- **Com o fluxo completo, o pedido que tem resposta espera no estado configurado.** A Suite observa a
  transição de status (`woocommerce_order_status_changed`) somente quando o WooCommerce chega a
  `processing`, `completed` ou `on-hold`, e também `woocommerce_payment_complete` para gateways que usam
  esse sinal. O checkout criado não é retido antes da decisão do gateway. O pedido leva uma nota a dizer
  por que espera, e uma chamada repetida não muda nada nem escreve duas notas.
- **O cliente é informado quando o lojista pediu.** Com `show_status`, o painel do pedido mostra
  «Situação da análise: <o nome que a loja escolheu>» — e o painel deixou de desaparecer quando não há linhas
  a mostrar, porque a situação do pedido é informação do cliente mesmo quando o documento não lhe é exibido.
- **No inspetor**, ligar «Exigir análise manual» propõe o estado que o desenho sugere ("Pendente de
  aprovação"), escrito no campo que o lojista vê e pode editar. Um fluxo que não nomeia estado é recusado pelo
  servidor; a sugestão existe para que a configuração seja possível, não para completar o que falta em
  silêncio.

## 2. Achados desta tarefa

1. **Um estado não cabe num rótulo.** A primeira versão derivava o identificador do estado a partir do texto
   do lojista ("Pendente de aprovação" → `wc-wccs-pendente-de-aprovacao`) e o harness apanhou-o: o pedido
   ficou em `wccs-pendente-de-` — a coluna `post_status` (e a `status` do HPOS) tem **vinte caracteres** e
   truncou o resto. Pior: como o estado guardado deixou de ser igual ao que o código achava que tinha
   escrito, a idempotência caiu por terra e a segunda chamada escreveu uma segunda nota — o harness viu as
   duas coisas. O identificador passou a ser derivado do **campo** (`wccs-` + dez caracteres de hash, dezoito
   no total com o `wc-`), o que também o torna estável: renomear o estado não move os pedidos que já esperam
   nele, e dois campos nunca colidem.
2. **Um estado não registado não pode ser escrito.** A mesma investigação mostrou que o WordPress recusa
   gravar um `post_status` que ninguém registou (o pedido ficava em `pending`), o que é uma boa rede de
   segurança: a Suite não consegue pôr um pedido num estado que não registou, nem por engano.
3. **A validação de rascunho mantém-se, e agora sabe mais.** `approval_incomplete` já corria em cada gravação
   de rascunho desde WCCS-071; o que faltava era o estado na regra e o cruzamento com as secções. Os fixtures
   de WCCS-071 e WCCS-072 que declaravam um fluxo foram ajustados (o de WCCS-072 já apontava para uma secção
   oferecida na área, por isso continua a passar sem alterações de fundo).
4. **O `wc_order_statuses` já tinha um filtro.** A primeira asserção «nenhum filtro é adicionado» media o
   filtro errado: o WooCommerce Blocks regista ali o estado `wc-checkout-draft`. A prova passou a ser sobre o
   que a Suite acrescenta à lista, que é o que interessa.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 421 testes, 1512 asserções (phpcs, phpstan, phpunit) |
| `tests/Integration/F14-wccs-075-approval-flow-proof.php` | **21 passaram, 0 falharam** |
| `tests/Unit/Domain/Approval/ApprovalFlowTest.php` | sete casos novos (desligado, incompleto chave a chave, estado derivado e estável, estado por nomear, interruptores, só campos da Suite, o que conta como resposta) |
| `tests/Unit/Domain/Fields/DefinitionValidatorTest.php` | o fluxo completo passou a incluir o estado, e um caso novo prova que sem ele a configuração é recusada |
| `tests/js/views/FieldProperties.test.js` | 15 especificações, duas sobre o fluxo (a sugestão ao ligar, e manter o estado que o lojista já tinha nomeado) |
| varredura de integração | 63 harnesses, 1441 asserções, zero falhas |

## 4. Limites

- **Aprovar, pedir correção e reenviar ainda não executam nada.** `allow_correction` e `allow_resubmit` são
  configuração registada e lida pelo fluxo, e a *permissão* de cada destino é aplicada desde WCCS-074 (a
  matriz mostrar/ver/baixar/aprovar/reenviar, e o `destination` que não se herda). O acto em si — a equipa
  pedir correção, o cliente enviar uma nova versão para um pedido já feito — não pertence a nenhuma tarefa
  deste roadmap, e o plugin **não promete em nenhuma superfície** o que não faz: não há botão, nem aviso, nem
  frase que sugira um envio que não existe.
- **O estado da Suite não tem e-mail.** O WooCommerce envia e-mails nas transições que conhece; um estado
  próprio não tem nenhum, e a Suite não inventa um. Na prática a retenção acontece depois de o gateway ter
  levado o pedido a `processing`/`on-hold`, ou seja depois de os e-mails desse estado terem saído; o que
  informa é a nota no pedido, para a equipa, e a situação no painel do cliente, quando o lojista a pediu.
  Mapear um e-mail para o estado da Suite exige código da loja (`woocommerce_email_actions`) e fica registado
  como decisão da loja, não como omissão do plugin.
- **Desligar o fluxo depois de haver pedidos retidos** deixa esses pedidos num estado que já não está
  registado: o WooCommerce mostra o identificador em vez do nome. Não é uma migração automática — os pedidos
  existem — e o roadmap não pede uma.
- **Uma resposta vazia não é uma resposta.** Um campo de arquivo sem ficheiros anexados, uma string vazia,
  `null` e `false` não retêm o pedido; `0` e `'0'` são respostas. É o que o modelo considera «tem resposta»,
  e a regra está num sítio só.
- **A auditoria área por área é WCCS-076.** Aqui provou-se o fluxo: que não age sem configuração, que é
  apontado quando está incompleto e que retém o pedido quando está completo.
