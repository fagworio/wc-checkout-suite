# Registro de validação — WCCS-059

**Tarefa:** WCCS-059 · "Documentar hooks e contratos"
**Fase:** F11 · Portabilidade, SDK e documentação
**Prioridade:** `required_v1` · **Dependências:** F03, F07, F10
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Ordem, assinaturas, versão, depreciação e componente React demonstrados."

**Resultado:** **396 testes unitários PHP** (4 novos) · **1262 asserções de integração** em 53 provas, 0 falhas · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `docs/api/extension-contracts.md` | O contrato publicado, cláusula a cláusula |
| `tests/Unit/Docs/ExtensionContractsTest.php` | O guarda que impede o documento de divergir |
| `resources/blocks/index.js` | O registro de componentes que o documento promete |
| `resources/blocks/fields.js` | O componente contribuído tem precedência |

## 3. O guarda é o que faz o documento valer

Um documento que nomeia contratos é um documento que **diverge** — na primeira vez que um hook é acrescentado e ninguém se lembra de o escrever. O teste unitário lê os hooks publicados **do código** e exige que cada um esteja nomeado no documento; e lê os hooks nomeados **no documento** e exige que cada um exista. Um hook novo sem documentação falha aqui, e um hook documentado depois de removido falha aqui também.

Três defeitos do próprio guarda apareceram enquanto ele era escrito, e os três são a mesma lição em três formas:

1. a primeira versão tratava **nomes de opções** como hooks (qualquer constante cujo valor começa por `wccs_`), e acusava `wccs_custom_checkout` e `wccs_schema_` de não estarem documentados — passou a ler só constantes cujo **nome** contém `HOOK`, `ACTION` ou `FILTER`, que é a convenção que este código já seguia;
2. a segunda lia o **grupo 1** da expressão (`$constants[1]`, o nome da constante) em vez do valor, o que fazia **todos** os hooks documentados parecerem fantasma;
3. e a terceira acusava identificadores em texto corrido — o teste passou a ler só as linhas que mostram a chamada (`do_action(`/`apply_filters(`), porque é isso que distingue documentar um hook de mencionar um nome.

E o guarda encontrou um hook real que faltava: **`wccs_core_fields_inventory`**, publicado desde a F03 e nunca documentado.

## 4. As cinco cláusulas

| cláusula | como está no documento |
|---|---|
| **Ordem** | uma tabela com os sete momentos, de `plugins_loaded` a `admin_menu`, e o que acontece em cada um; e a ordem no cliente, onde o bundle publica o registro **antes** de desenhar e o script contribuinte declara `wc-checkout-suite-blocks` como dependência |
| **Assinaturas** | uma linha por hook, com a chamada e o que devolve — quinze hooks, sendo treze do plugin e dois filtros |
| **Versão** | seis contratos versionados e onde cada um vive, incluindo `contract_version()` por tipo e a versão do ficheiro de exportação |
| **Depreciação** | quatro regras, a primeira delas "nada é removido sem uma versão de aviso", com o aviso a viajar no código (`_deprecated_hook`) e não só no documento |
| **Componente React demonstrado** | o registro `window.wccsBlocksFields.register( key, component )`, publicado pelo bundle e documentado com o exemplo dos dois lados |

## 5. O componente React é código, não um parágrafo

O bundle publica `window.wccsBlocksFields` **antes** de desenhar qualquer campo, e `componentFor()` consulta-o **antes** dos componentes que traz. Um tipo que declara `control => 'custom'` e cujo componente não está registado **não é desenhado** — é reportado, porque um campo desenhado por ninguém é um campo que o cliente não vê.

Isto é o outro lado da WCCS-058: lá, um tipo contribuído passou a poder escolher **um dos controlos que existem**; aqui, pode trazer **o seu**. E não há dois registros: o mesmo `components` é consultado primeiro, portanto um componente contribuído tem precedência sobre a degradação que o bundle faria.

## 6. O que NÃO foi provado, e porquê

**Um componente contribuído a desenhar num browser.** O registro é publicado e consultado — afirmado no bundle e nos tipos — mas nada foi desenhado num browser: é a WCCS-063.

**Uma depreciação a acontecer.** Nenhum contrato deste plugin está depreciado; a política existe para que o primeiro o seja por escrito. Uma política não exercitada é uma política, e dizê-lo é mais honesto do que encenar uma depreciação para a poder afirmar.

**O exemplo a registar o seu componente.** O lado do servidor do exemplo está provado (WCCS-058); o ficheiro JS do exemplo que registaria o componente no cliente ainda **não existe** — fica nomeado. O que existe e está provado é o mecanismo e a sua documentação.

## 7. Fecho

Quatro das cinco tarefas da F11 estão feitas. O gate — *"exportar e importar em staging mantém IDs; plugin de exemplo adiciona tipo sem patch do core"* — está cumprido na segunda cláusula e **não exercitado como um todo** na primeira, que pede duas lojas.

**Próxima tarefa:** **WCCS-060**, a documentação do lojista.
