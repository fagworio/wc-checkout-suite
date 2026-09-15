# Contratos publicados — hooks, rotas e tipos

**Artefato da tarefa WCCS-059** (F11) · **Data:** 11/09/2026
**Referência no planejamento:** `ROADMAP.md §6`, `§8` e `§18`

> Este documento responde ao aceite de WCCS-059: **ordem, assinaturas, versão, depreciação e componente
> React demonstrados**. Tudo o que está aqui foi lido do código e do registro em runtime da instalação;
> um teste unitário (`tests/Unit/Docs/ExtensionContractsTest.php`) falha quando um hook publicado não
> aparece neste documento ou quando este documento nomeia um hook que o plugin não publica. É isso que
> impede o documento de divergir — um contrato documentado à mão é o documento que ninguém actualiza.

## 1. Ordem

O servidor constrói os registros uma vez por pedido e publica-os por esta ordem. Um plugin que se
registre depois de `wccs_registries_ready` continua a ser aceite pelo hook do seu registro, porque
registrar de novo é idempotente (`register()` recusa uma chave já tomada em vez de a substituir).

| # | Momento | Hook | O que acontece aqui |
|---|---|---|---|
| 1 | `plugins_loaded` | — | O plugin carrega; nenhum registro existe ainda |
| 2 | primeiro uso dos registros | `wccs_booted` | A instância dos registros existe e ainda está vazia |
| 3 | construção dos registros | `wccs_register_*` (sete hooks) | Cada registro é preenchido: tipos, presets, validadores, normalizadores, máscaras, renderers, avaliadores de condição |
| 4 | fim da construção | `wccs_registries_ready` | Tudo registrado; a partir daqui os registros respondem |
| 5 | `rest_api_init` | — | As rotas são registadas (administração, integração, transferência) |
| 6 | `admin_init` | — | A política de privacidade sugerida é oferecida |
| 7 | `admin_menu` | — | A tela do plugin entra no menu |

No cliente, a ordem é a do carregamento: o bundle publica o registro de componentes
(`window.wccsBlocksFields`) **antes** de desenhar qualquer campo, e o script de um plugin contribuinte
declara `wc-checkout-suite-blocks` como dependência, o que garante que o registro já existe quando ele
corre.

## 2. Assinaturas

Cada hook recebe exatamente um argumento, e é o registro que ele preenche.

| Hook | Assinatura | Devolve |
|---|---|---|
| `wccs_booted` | `do_action( 'wccs_booted', Registries $registries )` | nada |
| `wccs_registries_ready` | `do_action( 'wccs_registries_ready', Registries $registries )` | nada |
| `wccs_register_field_types` | `do_action( ..., FieldTypeRegistry $types )` | nada |
| `wccs_register_presets` | `do_action( ..., PresetRegistry $presets )` | nada |
| `wccs_register_validators` | `do_action( ..., ValidatorRegistry $validators )` | nada |
| `wccs_register_normalizers` | `do_action( ..., NormalizerRegistry $normalizers )` | nada |
| `wccs_register_masks` | `do_action( ..., MaskRegistry $masks )` | nada |
| `wccs_register_renderers` | `do_action( ..., RendererRegistry $renderers )` | nada |
| `wccs_register_condition_evaluators` | `do_action( ..., ConditionEvaluatorRegistry $evaluators )` | nada |
| `wccs_field_category_labels` | `apply_filters( ..., array $labels )` | `array<string, string>` |
| `wccs_core_fields_inventory` | `apply_filters( ..., array $inventory )` | `array{available: bool, fields: array<int, array<string, mixed>>}` |
| `wccs_classic_adapter_report` | `do_action( ..., array $registered, array $refused )` | nada |
| `wccs_blocks_adapter_report` | `do_action( ..., array $registered, array $refused )` | nada |
| `wccs_email_field_links` | `apply_filters( ..., bool $enabled )` | `bool` |
| `wccs_uploads_cleanup` | `do_action( ..., int $now )` | nada (trabalho agendado) |
| `wccs_account_document_url` | `apply_filters( ..., string $url, string $token )` | `string` (endereço da porta que serve o documento do cliente) |
| `wccs_workflow_expire` | `do_action( ..., int $order_id )` | nada (trabalho agendado) |
| `wccs_workflow_sweep` | `do_action( ... )` | nada (trabalho agendado) |

