# Registro de validação — WCCS-008

**Tarefa:** WCCS-008 · "Implementar draft, publicação e revisões"
**Fase:** F01 · Core, schema e contratos de extensão
**Prioridade:** `required_v1` · **Dependências:** F00, WCCS-006, WCCS-007
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Gravação atômica e conflito 409 testados; publicar não perde histórico."

**Resultado:** `RESULT: 38 passed, 0 failed` — exit 0, **com verificação de que nenhuma opção ficou no banco** (`before=0 after=0`).
**Regressão:** WCCS-006 `33/33` · WCCS-007 `38/38`.
**Qualidade:** PHPCS **exit 0** · PHPStan level 5 com stubs **`[OK] No errors`**.

## 2. Código criado

| Arquivo | Papel |
|---|---|
| `src/Domain/Schema/SchemaDocument.php` | Revisão imutável do schema, em JSON — **nunca** objeto PHP serializado (`§13`) |
| `src/Domain/Schema/WriteResult.php` | Resultado do CAS: `ok` / `conflict` / `invalid` |
| `src/Domain/Schema/SchemaMigrations.php` | Versão de schema, carimbo e histórico de migração |
| `src/Domain/Schema/SchemaRepository.php` | **Núcleo do CAS**: draft, publicado, histórico e restauração |
| `src/Http/Admin/SchemaController.php` | Endpoints REST `/schema/{draft,publish,revisions,restore}` |
| `tests/Integration/F01-wccs-008-schema-repository-proof.php` | Prova: 38 asserções |

## 3. Como cada cláusula foi cumprida

### "Gravação atômica" — ✅

A escrita **não** é leitura-seguida-de-escrita. É um único `UPDATE` condicional:

```sql
UPDATE wp_options SET option_value = %s
WHERE option_name = %s AND option_value = %s     -- valor exato lido pelo chamador
```

- A primeira escrita usa `add_option()`, que **falha atomicamente** se a opção já existir.
- **Zero linhas afetadas** não é ignorado: o repositório relê o valor e só trata como sucesso se o conteúdo gravado for **byte-idêntico** ao pretendido. Isso distingue *corrida perdida* de *escrita que não mudou nada* — e é o que a prova confirma: reescrever o mesmo documento é **no-op idempotente**, não um falso conflito.
- O cache de opções é invalidado após a escrita direta, porque o CAS precisa do valor **real**, não de uma cópia em cache.

### "Conflito 409" — ✅

O repositório **não decide HTTP**: ele reporta `conflict` com a revisão que venceu, e o controller — única camada que conhece HTTP — mapeia para **409**. Provado com requisição REST real:

| Cenário | Status observado |
|---|---|
| `GET /schema/draft` como administrador | **200** |
| `PUT /schema/draft` com `expected_revision` obsoleto | **409** |
| `PUT /schema/draft` com revisão corrente | **200** |
| `POST /schema/publish` válido | **200** |
| `POST /schema/publish` com schema inválido | **422** |
| Requisição sem usuário autenticado | **401** |

### "Publicar não perde histórico" — ✅

- Publicar faz **validação integral** e só então a substituição atômica; um schema inválido é recusado e **o publicado permanece intacto** (verificado).
- Cada publicação cria um **novo** registro no histórico (revisão, autor, data e hash), mais recente primeiro.
- **Restaurar não reescreve o passado**: republica o conteúdo antigo como uma revisão **nova** e acrescenta uma entrada. O histórico apenas cresce.
- O histórico é limitado (5 no teste, 20 por padrão) e **configurável** pelo construtor, como o `§13` determina.
- `GET /schema/revisions` devolve os metadados **sem** os documentos armazenados — nenhum vazamento de payload.

## 4. Bug real encontrado pela própria prova

A primeira execução falhou **11 de 35** asserções. A causa não era o teste: era um **defeito de projeto no meu controller**.

`SchemaDocument::bumped()` incrementa a revisão **do próprio documento**. No `PUT`, eu construía o documento a partir do corpo da requisição — que não traz `revision` — então a revisão resultante era sempre `1`. **A revisão estava sendo controlada pelo cliente**, o que tornaria o CAS inútil: qualquer cliente poderia reenviar `revision: 1` indefinidamente e vencer todas as corridas.

**Correção:** a revisão passou a ser **do servidor**. O controller lê o draft armazenado, aplica o conteúdo recebido e então incrementa:

```php
$document = $current
    ->with_fields( $incoming->fields() )
    ->with_sections( $incoming->sections() )
    ->with_settings( $incoming->settings() )
    ->bumped( get_current_user_id(), gmdate( 'c' ) );
```

A prova agora verifica explicitamente essa propriedade: *"The server owns the revision: the request body cannot set it"*.

Isso é exatamente o tipo de defeito que uma prova de F00/F01 existe para pegar antes de virar um bug de produção sob concorrência real.

## 5. Outros achados

1. **`add_option()` espera `bool|null` no autoload desde o WordPress 6.6**, não a string `'yes'`/`'no'`. Eu havia passado `'no'`; o PHPStan apontou a assinatura. Corrigido para `false`, o que também confirma "options sem autoload" do `§13`.
2. **O WPCS não conhece `manage_woocommerce`** (é registrada pelo WooCommerce). O `§19` a exige explicitamente. Aplicado `phpcs:ignore` pontual, com a correção definitiva registrada: declarar `custom_capabilities` no ruleset de **WCCS-010**.
3. **Acesso direto ao banco exige justificativa explícita.** Os dois `$wpdb` do repositório (leitura crua e CAS) carregam `phpcs:ignore` documentando por que o caminho normal não serve — o CAS precisa do valor exato armazenado, e `update_option()` não oferece comparação.
4. **Documento de versão mais nova é recusado, não lido errado.** Um schema com `schema_version` acima do suportado decodifica como documento vazio em vez de ser interpretado com o formato errado.

## 6. O que NÃO foi feito

- **Cache do schema compilado** indexado por revisão, adapter, idioma e capacidades (`§13`). Depende do compilador que é **WCCS-009**; não faria sentido indexar algo que ainda não é produzido.
- **Options de settings da loja** (distintas do schema) — entram com a tela de configurações.
- **Importação/exportação** e preview de diff — **WCCS-056** (F11).
- **Client REST do admin** (nonce, retry, estado não salvo) — **WCCS-014** (F02).
- Nenhuma interface foi construída; nada foi validado em navegador.

## 7. Próxima tarefa

**WCCS-009 — "Criar normalizadores e contratos"** (F01). Aceite: "Interfaces versionadas; mesmo contrato serve Classic/Blocks/pedidos." Componentes: Validation, Conditions, Renderers. É onde entram os registries de **máscaras e renderizadores** (os dois hooks do `§6` que ainda não têm destino) e os contratos de formatação que alimentam pedidos, e-mails e API.
