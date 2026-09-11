# Registro de validação — WCCS-018

**Tarefa:** WCCS-018 · "Criar seções e ordenação"
**Fase:** F03 · Field Manager, seções e publicação
**Prioridade:** `required_v1` · **Dependências:** F01, F02
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Ordem por seção salva; mover por teclado e botões preserva foco."

**Resultado:** **199 testes de JS** (13 suítes, 39 novos) · **387 asserções de integração** em 14 provas, 0 falhas (19 novas) · **110 testes unitários PHP** (14 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Sections/SectionDefinition.php` | A seção do `§4`: id estável, título, descrição, posição, localização |
| `src/Domain/Sections/SectionLocations.php` | Os cinco conceitos de domínio do `§4` |
| `src/Domain/Sections/SectionValidator.php` | Valida a seção **e** a referência do campo a ela |
| `src/Domain/Schema/SchemaRepository.php` (alterado) | Integridade de seção na escrita do rascunho e na publicação |
| `resources/admin/app/components/SortableList.js` | Lista reordenável acessível: botões, anúncio, foco preservado |
| `resources/admin/app/components/IconButton.js` (alterado) | Passou a encaminhar `ref` |
| `resources/admin/app/schema/fieldOperations.ts` (alterado) | Operações de seção e de reordenação |
| `resources/admin/app/FieldsScreen.js` (alterado) | Seções com criar/renomear/remover/mover, campos reordenáveis |

## 3. Como cada palavra do aceite foi provada

### "Ordem por seção salva" — ✅

Ordem é uma afirmação sobre o **armazenamento**, então foi provada pela rota real e não por função pura: a prova envia uma ordem, lê o rascunho de volta, envia a ordem oposta e lê de novo. Uma troca de posições que só existisse na memória do cliente passaria por qualquer teste unitário e não sobreviveria a um recarregamento.

Duas metades, ambas verificadas:

- a ordem enviada é a ordem lida (`billing_document, billing_ie`);
- depois da troca, a ordem lida é a nova (`billing_ie, billing_document`).

As posições são **renumeradas** (10, 20, 30…) a cada movimento, em vez de trocadas. Trocar deixaria empates para trás e faria a ordem gravada depender da ordem em que as entradas por acaso aparecem no array.

### "mover por teclado e botões preserva foco" — ✅

19 testes de DOM, com um harness que **realmente reordena**. Um `jest.fn()` como espião teria deixado a ordem intacta, e uma reordenação que nunca acontece não pode perder foco — que é exatamente o bug que estes testes existem para pegar.

| Comportamento | Provado |
|---|---|
| Mover para cima e para baixo pelos botões | sim |
| Chegar ao fim da lista um passo de cada vez | sim |
| Operar o controle depois de tabular até ele | sim |
| Botões desabilitados nas pontas | sim |
| **Foco permanece no mesmo item ao descer** | sim |
| **Foco permanece no mesmo item ao subir** | sim |
| **Foco cai no controle oposto quando o usado fica desabilitado** | sim |
| Foco sobrevive a vários movimentos seguidos | sim |
| A nova posição é anunciada numa região viva | sim |
| O anúncio usa o nome do item, não o identificador | sim |
| Nada é anunciado antes de qualquer movimento | sim |

A linha do "controle oposto" é a que mais importa. Ao mover o segundo item para o topo, o botão "mover para cima" fica **desabilitado** — e focar um botão desabilitado é impossível. Sem o fallback, o foco cairia no `body` e o usuário de teclado perderia o lugar.

## 4. Duas lacunas fechadas

### 4.1 `sections` nunca era validada

O documento guardava seções e **nada as verificava**. Podia carregar uma seção sem título, com posição negativa, ou duas seções com o mesmo id — e o `§4` lista id, título, descrição, posição e localização como o que uma seção carrega.

### 4.2 A `section` de um campo nunca era verificada

Um campo podia pertencer a uma seção que não existia. Num checkout, isso é um campo configurado, gravado, e **renderizado em lugar nenhum** — o modo de falha mais silencioso desta fase.

O conjunto de destinos válidos é **as seções declaradas ∪ as cinco localizações de domínio**. As cinco são sempre válidas porque o `§4` as fixa como conceitos do domínio e porque um campo do WooCommerce adotado já pertence a uma; qualquer outra tem de ser declarada no mesmo documento.

## 5. Um bug real encontrado — e pela prova, não por revisão

A primeira versão de `validate_references` lia a chave `section` **crua** do array. Mas `FieldDefinition::from_array` atribui `'order'` quando a chave está ausente. Dois lugares, duas respostas para o mesmo facto: um campo sem `section` era **válido** para o modelo da definição e **inválido** para a verificação de referência, ao mesmo tempo.

A prova de integração pegou isso em duas frentes ao mesmo tempo — a prova da F01 passou a devolver **409 em vez de 422** (o rascunho inválido deixou de ser gravado, então a publicação seguinte colidiu de revisão) e a da F03-017 recusou um campo perfeitamente normal.

É a mesma classe de defeito que o **ADR-0007** descreve: duas fontes para o mesmo facto. Corrigido na raiz — a verificação resolve a seção pelo modelo da definição — com dois testes de regressão, incluindo o caso explícito de string vazia, que **não** é o mesmo que chave ausente e continua sendo recusado.

## 6. Uma decisão de acessibilidade, e a alternativa que rejeitei

A implementação intuitiva de "mover por teclado" é pôr `onKeyDown` no `<li>` com `Alt+↑`/`Alt+↓`. O lint de acessibilidade recusou, e **com razão**: um elemento não-interativo que anuncia a si mesmo como item de lista e depois se comporta como controle mente sobre o que é.

Rejeitei a alternativa por dois motivos:

1. **Não é necessária.** Os dois controles são `<button>` de verdade. Tabular até eles e pressionar Enter é o caminho de teclado, e é o mesmo caminho para todo mundo.
2. **O atalho criaria uma segunda classe de usuário.** Quem usa teclado teria dois comportamentos para aprender, um deles invisível.

O resultado é mais simples e mais honesto do que o atalho. Registro aqui, e não num ADR: é uma convenção de interface reversível, já exigida pelo `§428`, e transformá-la em ADR diluiria o conjunto — os ADRs 0005 a 0007 registram restrições de arquitectura, não escolhas de interação.

## 7. O que NÃO foi feito

- **Arrastar com o ponteiro (drag-and-drop).** O `§428` exige que o arrasto **tenha** alternativa acessível; o caminho acessível é o que foi entregue. O próprio `BACKLOG.json` declara o risco principal desta fase como *"Drag-and-drop e salvar automático podem alterar produção sem intenção"*, e o arrasto é justamente a interação que faz isso. Acrescentá-lo depois **não pode** remover os botões nem o anúncio.
- **Salvar automaticamente.** A tela continua salvando só quando o lojista pede. Pelo mesmo motivo.
- **Regras de seção e mapeamento de inserção.** O `§4` os lista como parte do `SectionDefinition`; as regras são o motor de condições (**F06**) e o mapeamento é do adapter (**F04**). Não foram modelados como chaves que nada lê — uma chave não lida é uma promessa que o código não cumpre.
- **Publicação e diff.** **WCCS-019**.
- **Desfazer local e ações em lote.** **WCCS-020**.

## 8. Observação honesta: o que foi e o que não foi observado

**Não abri um navegador.** Não há captura de tela da tela de campos com as seções.

O que sustenta a aceitação é o comportamento no DOM (199 testes em jsdom) e o armazenamento pela rota real. O que falta é a confirmação visual e, em particular, a experiência de mover um campo com foco real em um navegador — `jsdom` implementa `focus()` e `document.activeElement`, então o que foi provado é que o componente **pede** o foco ao elemento certo no momento certo, não que o navegador o entrega como esperado em toda circunstância. Isso pertence à WCCS-063.

## 9. Próxima tarefa

**WCCS-019 — "Implementar draft e PublishDiff"**. Aceite: *"Salvar draft não afeta a loja; publicação mostra diferenças e incompatibilidades."* É a tarefa que finalmente liga a publicação à interface: hoje a tela diz que o rascunho não alcança a loja, e o `SchemaRepository` já publica e guarda histórico desde a F01, mas nenhum botão chega lá.