## 3. Versão

Um contrato que muda sem dizer em que versão está é um contrato que quebra em silêncio.

| Contrato | Versão | Onde vive |
|---|---|---|
| Tipo de campo | `contract_version()`, por tipo | `src/Domain/Fields/AbstractFieldType.php` |
| Registro de tipos | versão de contrato do registro | `AbstractRegistry::contract_version()` |
| Documento de esquema | `schema_version` no documento | `src/Domain/Schema/SchemaDocument.php` |
| Ficheiro de exportação | `SchemaTransfer::FORMAT` + `::VERSION` | `src/Domain/Schema/SchemaTransfer.php` |
| Namespace REST | `wc-checkoutsuite/v1` | `src/Http/Admin/SchemaController.php` |
| Componente de cliente | o registro `window.wccsBlocksFields` | `resources/blocks/index.js` |

Um tipo contribuinte declara a versão do contrato contra a qual foi escrito, e o registro recusa um
tipo cuja versão não é a que ele aceita — em vez de o aceitar e falhar mais tarde de uma forma que
ninguém liga ao tipo.

## 4. Depreciação

A política, e é curta de propósito:

1. **Nada é removido sem uma versão de aviso.** Um hook, uma chave ou um método de contrato que vai
   sair continua a funcionar por uma versão de contrato, e o aviso é publicado na versão anterior.
2. **O aviso viaja no código, não só aqui.** Quem se registra por um contrato depreciado recebe
   `_deprecated_hook()` ou `_deprecated_argument()`, que o WordPress já mostra a quem desenvolve.
3. **Uma mudança incompatível muda a versão do contrato**, e o registro passa a recusar o antigo em
   vez de o reinterpretar.
4. **Um contrato experimental não é documentado como estável.** Os slots de React do WooCommerce que
   este plugin usa (ver `docs/api/checkout-extension-points.md §1`) carregam `Experimental` no nome e
   são tratados como tal.

Nenhum contrato deste plugin está hoje depreciado; a política existe para que o primeiro o seja por
escrito em vez de por omissão.

## 5. Componente React demonstrado

Um plugin contribuinte pode desenhar o seu próprio componente no checkout de blocos, e não apenas
escolher um dos que existem.

**No servidor**, o tipo declara que é desenhado por um componente do contribuinte:

```php
public function supports(): array {
    return array( 'value' => true, 'control' => 'custom', /* … */ );
}
```

**No cliente**, o script do contribuinte regista o componente no registro publicado e declara a
dependência `wc-checkout-suite-blocks`:

```js
// O registro existe antes deste ficheiro correr: a dependência garante a ordem.
window.wccsBlocksFields.register( 'example.membership-code', MembershipCodeField );
```

`window.wccsBlocksFields.register( key, component )` recebe a chave do tipo e um componente React
(criado com `@wordpress/element`); o bundle procura primeiro no registro e só depois nos componentes
que traz. Um tipo que declara `control => 'custom'` e cujo componente não está registado **não é
desenhado**: é reportado, porque um campo desenhado por ninguém é um campo que o cliente não vê.

O exemplo em `examples/custom-field-type/` traz os dois lados: `MembershipCodeType::supports()` declara
o controlo, e `assets/membership-code-field.js` regista o componente.

## 6. O que este plugin não publica

Para que a ausência seja tão legível como a presença:

1. **Nenhum hook para alterar o esquema publicado a meio de um pedido.** O documento é lido do
   armazenamento e validado; alterá-lo por filtro seria uma segunda fonte para ele.
2. **Nenhum endpoint não autenticado.** Todas as rotas do plugin exigem capability, e as de integração
   exigem também permissão por pedido.
3. **Nenhuma forma de registar um gateway.** Ver `docs/api/checkout-extension-points.md §6`.
