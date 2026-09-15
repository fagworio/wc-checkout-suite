# Destinos: Minha Conta e Perfil do cliente no admin

**Fase da especificação:** §0 (modelo e destinos) · **Documento:** `roadmap/ESPECIFICACAO-SECOES-WCCS.md` §2, §10, §14, §20
**Pergunta:** o destino `customer_profile` juntava duas superfícies diferentes — a página do próprio
cliente em Minha Conta e o painel que a equipa lê no perfil dele. A separação foi feita, e cada uma
passou a existir de verdade?

## 1. O que mudou

`customer_profile` deixa de existir e dá lugar a dois destinos:

| Destino | Superfície | Quem escreve |
|---|---|---|
| `customer_account` | A página autenticada do cliente dentro de Minha Conta (endpoint, entrada no menu, formulário) | O cliente, pelo formulário da página |
| `admin_customer` | O painel no ecrã de perfil do utilizador (`user-edit.php` / `profile.php`), que é onde o próprio WooCommerce edita os endereços do cliente | A equipa, pelo formulário do perfil |

As duas superfícies leem e escrevem **o mesmo armazenamento** (`CustomerFieldsService`,
`_wccs_customer_fields`): um valor escrito pela equipa é o valor que o cliente vê na sua página, e
não uma segunda cópia que passaria a ser a verdade conforme a última gravação.

`my_account` sobrevive apenas como *propriedade do campo* (`collection_surface`), que responde a
outra pergunta — onde o valor é recolhido — e não como destino.

## 2. O que o modelo recusa, e porquê

- **Um campo vinculado a uma superfície de cliente tem de guardar no cliente.** Nenhuma das duas
  tem um pedido em mãos: a página está fora de qualquer pedido e o painel é sobre o utilizador. Um
  campo que guardasse no pedido ofereceria um formulário que grava num sítio que ninguém fornece.
  A regra é de documento (`account_section_requires_customer_storage`), porque precisa do vínculo e
  da seção ao mesmo tempo.
- **O modo do vínculo é `edit` ou `view`** nas duas superfícies (`invalid_account_field_mode`).
  `view` desenha o valor e nada para submeter — nem na página (um `<div>`, não um `<form>`) nem no
  painel (uma célula com o valor, sem controlo).
- **A chave retirada é recusada pelo nome.** `customer_profile` e `my_account` produzem
  `ambiguous_destination`, com as substituições no erro, em vez de `unknown_destination`: eram duas
  superfícies com um nome só, e só o lojista sabe qual delas um vínculo guardado queria dizer.
  Escolher por ele publicaria a página do cliente à equipa, ou esconderia dela o que ela responde.
  A recusa acontece **na gravação do rascunho**, não só na publicação: um rascunho que carregasse a
  chave antiga não pode ser salvo como se estivesse tudo bem.
- **A apresentação de conta** (slug, rótulo de menu, ícone, posição) pertence a quem tem página, ou
  seja a uma seção oferecida em `customer_account`. Um painel no perfil não inventa um endereço.

## 3. A migração

O editor carrega o documento, encontra a chave retirada e **pergunta** antes de reescrever nada: um
diálogo com as duas superfícies, e a resposta reescreve os vínculos, a área de aprovação quando
nomeava a chave antiga, e as áreas das seções (uma seção oferecida na área retirada perderia o lugar
onde aparece). Os valores guardados, os identificadores e as seções não mudam.

## 4. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/CUSTOMER-admin-profile-panel-proof.php` (novo) | **24 passaram, 0 falharam** |
| `tests/Integration/F14-wccs-076-areas-proof.php` (a auditoria por área passa a cobrir o painel) | **25 passaram, 0 falharam** |
| `tests/Integration/ACCOUNT-my-account-sections-proof.php` | 30 passaram, 0 falharam |
| `tests/Integration/F14-wccs-071-destinations-proof.php` (oito destinos, todos com ações) | 20 passaram, 0 falharam |
| `tests/Integration/F14-wccs-073-section-areas-proof.php` (todas as áreas que desenham painel) | 12 passaram, 0 falharam |
| `composer check` | phpcs e phpstan sem erros; **447 testes, 1604 asserções** |
| `npx jest` | 42 suites, **655 testes**, 0 falhas |
| `npx tsc --noEmit`, `npm run lint:js`, `npm run build` | limpos |
| Varredura de integração | **67 harnesses, 1541 asserções, 0 falhas** |

**Na loja, por HTTP.** O painel foi exercido no ecrã real do WordPress, não só desenhado a partir da
classe:

- `GET user-edit.php?user_id=<cliente>` com sessão de equipa devolveu **200** com a seção, o título do
  vínculo (`Preferência de contacto (equipa)`), o valor guardado do cliente e o nonce do painel — e
  **nenhum controlo**, porque o vínculo publicado está em `view`: o caminho de leitura é o que o
  documento pediu.
- Com o vínculo em `edit`, o formulário do perfil foi submetido como o browser o submete (nonce de
  `update-user_<id>` e nonce do painel, gerados na sessão do cookie): **302** no redirecionamento do
  WordPress e o armazenamento do cliente passou a conter o valor escrito no painel
  (`Telefone e e-mail`). O documento publicado foi reposto pela semente canónica a seguir.

## 5. Limites que ficam registados

- **Ainda não há ações de ficheiro nas superfícies de cliente.** `download`/`view` de um documento
  pressupõem o ciclo de vida do ficheiro privado ligado ao cliente, que chega com o upload de Minha
  Conta (§12). Até lá os dois destinos declaram `show_metadata` e `view`.
- **O editor continua a usar áreas de trabalho** («Minha conta», «Perfil do cliente», «Pedido»,
  «Checkout»); a organização por destino, com a nomenclatura contextual do §2 e o modal de áreas
  removido, é a fase seguinte da especificação.
- **A apresentação do painel é a do documento**: a seção desenha-se no ecrã de perfil pela ordem do
  documento, com o título da seção quando `show_title` o permite, e os campos sob os títulos dos
  vínculos. Posição livre dentro do ecrã (antes/depois de que bloco) não é configurável — o §10 ainda
  não fixa esse contrato.
- **Não há migração automática.** Uma loja que atualize com a chave antiga fica sem essas superfícies
  até o lojista escolher; os valores continuam guardados no cliente e nada é apagado.
