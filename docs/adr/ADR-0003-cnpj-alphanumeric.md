# ADR-0003 — CNPJ como string alfanumérica

- **Status:** Aceito
- **Data:** 11/09/2026
- **Tarefa:** WCCS-005 (F00) · **Fase de impacto:** F05, F07
- **Decisão já fixada em:** `ROADMAP.md §2`, `§5` e `§9`
- **Evidência / fontes:** referência S9 (Receita Federal, início do CNPJ alfanumérico em 31/07/2026) e S11 (simulador oficial)

## Contexto

A máscara numérica de CNPJ do plugin de referência **não é suficiente**. A Receita Federal informa início
efetivo do uso alfanumérico em **31/07/2026**. O `ROADMAP.md §25` classifica "CNPJ truncado para somente
dígitos" como risco **Alta**, com mitigação por string alfanumérica, normalizador e fixtures.

O risco é concreto e silencioso: qualquer conversão para inteiro, `preg_replace('/\D/')` ou máscara
numérica transforma um CNPJ alfanumérico válido em um valor diferente — possivelmente em um valor que
ainda passa por alguma validação, produzindo um documento errado gravado no pedido.

## Decisão

1. **CNPJ é armazenado como string** de ponta a ponta — domínio PHP, Store API, meta do pedido, REST e
   apresentação. **Nunca** `int`, `float` ou tipo numérico do banco.
2. **Dois formatos aceitos:** o legado numérico e o alfanumérico. Ambos válidos.
3. **Letras preservadas em caixa alta** na normalização. **Zeros à esquerda preservados** — nunca truncar.
4. **Estrutura:** 12 posições alfanuméricas + 2 dígitos verificadores. O conjunto de caracteres aceito e o
   cálculo dos verificadores são o contrato a implementar e **fixar por fixtures** em **WCCS-028**,
   confrontados com as fontes oficiais (S9, S11). Este ADR fixa o **tipo e a política**, não o algoritmo.
5. **Máscara é UX, nunca autoridade.** A normalização e a validação acontecem no servidor; a máscara
   apenas ajuda a digitação e é desvinculada da validação. Estados incompletos durante a digitação são
   permitidos; o campo não é marcado como inválido antes da interação.
6. **Caracteres ilegais produzem erro**, não descarte silencioso. Remover apenas a pontuação reconhecida;
   jamais "limpar" o valor até que ele pareça válido.
7. **CPF, CNPJ e CEP são strings.** O tipo `number` não pode ser usado para documento.

## Questão aberta registrada (não decidida aqui)

O planejamento é **ambíguo** sobre se os **dois dígitos verificadores** também podem ser alfanuméricos:

- `ROADMAP.md §5` descreve "CNPJ aceita os dois formatos e mantém letras em caixa alta";
- `ROADMAP.md §9` descreve "12 posições alfanuméricas e dois dígitos verificadores";
- `PLANEJAMENTO.html` repete as duas formulações e os critérios de aceite falam apenas em
  "CNPJ numérico e alfanumérico".

**Encaminhamento:** resolver em **WCCS-028** contra a fonte oficial (S9/S11) e registrar a resposta em
`docs/compatibility.json`. Até lá, nenhuma implementação pode afirmar qual é o formato correto, e nenhuma
fixture pode ser dada como definitiva.

## Consequências

- O normalizador `br.cnpj` é o único ponto autorizado a transformar o valor. Nenhuma outra camada aplica
  `preg_replace` sobre documento.
- Fixtures de CNPJ são **compartilhadas entre PHP e JS** (WCCS-028/WCCS-033), para que os dois lados não
  divirjam.
- Validação matemática de documento **não comprova titularidade, situação cadastral nem identidade** — e a
  interface não pode sugerir o contrário (critério de aceite de WCCS-028).
- A coluna do banco e o meta precisam comportar string com letras; qualquer índice ou comparação numérica
  futura sobre esse valor é um defeito.

## Alternativas consideradas e rejeitadas

- **Aceitar apenas o formato numérico** (como o plugin de referência): rejeitada — inviabiliza o cadastro
  de empresas com CNPJ alfanumérico a partir de 31/07/2026.
- **Armazenar normalizado apenas com dígitos**: rejeitada — é exatamente o truncamento classificado como
  risco Alto.
- **Validar só no navegador**: rejeitada — o servidor é autoritativo; POST adulterado precisa falhar.
- **Inventar um "validador nacional universal" de RG** por analogia: rejeitada — regras de RG dependem de
  UF/tipo e permanecem opcionais, conforme `§5`.

## Como verificar conformidade

- **WCCS-026/WCCS-028:** fixtures com CNPJ numérico e alfanumérico, com letras e zeros à esquerda,
  passando e falhando de forma idêntica em PHP e JS.
- Gate de F05 (literal): *"CNPJ alfanumérico válido não é truncado nem convertido em número."*
- WCCS-028: teste explícito de que `preg_replace('/\D/')` **não** é usado no caminho de documento.

---

## Resolução da questão aberta (WCCS-028)

**Encerrada em 11/09/2026, na execução da WCCS-028, como este ADR determinou.**

A pergunta era se os **dois dígitos verificadores** de um CNPJ alfanumérico também podem ser
alfanuméricos. A resposta é **não**: são doze posições alfanuméricas e **dois dígitos verificadores
sempre numéricos**. A leitura do `§9` estava correta.

### Como foi apurado

O PDF de perguntas e respostas da Receita Federal citado na pesquisa não pôde ser lido a partir deste
ambiente (o tipo de conteúdo é PDF e a página de notícias exige autenticação), portanto a resposta **não**
foi lida na fonte primária. Foi apurada numa fonte que reproduz a tabela oficial de valores e o cálculo
passo a passo, e **corroborada por dois exemplos trabalhados independentes** que a implementação
reproduz:

| Vetor | Origem | Resultado |
|---|---|---|
| `12.ABC.345/01DE-35` | exemplo passo a passo publicado com a regra | aceito |
| `UK.PVM.E1E/8HI9-96` | número de teste publicado para integração | aceito |
| `11.222.333/0001-81` | formato numérico legado | aceito |
| `529.982.247-25` | CPF, vetor conhecido | aceito |

Fontes: [cálculo dos dígitos verificadores do CNPJ alfanumérico](https://docs.cnpj.ws/en/blog/alphanumeric-cnpj)
e [exemplo de teste do CNPJ alfanumérico](https://docs.cnpj.ws/en/blog/alphanumeric-cnpj-test-example).

**Verificação pendente de terceiro:** a confirmação numa fonte primária da Receita Federal continua
recomendada e é a única parte desta resolução que não foi feita aqui.

### O que a resposta fixou

- O valor de cada caractere é `ord( caractere ) - 48`: um dígito vale o seu valor facial e `A` vale 17.
  Uma implementação para os dois formatos, porque são um documento só.
- Os pesos, da direita para a esquerda em passos de dois a nove, são os mesmos do CNPJ numérico. Em
  `br.cnpj` o formato alfanumérico **não** tem caminho próprio.
- Um dos doze caracteres iniciais em minúscula não é forma canônica: a normalização põe em caixa alta e
  uma minúscula que chegue ao validador é recusada.
- Registrada em `docs/compatibility.json`: `CNPJ-CHECK-DIGITS` saiu de `open_decisions` e está em
  `closed_decisions`.
