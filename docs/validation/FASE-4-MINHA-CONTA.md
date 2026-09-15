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

## 3. Prova

| Prova | Resultado |
|---|---|
| `UploadsRetentionTest` (3 testes novos) | um documento de cliente é guardado enquanto o cliente existe; fica órfão quando o cliente desaparece; uma linha `stored` sem cliente é órfã |
| `tests/Integration/FASE4-customer-upload-proof.php` (novo) | **15/0** contra a base e o diretório reais: a tabela tem `user_id` e a versão diz qual build a instalou; o upload de um cliente é aceite e fica `stored`, sem expiração e sem pedido; a página encontra-o **por cliente e campo**; conta na quota desse cliente; **outro cliente recebe `not_yours`** e o dono recebe o registo; uma sessão de checkout não é o cliente; o limpo olha para o documento mesmo sem expiração; é guardado enquanto o cliente existe; e o `sweep` remove-o, ficheiro e linha, quando não existe |
| `composer check` | phpcs e phpstan sem erros; **507 testes, 1832 asserções** |
| Varredura de integração | **70 harnesses, 1583 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` | limpos (687 testes) |

O harness segue a convenção que WCCS-042 fixou para este ambiente: faz a observação **real** primeiro
e di-lo em nota — nesta máquina o diretório privado é servido por HTTP (`protected=no status=200`),
portanto o upload está desligado por regra —, e só depois injeta a observação para exercer as regras
de posse e de retenção, apagando o que escreveu no fim.

## 4. Limites que ficam registados

- **O formulário da conta ainda não oferece o campo de ficheiro.** Esta fatia entregou o servidor: o
  serviço aceita, guarda e encontra o documento do cliente. Falta o lado da tela — desenhar o
  controlo de upload e o documento atual na página da conta, tornar o formulário `multipart`, aceitar
  o ficheiro na submissão e mostrar a recusa quando o ambiente não protege o diretório. É o passo
  seguinte, e sem ele o gate da fase ainda não está fechado.
- **O valor guardado é o token do upload.** É a mesma escolha do checkout (o token é o único
  identificador que sai do servidor), mas obriga a que a tela resolva o token para o nome do ficheiro
  ao mostrar o valor — trabalho da mesma fatia seguinte, e a razão pela qual `display_value()` para um
  ficheiro ainda não é o que deve ser.
- **Nesta máquina o upload está desligado** porque o `.htaccess` do diretório privado é ignorado pelo
  Apache do devilbox (`AllowOverride`). A prova de browser da fatia seguinte terá de injetar a
  observação — e dizê-lo — como o harness faz.
