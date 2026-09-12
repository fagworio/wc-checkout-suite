# WCCS-071 · Modelar vínculos e exibição no schema

**Fase:** F14 · **Depende de:** F03, F04, F08, F10
**Aceite:** "Coleta, vinculação e exibição separadas; `destinations` substitui o mapa booleano; destinos começam desativados; migração de documento antigo testada."

## 1. O que foi entregue

`destinations` é agora o modelo de exibição, e substitui o mapa booleano `visibility` (`admin_order`,
`customer_order`, `customer_email`, `admin_email`, `public_api`). Cada destino carrega o que só ele decide:

```json
"admin_order": {
  "enabled": true,
  "section": "documentos_para_analise",
  "title": "Documentos para análise",
  "position": 10,
  "actions": [ "show_metadata", "view", "download", "approve" ]
}
```

- **`DefinitionVocabulary`** ganhou o conjunto fechado dos sete destinos (com o que cada um pode fazer),
  a lista fechada das cinco ações, `default_destinations()` (todos desativados) e
  `destinations_from_visibility()`, que migra o mapa antigo. O catálogo publicado passou a levar
  `destinations` e `destinationActions`.
- **`FieldDefinition`** lê `destinations`; na falta dele migra `visibility`; na falta dos dois, **nenhum
  destino fica ativo** — que é a regra do roadmap. Expõe `destinations()`, `shows_in( $key )` e `approval()`.
- **`DefinitionValidator`** valida destinos e o fluxo de aprovação, e publica `validate_links()` para o
  repositório usar no caminho de escrita.
- **`SchemaRepository::write()`** aplica essas regras **em toda gravação de rascunho**, ao lado da proteção
  dos campos nativos, da regra de origem e da integridade de seções.
- **`approval`** entrou como configuração separada (`null` por defeito), com `approval_incomplete` quando
  exige análise e não diz onde ela acontece.

## 2. Achados desta tarefa

1. **A migração não pode interpretar o que encontra.** A primeira versão de
   `destinations_from_visibility()` traduzia cada público para `enabled => ! empty( $valor )`. Isso
   transformava um documento malformado (`visibility: { admin_order: 'yes' }`) num documento válido e
   apagava a prova do defeito. A versão entregue carrega o valor como estava: o validador recusa
   `invalid_destination`, e um público que a lista fechada não conhece sobrevive à migração para ser
   recusado como `unknown_destination`. O harness `F03-wccs-017` apanhou exatamente isso — a asserção dele
   passou de `invalid_visibility_value` para `invalid_destination`, mantendo os dois lados da verificação.

2. **Recusar só na publicação era o defeito que a tarefa existia para não repetir.** O harness postou um
   destino inexistente pelo caminho real e recebeu **200**: o rascunho aceitava a configuração inválida e a
   recusa só apareceria ao publicar. Passou a ser recusada na gravação (422, `unknown_destination`), pelo
   mesmo raciocínio que trouxe a regra de origem para o rascunho em F03.

3. **`SchemaDocument::fields()` devolve arrays, não objetos.** Chamar `->id()` num deles é `Error`, e o
   `wp eval-file` **engole o fatal sem imprimir nada** — o harness "desaparecia" com código 255 e sem
   mensagem. Levou três bisseções com marcadores até aparecer; o caminho certo para ler um campo guardado
   é `FieldDefinition::from_array()`, que é também o que as telas de pedido fazem. O helper do harness
   passou a ler por aí, o que torna a asserção sobre o *modelo* e não sobre o JSON.

4. **O payload de recusa da rota é uma WP_Error aninhada** (`data.errors[]`), não uma lista no topo. Um
   harness que só lê o topo diz "sem código" para uma recusa que aconteceu — o pior verde possível. O
   helper passou a ler os dois níveis.

5. **A projeção de compatibilidade fica, e sai em WCCS-072.** `to_array()` continua a publicar `visibility`
   derivado de `destinations`, porque a aba do inspetor e o `OrderFieldsController` ainda leem o mapa
   antigo. Sem isso o admin deixaria de gravar. Está marcada no código com quem a remove.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 407 testes, 1468 asserções, sem erros (phpcs, phpstan, phpunit) |
| `tests/Integration/F14-wccs-071-destinations-proof.php` | **20 passaram, 0 falharam**, 1 nota |
| `tests/Unit/Domain/Fields/DefinitionValidatorTest.php` | cinco casos novos: destino desconhecido, ação que o destino não pode executar, `enabled` que não é booleano, aprovação incompleta, aprovação completa, e nenhum destino ativo sem configuração |

O harness cobre, pelo caminho REST real: o conjunto fechado publicado; um campo sem mapa de destinos
guardado com todos desativados; cada destino independente com a sua seção, título, ordem e ações; as quatro
recusas (422) e o rascunho intacto depois delas; a migração do mapa antigo; e que publicar não acrescenta
exibição nenhuma.

## 4. Limites

- A **interface** que edita estes vínculos é WCCS-072: hoje o inspetor continua a gravar o mapa antigo, que
  o servidor migra. A projeção de compatibilidade é o que mantém as duas metades a funcionar no meio.
- A regra por área ("um campo sem vínculo não aparece em lugar nenhum") é provada por WCCS-076; aqui provou-se
  que o modelo não ativa nada sozinho.
- A aprovação está modelada e validada; **não** há ainda fluxo que a execute (WCCS-075).
