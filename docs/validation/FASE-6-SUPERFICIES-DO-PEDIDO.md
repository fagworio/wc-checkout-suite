# Fase 6 — O pedido, para o cliente, para a equipa e no e-mail

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §3.3, §13, §14, §27 · **Fase 6**
**Gate:** «todos obedecem somente bindings explícitos.»

## 1. O que a fase encontrou

As três superfícies do pedido — a página de agradecimento e a conta do cliente
(`Checkout\CustomerOrderFields`), o painel da equipa (`Admin\Orders\OrderFieldsPanel`) e os dois
e-mails (`Checkout\OrderEmailFields`) — já desenhavam por **container** e por **uso**: as três passam
por `AreaProjection::group()`, que percorre `bindings_for($destination)` e por isso já mostrava o
mesmo campo duas vezes quando ele está usado duas vezes no mesmo destino (trabalho da Fase 2).

Faltava o **filtro que vem antes**: `CustomerOrderFields::visible()` e `OrderEmailFields::visible()`
respondiam por **campo**, com duas consequências que a fase veio corrigir:

- **Um uso invisível era dado como visível.** O filtro perguntava `shows_in($destination)`, que é
  «existe algum uso aqui» — incluindo os que não são visíveis. A projecção saltava-os e desenhava
  nada, portanto a resposta era *sim* e a página dizia *não*. Medido antes da correcção:
  `visible()` devolvia `resumo` para um campo cujo único uso estava com `visible: false`.
- **O e-mail decidia «é um documento?» por uma lista escrita à mão** (`'file' === $field['type']`), e
  não pelo registo de tipos: um tipo contribuído por outra extensão que declare guardar ficheiro não
  era tratado como documento no e-mail, enquanto o checkout e as restantes superfícies o tratavam.

## 2. O que foi mudado

- **`CustomerOrderFields::visible()` e `OrderEmailFields::visible()`** passaram a exigir **pelo menos
  um uso visível** colocado naquele destino. A pergunta de nível de campo passa a ser a mesma que a
  página responde: um campo está nesta superfície quando alguma utilização dele ali está visível.
  Para um documento legado (sem `bindings`) isto é exactamente o que era: os usos derivados de um
  vínculo ligado são visíveis.
- **`OrderEmailFields::is_document()`** pergunta ao registo (`FilePermissions::is_file`), como o resto
  do produto: o tipo decide, e uma extensão que contribua um tipo de ficheiro não tem de ser conhecida
  por esta classe.
- **Nada mudou no desenho**: as três superfícies continuam a agrupar por container e a desenhar uma
  linha por uso, com o título e a ordem do uso, e as permissões de ficheiro decididas **por uso**
  (`FilePermissions::allows_binding()` dentro da projecção).

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE6-order-surfaces-proof.php` (novo) | **15/0** com um documento e um pedido reais: um campo com **dois usos** em `customer_order` aparece **duas vezes** na conta do cliente, cada um no seu container e na ordem configurada; um uso **invisível** não aparece em lado nenhum; um uso de ficheiro que **retirou `show_metadata`** não é listado enquanto o outro é; um campo **sem vínculo** não aparece; o e-mail mostra o uso que **o seu** destino configurou e não empresta as linhas das outras superfícies (nem o documento cujo uso não pediu o nome); o painel da equipa mostra os **seus dois usos** e nenhum dos outros; e a pergunta de nível de campo responde o que a página faz |
| `CustomerOrderFields`/`OrderEmailFields` | a correcção do uso invisível e do tipo de ficheiro; a varredura inteira continua verde, incluindo os harnesses de permissões de ficheiro e de valores nativos que usam `visible()` |
| `composer check` | phpcs e phpstan sem erros; **527 testes, 1893 asserções** |
| Varredura de integração | **71 harnesses, 1614 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` | limpos (690 testes; nenhum ficheiro JS mudou nesta fase) |

## 4. Limites que ficam registados

- **O e-mail não mostra o documento pela porta de download.** Um ficheiro aparece no e-mail como
  nome e detalhes; o conteúdo continua a ser servido pela porta que verifica o vínculo, porque um
  anexo num e-mail é uma cópia que sai do alcance das permissões. É a decisão da §12 e não uma
  omissão desta fase.
- **`visible()` continua a responder por campo.** É a pergunta «esta área pode mostrar este valor?»,
  e é a que os chamadores que indexam por campo (o histórico do pedido, um por campo) precisam de
  fazer. Quem desenha uma linha pergunta por uso, pela projecção. As duas respostas coincidem agora
  no que importa: nenhuma delas promete uma linha que não aparece.
- **Os documentos legados continuam a ser lidos pelo mapa derivado**, e um uso derivado de um vínculo
  ligado é visível — portanto uma loja que nunca tenha usado `visible: false` não vê diferença
  nenhuma. A regra mudou para os documentos que a usam.
