# Registro de validação — WCCS-019

**Tarefa:** WCCS-019 · "Implementar draft e PublishDiff"
**Fase:** F03 · Field Manager, seções e publicação
**Prioridade:** `required_v1` · **Dependências:** F01, F02
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Salvar draft não afeta a loja; publicação mostra diferenças e incompatibilidades."

**Resultado:** **230 testes de JS** (15 suítes, 31 novos) · **424 asserções de integração** em 15 provas, 0 falhas (36 novas) · **122 testes unitários PHP** (12 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Schema/SchemaDiff.php` | O que a publicação mudaria: campos, seções e ordem |
| `src/Domain/Checkout/AdapterCapabilities.php` | A matriz do `§8` como dado consultável, não prosa |
| `src/Domain/Schema/PublishIncompatibilities.php` | O que um checkout não vai honrar como está escrito |
| `src/Http/Admin/SchemaController.php` (alterado) | Rota `GET /schema/diff` com as três respostas |
| `resources/admin/app/components/PublishPanel.js` | Diferenças, validações e incompatibilidades |
| `resources/admin/app/components/RevisionsList.js` | Histórico e restaurar |
| `resources/admin/app/FieldsScreen.js` (alterado) | Estado de publicação, publicar e restaurar |
| `docs/adr/ADR-0008-incompatibility-is-not-validation.md` | A decisão que separa as três respostas |

## 3. Como cada palavra do aceite foi provada

### "Salvar draft não afeta a loja" — ✅

Provado sobre os **documentos armazenados**, não sobre o que a interface mostra:

1. um documento é publicado (revisão 1);
2. um rascunho diferente é gravado por cima (dois campos, um deles alterado);
3. o documento **publicado** é lido de volta e comparado com o que era antes.

A asserção é uma igualdade byte a byte. Um `write()` de rascunho que alcançasse o slot publicado passaria por
todos os testes unitários do repositório e mudaria uma loja a funcionar.

E a outra metade da mesma afirmação: o rascunho **contém** a mudança que a loja não tem — senão "não afeta a
loja" seria satisfeito por um rascunho que não guardasse nada.

### "diferenças" — ✅

| Asserção | Resultado |
|---|---|
| A alteração de um campo existente é reportada | `billing_document` |
| O campo novo é reportado como adição | `billing_extra` |
| A mudança nomeia **os dois lados** | `label: CPF → Documento` |
| As duas revisões comparadas são nomeadas | `published=1 draft=2` |
| O relatório não está vazio | `total=2` |

Uma decisão de desenho que o diff defende: **uma troca de posições é uma reordenação, não uma mudança por
campo abaixo dela**. Mover o primeiro de vinte campos renumera dezanove posições e continua a ser uma decisão
só. Um teste afirma exactamente isso (`total_changes === 1`).

### "incompatibilidades" — ✅

A palavra que dava mais trabalho para implementar honestamente, porque nada no código dizia o que ela
significava. Duas famílias, ambas verificáveis:

**1. Um tipo que o adapter não renderiza sozinho.** A resposta do Block não é opinião: a **WCCS-003** verificou
contra o WooCommerce 11.1.0 instalado que a API de additional fields regista exactamente `text`, `select` e
`checkbox` — e que `date`, que o `§8` lista, **não** está entre eles. A razão citada na interface é essa
verificação.

| Adapter | Resultado |
|---|---|
| Clássico | **0 incompatibilidades** — renderiza por hooks que o plugin possui |
| Blocks, campo `date` | 1 entrada, nível `component`, com a razão verificada |

**2. Uma sobreposição central cujo campo do WooCommerce desapareceu.** A Suite guarda uma sobreposição, não
uma cópia (ADR-0001), então um campo removido por outro plugin deixa a sobreposição a apontar para nada. É
exactamente a deriva que o ADR-0001 escolheu poder detectar. Provado filtrando o inventário e confirmando que a
entrada aparece — e que **desaparece** quando o campo volta.

Uma regra de honestidade que ficou explícita: a segunda família **só** é reportada quando o inventário foi
realmente lido. Um inventário ilegível não é prova de que todos os campos centrais desapareceram.

### A distinção entre as três, que é o que dá valor às três

| Afirmação | Provado |
|---|---|
| Um rascunho **válido** pode ter incompatibilidades | `valid=true total=1` |
| Uma validação **bloqueia** a publicação | botão desactivado, com a razão |
| Uma incompatibilidade **não bloqueia** | botão activo, com o aviso |
| As duas aparecem com textos diferentes | "do not block publication" vs "cannot be published yet" |

Sem esta separação, "incompatibilidades" seria só uma segunda palavra para erros — e bloquear a publicação por
uma limitação da plataforma que a Suite ainda vai resolver transformaria cada lacuna do Blocks numa parede para
o lojista. Ver `ADR-0008`.

## 4. Publicar e restaurar

| Asserção | Resultado |
|---|---|
| Publicar um rascunho válido é aceite | 200 |
| O relatório fica **vazio** depois de publicar | `total=0` |
| A publicação entra no histórico | 2 revisões |
| O histórico não vaza os documentos armazenados | `revision, published_at, published_by, hash` |
| Revisão anterior pode ser publicada de novo | 200, nova revisão |
| O histórico **cresce** em vez de perder uma entrada | `3 → 4` |
| A revisão restaurada continua lá, intacta | sim |

Publicar continua a ser **um único POST com compare-and-swap**; o relatório é uma leitura e não publica nada.
Uma rota de leitura que publicasse seria a forma mais fácil de alterar uma loja por engano.

## 5. Dois defeitos meus, encontrados pela própria prova

### 5.1 A prova morria antes da limpeza e acumulava estado

`count( $wccs_before_restore )` sobre uma variável que **já era um inteiro** — um `TypeError` que matava o
harness depois da secção 7. A consequência foi mais interessante que o erro: as secções 8 e 9 nunca corriam,
a limpeza nunca acontecia, e o rascunho de cada execução era herdado pela seguinte. O sintoma que apareceu
primeiro foi uma secção **anterior** a falhar, por um motivo que não tinha nada a ver com ela.

Corrigido, e a prova passou a ser verificada como **idempotente**: duas execuções seguidas dão 36/36 e deixam
`wccs_* = 0`. Um harness que só passa na primeira execução não é um harness.

### 5.2 A prova não carregava o documento como um cliente carrega

Ao gravar um rascunho, eu enviava apenas o campo que me interessava. O cliente real envia o documento inteiro.
A diferença apareceu como um `core_field_removed` correcto: enviar só um campo **remove** todos os outros,
incluindo as sobreposições centrais. A prova passou a construir cada rascunho a partir do documento publicado —
que é o que a interface faz — e o guard deixou de ter razão para recusar.

Vale registar porque é o segundo harness desta fase a apanhar a mesma família de erro: **o estado é herdado
entre secções**, e uma prova que não o carrega explicitamente está a testar o que sobrou, não o que escreveu.

## 6. Uma asserção de uma fase anterior reescrita

A prova da F02-015 afirmava "o conjunto de rotas de schema continua em quatro" — a forma honesta de dizer "a
prévia não acrescentou rota nenhuma" enquanto quatro era tudo o que havia. A F03 acrescentou rotas legítimas (o
catálogo do picker e agora o relatório de publicação), e uma contagem deixou de exprimir a intenção.

Foi reescrita sem a enfraquecer: afirma que o controlador publica **exactamente** os caminhos que declara como
constantes, e que nenhum deles renderiza a prévia no servidor. Uma contagem não diz de onde veio uma quinta
rota; isto diz.

## 7. O que NÃO foi feito

- **Desfazer local de edição.** O `§428` mantém-no separado do histórico de publicação, e continua a ser a
  **WCCS-020**. Nada aqui se apresenta como se fosse isso.
- **Aplicar o schema ao checkout.** O relatório diz o que um adapter faria; quem o faz é a **F04**.
- **Os componentes da Suite para o Block checkout.** A lista de incompatibilidades **é** a lista do que a
  **F07** tem de entregar. Não foi maquilhada para parecer menor.
- **Publicação automática.** Não existe, deliberadamente: o risco principal declarado da fase é
  "drag-and-drop e salvar automático podem alterar produção sem intenção".
- **Nome do ficheiro de configuração e contexto Classic/Blocks no topo.** O `§428` pede ambos no cabeçalho. O
  contexto é reportado *por adapter* no relatório; escolhê-lo como definição guardada é da F09.

## 8. Observação honesta: o que foi e o que não foi observado

**Não abri um navegador.** Não há captura de tela do painel de publicação.

O que sustenta a aceitação é o comportamento no DOM (230 testes) e o armazenamento pela rota real. O que falta
é a confirmação visual de um painel que mostra três listas de naturezas diferentes — e essa é precisamente a
parte em que o desenho pode falhar de um modo que nenhum teste apanha: três listas podem ser *correctas* e
ainda assim ilegíveis.

## 9. Próxima tarefa

**WCCS-020 — "Criar estados e ações em lote"**, a última da F03. Aceite: *"Vazio, erro, rede, conflito e
permissão tratados; desfazer local e revisão separados."* É a tarefa que fecha o gate da fase: o lojista monta
e publica um formulário completo, restaura revisão e não perde trabalho em conflito.
