# Fase 16 — Usability refinement

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §24, §25, §29, §30 · **Fase 16**
**Gate:** «rodar tarefas definidas, medir falhas, corrigir nomenclatura, reduzir passos, ajustar empty states, melhorar previews, rever defaults.»

## 1. O que a fase encontrou, e o que ela não pode medir

O §25 mede usabilidade com **pessoas** que conhecem WooCommerce e não o código desta Suite: tempo
para localizar a função, quantidade de erros, quantidade de retornos, necessidade de explicação
externa, compreensão de destino/seção/campo, de perfil versus seção e de status versus automação, e
confiança ao salvar.

**Nenhuma pessoa foi observada nesta fase, e nenhum desses números é reportado.** Inventar um tempo
ou uma taxa de erro seria transformar uma medição que exige gente numa frase com aparência de dado —
o que é exatamente o que o §30.1 proíbe para a interface e valeria igualmente para a prova.

O que o §25 diz logo a seguir, no entanto, é uma pergunta sobre a *interface* e não sobre a pessoa:

> Se o usuário não responder corretamente, a interface ainda está ambígua.

E as seis perguntas pós-tarefa são respondíveis por um browser, porque são sobre o que está escrito no
ecrã. Três das quatro cláusulas que sobraram da fase também são: **reduzir passos** (mede-se em
interações), **corrigir nomenclatura** (mede-se no texto visível) e **rever defaults** (mede-se no que
a tela abre por omissão).

## 2. O que foi medido, e o que foi corrigido

### 2.1 As perguntas do §25 que a interface responde

| Pergunta | Onde a resposta está, e como |
|---|---|
| «Onde esse campo será preenchido?» | A lista é agrupada pela secção do destino («Cobrança», «Pedido»), e o título de cada secção é o que o checkout usa (§6.4). |
| «Onde esse valor aparecerá depois?» | Os destinos do campo são uma lista explícita no inspector, e é ela que decide: o ecrã diz que cada destino decide por si e que publicar um campo não o mostra em lado nenhum. |
| «Este status significa que o pedido está pago?» | O ecrã de status abre com «Um estado não cobra nada: quem cobra é uma transição de workflow com uma ação de pagamento autorizada», e tem um interruptor nomeado «Estado antes do pagamento». |
| «Quando a cobrança acontece?» | O ecrã de automação abre com «Uma automação muda estados e não cobra nada», e o passo 4 oferece **só** as estratégias que a loja executa — nenhuma opção inventada. |

As perguntas 3 («o que acontece se apagar esta seção?») e 6 («o cliente consegue acessar esse
arquivo?») não foram medidas num browser nesta fase. A primeira é respondida pelo diálogo de remoção,
que lista campos, links e aprovações afectados, e está provada onde vive
(`tests/js/schema/fieldOperations.test.js`, `sectionImpact`/`removeSectionWithDependents`, e a prova
de integração da Fase 3). A segunda é decidida por uma só classe, `DownloadPolicy`, provada em
`F14-wccs-074-file-permissions-proof.php` e nos fluxos de privacidade da fase 10. Ficam nomeadas como
não medidas em browser, e não como medidas.

### 2.2 Reduzir passos

A tarefa principal de cada tela está **visível sem abrir nada**, e a prova lê isso do DOM:

- Campos: «Adicionar campo» e «Vincular campo existente» no cabeçalho da tela;
- Status: «Novo estado»;
- Automação: «Nova automação»;
- Configurações: a tela **não** abre com uma ação, e diz porquê — é uma pergunta com duas respostas, e
  a prova afirma os dois casos em vez de exigir um botão onde não deve haver.

### 2.3 Nomenclatura

O código diz `ContainerDefinition`, `FieldBinding` e `containerWords()`; a interface **não** diz
nenhuma delas. A prova percorre as quatro telas e falha se `\bcontainers?\b`, `FieldBinding` ou
`FieldDefinition` aparecerem no texto visível — e cada destino usa a sua própria palavra para o que
guarda (Secção, Painel, Bloco, Página), que é a regra que o `containerWords()` existe para manter.

