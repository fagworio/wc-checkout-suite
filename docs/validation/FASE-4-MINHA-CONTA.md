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

## 4. §7.4 — a secção dentro de uma página nativa homologada

O documento pede uma secção **dentro de uma página que a Minha conta já tem** («Detalhes da conta») e
exige que a implementação declare quais as páginas nativas que podem receber conteúdo em segurança.

- **`Domain\Customers\AccountSurfaces`** é essa declaração: uma **lista fechada** (`edit-account`,
  `dashboard`), cada entrada com a frase que o lojista lê, e um mapa das **recusadas** com o motivo
  de cada uma — a lista de pedidos e a de downloads são o que listam, `edit-address` renderiza um
  formulário por tipo de endereço, `payment-methods` é do gateway, e `customer-logout` não é uma
  página. A pergunta não é se algo é uma página: é se inserir um formulário nela é seguro e significa
  alguma coisa. É seguro porque cada template da WooCommerce fecha o seu próprio `<form>` antes de a
  ação do endpoint devolver, portanto a secção é **irmã** do conteúdo nativo e nunca um formulário
  aninhado — aninhar faria o browser descartar um dos dois, em silêncio.
- **`presentation.account.page`** é a escolha no documento: vazio significa página própria (o endpoint
  de sempre); uma página nativa significa que a secção se desenha **dentro** dela e **não regista
  endpoint nenhum** — nem rewrite rule, nem entrada no menu, nem slug. `MyAccountSections` separa os
  dois casos em `configured_sections()` e `native_sections()`, e `render_native()` responde à ação
  `woocommerce_account_<página>_endpoint` com prioridade 20, depois da WooCommerce. O caminho de
  submissão é **o mesmo código**: `render_section()` não sabe de que página veio.
- **`SectionValidator`** aceita `page` da lista (e só dela) e passa a exigir o slug **só** quando a
  secção tem página própria; duas secções em páginas nativas não colidem por um slug que nenhuma tem.
  Uma página recusada é-o pelo nome (`account_page_not_supported`) com o motivo na mensagem.
- **O editor** ganhou «Onde esta seção aparece»: «Página própria (nova aba)» ou uma das páginas
  nativas — a lista vem do servidor (`catalog.accountSurfaces`, do mesmo `AccountSurfaces` que o
  validador lê), e escolher uma página nativa esconde o endereço e o nome no menu, que não se aplicam,
  mostrando a razão da página.

## 5. Prova

### Inventário e overrides nativos usados pelo editor

O editor lê o contrato da página nativa `edit-account` do WooCommerce e mostra
`account_first_name`, `account_last_name`, `account_display_name`, `account_email` e o grupo
`password_current` / `password_1` / `password_2`. Cada linha pode ser editada: o rótulo e a
descrição são gravados como override, e o botão de remoção oculta o controle com possibilidade de
restaurar. O identificador, o tipo, a validação e a gravação continuam pertencendo ao formulário
nativo. O override só entra no documento quando usado, para não transformar o inventário padrão em
uma cópia desnecessária. Ao selecionar `edit-address`, a interface informa que a página possui os
submenus reais de Cobrança e Entrega e deixa a integração desses campos para uma etapa posterior.

| Prova | Resultado |
|---|---|
| `UploadsRetentionTest` (3 testes novos) | um documento de cliente é guardado enquanto o cliente existe; fica órfão quando o cliente desaparece; uma linha `stored` sem cliente é órfã |
| `CustomerSectionFieldsTest` (2 testes novos) | um documento é recolhível numa superfície de cliente e `is_document()` distingue-o de um valor; `document_label()` nomeia o ficheiro e o tamanho, e diz «Documento enviado.» quando o nome se perdeu |
| `tests/Integration/FASE4-customer-upload-proof.php` (novo) | **21/0** contra a base e o diretório reais: a tabela tem `user_id` e a versão diz qual build a instalou; o upload de um cliente é aceite e fica `stored`, sem expiração e sem pedido; a página encontra-o **por cliente e campo**; conta na quota dele; **outro cliente recebe `not_yours`**; a **página renderizada** mostra o documento, a ligação com o token, o formulário `multipart` com `name="wccs_account_files[…]"`; sem ambiente protegido mostra a razão **sem** controlo e continua a mostrar o documento; o limpo vê a linha sem expiração; e o `sweep` remove-a, ficheiro e linha |
| `tests/browser/fase4-my-account-upload.mjs` (novo) | **12/0** com um POST real: o cliente abre a sua página, vê o campo e «Nenhum documento enviado.»; envia um PDF pelo formulário; a página volta a mostrar o documento e a dizer que foi enviado; o campo de texto da **mesma** submissão foi gravado; o documento continua lá num acesso novo; e o link protegido devolve o arquivo |
| `tests/Integration/support/seed-customer-document.php` (novo) | escreve e desfaz o cenário: documento publicado, cliente e observação real do ambiente; o diretório padrão fica fora do document root e `teardown` remove o cenário |
| `AccountSurfacesTest` (novo) | 5 testes: as superfícies oferecidas são as seguras; cada uma se explica; uma chave é conhecida e rotulada (e uma desconhecida é devolvida a si mesma, para a recusa poder nomeá-la); cada página recusada diz porquê; o logout é explicado como ligação |
| `SectionValidatorTest` (3 testes novos) | uma secção pode viver numa página nativa e não precisa de slug; uma página que não pode receber conteúdo é recusada pelo nome (`account_page_not_supported`) em todas as cinco; duas secções em páginas nativas não colidem por um slug |
| `FASE4-customer-upload-proof.php` (§7.4) | **+3 asserções**: a secção numa página nativa é aceite pelo servidor, **não regista endpoint nem entrada de menu**, e renderiza-se dentro da ação da página com o seu próprio formulário e o marcador de secção |
| `FieldsScreen.test.js` (1 teste novo) | o editor oferece as páginas da lista do servidor; escolher «Detalhes da conta» esconde o endereço da aba e mostra o título da secção; e o documento gravado fica com `presentation.account.page = 'edit-account'` |
| `composer check` | phpcs e phpstan sem erros; **517 testes, 1874 asserções** |
| Varredura de integração | **70 harnesses, 1593 asserções, 0 falhas** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**688 testes**) |

O harness faz a observação **real** primeiro e di-lo em nota. Nesta máquina o diretório privado fica
fora do document root (`protected=yes status=404`), portanto o upload é exercitado sem injeção de
estado. O cenário é apagado no fim.

## 6. Limites que ficam registados

- **A substituição mantém somente a versão atual.** O ficheiro novo é gravado com segurança e, depois
  do registro bem-sucedido, a versão anterior é removida; uma falha nunca apaga o documento atual.
- **O envio pelo painel de administração não existe.** O painel mostra o documento e oferece download
  quando a ação está autorizada; enviar ou substituir pela equipe continua sendo uma etapa posterior.
- **Um ambiente configurado manualmente precisa ser protegido.** `WCCS_PRIVATE_UPLOAD_DIR` permite
  escolher outro diretório, mas a verificação HTTP continua obrigatória e o upload permanece desligado
  se o servidor expuser os arquivos.
