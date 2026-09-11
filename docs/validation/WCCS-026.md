# Registro de validação — WCCS-026

**Tarefa:** WCCS-026 · "Implementar presets brasileiros"
**Fase:** F05 · Presets Brasil, IMask e validação remota
**Prioridade:** `required_v1` · **Dependências:** F04
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "CPF, CNPJ numérico/alfanumérico, RG, CEP, telefone e endereço com contratos próprios."

**Resultado:** **200 testes unitários PHP** (19 novos, 196 asserções novas) · **655 asserções de integração** em 22 provas, 0 falhas (36 novas) · **350 testes de JS em 23 suites** (4 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Validation/Normalizers/BrazilianDocumentNormalizer.php` | A política de armazenamento dos documentos |
| `src/Domain/Validation/BrazilianDocuments.php` | Os normalizadores nomeados e as máscaras |
| `src/Domain/Fields/BrazilianPresets.php` | Os presets com contratos próprios (saíram de `CoreTypes`) |
| `resources/fixtures/br-documents.json` | Os vetores compartilhados entre PHP e JS |
| `fieldOperations.ts` | Os defaults do preset passam a chegar ao campo criado |

## 3. "contratos próprios" — e onde eles param

Cada preset declara **o que o campo é**: o tipo em que se apoia, a máscara com que se digita, o normalizador que transforma o que o cliente escreveu no que a loja guarda, e as configurações que os dois precisam. Um preset é dado, e o `ADR-0003` exige que a máscara nunca seja autoridade: por isso o normalizador é nomeado e é o servidor que o aplica.

Três coisas estão **deliberadamente ausentes**, e cada uma é uma afirmação:

**Validadores.** Os dígitos verificadores são contrato da WCCS-028, fixados contra as fontes oficiais. Nomear uma chave de validador não registrada faria todo campo criado a partir do preset falhar ao salvar; nomear uma que existe e não faz nada seria uma promessa que o código não cumpre. Uma prova afirma que **nenhum preset nomeia um validador**, para que o acrescento seja uma decisão e não um deslize.

**Máscara para o RG.** O formato depende da unidade federativa e do tipo de documento, e inventar um padrão nacional foi rejeitado no `ADR-0003`. RG é uma string, que é o que ele é. Há uma prova disso também.

**Tipo numérico para documento.** `ADR-0003` regra 7: CPF, CNPJ e CEP são strings. Nenhum preset de documento usa `number`.

## 4. O normalizador é escrito contra `preg_replace( '/\D/' )`

É a linha que qualquer pessoa escreveria, e é a linha que o `ADR-0003` proíbe neste caminho. Ela passa no primeiro caso de cada documento e falha em todos os outros: transforma um CNPJ alfanumérico num **número diferente**, transforma um CPF digitado com uma letra num **CPF válido que ninguém digitou**, e destrói os zeros à esquerda de um CEP.

O normalizador entregue remove **apenas a pontuação reconhecida** — `. / - ( ) +` e espaço — e deixa qualquer outro caractere intacto para o validador recusar. O conjunto está declarado como lista, e não como classe de caracteres dentro de um padrão, para que acrescentar um seja uma edição visível com uma razão.

E está **provado por fixtures**, que é a forma de o fixar. Alguns dos vetores existem precisamente para falhar se alguém reescrever a linha:

| Documento | Entrada | Canônico | O que a linha ingênua faria |
|---|---|---|---|
| CNPJ | `12.ABC.345/01DE-35` | `12ABC34501DE35` | `123450135` |
| CPF | `123.456.789-0A` | `1234567890A` | `1234567890` — e passaria a ser válido |
| CNPJ | `00.111.222/3333-44` | `00111222333344` | `111222333344` |
| CEP | `01310-100` | `01310100` | `1310100` |

Há também um caso de telefone com código de país (`+55 (11) 99999-8888` → `5511999998888`) que existe para afirmar que o código **não é cortado** para caber: os presets de telefone são brasileiros, declaram máscara e comprimento brasileiros, e quem precisa de números internacionais usa um campo `tel` comum — que é o que o `§9` quer dizer com "não impor máscara brasileira".

## 5. Dois defeitos encontrados

### 5.1 A guarda de máscara recusava parênteses

`Mask::is_declarative()` rejeita um padrão que contenha `(`, `)`, `;`, `{`, `}`, `<`, `>`, `\`, `` ` ``, `$` ou `=`. A lista é uma heurística grosseira contra uma definição com forma de código, e ela **recusou a máscara de telefone brasileira**, que é escrita `(00) 00000-0000`.

O erro é de raciocínio, não de digitação: parênteses são **caracteres literais de um formato escrito**, e é isso que uma máscara é. A definição é dado consumido pela biblioteca de máscara e nunca avaliado, portanto os parênteses não carregam significado nenhum além do que uma pessoa vê. A lista perdeu `(` e `)`, e ganhou uma prova que afirma que as duas máscaras de telefone são declarativas — para que um aperto futuro tenha de enfrentá-las — enquanto as duas definições que **são** código (`() => 1`, `function(){}`) continuam a ser recusadas.

### 5.2 O contrato do preset era descartado antes de chegar ao campo

O `FieldPicker` monta a escolha com `defaults: preset.defaults ?? {}`, o tipo `PickerChoice` declara `defaults`, o `CatalogController` publica `defaults` — e o `buildField` **nunca os lia**: escrevia `mask: choice.mask ?? null` e `normalizer: null`, fixos.

Ou seja: todo o produto desta tarefa teria sido inerte. O lojista escolhia "CPF" e recebia um campo de texto com um placeholder. Não é um erro que apareça num teste de unidade do preset — o preset estava certo; é um erro na junta entre o que o servidor publica e o que o cliente aplica, e só aparece ao seguir o dado de ponta a ponta.

Corrigido com uma **lista de permissão**: um preset pode semear `mask` e `normalizer`, e nada mais. Um preset vem do servidor, onde qualquer plugin registra um pelo hook público `wccs_register_presets`, portanto é dado de fora desta aplicação: pode descrever como o valor é digitado e guardado, não pode escolher a identidade do campo, a secção onde ele vive ou quem pode vê-lo. A verificação passa um preset que tenta `id`, `integration_id`, `origin`, `section`, `enabled`, `type`, `storage`, `visibility` e `hidden_value_policy` — e afirma que **nenhum** deles é obedecido.

## 6. Duas asserções alheias deixaram de testar o que diziam

A WCCS-009 afirmava que um normalizador não registrado é recusado usando `br.cnpj` como exemplo. A WCCS-017 fazia o mesmo com a máscara `br.cpf`. Ambas eram verdadeiras enquanto os documentos brasileiros eram uma promessa, e ambas passaram a afirmar o contrário da verdade quando esta tarefa os registrou.

Reescritas para uma chave ausente por construção (`not.registered`), com o comentário a dizer porquê. É a **quarta tarefa seguida** em que uma asserção que fixa um valor exato apanha uma mudança deliberada — depois do conjunto de hooks (WCCS-023), do formato do payload (WCCS-024) e do array de atributos do adapter (WCCS-025). Aqui a forma é mais interessante do que nas anteriores: não era um valor que mudou, era um **exemplo negativo** que deixou de ser negativo.

## 7. Uma decisão que continua aberta, e não foi tomada aqui

O `ADR-0003` registra uma ambiguidade do planejamento sobre se os **dois dígitos verificadores** do CNPJ também podem ser alfanuméricos, e encaminha a resposta para a **WCCS-028**, contra as fontes oficiais. Esta tarefa **não** a resolve: a máscara declara doze posições curinga e duas de dígito, que é a estrutura do `§9`, e a fixture alfanumérica tem dois dígitos no fim — mas nada aqui afirma qual é o formato correto, e as fixtures dizem isso no próprio arquivo.

Vale notar que a decisão **não está** em `open_decisions` do `compatibility.json`, e devia estar: é uma pergunta em aberto com um encaminhamento claro, e um registo que só existe dentro de um ADR é um registo que ninguém procura. Fica acrescentada nesta tarefa.

## 8. O que NÃO foi provado, e porquê

**Validação.** Um CPF errado é normalizado corretamente e **aceito**. É a WCCS-028, e é honesto: nenhum preset nomeia um validador e nenhuma fixture pode ser lida como vetor de validade — o próprio arquivo o diz na primeira linha.

**A máscara a funcionar no navegador.** As máscaras estão declaradas e registradas; aplicá-las é a WCCS-027, que traz a IMask. O que esta tarefa prova é que o padrão oferece exatamente uma posição por caractere do valor guardado — um a menos recusa um documento válido na última tecla, um a mais aceita um valor incompleto como completo.

**Um checkout renderizado.** O lado da administração é exercido pela rota REST real; o lado do checkout é a mesma definição lida pelo adapter, que a WCCS-021 prova contra o array de campos que o WooCommerce constrói.

## 9. Próxima tarefa

**WCCS-027 — "Integrar IMask localmente"**. Aceite: *"Colar, apagar, autocomplete, mobile e refresh não quebram cursor/valor."*

É o primeiro consumidor real do ciclo de vida da WCCS-025: uma máscara aplicada **uma vez por input** é exatamente o caso para que a regra por elemento existe, e a distinção entre sobreviver ao refresh e ser substituído deixa de ser abstrata. A máscara continua a ser UX — o valor canônico é o que a WCCS-022/023 provaram e o servidor reaplica.
