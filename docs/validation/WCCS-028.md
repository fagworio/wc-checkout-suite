# Registro de validação — WCCS-028

**Tarefa:** WCCS-028 · "Implementar validadores PHP/JS"
**Fase:** F05 · Presets Brasil, IMask e validação remota
**Prioridade:** `required_v1` · **Dependências:** F04
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Mesmos fixtures passam/falham; DV não é apresentado como validação de identidade."

**Resultado:** **215 testes unitários PHP** (15 novos, 199 asserções novas) · **708 asserções de integração** em 24 provas, 0 falhas (37 novas) · **383 testes de JS em 25 suites** (19 novos) · os **7 gates** verdes.

## 2. A decisão aberta do ADR-0003 está encerrada

A pergunta era se os dois **dígitos verificadores** de um CNPJ alfanumérico também podem ser alfanuméricos. A resposta é **não**: doze posições alfanuméricas e **dois dígitos verificadores sempre numéricos**. A leitura do `§9` estava correta.

**Como foi apurado, e com que confiança.** O documento oficial da Receita Federal **não pôde ser lido deste ambiente** — a FAQ é servida como PDF e a página de notícias exige autenticação —, portanto a resposta **não foi lida na fonte primária**. Foi apurada numa fonte que reproduz a tabela oficial de valores e o cálculo passo a passo, e **corroborada por dois exemplos trabalhados independentes** que a implementação reproduz:

| Vetor | O que é | |
|---|---|---|
| `12.ABC.345/01DE-35` | o exemplo passo a passo publicado com a regra | aceito |
| `UK.PVM.E1E/8HI9-96` | número de teste publicado para integração | aceito |
| `11.222.333/0001-81` | formato numérico legado, mesmo caminho de código | aceito |
| `529.982.247-25` · `111.444.777-35` | vetores de CPF conhecidos | aceitos |

