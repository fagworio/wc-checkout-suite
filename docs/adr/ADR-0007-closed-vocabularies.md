# ADR-0007 — Vocabulários fechados: o publicado e o exigido são o mesmo

WC CheckoutSuite · decisão tomada na execução da tarefa **WCCS-017** (F03)

**Status:** Aceito
**Fases de impacto:** F03, F05, F06, F07, F09
**Origem:** decisão de implementação, **não** diretriz do `ROADMAP.md`

## Contexto

O `§428` do `ROADMAP.md` exige que o inspector "exiba somente propriedades suportadas". Um painel que
esconde um controle é fácil; o difícil é que **a regra continue valendo depois do painel**.

Três lacunas reais foram encontradas ao implementar:

1. `FieldDefinition` carregava `mask` desde a F01 e **nada no domínio a lia nem a validava**. Um schema podia
   referenciar uma máscara inexistente e ser armazenado.
2. `storage` e `visibility` eram mapas livres. Qualquer string era um escopo válido; qualquer chave era um
   público válido. Um cliente podia escrever `scope: "redis"` e nada reclamava.
3. `SchemaValidator` não conhecia `items`, então o único requisito sobre `options` era "uma lista de 1 a 500
   itens". Uma opção podia ser a string `"banana"` e passar. O que uma escolha precisa — valor e rótulo — não
   estava declarado em lugar nenhum, e portanto não era nem validado nem editável por geração.

## Decisão

**1. Todo conjunto fechado vive numa única classe, e é dela que o validador e o catálogo leem.**

`DefinitionVocabulary` é a única fonte dos escopos de storage, dos níveis de sensibilidade, dos destinos de
exibição e das políticas de valor oculto. (O conjunto que este ADR chamava de "públicos de visibilidade" passou a
ser a lista de **destinos**, cada um com seção, título, ordem e ações próprias — ver `ROADMAP.md` §4. Continua a ser
um conjunto fechado lido de um só lugar.) Os valores aceitos são **derivados** das listas rotuladas, nunca
escritos uma segunda vez:

```php
public static function storage_scope_values(): array {
    return self::values( self::storage_scopes() );
}
```

O catálogo publica `DefinitionVocabulary::to_array()`; o validador pergunta
`DefinitionVocabulary::storage_scope_values()`. Divergir exigiria editar a mesma classe de duas formas
incompatíveis, e a prova de integração compara os dois arrays diretamente.

**2. Um valor que o admin não oferece é recusado, não ignorado.**

A alternativa — aceitar em silêncio o que não estava na lista e descartar o resto — foi rejeitada. Descartar em
silêncio deixa quem chamou acreditando ter configurado algo que nunca foi configurado; uma chave de público
desconhecida, em particular, é exatamente o tipo de erro que se descobre em produção ao ver um dado exposto.

`unknown_storage_scope`, `unknown_storage_sensitivity`, `unknown_visibility_audience` e
`unknown_hidden_value_policy` são erros, com o vocabulário válido na mensagem.

**3. Uma referência é fixada na versão contra a qual foi configurada.**

`Mask` já carregava `version()` com a intenção declarada: *"a field definition stores the mask version it was
configured against, so a later change can be detected instead of silently altering stored values"*. O
`FieldDefinition` guardava um mapa livre, então essa intenção não tinha como valer.

Agora a referência é `{ key, version }`, a chave tem de estar registrada e **a versão tem de coincidir** com a
registrada. Uma máscara cuja definição mudou é detectada (`mask_version_stale`) em vez de alterar em silêncio o
que o campo aceita.

**4. As regras de superfície são validadas, não apenas renderizadas.**

Um guarda-chuva de regras verifica, contra as capacidades que o tipo declara:

| Regra | Código |
|---|---|
| Máscara em tipo que não é mascarável | `mask_not_supported` |
| Escopo de storage num tipo que não guarda valor | `storage_scope_requires_value` |
| `public_api` num tipo que não guarda valor | `visibility_exposes_missing_value` |

**5. Os defaults seguem o tipo, não uma constante.**

