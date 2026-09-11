# Registro de decisões arquiteturais (ADRs)

WC CheckoutSuite · Planejamento 1.0 · artefatos da tarefa **WCCS-005** (F00)

## Índice

| ADR | Decisão | Status | Fases de impacto | Exigido por |
|---|---|---|---|---|
| [ADR-0001](ADR-0001-storage-authority.md) | Autoridade única de persistência por origem de campo | **Aceito** | F04, F07, F10 | WCCS-005 (storage) |
| [ADR-0002](ADR-0002-private-upload-storage.md) | Armazenamento privado de arquivos enviados | **Aceito** | F08 | WCCS-005 (upload privado) |
| [ADR-0003](ADR-0003-cnpj-alphanumeric.md) | CNPJ como string alfanumérica | **Aceito** | F05, F07 | WCCS-005 (CNPJ alfanumérico) |
| [ADR-0004](ADR-0004-checkout-sidebar-excluded.md) | Checkout Sidebar excluído da versão 1.0 | **Aceito** | Todas | WCCS-005 (exclusão de Sidebar) |
| [ADR-0005](ADR-0005-preview-container-queries.md) | Prévia visual reflui por container queries, não por viewport | **Aceito** | F02, F03, F09, F10 | WCCS-015 (prévia visual) |
| [ADR-0006](ADR-0006-core-field-protection.md) | Proteção dos campos centrais no ponto único de persistência | **Aceito** | F03, F04, F07, F09 | WCCS-016 (campos core protegidos) |
| [ADR-0007](ADR-0007-closed-vocabularies.md) | Vocabulários fechados: o publicado e o exigido são o mesmo | **Aceito** | F03, F05, F06, F07, F09 | WCCS-017 (inspector por tipo) |
| [ADR-0008](ADR-0008-incompatibility-is-not-validation.md) | Incompatibilidade não é validação | **Aceito** | F03, F04, F07, F09 | WCCS-019 (draft e PublishDiff) |
| [ADR-0009](ADR-0009-version-control.md) | O plugin é versionado num repositório próprio, dentro do Devilbox | **Aceito** | Todas | Instrução do responsável do produto |
| [ADR-0010](ADR-0010-order-snapshot-preserves-history.md) | O snapshot do pedido torna a mudança de tipo segura para o histórico | **Aceito** | F04, F07, F10 | WCCS-024 (histórico de valores) |

Documento correlato: [Modelo de ameaças](../operations/threat-model.md).

## Sobre o status "Aceito"

Os ADRs **0001 a 0004** não introduzem decisão nova: registram, de forma verificável e com a evidência
coletada em WCCS-001 a WCCS-004, decisões que o **`ROADMAP.md` já fixa** (`§13`, `§12`, `§2`/`§5`/`§9` e
`§1`). O responsável do produto determinou seguir o planejamento; é essa a base da aceitação.

Os **ADRs 0005 a 0009 têm origem diferente** e isso está declarado em cada um: são registos de **decisões
tomadas durante a execução**, não de diretrizes do planejamento.

- **ADR-0005** — o `§17` fixa os pontos de quebra do produto, mas não diz como a prévia deve reproduzi-los
  dentro de uma moldura mais estreita que a janela. A lacuna foi encontrada ao executar a WCCS-015. **Não
  contraria** o `ROADMAP.md`: apenas determina o mecanismo.
- **ADR-0006** — o `§7` exige que campos estruturais sejam protegidos, mas não diz onde nem como. O ADR
  determina ambos.
- **ADR-0007** — sustenta o `§428` fora do painel, determinando que os conjuntos fechados de uma definição
  sejam publicados pela mesma classe que os exige.
- **ADR-0008** — impede que uma limitação de plataforma seja tratada como erro do lojista.
- **ADR-0009** — de natureza diferente das anteriores: não é uma decisão de implementação, é a decisão do
  responsável do produto sobre onde o código vive, e encerra a decisão aberta `GIT-REPOSITORY`.
- **ADR-0010** — o `§5` e o `§13` dizem que mudar o tipo de um campo exige uma migração, sem dizer se isso
  significa recusar a mudança ou garantir que ela não estraga o histórico. A WCCS-024 teve de escolher, e o ADR
  registra a escolha e o que ela obriga.

Por terem origem distinta do planejamento, os seis são candidatos à ratificação explícita do responsável do
produto.

Três consequências práticas:

1. **Mudar qualquer uma destas decisões exige decisão humana explícita** e a substituição do ADR — não uma
   edição silenciosa deste diretório.
2. **A ratificação formal do responsável do produto** sobre os registros 0001–0004 permanece recomendada,
   mas não bloqueia as fases seguintes, porque nenhum rumo novo foi aberto.
3. **O ADR-0005 abriu precedente, e os ADRs 0006 a 0008 e 0010 o confirmam:** decisões de implementação que
   restrinjam trabalho futuro passam a ser registradas aqui, e não apenas as que o planejamento já fixava.

## Decisões ainda abertas (sem ADR)

Listadas em `docs/compatibility.json` (`open_decisions`). Nenhuma delas está no aceite de WCCS-005, portanto
o registro correto é "aberta", não "decidida":

| ID | Questão | Bloqueia |
|---|---|---|
| `PHP-BASELINE` | Manter o baseline 8.3 proposto pelo `§18` ou rebaixar o piso para 8.2, único runtime disponível no Devilbox? | F01 / WCCS-006, WCCS-010 |
| `CLASSIC-TEST-SURFACE` | Como exercitar F04 se a loja não tem página de Checkout Classic? Criar página ou trocar a 2755 é mudança fora da raiz do plugin. | F04 |
| `NODE-RUNTIME` | Build no container (Node 18, sem `pnpm`) ou no host (Node 22, com `pnpm` 10.15.1)? | F01 / WCCS-006 |

`GIT-REPOSITORY` saiu desta tabela: foi encerrada por decisão do responsável do produto e está registada no
[ADR-0009](ADR-0009-version-control.md).

## Convenção

- Um ADR é imutável depois de aceito. Mudança de rumo gera um ADR novo que **substitui** o anterior.
- Todo ADR informa: contexto, decisão, consequências, alternativas rejeitadas e **como verificar
  conformidade** — este último é o que liga a decisão a um gate de fase verificável.
- Nenhum ADR pode contrariar o `ROADMAP.md` sem ADR de substituição explícito.
