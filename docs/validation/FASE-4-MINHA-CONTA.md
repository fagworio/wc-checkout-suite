# Fase 4 — Minha Conta

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §7, §27 · **Fase 4**
**Gate:** «cliente sem pedido usa formulário e persiste dados.»

## 1. O que já existia (e continua provado)

- **A página existe e é a do WooCommerce** (`my_account_endpoint`), como §7.2–7.4 pedem: cada
  secção oferecida a `customer_account` com apresentação de conta vira um endpoint próprio, entra no
  menu da Minha Conta e regista-se com o seu slug (`MyAccountSections`). O gate já corria a 6/6 na
  observação de browser: o cliente preenche, grava, o valor persiste no acesso seguinte, e um campo
  de Minha Conta não aparece no checkout.
- **Os valores são do cliente** (`CustomerFieldsService`, `meta` do utilizador), com a submissão a
  passar pelo mesmo `ValueProcessor` do checkout e a escrever cada campo **uma vez** por submissão,
  mesmo quando está usado duas vezes na página (§3.3).

## 2. O que esta fatia acrescenta — um documento do cliente, sem pedido

Até aqui a Minha Conta guardava valores escalares. Um **documento** (campo de ficheiro) era o que
faltava, e não é um upload de checkout: pertence ao cliente, tem de estar lá quando ele entra noutro
dispositivo, e não pode expirar com um carrinho que não foi fechado.

- **`UploadsTable` versão 2**: a tabela ganhou `user_id` (com índice). A versão é comparada em cada
  arranque, portanto uma loja atualizada sem ser reativada instala a coluna — o mesmo mecanismo que
  já existia para `order_id`.
- **`UploadRepository`** ganhou o estado `stored` (um documento que vive enquanto o cliente existir),
  um `insert()` que distingue os dois casos — com `user_id` não escreve expiração, sem ele continua a
  ser o temporário de checkout — e `for_user( $user_id, $field_id )`, que é a pergunta que a página
  da conta faz: «qual é o meu ficheiro deste campo?». A pergunta é por cliente e campo, e não por
  token, porque a página não guarda um token e um cliente noutro dispositivo não teria sessão onde o
  ter lembrado.
- **`UploadService::accept_for_customer()`** aceita o ficheiro com as **mesmas três perguntas, na
  mesma ordem** que o upload de checkout (ambiente, o que o ficheiro é, quota do dono), e o que
  difere é a vida do resultado: sem expiração e com o dono derivado do utilizador
  (`customer_owner()`). `for_customer_field()` e `remove_for_customer()` completam o ciclo.
  O dono de um upload de checkout continua a ser a **sessão** — a razão está no docblock e não mudou:
  duas compras não podem ver os documentos uma da outra.
- **`UploadsRetention`** ganhou o quarto caso: um documento de cliente é **guardado** enquanto o
  cliente existe e é **órfão** quando não existe. Isto obrigou a duas coisas que faltavam e que sem
  isto deixariam ficheiros para sempre: `candidates()` passou a considerar as linhas com `user_id > 0`
  (não têm expiração, portanto nenhuma outra regra as olhava) e o `sweep()` passou a resolver se o
  cliente ainda existe antes de decidir.
- **`resubmit` como nova versão**: o ficheiro novo é uma linha nova e a página lê a mais recente
  (`ORDER BY id DESC`), portanto substituir não perde o histórico da linha antiga e a antiga passa a
  ser um ficheiro que ninguém aponta — que a retenção recolhe pela regra do órfão quando o cliente
  sai. Não é uma tabela de versões: é a mais recente a ganhar.

## 3. O formulário da conta — o documento na página do cliente

- **`CustomerSectionFields`** passou a incluir `file` nos tipos que uma superfície de cliente
  recolhe, com `is_document()` (a pergunta é feita ao registo de tipos, como o checkout faz) e
  `document_label()` — a frase que as duas superfícies mostram: o nome que o cliente reconhece, com
  o tamanho, ou «Nenhum documento enviado.».
- **`MyAccountSections`** desenha o campo como documento e não como valor: o documento atual, a
  ligação para a porta que serve os bytes (só quando o **uso** permite ler — `FilePermissions`), o
  controlo de envio com os limites do próprio campo, e — quando a loja não consegue guardar ficheiros
  em privado — **a razão**, em vez de um controlo que não pode funcionar.
- O formulário passa a `multipart/form-data` quando a secção **tem** um campo de documento, e não
  quando já há um documento: a primeira submissão é exactamente o caso em que nada está em ficheiro.
