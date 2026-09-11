# Registro de validação — WCCS-025

**Tarefa:** WCCS-025 · "Tratar lifecycle e refresh"
**Fase:** F04 · Classic Checkout e persistência canônica
**Prioridade:** `required_v1` · **Dependências:** F03
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Refresh preserva valores e cria apenas uma instância por componente."

**Resultado:** **181 testes unitários PHP** (3 novos) · **619 asserções de integração** em 21 provas, 0 falhas (23 novas) · **346 testes de JS em 23 suites** (21 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/checkout/lifecycle.js` | O registo de componentes: uma instância por elemento |
| `resources/checkout/values.js` | Preservar o que o cliente escreveu através de um refresh |
| `resources/checkout/index.js` | A entrada: liga os dois aos eventos do WooCommerce |
| `src/Checkout/Classic/ClassicAssets.php` | Entrega o bundle só onde ele é necessário |
| `ClassicAdapter` | Marca os campos da Suite, e só os dela |
| `tests/js/checkout/*` · `tests/Integration/F04-wccs-025-classic-js-proof.php` | 21 specs de JS, 23 asserções de integração |

## 3. A parte difícil é uma frase

O contrato inteiro cabe numa linha: **um componente arranca uma vez por elemento, não uma vez por refresh.** Tudo o resto sai daí.

- Um elemento que **sobreviveu** ao refresh nunca arranca de novo. Arrancar um input mascarado duas vezes embrulha-o outra vez, e é o segundo embrulho que apaga o que o cliente escreveu — é exatamente assim que "o refresh não preservou o valor".
- Um elemento que **foi substituído** é um nó novo que o registo nunca viu, portanto arranca de novo. Está certo: o nó novo está vazio.
- Um elemento **removido e readicionado** também é novo, e arranca pela mesma razão.

A identidade é **por nó**, não por identificador nem por seletor. Dois inputs com o mesmo `name` são dois elementos; a substituição de um deles é um terceiro. É isso que faz com que "isto já arrancou?" seja a mesma pergunta que "este elemento sobreviveu ao refresh?".

E é essa a mesma pergunta de que a preservação de valores precisa: **um elemento que o registo nunca viu é, por definição, um elemento que acabou de ser substituído — o único caso em que um valor se pode ter perdido.** Um mecanismo, dois usos.

## 4. Preservar o valor: o que a prova afirma

A captura acontece em `update_checkout` (antes do AJAX) e a reposição em `updated_checkout` (depois do HTML novo estar na página). Provado em jsdom:

| Caso | Resultado |
|---|---|
| campo substituído por um vazio | o valor escrito é reposto |
| campo que sobreviveu | intacto, e o componente não arranca de novo |
| substituição que veio com valor do servidor | **não** é sobrescrita — isso não é uma perda, e escrever por cima seria inventar uma resposta |
| caixa marcada que voltou desmarcada | remarcada |
| caixa que estava desmarcada | continua desmarcada |
| grupo de rádio | repõe **o botão que estava escolhido**, não o primeiro |
| dois refreshes seguidos | guarda a resposta **mais recente**, não a primeira |

**Eventos deliberadamente não são disparados depois da reposição.** O WooCommerce recalcula em `change`; uma reposição que se anunciasse pediria um refresh, que substituiria o elemento outra vez, que reporia outra vez — um ciclo. E nada precisa do recálculo: só os campos da Suite são marcados e repostos, e esses não carregam frete, imposto nem total. Há um teste cujo único objectivo é afirmar que nenhum `change`/`input` sai dali.

## 5. O servidor diz quais são os seus

O cliente tem de distinguir os campos da Suite de todos os outros, e o identificador que o lojista escolheu não é um padrão que se possa procurar. Passou a haver um marcador: `data-wccs-field="<id>"`, nos `custom_attributes` do campo.

**Só um campo próprio é marcado.** Um campo do WooCommerce é re-renderizado e repovoado pelo próprio WooCommerce a partir da sessão; marcá-lo convidaria o cliente a repor um valor de que a plataforma já é responsável, e poria uma chave num campo core que a regra da WCCS-021 diz que o adapter não toca. As duas asserções existem: o campo próprio tem o marcador, o campo core não tem.

Preservar um valor é uma coisa; **inventar** um valor é outra. O marcador é o que separa as duas.

## 6. O bundle é entregue apenas onde é preciso

`ClassicAssets` tem dois portões, e ambos são afirmados: a **página** (é o checkout clássico) e o **conteúdo** (o documento publicado tem algum campo que o checkout clássico consegue renderizar). Uma loja que não usa a Suite, ou cujo schema publicado só tem campos que o adapter omite, não recebe script nenhum. Um checkout Blocks também não: ele renderiza no cliente a partir da Store API, e um script clássico correria contra um formulário que não existe.

Há ainda um terceiro, de natureza diferente: **um bundle que não foi construído não é enfileirado.** Enfileirar um URL que não existe põe um 404 no checkout e não muda mais nada, e o que o bundle faz é uma melhoria em cima de um checkout que já funciona sem ele. A exigência de build continua registrada no ADR-0009.

A verificação afirma o caminho positivo por inteiro, e não por dedução: com os dois portões satisfeitos, `wp_script_is( 'wccs-checkout', 'enqueued' )` é verdadeiro, o `src` é o bundle construído, `jquery` está nas dependências, e `group = 1` (rodapé, depois de o formulário existir). Para isso a assinatura aceita os dois booleanos, tal como `Admin\Assets::enqueue()` aceita um screen id — **um portão que ninguém consegue exercitar é um portão que ninguém verificou.**

Um detalhe que veio do build e não de uma lista escrita à mão: a dependência `jquery` está no `index.asset.php` gerado, o que prova que o import foi mesmo externalizado para o `window.jQuery` que o WordPress fornece. O bundle tem 1929 bytes.

## 7. Dois defeitos encontrados — um no produto, um no harness

**No harness, e vale registar.** A asserção "o bundle está registrado no hook" falhou com o hook presente. O helper que verifica hooks procurava `$function[0] instanceof $class`, que só encontra um **método de instância**; um método **estático** é registrado como nome de classe, e a asserção lia um hook existente como ausente. Corrigido para aceitar as duas formas, com o comentário a dizer porquê. Um verificador que só conhece metade das formas de um callable é um verificador que mente.

**No produto, e é a terceira vez na fase.** A WCCS-021 afirmava, em dois sítios, que os `custom_attributes` de um campo são exatamente `array( 'maxlength' => 14 )`. O marcador desta tarefa juntou-se-lhes e as duas asserções falharam — corretamente. Foram reescritas para afirmar por chave em vez de por array inteiro, e ganharam uma asserção própria para o marcador, para que ele seja uma afirmação com nome e não um efeito colateral de outra.

É o mesmo padrão do conjunto de hooks (WCCS-022 → WCCS-023 → WCCS-025) e do formato do payload (WCCS-023 → WCCS-024): **uma asserção que fixa um valor exato tem de ser atualizada quando o valor muda de propósito, e é isso que a torna útil.** Três tarefas seguidas em que ela apanhou a mudança e obrigou a assumi-la.

## 8. O que NÃO foi provado, e porquê

**Uma página de checkout clássico renderizada.** O caminho positivo do enfileiramento foi exercido entregando os dois portões, não observando um checkout real — esta loja não tem um. É a `CLASSIC-TEST-SURFACE`, que continua a ser a única coisa a bloquear o gate da F04.

**O refresh AJAX contra uma resposta real.** A substituição de nós é simulada em jsdom. Os fragmentos que o WooCommerce devolve por omissão são a tabela de revisão do pedido e a caixa de pagamento — e é por isso que o bundle é útil: um plugin ou tema que acrescente um fragmento seu pode substituir um campo da Suite, e é esse o caso que o preservador cobre.

**Componentes reais.** Nenhum é registrado ainda. O ciclo de vida é o produto desta tarefa e a F05 registra o primeiro componente contra ele — uma máscara aplicada uma vez por input, que é exatamente o caso para que a regra por elemento existe. Até lá o bundle tem um componente: o preservador de valores, que é um componente como qualquer outro.

## 9. O gate da F04, com as cinco tarefas fechadas

As cinco tarefas da fase estão concluídas. O gate — *"pedidos Classic válidos e inválidos testados em HPOS on/off, visitante/logado e carrinho físico/virtual"* — **continua aberto**, e agora só por uma razão: a loja não tem página com o shortcode `[woocommerce_checkout]`, portanto nenhum pedido foi criado por um checkout e as duas variáveis finais (visitante/logado, carrinho físico/virtual) são propriedades de um pedido de checkout.

O que a fase construiu e provou, sem ela, é substancial: o adapter contra o array de campos real, a normalização e a validação contra os hooks e a coleção de erros reais, os valores tipados nos dois backends de pedido, a leitura histórica sobre três mudanças de schema, e agora o ciclo de vida do cliente contra um DOM real que se substitui.

## 10. Próxima tarefa

**F05 · Presets Brasil, IMask e validação remota.** A primeira tarefa é **WCCS-026**, e é o primeiro consumidor do ciclo de vida entregue aqui.

A fase F05 é onde as máscaras e as regras brasileiras entram, e a decisão registrada no ADR-0001 sobre `_wccs_fields` e no ADR-0010 sobre o snapshot continua a valer: as máscaras são regra de renderização, não de armazenamento, e o valor canônico continua a ser o que a WCCS-022/023 provaram.

Vale a pena decidir antes de começar se a F05 avança com a `CLASSIC-TEST-SURFACE` em aberto, como a F04 avançou, ou se ela é resolvida primeiro — porque a partir de agora cada fase acrescenta comportamento visível no checkout, e a verificação end-to-end fica mais valiosa do que era.
