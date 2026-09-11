# Registro de validação — WCCS-016

**Tarefa:** WCCS-016 · "Criar Field Picker e CRUD"
**Fase:** F03 · Field Manager, seções e publicação
**Prioridade:** `required_v1` · **Dependências:** F01, F02
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Criar/editar/duplicar/arquivar; campos core protegidos; busca por categorias."

**Resultado:** **134 testes de JS** (11 suítes, 55 novos nesta tarefa) · **340 asserções de integração** em 12 provas, 0 falhas (33 novas) · **84 testes unitários PHP** (12 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Fields/FieldCategory.php` | Categorias do picker, com rótulo legível derivado da chave para uma categoria inventada |
| `src/Domain/Fields/FieldTypeRegistry.php` (alterado) | Categoria como argumento de registro e um `catalogue()` agrupado |
| `src/Domain/Checkout/CoreFields.php` | Inventário ao vivo dos campos que o WooCommerce possui |
| `src/Domain/Schema/CoreFieldGuard.php` | A proteção, na comparação entre o documento armazenado e o proposto |
| `src/Domain/Schema/SchemaRepository.php` (alterado) | O guard passou a ser chamado em `write()` **e** em `publish()` |
| `src/Domain/Fields/DefinitionValidator.php` (alterado) | `validate_origin()`, a regra que impede reivindicar um id do WooCommerce |
| `src/Http/Admin/CatalogController.php` | As duas rotas de leitura que alimentam o picker |
| `resources/admin/app/schema/types.ts` | **Primeiro TypeScript do projeto**: os tipos do domínio, compartilhados |
| `resources/admin/app/schema/fieldOperations.ts` | CRUD puro: criar, editar, duplicar, arquivar, remover |
| `resources/admin/app/components/FieldPicker.js` | Busca por categorias, presets primeiro, campos do WooCommerce |
| `resources/admin/app/FieldsScreen.js` | A tela: lista por seção, ações, salvar rascunho |
| `docs/adr/ADR-0006-core-field-protection.md` | A decisão de onde e como a proteção é aplicada |

## 3. Como cada palavra do aceite foi provada

### "Criar/editar/duplicar/arquivar" — ✅

Quatro operações puras sobre o documento, com 31 testes de comportamento:

| Operação | Comportamento provado |
|---|---|
| Criar | Id próprio gerado a partir do rótulo, chave de integração derivada, campo ativo, posição após o último da seção |
| Editar | Rótulo muda; **id, chave de integração e origem são ignorados** mesmo quando enviados |
| Duplicar | Novo id (`_copy`, `_copy_2`…), rótulo sufixado, e **sempre `origin: custom`** — uma cópia de campo central não pode nascer imorredoura |
| Arquivar | `enabled: false`, com restaurar disponível |

Toda operação é **pura**: um teste serializa o documento antes, aplica as cinco operações e confirma que o
original não mudou. Um gerenciador que mutasse o documento no lugar faria a guarda de trabalho não salvo e o
descarte mentirem sobre o que mudou.

### "campos core protegidos" — ✅

Esta é a parte com risco real, e a que mais mudou o desenho. Ver `ADR-0006` para a decisão completa.

O inventário lê os **20 campos reais** desta loja em 4 seções, através de `woocommerce_checkout_fields` — não
de uma lista fixa, que ficaria errada quando outro plugin ou outra versão do WooCommerce mudasse os campos. O
`form-row-first` do WooCommerce é traduzido para 6 colunas, para que adotar um campo não mude silenciosamente
a largura dele.

Provado **pela rota real**, não só por teste unitário:

| Tentativa | Resultado |
|---|---|
| Remover `billing_first_name` | 422 · `core_field_removed` |
| Arquivar | 422 · `core_field_disabled` |
| Trocar o tipo para `hidden` | 422 · `core_field_type_changed` |
| Tornar opcional um campo obrigatório do WooCommerce | 422 · `core_field_requirement_relaxed` |
| Re-declarar como `custom` e depois desativar | 422 · `core_field_origin_changed` |
| Declarar `billing_email` (nunca adotado) como `custom` | 422 · `core_field_origin_required` |
| **Renomear, mover e redimensionar** | **200 — aceito** |

As duas últimas linhas são o que separa proteção de paralisia: o limite da regra foi afirmado, não só a regra.
Uma suíte que só verificasse recusas passaria alegremente por uma implementação que recusa tudo.

### "busca por categorias" — ✅

O picker é dirigido pelo servidor: a prova confirma que **os 21 tipos registrados são oferecidos e nenhum
outro**, que as 7 categorias **particionam** os tipos (cada um aparece exatamente uma vez) e que **todo preset
oferecido tem um tipo registrado** — oferecer um preset órfão seria oferecer um campo que o validador recusa.

Do lado do DOM, 21 testes cobrem: busca insensível a acento (`endereco` encontra "Endereço"), busca que
atravessa nomes de categoria, filtro por categoria, **composição** entre busca e filtro, presets oferecidos
antes dos tipos crus, e o estado "nada encontrado". A seção da WooCommerce ganhou nome acessível, porque
"os campos da loja não puderam ser listados" é uma mensagem diferente de "sua busca não encontrou nada".

## 4. Três defeitos reais encontrados — um deles derrubou o site

Nenhum veio do ambiente. Os três são falhas minhas, e o primeiro é o mais sério que este projeto produziu.

### 4.1 A leitura do inventário no boot derrubou o site inteiro

A primeira versão lia os campos do checkout durante `plugins_loaded`, para construir o validador. Nesse
momento a WooCommerce **ainda não construiu seu objeto de países**, e
`WC_Checkout::get_checkout_fields()` o desreferencia incondicionalmente:

```
PHP Fatal error: Uncaught Error: Call to a member function get_base_country() on null
  #0 WC_Checkout->initialize_checkout_fields()
  #1 CoreFields->checkout_fields()
  #5 Registries->core_field_ids()
  #6 Plugin.php(80): Plugin::boot('')
  #8 do_action('plugins_loaded')
```

O fatal derrubou o site, o admin **e o WP-CLI** — inclusive `wp plugin deactivate`, o que tornou o diagnóstico
mais lento: por alguns minutos o sintoma parecia ser do ambiente, não do código.

A causa de fundo é que `function_exists( 'WC' )` é verdadeiro a partir do momento em que o arquivo do plugin é
incluído, muito antes de a WooCommerce existir de fato. **A verificação de prontidão era a errada**, não a
chamada.

Corrigido em três camadas: `CoreFields` espera `did_action( 'woocommerce_init' )` e envolve a leitura em
`try/catch`, reportando indisponibilidade com motivo; `Registries::core_field_ids()` **não cacheia** uma leitura
indisponível, porque um `[]` memorizado desligaria silenciosamente a regra de origem; e o repositório passou a
ser construído dentro do callback de `rest_api_init`.

Um teste de regressão dedicado (`CoreFieldsReadinessTest`) usa um stub que **conta os acessos ao objeto de
checkout**: a asserção não é "não lançou exceção", é "o objeto nunca foi tocado antes da hora".

### 4.2 O cliente REST nunca incluía o namespace na URL

Latente desde a WCCS-014. `Assets::bootstrap_data()` publica `root = rest_url()`, que termina em `/wp-json` — e
o namespace era publicado ao lado, **sem nunca ser usado**:

```js
const base = String( root || '' ).replace( /\/+$/, '' );   // …/wp-json
```

O cliente pedia `…/wp-json/schema/draft`, e a rota registrada é `…/wp-json/wc-checkoutsuite/v1/schema/draft`.

Nenhum teste pegou porque **o fixture do teste embutia o namespace no `root`** — ou seja, o teste descrevia uma
configuração que o servidor nunca envia. Enquanto isso, a prova PHP afirmava, corretamente, que o `root` é
`rest_url()` e "não um caminho fixo". As duas metades estavam certas e a junção nunca foi testada.

Corrigido no cliente, com o fixture alinhado ao payload real e três asserções novas sobre a URL montada. O
gerenciador de campos inteiro dependia disso para funcionar contra o servidor.

### 4.3 Uma origem forjada podia ficar parada no rascunho

Encontrado pela prova de integração, não por revisão. A regra `core_field_origin_required` vivia apenas no
`DefinitionValidator`, que só roda na publicação — então declarar `billing_email` como campo próprio era aceito
na escrita do rascunho e recusado apenas depois.

O guard protege o que está **armazenado** como central; um id central armazenado como próprio escaparia dele. A
regra foi extraída para `DefinitionValidator::validate_origin()` e passou a ser aplicada também pelo repositório
na escrita do rascunho — a única regra de validação completa que um rascunho obedece, porque não é trabalho em
andamento: é a primeira metade de um contorno.

## 5. A migração para TypeScript começou, onde ela paga

A decisão adiada na WCCS-013 e reconfirmada na WCCS-014 era migrar na F03. Verificou-se primeiro que o build
**já suporta** `.ts`/`.tsx` (`resolve.extensions` do `@wordpress/scripts` e `@babel/preset-typescript` no preset
padrão), então não houve nenhuma configuração nova.

A migração começou pelos **dados**: `schema/types.ts` descreve os objetos que o admin lê e escreve, e
`fieldOperations.ts` é o CRUD sobre eles. É onde o custo do JSDoc era maior e o benefício imediato. A biblioteca
de componentes continua em JS/JSDoc por ora — ali a dor era inferência de JSX, que é uma migração separada — mas
os componentes novos já consomem os tipos por `import()` no JSDoc.

O caminho foi instrutivo: anotar a **tupla** do `useState` não funciona em JSDoc e faz o estado virar `never`;
o padrão que funciona é anotar o **argumento**. JSDoc `@param` também não alcança um arrow dentro de
`useCallback`, porque o inicializador é uma chamada e não uma função.

## 6. Observação honesta: o que foi e o que não foi observado

**Não abri um navegador nesta tarefa.** Não há captura de tela nem interação real com a tela de campos, e não
afirmo que a interface foi vista funcionando.

O que sustenta a aceitação é: o comportamento no DOM (134 testes em jsdom, incluindo busca, filtro, criação,
duplicação e proteção), a persistência pela rota real (a prova grava um rascunho, tenta destruí-lo e confirma o
422), e o bundle publicado. **O que falta** é a confirmação visual e a interação com teclado real, que pertencem
à WCCS-063 junto com o restante da revisão de acessibilidade.

Vale registrar também que a tela **não foi exercitada contra o servidor a partir do navegador**: a correção da
URL (§4.2) foi provada por teste, não por uma requisição real do navegador. É o próximo passo natural de
verificação manual.

## 7. O que NÃO foi feito

- **Nenhum inspector por tipo.** Editar cobre o que todo campo tem — rótulo e obrigatoriedade. As configurações
  que um tipo declara, com as máscaras e opções, são a **WCCS-017**.
- **Nenhuma ordenação nem gestão de seções.** Os campos são exibidos por seção em ordem de posição, mas mover
  um campo é **WCCS-018**.
- **Nenhuma publicação nem diff.** A tela salva o rascunho e diz que ele não alcança a loja. Publicar, revisar
  diferenças e restaurar revisão é **WCCS-019**.
- **Nenhum desfazer local nem ação em lote.** **WCCS-020**.
- **Nenhum campo foi aplicado ao checkout.** O schema é armazenado; quem o aplica é F04.
- **Nada de upload, máscara ou validação de valor foi tocado** — fases posteriores.
- **Nenhum uso real de `IMask`**, como o `§9` exige que seja local e versionado; fases posteriores.

## 8. Próxima tarefa

**WCCS-017 — "Criar inspector por tipo"**. Aceite: *"Máscaras, opções, descrição, largura, storage e
visibilidade somente onde suportados."* É a tarefa em que o `settingsSchema()` de cada tipo — já publicado
desde a WCCS-007 e já enviado ao cliente pelo catálogo — finalmente vira interface, e onde a decisão do ADR-0006
sobre o que pode mudar num campo central será exercida de novo.
