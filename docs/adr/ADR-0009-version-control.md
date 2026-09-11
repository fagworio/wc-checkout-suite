# ADR-0009 — O plugin é versionado num repositório próprio, dentro do Devilbox

WC CheckoutSuite · decisão tomada na execução do pedido explícito do responsável do produto

**Status:** Aceito
**Fases de impacto:** todas (é o processo, não uma fase)
**Origem:** decisão do responsável do produto, que encerra a decisão aberta `GIT-REPOSITORY`

## Contexto

A decisão `GIT-REPOSITORY` estava aberta desde a WCCS-001 com uma pergunta concreta: *onde o código vive sob
versionamento, dado que o repositório do Devilbox ignora `/data/www/*`?*

O levantamento desta tarefa confirmou o problema e corrigiu um pressuposto:

- o plugin **não** tinha repositório próprio;
- o repositório do Devilbox, na raiz `/home/joaofagner/workfolder/devilbox`, ignora `/data/www/*` com uma única
  regra, e é ela que torna invisível **tanto o plugin como o `roadmap/`**;
- **não existia nenhuma regra `roadmap` em `.gitignore` algum.** O `.gitignore` do plugin nunca a teve. A
  crença de que havia uma lista para remover era incorrecta, e vale registá-lo: agir sobre o ficheiro errado
  teria "resolvido" o sintoma sem tocar na causa.

## Decisão

**O plugin é um repositório Git próprio, na raiz do plugin, com `main` como ramo inicial.**

Estar dentro de uma árvore que outro repositório ignora não é um problema: o repositório do Devilbox não vê
nada em `/data/www/*`, portanto o repositório aninhado não interfere com ele nem aparece no seu estado.

**O planeamento é versionado.** `roadmap/`, `docs/`, `examples/` e os dois ficheiros de lock fazem parte do
registo, não de saída derivada. As razões estão escritas no próprio `.gitignore`, para que a exclusão não seja
reintroduzida por engano:

1. os registos de validação citam o `ROADMAP.md` por secção;
2. o `BACKLOG.json` é a ordem em que o trabalho foi feito;
3. auditar o resultado só é possível contra o pedido que ele respondeu.

**O que fica de fora, e porquê:**

| Excluído | Razão |
|---|---|
| `build/` | gerado por `npm run build`; o ZIP de lançamento é que transporta os assets compilados (`§18`) |
| `node_modules/`, `vendor/` | resolvidos pela ferramenta; o ZIP não pode depender de o lojista correr `npm` ou Composer |
| caches (`.phpcs-cache`, `.phpstan-cache`, `.phpunit.result.cache`, `.npm-cache`) | estado local de ferramenta |

**A história começa no estado actual, não numa história reconstruída.** Não houve versionamento durante a
execução das fases F00 a F03. Fabricar commits retroativos produziria um registo falso do processo — datas,
autoria e ordem que não aconteceram. O primeiro commit diz o que é: uma importação, com a proveniência
declarada.

**As quebras de linha são fixadas em LF** por `.gitattributes`. O projecto desenvolve-se em Linux e constrói-se
num contentor Linux; sem isto, um editor noutra plataforma reescreveria cada linha e transformaria uma
alteração num diff do ficheiro inteiro.

## Consequências

- **A decisão `GIT-REPOSITORY` fica encerrada.** Deixa de bloquear a WCCS-010 (CI) e a WCCS-066 (pacote de
  lançamento).
- **O CI continua sem ter corrido.** O `ci.yml` é agora versionado e passaria a poder correr, mas correr exige
  um remoto e um executor. A afirmação em `docs/compatibility.json` muda de "não há repositório" para "há
  repositório, não há remoto".
- **Um checkout limpo não tem bundle.** `/build/` não é versionado, então clonar e activar o plugin exige
  `npm install && npm run build` antes de a tela de administração funcionar. É a consequência directa de não
  versionar saída gerada, e fica escrita aqui porque é o primeiro atrito que alguém vai encontrar.
- **O processo passa a evoluir por commits.** A partir daqui, cada tarefa do roadmap entra com o seu próprio
  commit, com o teste e a prova que a sustentam.
- **O repositório é local e não tem remoto.** Não há cópia fora desta máquina, e isso é uma limitação real, não
  uma escolha: publicar exige um destino que ninguém forneceu.

## Alternativas consideradas e rejeitadas

| Alternativa | Por que foi rejeitada |
|---|---|
| Deixar o repositório do Devilbox versionar o plugin | Ele ignora `/data/www/*` por decisão própria do Devilbox. Alterar isso é mudar a configuração de uma ferramenta de terceiros, fora da raiz do plugin. |
| Um repositório separado fora de `data/www` | O código teria de viver em dois sítios, ou o sítio passaria a ser um link para fora da árvore — mais frágil do que um repositório aninhado, que o Git suporta sem cerimónia. |
| Versionar `build/` para o checkout ser utilizável de imediato | Contradiz a decisão já documentada no `.gitignore` e no `§18`, e enche o histórico com diffs de ficheiros gerados que escondem as alterações reais. |
| Reconstruir a história com um commit por tarefa concluída | Datas, autoria e ordem seriam inventadas. Um registo falso é pior do que um registo que começa tarde. |
| Remover a regra `roadmap` do `.gitignore` | Não existia. Foi adicionada uma nota que explica porque é que o planeamento **não** é excluído, para que a regra não apareça mais tarde. |

## Como verificar conformidade

```bash
git rev-parse --show-toplevel     # a raiz do plugin, não o Devilbox
git ls-files roadmap | wc -l      # 7 — o planeamento está versionado
git ls-files | grep -c node_modules   # 0
git status --porcelain            # vazio
```

`docs/compatibility.json` registra a decisão como encerrada, e `docs/adr/README.md` deixa de a listar como
aberta.
