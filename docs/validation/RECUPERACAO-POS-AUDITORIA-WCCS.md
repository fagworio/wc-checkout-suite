# Recuperação depois da auditoria — uma passagem, um caminho de escrita

**Fases:** F01, F02, F03, F09, F10, F14 · **Tarefas:** WCCS-008, 013, 015, 019, 047, 055, 071–076
**Pergunta:** o que a auditoria apontou e o trabalho de 14/09 deixaram a meio volta a ser
verdade, e o destino `customer_profile` — que a auditoria chamou inerte — passou a ter
superfície?

## 1. De onde se partiu

Três documentos apontavam o caminho e ficaram no repositório: `roadmap/REVISAO-IMPLEMENTACAO-WCCS.md`
(14 achados, WCCS-AUD-01 a 14), `roadmap/AUDITORIA-FLUXO-WCCS.md` e `roadmap/ANALISE-MINHA-CONTA-SECOES-WCCS.md`.
O trabalho de 14/09 entregou correções reais (F-3, valores nativos) e, no mesmo movimento,
duas mudanças que deixaram provas vermelhas e obsoletas:

1. **Um segundo caminho de escrita para o documento vivo.** `SchemaRepository::update_active()`
   e a rota `/schema/update` escreviam a revisão publicada a partir do ecrã do editor, e
   `publish()` espelhava o publicado de volta no rascunho — o que fazia o rascunho perder a
   revisão que o editor tinha em mãos. O passo de publicação desapareceu da interface, e o
   `PublishPanel` ficou código morto.
2. **Um oitavo destino, `my_account`**, declarado no vocabulário sem ninguém que o servisse:
   a interface oferecia uma área que nenhuma superfície desenhava.

O estado medido no início desta passagem: `F01-008` (38 asserções, conflito de revisão),
`F02-015` (saída 255 — o harness citava a rota removida), `F02-013` (tokens de design
indefinidos), `F03-019` (a publicação não existia no pacote), `F09-047` (apresentação presa
aos campos), `F14-071/072` (a contagem de destinos), `F03-017` e `F14-076` (deriva do
vocabulário).

## 2. O que foi corrigido, e como se prova

### 2.1 Um caminho de escrita: salvar é o rascunho, publicar é o que a loja corre

`/schema/update`, `update_active()` e o espelho publicação→rascunho saíram. O ecrã do editor
voltou a ter a superfície de publicação (o painel de revisão com «Publicar alterações»), que
é o passo que transforma o rascunho na revisão publicada.

| Prova | Resultado |
|---|---|
| `tests/Integration/F01-wccs-008-schema-repository-proof.php` | 38 passaram, 0 falharam |
| `tests/Integration/F02-wccs-015-preview-proof.php` (lista de rotas declaradas = rotas registadas) | 25 passaram, 0 falharam |
| `tests/Integration/F03-wccs-019-*.php` (o painel de publicação existe no pacote) | 36 passaram, 0 falharam |
| `tests/js/api/client.test.js` («não há operação que reescreva o documento publicado») | passa |
| `tests/js/screens/FieldsScreen.test.js` («grava no rascunho, e só lá») | passa |

### 2.2 `customer_profile` deixou de ser um destino inerte

Uma seção oferecida na área do perfil é, agora, uma página verdadeira de Minha Conta: o slug
vira endpoint do WooCommerce (`add_rewrite_endpoint`), o menu da conta ganha a entrada com o
rótulo e a posição configurados, o formulário valida com o mesmo `ValueProcessor` do checkout
e grava no cliente (`_wccs_customer_fields`), e o modo `view` desenha valores sem formulário.
O título e a ordem são os do **vínculo**, como em todas as outras áreas.

O documento é recusado quando a configuração não teria onde gravar: um campo vinculado a uma
página de conta que não declara armazenamento no cliente falha com
`account_section_requires_customer_storage` (regra de documento, onde o vínculo e a seção são
conhecidos ao mesmo tempo), e um `mode` que não seja `edit` nem `view` falha com
`invalid_account_field_mode`.

| Prova | Resultado |
|---|---|
| `tests/Integration/ACCOUNT-my-account-sections-proof.php` (novo, 30 asserções) | 30 passaram, 0 falharam |
| `tests/Integration/F14-wccs-076-areas-proof.php` (o perfil passa a ter superfície auditada) | 23 passaram, 0 falharam |
| `tests/browser/my-account-sections-flow.mjs` (cliente real, browser real) | 6 passaram, 0 falharam |
| `tests/Unit/Domain/Sections/SectionValidatorTest.php`, `tests/Unit/Domain/Fields/DefinitionValidatorTest.php` | passam |

**Um defeito que só o harness novo podia ver.** O valor do cliente era escrito na meta como
JSON sem `wp_slash()`: a API de metadados desfaz as barras invertidas que o próprio JSON usa, e
`manhã` voltava do armazenamento como `manhu00e3` — o texto do cliente corrompido, em silêncio,
em qualquer língua com acentos. Corrigido em `CustomerFieldsService` (payload slashed, como o
WordPress espera) e provado por comparação byte a byte no harness (`bin2hex`).

### 2.3 F-3 fechado: o painel do pedido desenha-se no ecrã de equipa

O achado F-3 dizia que o painel estava registado mas o callback nunca era invocado no ecrã
HPOS. Verificado agora nos dois sentidos:

| Prova | Resultado |
|---|---|
| `curl` autenticado a `admin.php?page=wc-orders&action=edit&id=…` | 1 `<div class="wccs-order-fields">`, 3 controlos (`documento_fiscal`, `observacoes_entrega`, `arquivo_autorizacao`), 1 nonce |
| `tests/browser/f14-admin-order-screen.mjs` | 4 passaram, 0 falharam |

