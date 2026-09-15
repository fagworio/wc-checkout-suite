# Salvamento único: «Salvar alterações»

**Fase da especificação:** §1 (salvamento) · **Documento:** `roadmap/ESPECIFICACAO-SECOES-WCCS.md` §16
**Pergunta:** o lojista passou a ter **uma** ação que grava e põe a loja na revisão nova, sem
«Salvar rascunho», «Revisar publicação» nem «Publicar alterações» na interface — e o contrato do
servidor continua a proteger o que a loja corre?

## 1. O que mudou

| Antes | Agora |
|---|---|
| [Salvar campos] → grava o rascunho → [Revisar publicação] abre o painel → [Publicar alterações] | **[Salvar alterações]** — grava o rascunho e publica, na mesma ação |
| O ecrã dizia «A loja continua a correr a revisão publicada.» | «Alterações salvas com sucesso.» com **Ver checkout** e **Abrir em Minha Conta** |
| Um `PublishPanel` com estatísticas, diferenças e avisos | Sem painel: o que ele dizia é decisão do servidor, e a recusa continua a chegar ao ecrã |

O `PublishPanel` e o seu teste saíram com o diálogo (código morto depois da mudança), e a
possibilidade de revisão de uma revisão anterior **fica**: o diálogo de Revisões continua a
restaurar uma revisão publicando-a de novo.

## 2. O que **não** mudou, e porquê

O contrato do servidor é o mesmo, e é isso que mantém a mudança segura:

- O **rascunho continua a ser o buffer de edição**. É contra a revisão dele que o
  compare-and-swap recusa a segunda escrita de dois editores ao mesmo tempo (409), e é dele que
  a publicação parte.
- **Publicar continua a ser a única escrita no documento que a loja corre** — validada,
  guardada pelo `CoreFieldGuard` e registada no histórico de revisões. Não há um segundo
  caminho para o documento vivo (o `/schema/update` saiu na recuperação anterior e não volta).
- As rotas REST (`/schema/draft`, `/schema/publish`, `/schema/diff`, `/schema/revisions`,
  `/schema/restore`) e os seus harnesses continuam iguais; o que mudou foi quem as chama e em
  que ordem.

## 3. Quando as duas escritas se separam

Uma ação com duas escritas pode falhar entre elas, e a interface diz o que aconteceu em vez de
escolher por conta própria:

- **A gravação falhou** (validação, conflito, rede): nada foi gravado e a recusa aparece no ecrã,
  com o código e a mensagem que o servidor deu.
- **A gravação passou e a publicação não**: o trabalho fica guardado e o ecrã diz que a loja
  ainda corre a revisão anterior, com a instrução de voltar a guardar. Dizer «salvo» seria uma
  mentira sobre a loja; recusar a ação toda deitaria fora trabalho que o servidor aceitou.

Duas correções vieram deste caminho, encontradas ao exercê-lo num browser a sério:

1. **Uma recusa era silêncio quando a instância do ecrã já não estava montada.** O `catch` do
   salvamento só reportava se o componente continuasse montado; um pedido recusado depois de a
   pessoa ter mudado de vista não dizia nada a ninguém. Passa a reportar sempre — o estado é do
   React e uma instância desmontada ignora-o sem partir nada.
2. **A publicação deixou de depender do ecrã que a pediu.** As duas escritas são uma ação: parar
   entre elas porque o ecrã foi substituído deixaria o trabalho guardado, a loja na revisão
   antiga e ninguém avisado — o único desfecho que este fluxo não pode aceitar.

## 4. Prova

| Prova | Resultado |
|---|---|
| `tests/js/screens/FieldsScreen.test.js` — «salva o documento inteiro e põe-no a funcionar numa ação» | passa: `saveDraft` **e** `publish`, com a revisão devolvida, e a mensagem de sucesso no ecrã |
| O mesmo ficheiro — «grava no rascunho e publica nas suas rotas, por essa ordem» (cliente real) | passa: `PUT /schema/draft` e depois `POST /schema/publish` |
| O mesmo ficheiro — «diz que a loja ainda corre a revisão anterior quando só a publicação falha» | passa |
| `tests/Integration/F03-wccs-019-publish-diff-proof.php` §8 (o pacote publicado) | 36/0: o pacote traz a ação única e a mensagem nova, e **não** traz «Revisar publicação», «Publicar alterações» nem os textos do painel |
| `tests/browser/f14-links-observation.mjs` (browser real, sessão de equipa) | **20/0**: a ação existe e está ativa depois de uma edição, as escritas são `PUT /schema/draft` → `GET /schema/draft` → `POST /schema/publish` → `GET /schema/diff` → `GET /schema/revisions`, o ecrã diz «Alterações salvas com sucesso.» com os dois links contextuais, e um recarregamento mostra o valor guardado |
| `composer check`, `npx tsc --noEmit`, `npm run lint:js`, `npm run build` | limpos (447 testes PHP) |
| Varredura de integração | 67 harnesses, 1541 asserções, 0 falhas |

## 5. Limites que ficam registados

- **O histórico de revisões continua a existir e a ser acessível.** A especificação não pede que
  ele desapareça, e restaurar uma revisão é uma escrita direta no documento publicado, validada
  como qualquer outra. Se a loja quiser esconder essa possibilidade, é uma decisão de produto, não
  uma consequência desta mudança.
- **Não há confirmação antes de publicar.** Publicar deixou de ser um passo à parte, e portanto
  deixou de haver um momento em que a loja pergunta «tem a certeza?». O que resta é a validação do
  servidor: uma configuração que ele recusa não chega à loja.
- **Os harnesses de F03 continuam a exercer o rascunho e a publicação como contrato** (escrita de
  rascunho que não toca no publicado, `diff`, `revisions`, `restore`). Eles descrevem o servidor, e
  o servidor não mudou; o que mudou foi o ecrã que os usa.
- **Textos de registos anteriores** (`docs/validation/WCCS-019.md`, `WCCS-032.md`) descrevem o
  painel de publicação como ele era na altura em que foram escritos. O contrato que eles provam
  — diferenças, incompatibilidades, três tipos de resposta — permanece.