Uma definição cujo tipo não guarda valor precisa declarar `scope: none`. Como um objeto de valor não tem
acesso ao registro, quem constrói a definição (o cliente, que tem o catálogo) escolhe o default correto, e o
servidor recusa a alternativa. A regra existe justamente para que "heading que guarda valor no pedido" não
possa ser armazenado.

## Consequências

- **Um `items` declarado passa a ser exigido.** `options`, `allowedTags` e `allowedExtensions` declaram a forma
  dos seus itens, e o validador a impõe reusando a mesma rotina dos settings — exigidos e desconhecidos se
  comportam igual nos dois níveis.
- **`minItems` passou a ser validado.** Estava declarado em `options` desde a F01 e nunca era lido: era um
  requisito declarado que nada exigia.
- **O inspector é gerado, não escrito.** Um controle por propriedade declarada, escolhido pelo `type` da
  declaração. Um tipo registrado por um plugin de terceiros ganha editor sem nenhuma alteração no admin.
- **Acrescentar uma opção ao produto passa a ser editar uma classe.** É o custo deliberado: a alternativa é o
  admin oferecer algo que o servidor recusa.
- **`description` entrou na definição.** O `§7` exige descrições e o `§4` as repete na lista de propriedades,
  mas a definição não tinha onde guardá-las. É opcional e o padrão é string vazia, então um documento escrito
  antes desta chave continua sendo lido corretamente.
- **A máscara é selecionável, não implementada.** O runtime continua sendo a F05/WCCS-027. O catálogo tem duas
  máscaras genéricas hoje; as brasileiras (CPF, CNPJ, CEP, telefone) são da **WCCS-026** e aparecerão no
  inspector **sem alteração no inspector**, que é o ponto de ele ser dirigido pelo registro.

## Alternativas consideradas e rejeitadas

| Alternativa | Por que foi rejeitada |
|---|---|
| Duas listas: uma publicada, outra validada | Divergem na primeira alteração, e o sintoma é o admin oferecer o que o servidor recusa. |
| Ignorar valores fora da lista | Quem chamou fica acreditando ter configurado algo. Uma chave de público desconhecida descartada em silêncio é uma exposição que ninguém sabe que não configurou. |
| Aceitar qualquer versão de máscara | Torna a versão armazenada um campo decorativo: uma máscara alterada mudaria o que o campo aceita sem que nada registrasse isso. |
| Acrescentar as máscaras brasileiras nesta tarefa | A **WCCS-026** é a dona dos presets brasileiros ("CPF, CNPJ numérico/alfanumérico, RG, CEP, telefone e endereço com contratos próprios"). Adiantá-las aqui duplicaria trabalho e misturaria escopos. |
| Uma interface nova em `FieldTypeInterface` para declarar superfícies | Contrato publicado não ganha métodos: quebraria toda implementação de terceiros. As capacidades já estão em `supports()`. |
| Tornar `storage` obrigatório no cliente em vez de validado no servidor | Uma regra que só existe no cliente não é uma regra. |

## Como verificar conformidade

`tests/Unit/Domain/Fields/DefinitionValidatorTest.php` (18 testes) cobre cada regra de superfície, incluindo os
casos em que ela é **inerte** e os casos em que é **satisfeita** — uma suíte que só testasse recusas passaria
por uma implementação que recusa tudo.

`tests/Integration/F03-wccs-017-inspector-proof.php` (28 asserções) prova as duas metades que importam:

- **não há deriva**: cada valor publicado é aceito pelo validador, e cada valor não publicado é recusado;
- **as portas valem pela rota real**: máscara em tipo não mascarável, escopo em tipo sem valor, exposição de
  valor inexistente e versão de máscara obsoleta voltam com código estável.

`tests/js/components/FieldInspector.test.js` (26 testes) cobre a terceira metade: que o painel mostra o que é
suportado, opera, e **diz por que** uma superfície não aparece em vez de sumir em silêncio.

O `§428` do `ROADMAP.md` permanece a fonte do requisito; este ADR determina como ele é sustentado fora do
painel.
