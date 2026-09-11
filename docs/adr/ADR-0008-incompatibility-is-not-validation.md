# ADR-0008 — Incompatibilidade não é validação

WC CheckoutSuite · decisão tomada na execução da tarefa **WCCS-019** (F03)

**Status:** Aceito
**Fases de impacto:** F03, F04, F07, F09
**Origem:** decisão de implementação, **não** diretriz do `ROADMAP.md`

## Contexto

O `§428` pede que a publicação mostre "diferenças, validações e incompatibilidades". Três palavras que, numa
lista, parecem a mesma coisa e levam a acções opostas.

Três factos tornam a distinção obrigatória:

1. **O `§8` já aceita que um adapter não suporta tudo.** A coluna "Blocks nativo" diz explicitamente para
   textarea, radio, multiselect, time, datetime e upload: "Não assumir suporte da API de additional fields".
   O caminho declarado não é recusar — é o "Blocks próprio": um componente da Suite.
2. **Nada disso é inválido.** Um campo `date` num documento é um schema correcto. O tipo está registado, os
   settings são válidos, a máscara confere. Recusar a publicação seria tratar uma limitação de plataforma como
   erro do lojista.
3. **A tentação é real.** É mais simples marcar tudo o que "não funciona" como inválido e bloquear. Faz a
   interface parecer rigorosa e transforma cada lacuna do Blocks numa parede para o lojista.

## Decisão

**Uma validação recusa a publicação. Uma incompatibilidade avisa e não recusa.**

São calculadas separadamente e devolvidas separadamente:

| Pergunta | Onde é respondida | Efeito |
|---|---|---|
| O que vai mudar? | `SchemaDiff::between()` | Nenhum. É informação. |
| O schema é válido? | `SchemaRepository::validate()` | 422 na publicação. Bloqueia. |
| Um checkout vai honrá-lo como está escrito? | `PublishIncompatibilities::check()` | Nada. É um aviso. |

E a resposta do relatório mantém-nas em chaves distintas, `validation` e `incompatibilities`, para que a
interface não possa fundi-las por descuido.

**A matriz de capacidades é dado executável, não prosa.** O `§8` descreve a matriz numa tabela; o admin precisa
de a *consultar*. `AdapterCapabilities` guarda a parte verificável — o que cada adapter fornece por si — e a
resposta do Block não é uma opinião: a **WCCS-003** verificou contra o WooCommerce 11.1.0 instalado que a API
de additional fields regista exactamente `text`, `select` e `checkbox`, e que `date` **não** está entre eles.

A consequência imediata é que, hoje, tudo o que não sejam esses três tipos é reportado para o Block checkout
com nível `component`, com a razão e com o caminho declarado. Não é uma dívida escondida: é a lista do que a
**F07** tem de entregar.

**Uma incompatibilidade só é reportada onde é accionável.** Um campo arquivado não é renderizado, logo não pode
ser incompatível com coisa nenhuma; reportá-lo seria ruído que o lojista não consegue resolver. E a família "o
campo do WooCommerce desapareceu" só é reportada quando o inventário **foi realmente lido**: um inventário
ilegível não é prova de que todos os campos centrais desapareceram, e tratá-lo como tal encheria o ecrã de
alarmes sempre que a WooCommerce falhasse a arrancar.

## Consequências

- **A F04 e a F07 acrescentam capacidades, não erros de validação.** Quando um componente da Suite passar a
  renderizar `date` no Block checkout, o que muda é `AdapterCapabilities`, e o aviso desaparece sozinho. Marcar
  o caso como inválido obrigaria a *remover* uma validação mais tarde — e as validações removidas são as que
  ninguém se lembra de remover.
- **Publicar continua a ser um único POST com compare-and-swap.** O relatório é uma leitura; não publica nada.
  Uma rota de leitura que publicasse seria a forma mais fácil de alterar uma loja por engano.
- **A interface não pode bloquear por uma incompatibilidade.** Um teste afirma que o botão continua activo com
  uma incompatibilidade presente, e falha se alguém ligar as duas coisas.
- **Um adapter desconhecido não é silenciosamente nativo.** `for_type()` devolve `unsupported` com a razão em
  vez de assumir suporte — assumir suporte é como se promete uma paridade que não existe.
- **Custo assumido:** a lista de tipos nativos do Block está escrita uma vez, em `AdapterCapabilities`, e tem
  de ser revista quando o WooCommerce mudar. É o preço de a resposta ser verificável em vez de prosa.

## Alternativas consideradas e rejeitadas

| Alternativa | Por que foi rejeitada |
|---|---|
| Tratar incompatibilidade como erro de validação | Bloquearia a publicação por uma limitação da plataforma que a Suite pode resolver. Transformaria cada lacuna do Blocks numa parede. |
| Devolver uma lista única de "problemas" | As três respostas levam a acções diferentes: uma não pede nada, outra pede correcção, a terceira pede uma decisão. Fundi-las obriga a interface a reinterpretar o que recebeu. |
| Manter a matriz do `§8` só em documentação | O admin precisa de a consultar. Prosa não é consultável, e uma tabela em documentação diverge do código sem que nada avise. |
| Assumir suporte para um adapter desconhecido | É a forma mais fácil de prometer uma paridade que não existe. |
| Reportar incompatibilidades de campos arquivados | Ruído que o lojista não consegue resolver: um campo arquivado não é renderizado. |
| Reportar a família "campo do WooCommerce desapareceu" sempre | Um inventário ilegível não é prova de que os campos desapareceram. Encheria o ecrã de alarmes quando a WooCommerce falhasse a arrancar. |

## Como verificar conformidade

`tests/Integration/F03-wccs-019-publish-diff-proof.php` (36 asserções) prova, pela rota real, que o relatório
responde às três perguntas em separado, que um rascunho **válido** pode ter incompatibilidades, que o adapter
Clássico não tem nenhuma, e que uma sobreposição central órfã é detectada.

`tests/js/components/PublishPanel.test.js` (19 testes) prova o contrato de interface: uma validação desactiva o
botão de publicar, uma incompatibilidade **não** o desactiva, e as duas aparecem com textos distintos.

`tests/Unit/Domain/Schema/SchemaDiffTest.php` (12 testes) cobre o diff, incluindo a afirmação que impede a
contagem de ruído: uma troca de posições é **uma** reordenação, não uma mudança por campo abaixo dela.

O `§8` e o `§428` permanecem a fonte dos requisitos; este ADR determina o que cada resposta pode fazer.