### 2.4 Defaults revistos

- **O checkout customizado abre desligado** (§15), e é a apresentação que muda, nunca os campos: a
  Fase 14 prova que ligar não altera campos, gateways nem totais.
- **`show_title` nasce falso** numa secção nova (§6.4), e o nome continua no admin mesmo com o título
  oculto no frontend.
- **A reserva de estoque nasce `não reservar`** (§13.2 passo 3): uma automação nova não guarda
  estoque sem o comerciante o pedir, e a Fase 13 prova que um pedido sem automação mantém a janela da
  própria WooCommerce.
- **Um status novo nasce «antes do pagamento»** quando o comerciante o diz, e a loja nunca o considera
  pago por outra extensão o acrescentar à lista da WooCommerce (§12.4).

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/browser/fase16-usability.mjs` (observador histórico) | **16/0** no cenário original: as quatro telas abrem com um título que diz o que decidem e com a ação principal visível (e as Configurações, sem ação, afirmada como tal); a lista de campos é agrupada pela secção que os preenche e o checkout da loja é oferecido por cima dela; o ecrã de status responde que um estado não cobra nada e nomeia o interruptor «Estado antes do pagamento»; o ecrã de automação responde que uma automação não cobra nada e o passo do pagamento só oferece o que a loja executa; e nenhuma tela mostra o vocabulário do código (`container`, `FieldBinding`, `FieldDefinition`) |
| `composer check` | phpcs e phpstan sem erros; **625 testes, 2158 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**840 testes**, 53 suites); build compila |
| Varredura de integração | **80 harnesses, 1856 asserções, 0 falhas** |

### 3.1 UX-008 — prova autenticada de persistência e navegação

Após a validação real no navegador, `tests/browser/ux008-account-persistence-flow.mjs` foi
executado com sessão administrativa temporária e contexto de navegador novo. O fluxo passou por:

`Painel → Pedidos → Downloads → Endereços → Detalhes da conta`

e, em seguida, executou:

`criar página → salvar → reload → criar campo → editar campo e página → salvar → reload → desativar → salvar → reload → reativar → salvar → reload → remover e confirmar → salvar → reload`.

Resultado: **18 verificações, 0 falhas, 0 `pageerror` e 0 erros REST/API**. O campo editado
permaneceu na página personalizada correta após o reload; a página permaneceu inativa e depois ativa
conforme cada save; e a remoção confirmou a ausência após o reload. A sessão administrativa
temporária foi destruída ao final do cenário.

## 4. Limites que ficam registados

- **Nenhum usuário foi observado.** Tempo para localizar a função, quantidade de erros, quantidade de
  retornos, necessidade de explicação externa, compreensão de perfil versus seção, de status versus
  automação, e confiança ao salvar **não foram medidos**. É a única cláusula desta fase que uma
  máquina não pode responder, e ela fica aberta em vez de estimada.
- **«Melhorar previews» não foi tocado** nesta fase. A prévia contextual do §6.10 — perfil, cenário,
  produto, estado de regras e larguras, com o mesmo schema do runtime — continua a ser o que a Fase 14
  registou; o que existe é a prévia administrativa atual e o botão «Abrir checkout real».
- **As perguntas 3 e 6 do §25 não foram medidas em browser**, pelas razões nomeadas na secção 2.1.
- **Os E2E obrigatórios do §24 mantêm o seu estado por fase**: cada um tem prova de integração na
  varredura, e o que depende de gateway homologado (E2E-02 parcial, E2E-10, E2E-12) continua limitado
  pela ausência de credenciais de sandbox, como as Fases 11 a 15 registaram.
- **A prova UX-008 não é uma medição de usabilidade humana nem uma aprovação visual**: ela valida
  persistência, navegação, lifecycle e erros do browser em sessão autenticada; não substitui
  observação de pessoas nem baseline de screenshots.
