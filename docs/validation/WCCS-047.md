# Registro de validação — WCCS-047

**Tarefa:** WCCS-047 · "Implementar apresentação Blocks"
**Fase:** F09 · Página customizada e pagamento
**Prioridade:** `required_v1` · **Dependências:** F02, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Layout nas regiões suportadas; não clona campos nem componentes de pagamento."

**Resultado:** **382 testes unitários PHP** (9 novos) · **1022 asserções de integração** em 42 provas, 0 falhas (17 novas) · **581 testes de JS** em 34 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/blocks/presentation.css` | O layout, escrito sob a região que o plugin recebe |
| `src/Checkout/Presentation.php` | A regra partilhada: os tokens antes da folha de estilo que os lê |
| `src/Checkout/Blocks/BlocksRenderer.php` | Entrega a apresentação no checkout de blocos |
| `resources/design-tokens/tokens.css` | Os tokens passam a declarar-se também neste escopo |
| `tests/Unit/Checkout/Blocks/BlocksPresentationTest.php` | Lê o layout como folha de estilo |
| `tests/Integration/F09-wccs-047-blocks-presentation-proof.php` | A partição dos 21 tipos e a ausência de acoplamento ao pagamento |

## 3. "Regiões suportadas" é a frase que decide o ficheiro

O checkout de blocos é a **página do WooCommerce**. Os blocos de campo que ele desenha, o resumo do pedido e toda a área de pagamento são marcador que este plugin não escreveu e não lhe foi prometido. O que ele recebe é **uma região dentro de um local** — `contact`, `address`, `order` — e é isso que a apresentação estiliza.

Por isso todos os seletores estão sob `.wccs-blocks-field`, a classe que o wrapper dos componentes do plugin **sempre** renderiza. Três consequências, e são o motivo de ser este escopo e não outro:

- uma regra que não nomeia o escopo **não consegue** alcançar um campo do WooCommerce nem um componente de pagamento — não é uma promessa, é uma propriedade do seletor;
- o layout não depende de onde a plataforma decidiu colocar os campos: a mesma região é estilizada no mesmo sítio em qualquer local;
- a classe é emitida pelo componente que o plugin já tinha, por isso não há o defeito da WCCS-046 — um escopo que a folha de estilo espera e ninguém emite. E está afirmado: o teste unitário lê `resources/blocks/fields.js` e exige que o wrapper renderize essa classe.

## 4. "Não clona campos" — afirmado sobre os 21 tipos, não sobre um exemplo

Um campo clonado é um campo que as duas metades desenham. A prova **percorre o registo de tipos** e, para cada um, publica um campo e pergunta às duas metades:

| | tipos |
|---|---|
| `native` — o WooCommerce desenha | 3 |
| `controlled` — o componente do plugin desenha | 7 |
| `restricted` — ninguém desenha, com um motivo | 11 |

E afirma as três propriedades de uma vez: a interseção entre o que a metade nativa registou (lida de volta do serviço de campos de checkout da plataforma) e o que o renderer publicou é **vazia**; cada tipo é reclamado pela metade que o seu modo nomeia; e a metade nativa não registou nada além dos tipos nativos. Um tipo `restricted` reclamado por qualquer das metades também falha — desenhar um seria recolher um valor que a loja não consegue guardar.

Isto é mais forte do que as asserções por exemplo que a WCCS-037 já tinha, e é a diferença entre "estes quatro campos não são clonados" e "nenhum dos 21 tipos é".

## 5. "Não clona componentes de pagamento" — quatro ausências

- o plugin **não tem um único callback** nos pontos de extensão de pagamento dos blocos (`woocommerce_blocks_payment_method_type_registration`, `woocommerce_blocks_checkout_block_registration`);
- o registo de tipos de campo **não declara nenhum tipo** de cartão, CVC ou pagamento;
- nenhum motivo de restrição promete um renderer de pagamento;
- a folha de estilo que é servida **não nomeia** `payment`, `card`, `cvc` nem `credit` — lida depois de lhe retirar os comentários, porque o cabeçalho fala do assunto.

O detalhe impresso é o que separa esta asserção de uma vazia: o hook de pagamento **tem quatro callbacks** (os métodos de pagamento do próprio WooCommerce) e nenhum deles é do plugin. Perguntar "o hook está vazio?" teria respondido sobre o WooCommerce.

## 6. As mesmas decisões da apresentação clássica, e as duas que são diferentes

Iguais, porque é o mesmo desenho: **o espaço vem de `gap`** e não de margem entre irmãos — o defeito que a secção 17 regista — e a asserção é uma **ausência**, com todas as declarações de margem do ficheiro a valerem zero; o **anel de foco nunca é removido**; e **todos os tokens que o ficheiro lê existem**, verificado contra o ficheiro de tokens.

Diferentes, porque o checkout é outro:

1. **A regra de responsividade é uma container query, e não uma media query.** O mesmo checkout coloca estes campos numa coluna larga ou na barra lateral estreita do resumo, portanto a pergunta "quanto espaço tenho" é feita à região, que é a única que a sabe responder. Uma media query apertaria o espaçamento num desktop largo que deu ao campo uma região estreita e deixaria tudo folgado num telefone cuja região é a largura toda — o contrário das duas respostas. O teste unitário exige a container query **e proíbe** qualquer `@media` no ficheiro, para que a decisão não se perca numa revisão.
2. **Os tokens passam a declarar-se neste escopo.** O `tokens.css` declarava-os em `.wccs-admin` e `.wccs-checkout`; num checkout de blocos nenhum dos dois está na página, e o ficheiro renderizava com os fallbacks. É a mesma família de defeito da WCCS-046 — um seletor à espera de uma classe que ninguém emite — e tem teste próprio, que lê os seletores do ficheiro de tokens e exige o deste escopo.

## 7. A duplicação que não foi copiada

Entregar a folha de estilo com os tokens como dependência declarada passou a existir em **um** sítio, `Checkout\Presentation`: as duas apresentações pedem-lhe o mesmo, e a ordem — variáveis antes de quem as lê — deixa de ser duas implementações que hoje concordam. `ClassicAssets` foi repontado para ele sem mudar de comportamento, o que a prova da WCCS-046 confirma: continua a receber o ficheiro e os tokens, com os mesmos `deps`.

## 8. Os dois defeitos que a prova apanhou — na própria prova

**1. Uma classe que não tinha o método.** A prova chamava `BlocksRenderer::mode()`, que vive em `BlocksAdapter`. O harness morreu com `exit 255` e **sem uma única linha de erro**, o que já tinha acontecido nesta sessão duas vezes. Foi identificado com um `register_shutdown_function` que imprime `error_get_last()`, e a conclusão é a mesma de sempre: **um harness que morre em silêncio não é um harness que passou**, e é por isso que o sweep lê o código de saída e não só a linha `RESULT`.

**2. "O hook está vazio?" é uma pergunta sobre o WooCommerce.** A primeira versão afirmava que o hook de pagamento não tinha filtros; ele tem quatro, todos do próprio WooCommerce. A asserção media o mundo em vez do sujeito — a terceira vez nesta sessão, depois do `assets=2` da WCCS-025 e dos três options da WCCS-008. Passou a perguntar quem está no hook e qual deles é o plugin, e imprime a contagem total ao lado, para que a diferença entre "não há nada" e "não há nada nosso" fique visível no output.

**3. Uma asserção que dependia do ambiente, corrigida antes de falhar.** A contagem de options no fim comparava com o valor medido no início, e o início vinha do que a execução anterior tivesse deixado. O harness passa a limpar ele próprio o slot publicado antes de medir — a mesma correção que a WCCS-008 recebeu no mesmo dia, e pela mesma razão: uma prova tem de passar em qualquer ordem.

## 9. O que NÃO foi provado, e porquê

**Um campo dentro de uma região viva.** Esta loja **não tem página de checkout de blocos**, por isso o caminho positivo é exercitado entregando à função os seus dois booleanos. Não foi observado nenhum campo colocado numa região real, porque **onde a plataforma permite inserir um campo de terceiros é uma decisão de integração** que ainda não foi observada — e é a mesma coisa que falta desde a F07. O que está provado é a entrega, a partição dos tipos e a ausência de acoplamento ao pagamento.

**A aparência dentro do checkout do WooCommerce.** A precedência entre este ficheiro e os estilos dos blocos não foi medida num browser: é a revisão da WCCS-063.

**Um leitor de ecrã sobre a região.** Como em todas as fases, a auditoria de acessibilidade é da WCCS-063.

## 10. Fecho

O gate da fase — *"Pedidos reais de sandbox nos cenários homologados, sem inputs de cartão próprios e sem Checkout Sidebar"* — continua **aberto**, e agora por três razões nomeadas: faltam três tarefas (WCCS-048 a WCCS-050), não há gateway nem credenciais de sandbox (`SANDBOX-PAYMENT`), e nenhum browser foi aberto. A cláusula "sem inputs de cartão próprios" está, essa, afirmada sobre o registo de tipos, sobre os pontos de extensão de pagamento e sobre o ficheiro servido.

**Próxima tarefa:** **WCCS-048**, o accordion e o resumo do pedido.
