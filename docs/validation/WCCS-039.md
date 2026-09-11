# Registro de validação — WCCS-039

**Tarefa:** WCCS-039 · "Implementar matriz no admin"
**Fase:** F07 · Checkout Blocks nativo e tipos próprios
**Prioridade:** `required_v1` · **Dependências:** F04, F05, F06
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Limites de core, largura, seção e tipo visíveis antes de publicar."

**Resultado:** **336 testes unitários PHP** (9 novos) · **906 asserções de integração** em 35 provas, 0 falhas (12 novas) · **557 testes de JS** em 32 suítes (3 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Checkout/CapabilityResolver.php` | Resolve, por campo, os quatro limites e o motivo de cada um |
| `src/Http/Admin/SchemaController.php` | Publica a matriz no relatório de publicação |
| `resources/admin/app/components/PublishPanel.js` | Mostra a matriz ao lojista antes do botão |
| `tests/Integration/F07-wccs-039-capability-matrix-proof.php` | A matriz na rota real, sobre o rascunho real |

## 3. Não é o relatório de incompatibilidades com outro nome

A WCCS-019 já responde **se algo não será honrado**, no vocabulário dos avisos. Esta tarefa responde **o que cada checkout faz com cada campo**, haja ou não problema. Um exemplo que mostra a diferença: *"quatro colunas são honradas pelo checkout clássico e não são aplicadas no Blocks"* não é um defeito a corrigir — é um facto com que se decide, e a interface não o deve esconder. Os dois viajam na **mesma resposta, em chaves separadas**, para a interface não os poder fundir por acidente; a prova afirma que não são o mesmo objecto.

## 4. As quatro famílias, e o que cada uma diz

- **core** — um campo do WooCommerce não pode ser removido, arquivado nem retipado, e o que *pode* ser feito com ele (renomear, mover, redimensionar) também é dito. Quando o inventário foi mesmo lido e o campo já não existe, o motivo é a deriva que a ADR-0001 escolheu poder detetar.
- **largura** — a grelha clássica tem doze colunas e só duas larguras nela: doze e seis são honradas pela grelha, quatro e três por uma classe que esta extensão aplica. O Blocks dispõe os seus próprios campos e **não aplica a largura**. Uma largura fora da grelha é reportada como não aplicada, com o viewport no motivo.
- **seção** — a localização lógica e onde cada checkout a põe: o clássico não tem passo de contato, por isso um campo de contato é desenhado com a morada de faturação (limitado, com essa razão); uma secção que não está no documento não tem onde ser desenhada (indisponível).
- **tipo** — `provided` quando o adapter desenha o tipo sozinho, `limited` quando precisa de um componente desta extensão, `unavailable` quando nenhum dos dois o faz. **As respostas do Blocks vêm do próprio adapter** (`BlocksAdapter::mode_for()`), que lê a lista nativa do WooCommerce instalado e as definições do documento — não há aqui uma segunda tabela: um limite que discordasse do adapter seria a interface a prometer o que o checkout não faz.

## 5. O defeito que o `field=0` no output denunciou

A primeira versão lia o inventário do núcleo como um mapa por identificador:

```php
if ( $inventory['available'] && ! isset( $inventory['fields'][ $definition->id() ] ) ) { … }
```

`CoreFields::catalogue()['fields']` é uma **lista plana** de campos descritos, não um mapa — a condição era sempre verdadeira (`! isset(...)`), portanto o motivo de deriva **nunca podia aparecer**, e o ramo era código morto que nenhum teste teria apanhado por não haver campo em deriva para exercitar.

Foi visto porque a própria prova imprimiu `field=0`: o harness tinha usado `array_keys()` sobre a lista, ficando com um índice como identificador. A asserção passava (o limite `core` aparece por o campo ter origem `core`), mas o output mostrou que o identificador não era um identificador. Corrigido nos dois lados: a pertença é agora uma procura pelo `id` de cada entrada, e o harness lê o identificador da entrada — `billing_first_name`, o campo real desta loja.

Vale registar a forma: a asserção estava verde e o que a denunciou foi **o detalhe impresso**, não a condição. Foi para isso que cada asserção passou a imprimir o que observou.

## 6. O que a prova estabelece

Sobre o rascunho real, através da rota real do relatório de publicação:

- a resposta traz `capabilities` **e** `incompatibilities`, e não são o mesmo objecto;
- as quatro famílias estão todas representadas, e **todos** os campos do rascunho aparecem (nada é silenciosamente omitido);
- um tipo que nenhum checkout desenha é `unavailable`, um tipo que esta extensão desenha é `limited` com a tarefa que o entrega no motivo, a largura é `provided` no clássico e `limited` no Blocks, uma largura fora da grelha é `unavailable` com o viewport dito, uma secção inexistente é `unavailable`, e um campo do WooCommerce traz o limite `core` com o que pode e não pode ser feito nele;
- nada ficou gravado no ambiente.

No admin, três specs em jsdom fixam o contrato da superfície: sem matriz no relatório a secção não aparece; com ela, mostra o campo, o motivo e a família com o adapter; e uma família que se aplica aos dois lados aparece sem adapter.

## 7. O que NÃO foi provado, e porquê

**A matriz vista num browser.** O painel foi renderizado e operado em jsdom; nenhum browser foi aberto (WCCS-063).

**A matriz a impedir uma publicação.** Ela informa; não bloqueia, e é essa a decisão já registada na ADR-0008 — uma limitação avisa e nunca bloqueia. O que a tarefa pede é que esteja **visível antes**, e está: a mesma resposta que o botão de publicar consome.

**A largura no Blocks a funcionar de outra maneira.** O que está dito é o que se verificou: o Blocks dispõe os seus próprios campos e não há hoje extensão oficial que aplique a largura pedida por um campo adicional.

## 8. Próxima tarefa

**WCCS-040 — "Testar edição e lifecycle Blocks"** (aceite: *troca de endereço/frete, remount e rerender preservam valores sem DOM hacks*). É a última tarefa da fase, e é onde os componentes ganham o lugar que lhes falta: a troca de endereço e de frete a re-renderizar o checkout, o remount a preservar valores, e nenhum `MutationObserver`.
