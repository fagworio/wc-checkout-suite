# ADR-0006 — Proteção dos campos centrais no ponto único de persistência

WC CheckoutSuite · decisão tomada na execução da tarefa **WCCS-016** (F03)

**Status:** Aceito
**Fases de impacto:** F03, F04, F07, F09
**Origem:** decisão de implementação, **não** diretriz do `ROADMAP.md`

## Contexto

O `§7` do `ROADMAP.md` exige que o lojista possa alterar os campos padrão do WooCommerce de forma
**seletiva**, preservando os requisitos de país, as regras de endereço, o checkout de visitante, o cadastro e o
comportamento de plugins de terceiros. Ele também fixa o limite: campo obrigatório estrutural "não pode ser
removido sem diagnóstico do impacto".

O que o planejamento não diz é **onde** esse limite é aplicado. A implementação encontrou três lacunas reais:

1. `FieldDefinition` já carregava `origin` (`core` ou `custom`) desde a F01, e **nada no domínio o lia**. Não
   existia proteção alguma.
2. `SchemaRepository::write()` **não valida**. Só `publish()` validava. Uma regra aplicada apenas na publicação
   seria contornável: o rascunho é o que a publicação copia.
3. O `FieldTypeInterface` é um contrato publicado. Acrescentar um método a ele quebraria toda implementação de
   terceiros já existente — o exemplo em `examples/custom-field-type` incluído.

## Decisão

**1. A proteção vive na comparação entre dois documentos, no ponto único de persistência.**

`CoreFieldGuard` recebe o documento que está sendo substituído e o documento proposto. É chamado por
`SchemaRepository::write()` e por `SchemaRepository::publish()`. Nenhum cliente chega ao armazenamento sem
passar por lá, então esconder um botão na interface é cortesia, não regra.

Para um campo armazenado como `core`, o guard recusa:

| Mudança | Código | Por quê |
|---|---|---|
| Ausente do documento proposto | `core_field_removed` | Frete, fiscal e pagamento leem o campo |
| `enabled: false` | `core_field_disabled` | Arquivar é remover com outra roupa |
| `type` diferente | `core_field_type_changed` | Muda o que a loja persiste |
| `integration_id` diferente | `core_field_identifier_changed` | Pedidos e outro código apontam para ele |
| `required` de verdadeiro para falso | `core_field_requirement_relaxed` | É o caso que o `§7` manda diagnosticar |
| `origin` alterado | `core_field_origin_changed` | **Sem isso a proteção é teatro**: bastaria renomear a origem e apagar em seguida |

Tudo o que é apresentação — rótulo, descrição, posição, largura, seção, `settings`, condições — permanece
livre. Proteção que também congelasse a apresentação tornaria o recurso inútil.

**2. A linha de base é o documento armazenado, não uma lista fixa.**

Um campo é protegido porque **já está armazenado como `core`**. Isso mantém a regra independente da versão do
WooCommerce e dos plugins que registram campos, e significa que um campo jamais adotado não vira "protegido"
do nada. Adotar um campo central pela primeira vez é uma adição, não uma alteração, e é permitida.

**3. Um identificador que pertence ao WooCommerce tem de se declarar `core`.**

Esta é a única regra aplicada **também na escrita do rascunho**, através de
`DefinitionValidator::validate_origin()`. Sem ela, um cliente declararia `billing_email` como campo próprio e
depois o desativaria livremente, porque o guard protege o que está armazenado como central. A regra é uma
propriedade de uma definição isolada, então mora no validador; o repositório decide **quando** aplicá-la.

As demais regras continuam fora da escrita do rascunho de propósito: um campo pela metade precisa poder ser
salvo e continuado depois.

**4. A categoria de tipo é argumento de registro, não método de interface.**

`FieldTypeInterface` não ganhou `category()`. A categoria é o terceiro parâmetro opcional de
`FieldTypeRegistry::register_type()`, com `general` como padrão. Uma extensão que ignora o assunto continua
funcionando e aparece em "Outros"; um rótulo legível é derivado da chave para uma categoria inventada, de modo
que nenhum grupo aparece sem título.

## Consequências

- **A proteção não depende do cliente.** Vale para a interface actual, para uma futura e para uma chamada
  manual à API REST.
- **Um erro de proteção chega como 422 com código estável**, não como uma falha genérica. A tela consegue
  dizer qual regra foi quebrada.
- **Documentos que perdem um campo central são recusados na escrita do rascunho**, não na publicação. O
  lojista descobre o problema onde ele acontece.
- **Adotar um campo central cria um override, não uma cópia**, como o ADR-0001 exige: o valor continua no
  campo do WooCommerce.
- **Uma limitação conhecida e declarada:** o guard compara por `id`. Um `id` duplicado no documento proposto
  tornaria a comparação ambígua, então `duplicate_field_id` é recusado antes de qualquer outra decisão.
- **Custo para as fases seguintes:** a F04, que aplica o schema ao checkout, **não pode** tratar um campo
  `custom` cujo `id` seja de um campo central como um campo próprio. O validador já a impede de armazenar esse
  estado; a F04 deve herdar a mesma suposição ao renderizar.

## Alternativas consideradas e rejeitadas

| Alternativa | Por que foi rejeitada |
|---|---|
| Esconder os botões de arquivar/apagar na interface | Não é proteção. Qualquer outro caminho até o armazenamento passaria por cima, e a interface não é o único cliente possível. |
| Uma lista fixa de campos centrais no código | Fica errada duas vezes: outro plugin pode adicionar ou remover campos, e o próprio WooCommerce os muda entre versões. A lista é lida ao vivo por `CoreFields`. |
| Validar tudo na escrita do rascunho | Impediria trabalho em andamento: um campo recém-criado sem rótulo seria recusado antes de o lojista terminá-lo. |
| Validar só na publicação | O rascunho é o que a publicação copia; uma regra só na publicação é uma regra contornável. |
| Acrescentar `category()` a `FieldTypeInterface` | Quebraria toda implementação de terceiros existente. Contrato publicado não ganha métodos. |
| Endpoints REST por operação (criar, duplicar, arquivar) | Quatro guardas de revisão a mais para manter correto, sem nenhum comportamento que a rota de rascunho com compare-and-swap já não ofereça. |

## Como verificar conformidade

`tests/Unit/Domain/Schema/CoreFieldGuardTest.php` (15 testes) cobre cada regra, o limite da regra — campos
próprios **não** são congelados — e as duas tentativas óbvias de contorno: renomear a origem e duplicar o
identificador.

`tests/Unit/Domain/Fields/DefinitionValidatorTest.php` (6 testes) cobre a regra de origem, incluindo o caso em
que ela é inerte por não haver WooCommerce.

`tests/Integration/F03-wccs-016-field-picker-crud-proof.php` (33 asserções) prova o que os testes unitários não
alcançam: que a **rota real** consulta o guard. Cada tentativa de destruição devolve 422 com o código
esperado, nada é gravado pela requisição recusada, e renomear, mover e redimensionar um campo central continua
sendo aceito.

O `§7` do `ROADMAP.md` permanece a fonte do requisito; este ADR determina apenas onde e como ele é aplicado.
