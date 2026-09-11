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

Documento correlato: [Modelo de ameaças](../operations/threat-model.md).

## Sobre o status "Aceito"

Os ADRs **0001 a 0004** não introduzem decisão nova: registram, de forma verificável e com a evidência
coletada em WCCS-001 a WCCS-004, decisões que o **`ROADMAP.md` já fixa** (`§13`, `§12`, `§2`/`§5`/`§9` e
`§1`). O responsável do produto determinou seguir o planejamento; é essa a base da aceitação.

Os **ADRs 0005 a 0008 têm origem diferente** e isso está declarado neles: são registros de **decisões de
implementação tomadas durante a execução**, não de diretrizes do planejamento. O ADR-0005 é o primeiro desse
tipo; o ADR-0006 fecha uma lacuna que o `§7` deixa aberta — ele exige que campos estruturais sejam protegidos,
mas não diz onde nem como; o ADR-0007 sustenta o `§428` fora do painel, determinando que os conjuntos fechados
de uma definição sejam publicados pela mesma classe que os exige; o ADR-0008 impede que uma limitação de
plataforma seja tratada como erro do lojista. O `§17` fixa os pontos de
quebra do produto, mas não diz como a prévia deve reproduzi-los dentro de uma moldura mais estreita que a
janela — a lacuna foi encontrada ao executar a WCCS-015. O ADR-0005 **não contraria** o `ROADMAP.md`: apenas
determina o mecanismo. Por ter origem distinta, ele é o candidato natural à ratificação explícita do
responsável do produto.

Três consequências práticas:

1. **Mudar qualquer uma destas decisões exige decisão humana explícita** e a substituição do ADR — não uma
   edição silenciosa deste diretório.
2. **A ratificação formal do responsável do produto** sobre os registros 0001–0004 permanece recomendada,
   mas não bloqueia as fases seguintes, porque nenhum rumo novo foi aberto.
3. **O ADR-0005 abriu precedente, e o ADR-0006 o confirma:** decisões de implementação que restrinjam
   trabalho futuro passam a ser registradas aqui, e não apenas as que o planejamento já fixava.

## Decisões ainda abertas (sem ADR)

Listadas em `docs/compatibility.json` (`open_decisions`). Nenhuma delas está no aceite de WCCS-005, portanto
o registro correto é "aberta", não "decidida":

| ID | Questão | Bloqueia |
|---|---|---|
| `PHP-BASELINE` | Manter o baseline 8.3 proposto pelo `§18` ou rebaixar o piso para 8.2, único runtime disponível no Devilbox? | F01 / WCCS-006, WCCS-010 |
| `CLASSIC-TEST-SURFACE` | Como exercitar F04 se a loja não tem página de Checkout Classic? Criar página ou trocar a 2755 é mudança fora da raiz do plugin. | F04 |
| `NODE-RUNTIME` | Build no container (Node 18, sem `pnpm`) ou no host (Node 22, com `pnpm` 10.15.1)? | F01 / WCCS-006 |
| `GIT-REPOSITORY` | Onde o código vive sob versionamento, dado que `/data/www/*` é ignorado pelo repositório do Devilbox? | WCCS-010 e WCCS-066 |

## Convenção

- Um ADR é imutável depois de aceito. Mudança de rumo gera um ADR novo que **substitui** o anterior.
- Todo ADR informa: contexto, decisão, consequências, alternativas rejeitadas e **como verificar
  conformidade** — este último é o que liga a decisão a um gate de fase verificável.
- Nenhum ADR pode contrariar o `ROADMAP.md` sem ADR de substituição explícito.