### 2.4 O painel é desenhado uma vez, mesmo quando os dois caminhos disparam

No ecrã legado, o WooCommerce imprime o painel de dados do pedido — onde os hooks inline se
penduram — **dentro** do ecrã de meta boxes que também desenha a caixa. Sem um registo
partilhado, a mesma tabela aparecia duas vezes e cada campo era submetido duas vezes.
`OrderFieldsPanel::render()` passou a marcar o pedido ao desenhar, e é o primeiro caminho que
chega que fica com o painel; registar a caixa deixou de ser o sinal, porque o caso que os
hooks inline servem é exatamente o de uma caixa registada que nunca é desenhada.

`tests/Integration/F14-native-values-proof.php` prova as duas ordens de disparo (inline
primeiro e caixa primeiro): 14 passaram, 0 falharam; o browser confirma `panels=1 nonces=1`.

### 2.5 A retenção da análise deixou de se poder repetir

`ReviewStatus::apply()` não tinha guarda de saída: qualquer transição para um estado de
pós-pagamento — a própria aprovação da equipa, o envio, o reembolso — voltava a ler o pedido
como novo e a reter outra vez, desfazendo em silêncio a decisão de quem analisou. O pedido
passa a levar `_wccs_review_held` quando entra no estado de análise, e não volta a ser retido.
`on-hold` deixou de contar como pós-pagamento: é a loja à espera de transferência ou cheque, e
um pedido por pagar não sai do fluxo de pagamento que o cliente conhece — é retido quando o
pagamento chega e a loja o passa a `processing`.

`tests/Integration/F14-wccs-075-approval-flow-proof.php`: 23 passaram, 0 falharam. O hook
`hold_classic`, que o gancho de transição substituiu e que já ninguém chamava, saiu.

### 2.6 A privacidade cobre o que a conta guarda

O export e o apagamento liam só pedidos: um valor guardado na conta não tem pedido por onde ser
encontrado, e a pessoa ouvia que os seus dados não existiam enquanto a loja os tinha.
`DataSubjectCustomer` responde pela conta, o export ganha o grupo «Checkout fields (customer
account)» com a mesma definição de dado pessoal e o mesmo formatador, e o apagamento remove os
valores pessoais da conta preservando os restantes. A política sugerida à loja dizia «guardado
com o pedido, não na sua conta» — passou a dizer as duas coisas.

`tests/Integration/F10-wccs-055-privacy-flows-proof.php`: 28 passaram, 0 falharam.

### 2.7 Tokens de design

O tom de aviso não existia no vocabulário e dois ficheiros usavam cores literais. Os tokens
`warning_subtle`/`warning_border` entraram nos dois temas, o véu do modal passou a
`elevation.scrim` (um valor composto, como as sombras, e não uma cor hexadecimal), e a borda do
aviso ficou isenta com justificação escrita, ao lado de `border_decorative` — o mesmo tipo de
decisão, tomada no mesmo sítio.

`tests/Unit/Design/DesignTokensTest.php`: passa (cor opaca, par medido, decisão registada).

## 3. Portões

| Portão | Comando | Resultado |
|---|---|---|
| PHP | `composer check` (phpcs + phpstan + phpunit) | sem erros; 444 testes, 1595 asserções |
| JavaScript | `npx jest` | 42 suites, 652 testes, 0 falhas |
| Tipos | `npx tsc --noEmit` | limpo |
| Estilo JS | `npm run lint:js` | limpo |
| Pacotes | `npm run build` | compila |
| Varredura de integração | `bash /tmp/sweep2.sh` | **66 harnesses, 1515 asserções, 0 falhas, 0 saídas não-zero** |

## 4. O que esta passagem não fez

- **Upload numa página de conta.** O §12 da especificação pede upload privado sem pedido e a
  referência histórica no pedido que usar o documento. `MyAccountSections` ainda não oferece
  tipos de ficheiro, e a razão está escrita no próprio código: o ciclo de vida do ficheiro
  privado da conta não existe.
- **Usar uma página existente da conta** (Detalhes da conta e afins) em vez de criar uma nova.
- **Separar os destinos**: feito depois desta passagem. `customer_profile` deu lugar a
  `customer_account` (a página do cliente em Minha Conta) e `admin_customer` (o painel no perfil do
  utilizador, para a equipa), com recusa pelo nome da chave retirada e escolha no editor. Ver
  `docs/validation/DESTINOS-MINHA-CONTA-E-PERFIL-ADMIN.md`.
- **Exclusão que só desvincula**, `show_title` por omissão no checkout, e o editor por destino
  sem o modal de áreas.
- **A rota `/schema/update` não voltou.** Se um dia voltar, volta com o mesmo problema: dois
  caminhos para o documento que a loja corre.

## 5. Como reproduzir

```bash
# portões
docker exec -u devilbox devilbox-php-1 bash -lc 'cd /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite && composer check'
npx jest && npx tsc --noEmit && npm run lint:js && npm run build

# varredura de integração (66 harnesses)
docker exec -u devilbox devilbox-php-1 bash -lc 'bash /tmp/sweep2.sh'

# a página de conta, num browser
npm run test:e2e:my-account

# o ecrã do pedido, como equipa
wp eval-file tests/Integration/support/admin-session.php create      # imprime os cookies
WCCS_ORDER=<id> WCCS_COOKIE=<nome=valor> WCCS_AUTH_COOKIE=<nome=valor> \
  node tests/browser/f14-admin-order-screen.mjs
```
