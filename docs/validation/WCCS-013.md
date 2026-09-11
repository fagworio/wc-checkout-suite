# Registro de validação — WCCS-013

**Tarefa:** WCCS-013 · "Criar componentes básicos"
**Fase:** F02 · Design system e shell administrativo
**Prioridade:** `required_v1` · **Dependências:** F01, WCCS-011, WCCS-012
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Botões, diálogos, tabs, mensagens, badges e formulários têm teclado e foco."

**Resultado:**
- **41 testes de JS em 5 arquivos** — teclado, foco e ARIA executados num DOM real (jsdom), não verificados por leitura de código;
- **17 asserções PHP** estruturais complementares (build, escopo, tokens, suíte);
- **267 asserções de integração** em 9 provas, 0 falhas;
- **57 testes unitários PHP** / 322 asserções;
- gates: PHPCS exit 0 · PHPStan `[OK] No errors` · ESLint 0 · `tsc --noEmit` exit 0 · build OK.

## 2. Componentes entregues

| Componente | O que garante |
|---|---|
| `Button` | `<button>` nativo, variantes primária/secundária/perigosa, `aria-busy` em vez de desabilitar durante a ação |
| `IconButton` | **Recusa renderizar sem nome acessível** |
| `Dialog` | `<dialog>` nativo: contenção de foco, Escape e restauração de foco vêm da plataforma |
| `Tabs` | Padrão WAI-ARIA: roving tabindex, setas, Home/End, painel amarrado ao tab |
| `Notice` | `role="alert"` para erro/aviso, `role="status"` para informação, sempre com glifo + palavra |
| `Badge` / `StatusBadge` / `CompatibilityBadge` | Todo estado dito **por escrito**; o badge de compatibilidade exige o motivo |
| `Field` + `TextField`, `TextareaField`, `SelectField`, `CheckboxField` | Label, ajuda e erro ligados ao controle na árvore de acessibilidade |
| `ErrorSummary` | Contagem anunciada, cada erro como link para o campo, e foco no resumo |
| `EmptyState` | Exige descrição: tela vazia sem explicação é como o usuário fica preso |

## 3. Como cada palavra do aceite foi provada

**Teclado** — os testes usam `user-event` de verdade:

- botão: `Tab` até ele, `Enter` e `Espaço` disparam; desabilitado não dispara;
- tabs: setas movem seleção **e** foco, com wrap-around; `Home`/`End` vão aos extremos;
- campo de texto: `Tab` foca, digitar chama `onChange`;
- checkbox: clique dispara `onChange`.

**Foco** — asserções explícitas de `toHaveFocus()` no campo, no resumo de erros e na aba selecionada; e um teste que confirma que o resumo de erros **toma o foco** ao aparecer.

**Acessibilidade estrutural** — `aria-describedby` com os dois ids (ajuda + erro), `aria-invalid`, `aria-selected`, `aria-controls`/`aria-labelledby`, `aria-current`, `aria-busy`, `role="alert"`/`role="status"`, `tabindex` roving e nomes acessíveis em todos os controles de ícone.

**Nada depende só de cor** — os testes verificam que o glifo existe com `aria-hidden` e que a palavra está presente. O `CompatibilityBadge` só é considerado completo com o motivo ao lado.

## 4. Por que os testes de JS são a prova certa aqui

Uma verificação estática de CSS provaria que as regras existem, não que o teclado funciona. Como `@wordpress/scripts` já traz Jest, o preset do WordPress e jsdom, montei uma suíte de interação real: **41 testes que renderizam os componentes e os operam**, incluindo teclado. É a evidência mais forte disponível sem navegador.

## 5. Limites declarados desta prova

**jsdom 26 não implementa a API modal de `<dialog>`.** `showModal()` e `close()` não existem; só o atributo `open` é modelado. O `tests/js/setup.js` acrescenta um shim mínimo, com comentário explicando exatamente o que isso permite afirmar: **que o componente pede à plataforma para abrir e fechar nos momentos certos**. Contenção de foco, `::backdrop` e restauração de foco são comportamento nativo do navegador e **não** são verificados aqui — estão em **WCCS-063**.

## 6. Beco sem saída encontrado e decidido

Tentei tipar os componentes em JavaScript com `checkJs` e JSDoc. Isso funcionou para o domínio, mas **quebrou nos componentes**: o padrão `...rest` — encaminhar atributos nativos arbitrários ao elemento — **não é tipável por anotação JSDoc de propriedades**, porque as propriedades encaminhadas não existem na lista declarada.

Considerei quatro saídas e escolhi a que mantém o gate honesto:

| Opção | Veredito |
|---|---|
| Listar todos os atributos nativos um a um | Recusada: a API de um controle É a do elemento, uma lista fechada seria mentira |
| Marcar `props` como `*` em todos os componentes | Recusada: silenciaria também os erros reais que o gate já pegou |
| **Abrir a API apenas dos 4 controles que encaminham atributos**, mantendo anotação precisa nos componentes de conjunto fechado | **Escolhida** |
| Migrar tudo para TypeScript agora | **Adiada para F03**, onde o editor cresce e o custo se paga |

Está registrado no código, no docblock de `controls.js`: *"a closed list would be a lie"*. O `§18` prevê React/**TypeScript** no admin; a migração é a direção certa e está anotada como próximo passo, não como pendência esquecida.

## 7. Supressão de lint: uma só, justificada

`jsx-a11y/interactive-supports-focus` exige que elementos com papel interativo sejam focáveis. O `tablist` do padrão WAI-ARIA **não é um ponto de parada** — o foco vai para os tabs. A regra é falso positivo aqui, então apliquei `eslint-disable-next-line` com a justificativa na linha, em vez de contorcer o código com um `tabIndex` inútil.

## 8. O que NÃO foi feito

- **Nenhuma verificação em navegador.** Nenhum destes testes mede foco real, ordem de tabulação com `Shift+Tab` entre painéis, leitor de tela ou contraste renderizado. É **WCCS-063**.
- **Nenhum componente composto.** `FieldPicker`, `FieldRow`, `FieldInspector`, `SortableList`, `ConditionBuilder`, `MaskEditor`, `VisibilityMatrix`, `PublishDiff` e `RevisionList` (§17) dependem de domínio que ainda não existe; chegam com F03 em diante.
- **Nenhum uso real na tela.** Os componentes estão construídos, testados e no bundle, mas a tela ainda mostra apenas um `Notice` — não existe editor para eles renderizarem.
- **Nenhuma chamada REST** — `WCCS-014`. Nenhuma prévia — `WCCS-015`.

## 9. Próxima tarefa

**WCCS-014 — "Implementar client REST"** (F02). Aceite: *"Nonce, erro 403/409/422, retry seguro e estado não salvo tratados."* Os endpoints já existem desde a WCCS-008 e já devolvem 409 e 422; falta o cliente que os consome com nonce, repetição segura e proteção contra perda de trabalho não salvo.
