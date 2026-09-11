# Registro de validação — WCCS-017

**Tarefa:** WCCS-017 · "Criar inspector por tipo"
**Fase:** F03 · Field Manager, seções e publicação
**Prioridade:** `required_v1` · **Dependências:** F01, F02
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Máscaras, opções, descrição, largura, storage e visibilidade somente onde suportados."

**Resultado:** **160 testes de JS** (12 suítes, 26 novos) · **368 asserções de integração** em 13 provas, 0 falhas (28 novas) · **96 testes unitários PHP** (12 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/admin/app/components/FieldInspector.js` | O inspector: cinco grupos, controles gerados pelo `settingsSchema` |
| `src/Domain/Fields/DefinitionVocabulary.php` | Fonte única dos conjuntos fechados, lida pelo validador **e** publicada ao cliente |
| `src/Domain/Fields/DefinitionValidator.php` (alterado) | Regras de superfície: máscara, storage, visibilidade, política |
| `src/Domain/Fields/SchemaValidator.php` (alterado) | Passou a validar `items` e `minItems` |
| `src/Domain/Fields/FieldDefinition.php` (alterado) | Ganhou `description` |
| `src/Domain/Fields/Types/*` (alterados) | `options`, `allowedTags` e `allowedExtensions` declaram a forma dos seus itens |
| `src/Http/Admin/CatalogController.php` (alterado) | Publica máscaras e vocabulário |
| `docs/adr/ADR-0007-closed-vocabularies.md` | A decisão: o publicado e o exigido são o mesmo |

## 3. Como cada palavra do aceite foi provada

### "somente onde suportados" — ✅ (o coração da tarefa)

A frase é uma afirmação sobre o sistema inteiro, não sobre um painel. Um inspector que esconde um controle
enquanto o servidor aceita o valor por trás dele satisfaz a captura de tela e não o requisito. Por isso são
**três metades**, todas verificadas:

| Metade | Onde é provada |
|---|---|
| O painel mostra o que é suportado e opera | 26 testes de DOM |
| O painel **diz por que** uma superfície não aparece | idem — nunca some em silêncio |
| O servidor recusa o que o painel não oferece | 28 asserções, pela rota real |

A terceira tem a asserção mais importante da tarefa: **para cada valor publicado, o validador aceita; para
cada valor fora da lista, o validador recusa**. Sem ela, "somente onde suportados" seria uma promessa da
interface.

### "Máscaras" — ✅

| Situação | Resultado |
|---|---|
| Máscara num tipo mascarável (`text`, `textarea`, `tel`, `date`, `time`, `datetime`) | aceita |
| Máscara em `email` | 422 · `mask_not_supported` |
| Chave não registrada (`br.cpf`) | 422 · `unknown_mask` |
| Versão diferente da registrada | 422 · `mask_version_stale` |

O inspector oferece a máscara **e a versão** que ela está oferecendo, porque a definição guarda a versão
contra a qual foi configurada. Um tipo que não pode ser mascarado recebe uma linha dizendo isso, em vez de um
painel que perdeu uma linha.

**Escopo declarado:** o catálogo tem hoje duas máscaras genéricas. As brasileiras (CPF, CNPJ, CEP, telefone)
são da **WCCS-026** — verifiquei o `BACKLOG.json` antes de decidir, e adiantá-las aqui duplicaria trabalho e
misturaria escopos. Elas aparecerão no inspector **sem alteração no inspector**.

### "Opções" — ✅

Aqui havia uma lacuna real: `SchemaValidator` não conhecia `items`, então o único requisito sobre `options` era
"uma lista de 1 a 500 itens". Uma opção podia ser a string `"banana"` e passar.

A forma passou a ser **declarada** (`items` com `value` e `label`, ambos exigidos) e o validador a impõe
reusando a mesma rotina dos settings — exigidos e desconhecidos se comportam igual nos dois níveis. Cinco
casos provados: item que não é par, sem rótulo, com propriedade não declarada, lista vazia e lista correta.

`minItems` também estava declarado em `options` desde a F01 e **nunca era lido**: um requisito declarado que
nada exigia.

### "Descrição" — ✅

O `§7` exige descrições e o `§4` as repete na lista de propriedades, mas o `FieldDefinition` **não tinha onde
guardá-las**. A chave foi acrescentada, opcional e com padrão vazio, então um documento escrito antes dela
continua sendo lido corretamente. Provado ponta a ponta: o limite de 500 caracteres é recusado, e uma descrição
válida atravessa a rota de rascunho e volta.

### "Largura" — ✅

Os três seletores (desktop, tablet, mobile) nas 12 colunas, e a prova de que mudar um **não perturba os
outros** — o erro fácil aqui é o patch de layout apagar os outros dois viewports.

### "storage e visibilidade" — ✅

O `§4` avisa que isto não é escolha livre: *"um registro nativo que persiste automaticamente no cliente não
pode apresentar no admin a opção 'somente pedido' como se fosse equivalente"*. O inspector honra isso:

| Campo | Storage |
|---|---|
| Criado aqui | o escopo é escolhido entre três |
| Do WooCommerce | o controle fica **desabilitado**, com a razão ao lado |

E as regras de servidor que fecham a porta:

| Situação | Resultado |
|---|---|
| Escopo fora da lista (`redis`) | 422 · `unknown_storage_scope` |
| Sensibilidade fora da lista | 422 · `unknown_storage_sensitivity` |
| Escopo de storage num tipo que não guarda valor (`heading`) | 422 · `storage_scope_requires_value` |
| `public_api` num tipo que não guarda valor | 422 · `visibility_exposes_missing_value` |
| Público fora da lista (`twitter`) | 422 · `unknown_visibility_audience` |
| Público que não é booleano | 422 · `invalid_visibility_value` |

Um tipo que não guarda valor não recebe os controles de visibilidade **e** diz por quê — "não há nada para
mostrar em lugar nenhum".

## 4. O inspector é gerado, não escrito

Os controles do tipo vêm do `settingsSchema()` que ele declara: `type` escolhe o controle, `minimum`/`maximum`
viram atributos do input, `required` vira o marcador, `items` decide entre lista de textos e lista de pares.
Um tipo registrado por um plugin de terceiros ganha editor **sem nenhuma alteração no admin** — e a prova
confirma que os 21 tipos registrados são oferecidos, nenhum outro.

Quando um tipo não declara nada, o painel diz "este tipo não declara configurações próprias" em vez de mostrar
uma área vazia.

## 5. O que NÃO foi feito

- **O motor de condições.** O `§428` fixa seis grupos de inspector e as Condições são o sexto. Elas pertencem à
  **F06**, e a aba aparece só quando houver motor atrás dela (`hasConditions`) — construir uma aba que abre
  para o nada seria o placeholder que a WCCS-015 já me ensinou a não fazer.
- **A máscara em execução.** O inspector a *seleciona*; quem a aplica é a **WCCS-027** (IMask local).
- **Máscaras brasileiras.** **WCCS-026**, como explicado acima.
- **Ordenação e seções.** **WCCS-018**.
- **Publicação e diff.** **WCCS-019**. A tela continua salvando só o rascunho.
- **Desfazer local e ações em lote.** **WCCS-020**.
- **Avaliação de arquivo por tipo (retenção).** O `§320` pede retenção e política de privacidade para uploads.
  O inspector mostra os limites que o tipo declara e a nota de que o arquivo é privado, mas retenção é F08.

## 6. Observação honesta: o que foi e o que não foi observado

**Não abri um navegador.** Não há captura de tela da tela de campos nem do inspector.

O que sustenta a aceitação é o comportamento no DOM (160 testes em jsdom, incluindo qual controle aparece para
qual declaração), a validação pela rota real (a prova grava um campo totalmente configurado e lê a descrição e
a máscara de volta) e o bundle publicado. O que falta é a confirmação visual de que cinco abas com este volume
de controles são utilizáveis — isso pertence à WCCS-063, junto com o restante da revisão de acessibilidade.

Vale registrar um limite conhecido do que foi provado: os testes de DOM usam `fireEvent.change` nos campos
controlados, porque o inspector é controlado pelas props e um `type` caractere a caractere não substituiria o
valor. Isso exercita o caminho real do componente (um evento de mudança com o valor completo), mas **não**
exercita digitação real no navegador.

## 7. Próxima tarefa

**WCCS-018 — "Criar seções e ordenação"**. Aceite: *"Ordem por seção salva; mover por teclado e botões preserva
foco."* É também onde o `SectionDefinition` do `§4` — hoje uma lista livre no documento — ganha forma própria, e
onde a seção de um campo passa a ser escolhida em vez de herdada.