- Na submissão, os documentos são aceites **primeiro e à parte** do resto: um ficheiro não é um valor
  que o formulário carrega, é uma linha que o armazém do cliente guarda. A recusa vira mensagem no
  formulário e o documento anterior fica onde está; o sucesso não escreve valor nenhum, porque a
  linha mais recente *é* o documento atual. Os valores da mesma submissão seguem o caminho de sempre
  e são gravados juntos.
- **`CustomerProfilePanel`** (admin) mostra o documento a quem atende, com a nota de que é o cliente
  quem o envia e substitui na página dele: um `input type=file` sem tratador seria uma oferta que
  aquele ecrã não faz — o envio pelo painel é a Fase 5.

## 4. Prova

| Prova | Resultado |
|---|---|
| `UploadsRetentionTest` (3 testes novos) | um documento de cliente é guardado enquanto o cliente existe; fica órfão quando o cliente desaparece; uma linha `stored` sem cliente é órfã |
| `CustomerSectionFieldsTest` (2 testes novos) | um documento é recolhível numa superfície de cliente e `is_document()` distingue-o de um valor; `document_label()` nomeia o ficheiro e o tamanho, e diz «Documento enviado.» quando o nome se perdeu |
| `tests/Integration/FASE4-customer-upload-proof.php` (novo) | **21/0** contra a base e o diretório reais: a tabela tem `user_id` e a versão diz qual build a instalou; o upload de um cliente é aceite e fica `stored`, sem expiração e sem pedido; a página encontra-o **por cliente e campo**; conta na quota dele; **outro cliente recebe `not_yours`**; a **página renderizada** mostra o documento, a ligação com o token, o formulário `multipart` com `name="wccs_account_files[…]"`; sem ambiente protegido mostra a razão **sem** controlo e continua a mostrar o documento; o limpo vê a linha sem expiração; e o `sweep` remove-a, ficheiro e linha |
| `tests/browser/fase4-my-account-upload.mjs` (novo) | **11/0** com um POST real: o cliente abre a sua página, vê o campo e «Nenhum documento enviado.»; envia um PDF pelo formulário; a página volta a mostrar o documento e a dizer que foi enviado; o campo de texto da **mesma** submissão foi gravado; e o documento continua lá num acesso novo |
| `tests/Integration/support/seed-customer-document.php` (novo) | escreve e desfaz o cenário: documento publicado, cliente, e — porque esta máquina serve o diretório privado por HTTP — a observação de ambiente, que `teardown` remove |
| `composer check` | phpcs e phpstan sem erros; **509 testes, 1838 asserções** |
| Varredura de integração | **70 harnesses, 1589 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` | limpos (687 testes) |

O harness segue a convenção que WCCS-042 fixou para este ambiente: faz a observação **real** primeiro
e di-lo em nota — nesta máquina o diretório privado é servido por HTTP (`protected=no status=200`),
portanto o upload está desligado por regra —, e só depois injeta a observação para exercer as regras
de posse e de retenção, apagando o que escreveu no fim. A observação de browser faz o mesmo e di-lo
no cabeçalho e no resultado.

## 5. Limites que ficam registados

- **A substituição não apaga o documento anterior.** O ficheiro novo é uma linha nova e a página lê
  a mais recente; a antiga fica sem ninguém a apontar para ela até o cliente ser removido. Não é uma
  tabela de versões nem uma limpeza de cada substituição: é a escolha de não apagar nada enquanto o
  histórico puder ser pedido. Recolher as linhas antigas de um cliente que continua a existir é uma
  decisão de retenção que ainda não foi tomada.
- **O envio pelo painel de administração não existe.** O painel mostra o documento e di-lo; enviar
  ou substituir por conta do cliente é a Fase 5 (perfil do cliente para a equipa), onde as permissões
  de editar/ver por uso passam a ser configuráveis.
- **§7.4 — a página nativa ainda não recebe secções.** Hoje uma secção de cliente vive numa página
  própria (endpoint) ou não vive. O documento pede também o contrário: uma secção dentro de uma
  superfície nativa homologada («Detalhes da conta»), e a implementação tem de **declarar quais as
  páginas nativas que podem receber conteúdo em segurança** — inserir um formulário na lista de
  pedidos ou nos downloads não é o mesmo que inseri-lo em «Detalhes da conta». É o passo seguinte
  desta fase: a lista fechada com o motivo de cada página, a apresentação a poder apontar para uma
  delas, e a validação a recusar as outras pelo nome.
- **Nesta máquina o upload está desligado** porque o `.htaccess` do diretório privado é ignorado pelo
  Apache do devilbox (`AllowOverride`). A prova de browser injecta a observação — e di-lo no
  cabeçalho, na nota e no fixture — como o harness faz; a proteção real do diretório é uma decisão de
  servidor, não deste código.