A ressalva está registrada **na decisão** e não alisada: confirmação numa fonte primária continua recomendada e é a única parte desta resolução que não foi feita aqui. Fontes: [cálculo dos dígitos](https://docs.cnpj.ws/en/blog/alphanumeric-cnpj) · [exemplo de teste](https://docs.cnpj.ws/en/blog/alphanumeric-cnpj-test-example). O ADR-0003 ganhou uma secção de resolução; `CNPJ-CHECK-DIGITS` saiu de `open_decisions`.

## 3. "Mesmos fixtures passam/falham"

Um ficheiro, `resources/fixtures/br-documents.json`, lido pela suite PHP e pela suite JS. Duas listas, e a segunda existe por uma razão que a primeira não podia expressar:

**`validity`** — valores canônicos, com entradas válidas e inválidas. Ambas as partes aceitam e recusam exatamente as mesmas.

**`not_canonical`** — documentos **bem formados** que não estão em forma canônica. As duas partes respondem de maneira diferente, e isso é uma decisão:

> O servidor valida o valor que **guarda**, e o ADR-0003 faz do normalizador nomeado a única coisa autorizada a transformar um documento — portanto recusa um valor formatado em vez de o limpar. O browser valida o que está **no campo**, que é o valor formatado que a máscara produziu — portanto remove a pontuação e põe em caixa alta antes de conferir.

Manter isto no ficheiro partilhado, com `refused_by: server` e `accepted_by: browser` escritos, transformou uma diferença que se descobriria por acidente numa decisão afirmada dos dois lados. **Foi uma falha de teste que a expôs**: a minha primeira versão afirmava que todas as entradas "invalid" eram recusadas pelas duas partes, e três não eram.

## 4. "DV não é apresentado como validação de identidade"

O critério é uma restrição sobre o que a interface pode sugerir, e o sítio onde uma sugestão nasceria é a mensagem. Por isso a vocabulário é **verificado, não confiado**: as mensagens são afirmadas contra uma lista de palavras que reivindicariam verificação — `identity`, `titular`, `owner`, `verified`, `confirms`, `authentic` — para que uma mensagem acrescentada mais tarde tenha de enfrentar a regra. E cada uma nomeia o documento.

Nenhuma diz que o cliente é quem diz ser. Um dígito verificador correto diz que o número está bem formado, e nada sobre a pessoa, a empresa, ou quem o digitou.

## 5. RG não tem validador — e isso é uma afirmação, não um esquecimento

`br.rg` **não existe** no registro. O formato depende da unidade federativa e do tipo de documento, e inventar uma regra nacional foi rejeitado no ADR-0003. Uma chave que existisse e aceitasse tudo seria **pior** do que a ausência: o lojista que a selecionasse acreditaria que o campo estava a ser conferido. Uma prova afirma a ausência, e a fixture de RG traz a nota em vez de casos.

## 6. O gate da fase

**Cláusula 2 — cumprida e afirmada.** Um CNPJ alfanumérico atravessa o caminho inteiro: o normalizador remove só a pontuação reconhecida, conserva as letras, põe em caixa alta e devolve uma string; o validador aceita o exemplo publicado e o número de teste publicado; nada é truncado e o valor não é numérico.

**Cláusula 1 — cumprida na costura.** Um `POST` forjado com quatro documentos errados produz **quatro erros** na coleção que o WooCommerce transforma em avisos, e o `process_checkout()` não cria pedido nenhum quando essa coleção não está vazia. Com os mesmos quatro corretos, **zero erros**.

O que não está observado é o checkout a terminar ou a não terminar, porque esta loja não tem página com o shortcode. É a `CLASSIC-TEST-SURFACE` — a mesma decisão que bloqueia a observação de todos os gates desde a F04. O gate fica **aberto** por isso e apenas por isso.

## 7. Um risco que continua alcançável, registrado

O normalizador genérico `digits` continua a ser um `preg_replace( '/\D/' )`, e um lojista ainda o pode escolher no inspetor para um campo de documento. Escolhê-lo num campo de CNPJ alimentaria o validador com um valor truncado, que é **recusado em voz alta** em vez de guardado — uma falha, não uma corrupção silenciosa. Desviar o inspetor dessa combinação pertence ao trabalho da lista de campos e do inspetor; apertar uma primitiva que outros campos usam legitimamente seria a resposta errada.

## 8. Três defeitos meus, e um deles quase passou

**A verificação literal do ADR-0003 leu a prosa.** O ADR pede um teste explícito de que `preg_replace( '/\D/' )` não é usado no caminho de documento. A primeira versão procurava os dois caracteres no ficheiro inteiro e falhou em três ficheiros — porque três deles **documentam** a expressão proibida nos seus próprios comentários, e porque `WCCheckoutSuite\Domain` contém um separador de namespace seguido de um `D`. Passou a ler o **código**: literais de string em PHP, fonte sem comentários em JavaScript.

**O harness usou o singleton e quatro asserções passaram vacuamente.** `WC()->checkout()` memoiza o array de campos por requisição, e o harness publica o documento depois de o singleton poder já ter sido construído — portanto os campos não estavam nos dados publicados, não havia erros, e **uma lista de erros vazia parece exatamente um checkout que aceitou tudo**. É a mesma armadilha que os harnesses da WCCS-023 e da WCCS-025 já tinham encontrado. Corrigido com uma instância nova.

**Uma quarta asserção alheia deixou de testar o que dizia.** A WCCS-007 usava `br.cpf` como exemplo de validador não registrado. Reescrita contra uma chave ausente por construção — quarta tarefa seguida em que uma asserção que fixa um valor exato apanha uma mudança deliberada.

## 9. Próxima tarefa

**WCCS-029 — "Implementar endpoint de validação"**. Aceite: *"Sessão, rate limit, timeout, abort e request ID impedem estado obsoleto."*

O `§10` é explícito sobre o que este endpoint **não** pode ser: o response não funciona como autorização permanente, um "valid" antigo não autoriza um valor novo, e a versão do schema e o valor atual precisam ser revalidados antes do pagamento. Também não pode revelar se um CPF ou e-mail pertence a outro cliente, e não pode aceitar regex, callback, caminho de arquivo ou endpoint remoto vindos do comprador.
