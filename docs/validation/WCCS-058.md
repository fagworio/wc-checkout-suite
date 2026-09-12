# Registro de validação — WCCS-058

**Tarefa:** WCCS-058 · "Concluir exemplo de novo tipo"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Código de associado aparece no picker, nos dois checkouts declarados e no pedido."

**Resultado:** **392 testes unitários PHP** · **1262 asserções de integração** em 53 provas, 0 falhas (18 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Fields/AbstractFieldType.php` | O contrato ganha a declaração que torna um tipo visível: `control` |
| `src/Domain/Fields/FieldTypeRegistry.php` | `control()` — o que um tipo declara que o desenha |
| `src/Checkout/Classic/ClassicAdapter.php` | Deixa de consultar só o seu mapa fechado |
| `src/Checkout/Blocks/{BlocksAdapter,BlocksRenderer}.php` | Um tipo declarado é `controlled`, e o payload leva o controlo |
| `resources/blocks/fields.js` | O componente é escolhido pelo controlo declarado |
| `examples/custom-field-type/` | O exemplo declara `control => 'text'` |

## 3. O que tornava o exemplo invisível

Os dois adaptadores decidiam o que conseguem desenhar a partir de **mapas fechados que eles próprios** possuem (`TYPE_MAP`/`DEGRADED` no clássico, `NATIVE_TYPES`/`CONTROLLED` no Blocks). Um tipo contribuído por outro plugin não está em nenhum deles — portanto o **picker oferecia-o e nenhum dos dois checkouts o conseguia desenhar**. Um contrato que existe só no papel.

A correção não foi acrescentar o tipo a listas internas (isso não é um SDK, é um caso especial), mas **deixar o tipo declarar por que controlo existente é desenhado**: `supports()['control']`. Os adaptadores passam a honrar a declaração em vez de consultarem uma lista que é deles.

E a declaração é uma **escolha entre controlos que existem** — `text`, `textarea`, `email`, `tel`, `select`, `radio`, `checkbox` —, não uma promessa de que tudo se desenha. Um `select` continua a ser um `select`; o que faz de um código um código de associado é a validação que o plugin contribuinte escreveu. Um tipo que não declara nada continua a ser desenhado por nada, e há uma asserção que o exige (o `text` do núcleo e um tipo inexistente devolvem ambos vazio).

## 4. As quatro superfícies, afirmadas

| superfície | asserção |
|---|---|
| **registro** | o tipo é registado **pelo hook publicado** (`wccs_register_field_types`), com `source = wccs-example` — a chave vem da constante do próprio exemplo, portanto a prova não pode passar nomeando um tipo que o exemplo não registou |
| **picker** | a rota que o picker lê (`/field-types`) oferece-o, com a label do exemplo, a origem `wccs-example` e o controlo declarado |
| **checkout clássico** | o adaptador aceita-o e o campo chega a `woocommerce_checkout_fields` como `text`, com a label em português |
| **checkout de blocos** | `BlocksAdapter::mode()` responde `controlled`, o renderer publica-o com `control: text`, e o bundle escolhe o componente por `field.control` |
| **pedido** | o valor submetido passa pelo `normalize()`/`validate()` **do exemplo** (`assoc-0042` → `ASSOC-0042`; `not a code!` → recusado), é escrito no pedido e lido de volta, e o ecrã do pedido desenha o controlo para o staff o corrigir |

## 5. Uma coisa que a prova confirmou em vez de assumir

O `ValueProcessor` **já chamava** `$type->normalize()` e `$type->validate()` — o contrato estava implementado. A primeira versão das asserções esperava o contrário do que o exemplo faz (esperava minúsculas onde ele faz maiúsculas, e um valor de teste que ele aceita), e as duas falhas eram da prova, não do produto: uma prova escrita de fora parece assim até ler o contrato que está a testar.

Uma terceira falha era real e do **documento publicado da prova**: sem secção publicada, o renderer dos blocos não sabe onde pôr o campo e reporta-o — o que é o comportamento correto, e o que obrigou a prova a publicar a secção.

## 6. O que NÃO foi provado, e porquê

**Um browser.** Os dois checkouts são afirmados através dos adaptadores e do payload que o bundle lê, não abrindo uma página de checkout, e nada foi escrito num formulário. O que o cliente vê com o componente desenhado é a WCCS-063.

**O exemplo ativado como plugin.** O ficheiro do exemplo é carregado **neste pedido**, não ativado no site: ativar um plugin é uma alteração ao site, fora da raiz deste plugin. O que fica provado é o contrato — carregar o ficheiro e registar pelo hook —, que é o que um SDK promete.

## 7. Fecho da F10

As **oito tarefas estão concluídas**: o editor do staff, a exibição ao cliente, os e-mails, a API de integração, os dois fluxos de privacidade, o export/import, o migrador limitado e o exemplo de novo tipo.

O **gate da fase** — *"Auditoria por papel/endpoint/e-mail confirma ausência de exposição indevida e leitura histórica"* — continua **aberto**, e agora por uma razão diferente das outras fases: não falta nenhuma superfície. O que falta é a **auditoria** que o gate nomeia, e ela é um artefacto por si: uma leitura, papel a papel e endpoint a endpoint, do que cada superfície expõe. As autorizações de cada uma estão afirmadas onde vivem (o painel do pedido, as três da API de integração, os fluxos de privacidade), mas **espalhadas por provas não são uma auditoria** — e é isso que fica nomeado em vez de dado por feito.

**Próxima tarefa:** **F11**.
