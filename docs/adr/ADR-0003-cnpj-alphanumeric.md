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
