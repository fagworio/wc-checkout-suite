# Registro de validação — WCCS-005

**Tarefa:** WCCS-005 · "Congelar decisões arquiteturais"
**Fase:** F00 · última tarefa da fase
**Prioridade:** `required_v1` · **Dependências:** nenhuma declarada
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "ADRs aprovadas para storage, upload privado, CNPJ alfanumérico e exclusão de Sidebar."

## 2. Entregue

| Artefato | Papel |
|---|---|
| `docs/adr/README.md` | Índice, status e convenção; lista as 4 decisões abertas que **não** fazem parte deste aceite |
| `docs/adr/ADR-0001-storage-authority.md` | Autoridade única de persistência por origem de campo |
| `docs/adr/ADR-0002-private-upload-storage.md` | Armazenamento privado de arquivos |
| `docs/adr/ADR-0003-cnpj-alphanumeric.md` | CNPJ como string alfanumérica |
| `docs/adr/ADR-0004-checkout-sidebar-excluded.md` | Exclusão do Checkout Sidebar da V1 |
| `docs/operations/threat-model.md` | 22 ameaças com mitigação e gate, 6 fronteiras de confiança, 5 riscos residuais aceitos |

Cada ADR segue o mesmo formato e termina em **"Como verificar conformidade"**, que liga a decisão a um gate
de fase verificável — sem isso um ADR é só prosa.

## 3. Por que o status é "Aceito"

Estes ADRs **não abrem rumo novo**. Registram, com a evidência de WCCS-001 a WCCS-004, decisões que o
`ROADMAP.md` já fixa em `§13` (storage), `§12` (upload privado), `§2`/`§5`/`§9` (CNPJ alfanumérico) e `§1`
(exclusão de Sidebar). A aceitação se apoia na instrução do responsável do produto de seguir o planejamento.

Está registrado no `README.md` que: (a) mudar qualquer decisão exige decisão humana explícita e um ADR
substituto; (b) a ratificação formal do responsável do produto é recomendada, mas não bloqueia as fases
seguintes, porque nenhum rumo novo foi aberto.

## 4. Valor agregado além de repetir o roadmap

Cada ADR incorpora a evidência de F00 e transforma decisão em restrição executável:

- **ADR-0001** — usa o fato verificado em WCCS-003 de que o prefixo real de meta é `_wc_other/` (e não o
  descontinuado `_wc_additional/`), e o fato de WCCS-002 de que existe placeholder `shop_order_placehold`
  e de que `wc_get_order()` devolve `Automatic\WooCommerce\Admin\Overrides\Order`. Sem isso, a "autoridade
  única" seria uma frase sem consequência técnica.
- **ADR-0002** — parte de dois fatos medidos: o servidor é **nginx 1.22.1** (logo `.htaccess` não protege
  nada) e `wp-content/uploads` **está sob o webroot**. Isso converte "armazenar fora do webroot" de
  preferência em requisito, com *fail-closed* obrigatório e autoteste de ativação.
- **ADR-0003** — registra explicitamente uma **ambiguidade do planejamento** (se os dois dígitos
  verificadores podem ser alfanuméricos: `§5` e `§9` divergem) e a encaminha para WCCS-028 contra a fonte
  oficial, em vez de escolher silenciosamente um dos dois.
- **ADR-0004** — usa a verificação de WCCS-004 de que o resumo do pedido **já existe** na página Blocks,
  para eliminar a confusão com a sidebar futura e impedir trabalho desnecessário.

## 5. O que NÃO foi feito

- **Nenhuma ameaça foi explorada.** O modelo de ameaças é plano de mitigação; a verificação adversarial é
  WCCS-061 (F12). Nenhuma alegação de invulnerabilidade ou de conformidade legal é feita.
- As **decisões abertas** (`PHP-BASELINE`, `CLASSIC-TEST-SURFACE`, `NODE-RUNTIME`, `GIT-REPOSITORY`) **não**
  foram resolvidas — não fazem parte deste aceite e ficam registradas como abertas.
- O `ROADMAP.md` não foi editado, inclusive onde a verificação de F00 mostrou que ele está incorreto
  (tipo `date`).

## 6. Gate de conclusão de F00 — **NÃO fechado**

O gate literal da fase é:

> "Nenhuma capacidade crítica permanece apenas presumida; limitações de Blocks viram regras do produto."

Situação real:

| Tarefa | Situação |
|---|---|
| WCCS-001 | ✅ cumprida |
| WCCS-002 | ✅ cumprida |
| WCCS-003 | ✅ cumprida |
| WCCS-004 | ⚠ **parcial** — "slots documentados" cumprido; "pagamento de sandbox" **bloqueado** (`SANDBOX-PAYMENT`) |
| WCCS-005 | ✅ cumprida |

**A primeira metade do gate não está satisfeita:** o comportamento de pagamento permanece
**presumido**, não verificado, porque não há gateway habilitado nem credencial de sandbox. Pagamento é
capacidade crítica; portanto F00 **não pode ser declarada concluída**.

**A segunda metade está parcialmente satisfeita:** a limitação de Blocks foi descoberta e registrada
(`date` não é tipo nativo em 11.1.0, tipos = `text`/`select`/`checkbox`), mas ainda **não virou regra do
produto**, porque isso exigiria corrigir o `ROADMAP.md §8` e o entregável de WCCS-036 — o que a instrução
atual proíbe.

**Encaminhamento:** F00 permanece aberta em WCCS-004. As fases seguintes **podem avançar** nos itens que não
dependem de pagamento, começando por F01 (WCCS-006), cujas tarefas não têm relação com gateway.

## 7. Próxima tarefa

**WCCS-006 — "Criar bootstrap e build"** (F01 · Core, schema e contratos de extensão).
Aceite: "Ativação segura sem Woo; assets compiláveis; namespace e i18n definidos."

Bloqueios parciais conhecidos para F01: `PHP-BASELINE`, `NODE-RUNTIME` e `GIT-REPOSITORY` continuam abertos
e afetam diretamente WCCS-006 e WCCS-010 (CI). WCCS-006 pode começar com decisões provisórias explícitas
(PHP 8.2 como piso validado, Node do host para o build), desde que registradas — não como se estivessem
resolvidas.
