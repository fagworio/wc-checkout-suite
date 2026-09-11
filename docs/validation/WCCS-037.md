# Registro de validação — WCCS-037

**Tarefa:** WCCS-037 · "Implementar campos controlados próprios"
**Fase:** F07 · Checkout Blocks nativo e tipos próprios
**Prioridade:** `required_v1` · **Dependências:** F04, F05, F06
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Textarea, radio, multiselect, time/datetime e presets mascarados nos slots permitidos."

**Resultado:** **323 testes unitários PHP** (2 novos) · **878 asserções de integração** em 33 provas, 0 falhas (16 novas) · **554 testes de JS** em 32 suítes (13 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/blocks/fields.js` | Os componentes: textarea, radio, multiselect, date/time/datetime e o texto mascarado |
| `resources/blocks/index.js` | Lê o payload, constroi os elementos e entrega-os a quem os coloca |
| `src/Checkout/Blocks/BlocksRenderer.php` | Publica os campos que precisam de um componente, e recusa os que não têm |
| `src/Checkout/Blocks/BlocksAdapter.php` | `mode()`/`mode_for()`: a classificação de cada tipo, e a exceção da máscara |
| `tests/js/blocks/fields.test.js` · `tests/Integration/F07-wccs-037-*` | O comportamento dos componentes, e a metade servidor |

## 3. A classificação não tem buraco, e a prova é que o diz

O gate da fase pede que **todos os tipos não-file da V1** tenham renderer ou restrição explícita. `BlocksAdapter::mode()` responde `native`, `controlled` ou `restricted` para qualquer tipo, e a prova percorre o **registo de tipos real** (21 tipos) e recusa-se a deixar um só sem modo decidido:

```
native: 3 · controlled: 7 · restricted: 11
```

Nenhum tipo fica "para depois". É a diferença entre uma lista de intenções e uma afirmação verificável.

## 4. A máscara é a exceção que o tipo não decide

Um campo de texto é nativo — mas o input nativo **não aceita máscara**. Um documento escrito nele seria guardado sem formatação e recusado pelo servidor: um campo que parece certo e não pode ser preenchido corretamente. Por isso `mode_for()` olha para a **definição** e não só para o tipo: um texto com máscara é renderizado por nós.

Sem isto, os "presets mascarados" do aceite não chegariam ao checkout Blocks — e foi exatamente o que a prova apanhou: a primeira versão publicava só textarea/radio/date, e o campo mascarado aparecia na lista de ausentes. O `mode_for()` é agora o único sítio que decide, e o adapter nativo e o renderer leem dele.

## 5. Os componentes, e o que cada um promete

- **São controlados, e o valor pertence ao checkout.** Nenhum guarda cópia: um componente com o seu próprio estado sobreviveria a um rerender com um valor que o checkout não tem — a duplicação que o gate da fase nomeia.
- **A máscara formata, nunca decide.** O valor canónico é do servidor (ADR-0003, regra 5); calculá-lo aqui seria o cliente a decidir o que a loja guarda.
- **Uma máscara e um input controlado do React não coexistem** — o React escreve `value` diretamente no elemento e salta o listener da máscara, que deixa de formatar. O texto passa a ser do elemento, a máscara formata-o e o valor é reportado a cada alteração aceite. Uma regra mantém o checkout dono do valor sem lutar com o cliente: **o valor de fora só é escrito no elemento quando o elemento não é o que está a ser escrito**. Um restauro depois de um refresh entra; uma tecla nunca é sobrescrita pelo valor que acabou de produzir.
- **A acessibilidade não é decoração:** `required` e `aria-required`, `aria-invalid` com a mensagem ao lado num `role="alert"`, rótulo ligado ao controlo por `htmlFor`/`id` — inclusive nos radios, onde o `fieldset`/`legend` dá o nome ao grupo.
- **Os temporais usam os inputs nativos** (`date`, `time`, `datetime-local`): o browser dá o seletor, o teclado e o formato da língua do cliente. Um widget próprio substituiria os três por algo pior.
- **Um tipo sem componente devolve `null` e diz qual é**, em vez de desenhar um campo de texto que recolheria algo que a loja não guarda.

## 6. O que a prova estabelece

- A classificação cobre os 21 tipos registados, sem buracos;
- o servidor publica textarea, radio, date e o texto mascarado, e **não** publica o texto nativo (seria desenhado duas vezes) nem o tipo restrito;
- o radio leva as opções na ordem em que o lojista as escreveu, o campo mascarado leva a **definição da máscara** e não um identificador a resolver no browser, e o `required` e a descrição viajam com o campo;
- um multiselect sem opções é **relatado**, não publicado vazio;
- o bundle é construído, o manifesto declara só dependências que o WordPress fornece (tudo o resto está dentro dele, como o `§9` exige), o payload é escrito **antes** do bundle, e nada é enfileirado num pedido que não é o checkout Blocks.

## 7. O que NÃO foi provado, e porquê

**Os componentes dentro de um checkout Blocks a sério.** Foi aberto nenhum browser. Os componentes foram renderizados e operados em jsdom, um a um; o que não foi visto é a página.

**O registo no slots do checkout.** `resources/blocks/index.js` tem a costura: encontra a API do checkout, constroi os elementos e entrega-os a quem os coloca, e responde `no_blocks_checkout_api` em vez de fingir quando não há onde os pôr. Colocá-los é a tarefa de integração — **um campo que se desenha mas cujo valor não vai a lado nenhum é pior do que um campo que não se desenha, porque o cliente preenche-o** —, e o valor só passa a existir com a Store API da WCCS-038.

**A largura, a ordem e a posição.** O adapter não emite nada disso: o Blocks decide o layout, e a matriz de capacidades é a WCCS-039.

## 8. Próxima tarefa

**WCCS-038 — "Integrar Store API e validação"** (aceite: *erro final bloqueia pagamento; payload tipado; retry não duplica gravação*). É onde estes componentes passam a ter um valor que chega ao pedido, e onde o `no_blocks_checkout_api` desta tarefa deixa de ser a resposta.
