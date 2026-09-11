# ADR-0010 — O snapshot do pedido é o que torna a mudança de tipo segura para o histórico

WC CheckoutSuite · decisão tomada na execução da WCCS-024

**Status:** Aceito
**Fases de impacto:** F04, F07, F10
**Origem:** decisão de implementação tomada durante a WCCS-024, ao confrontar o `§5`/`§13` com o que já estava construído
**Evidência de apoio:** WCCS-023 (valores tipados gravados com a chave estável) e WCCS-024 (leitura histórica)

## Contexto

O `ROADMAP.md` diz, em dois lugares, que mudar o tipo ou a normalização de um campo exige uma **operação de
migração**, "não apenas um select no painel":

- `§5` linha 109: *"Renomear o label não renomeia a chave de armazenamento. Mudar o tipo ou a normalização
  exige uma operação de migração, não apenas um select no painel."*
- `ADR-0001`, regra 4: a mesma frase, como uma das regras decorrentes da autoridade de armazenamento.

Lida ao pé da letra, a frase admite duas implementações opostas:

**(A)** Recusar a mudança de tipo na publicação. O lojista que quer mudar um campo de texto para número não
consegue, porque a migração que a frase exige não existe.

**(B)** Permitir a mudança e garantir que ela não estraga o histórico — deixando a migração como a operação
que *reescreve* valores, exigida apenas quando alguém quer reinterpretar o passado.

A WCCS-023 gravou os valores com o **tipo canônico** e a **chave estável** do campo, e guardou ao lado a
revisão do schema. Isso resolve metade do problema: um pedido sabe o que valia, mas não o que aquilo *era* —
um `m` numa lista de opções não diz nada depois de o campo ser arquivado, e um `12345678909` não diz que era
um CPF.

## Decisão

**O pedido carrega o seu próprio registo mínimo — label, tipo e os labels das opções — e é ele que responde
quando o schema já mudou.**

Consequências diretas, todas obrigatórias:

1. **A mudança de tipo é permitida, e não reescreve nada.** O pedido antigo continua a ser lido com o tipo com
   que foi capturado. O tipo atual e o tipo capturado são comparados, e a divergência é **reportada** ao
   leitor; nunca é resolvida em silêncio.
2. **Nenhum componente reescreve um pedido histórico.** Trocar um label não toca em pedido algum. A prova da
   WCCS-024 afirma que o payload armazenado é byte a byte o mesmo depois de o schema ser alterado de três
   formas diferentes.
3. **A migração continua a existir como operação explícita, e continua a ser a única coisa autorizada a
   reescrever valores.** Ela não é implementada na v1 e nada neste plugin a insinua: o `§13` exige dry-run,
   backup, relatório e confirmação, e nada disso está construído.
4. **Um valor nunca é descartado por falta de metadados.** Um campo que o schema atual não conhece e que o
   snapshot também não conhece é devolvido com o identificador, o valor e um tipo **vazio** — não com um tipo
   adivinhado. Um tipo inventado formataria o valor como outra coisa, que é pior do que não formatar.

O snapshot é **mínimo** e é deliberado: label, tipo e opções. Nada que só afete o comportamento futuro
(obrigatoriedade, condições, largura, validadores, política de armazenamento) é registrado, porque nada disso
muda como um valor guardado se lê. Formatadores — máscara, preset — são regra de *renderização* e pertencem à
camada que renderiza (F05); gravá-los aqui seria uma promessa que nada lê.

## Consequências

- O `OrderFieldsService` passa a conhecer dois formatos: o 1, sem snapshot, e o 2, com ele. Um payload do
  formato 1 continua a ser lido — é para isso que serve o marcador de versão — e lê-se como um pedido que
  lembra os valores mas não os nomes.
- A leitura histórica precisa das definições publicadas para decidir o que mudou, e recebe-as por argumento
  em vez de as ir buscar: o serviço conhece o formato do pedido, não o armazenamento do schema.
- Um campo arquivado (`enabled: false`) continua a ler-se pelo schema, porque a definição fica no documento.
  Um campo **removido** do documento só se lê pelo snapshot — e é esse o caso que justifica o snapshot existir.
- Aumentar o snapshot (por exemplo com o formatador de máscara em F05) exige subir o formato e manter a
  leitura do anterior. O precedente do formato 1 fica registrado como o que se espera.
- A entrada de leitura distingue **de onde veio** o label (`snapshot`, `schema`, `unlabelled`) de **se o
  schema atual concorda** (`is_current`). São perguntas diferentes: um campo renomeado é do snapshot e está
  atual — nada está errado; um campo retipado é do snapshot e não está atual — e é isso que o leitor não pode
  varrer para debaixo do tapete.

## Alternativas consideradas e rejeitadas

- **Recusar a mudança de tipo na publicação** — é a leitura literal do `§5`, e foi rejeitada porque bloqueia
  o lojista sem lhe dar a operação que a frase exige, e porque o snapshot torna a mudança segura: o pedido
  antigo lê-se pelo registo que trouxe. Recusar seria impor um custo real para evitar um problema que já não
  existe.
- **Reescrever os pedidos históricos quando um label muda** — rejeitada explicitamente pelo `§13` (*"Não
  modificar os pedidos históricos em massa para apenas trocar label"*) e é a razão de ser do snapshot.
- **Guardar a definição inteira no pedido** — rejeitada: duplicaria a autoridade do schema dentro do pedido,
  e um pedido passaria a ter opinião sobre obrigatoriedade e condições, que não são facto histórico.
- **Guardar só a revisão e ler tudo pelo histórico de publicações** — rejeitada: exigiria que o histórico de
  publicações fosse eterno e completo, e uma revisão apagada pelo limite do histórico tornaria o pedido
  ilegível. O `§13` fixa o limite de histórico como configurável, o que é incompatível com depender dele.
- **Devolver os valores sem metadados quando nada os conhece** — rejeitada: um dicionário de valores sem
  nomes é precisamente um pedido ilegível. O identificador é pouco, mas é mais do que nada.
- **Assumir `text` para um tipo desconhecido** — rejeitada: formataria um número como texto e um booleano
  como texto, inventando uma leitura que ninguém autorizou.

## Como verificar conformidade

- **WCCS-024**, e é a prova central da tarefa: um pedido é criado, o schema é renomeado por inteiro, uma
  opção é renomeada, um campo é retipado, um campo é arquivado e um campo é removido do documento; em cada
  passo o pedido volta a ser lido e o payload armazenado é comparado byte a byte com o original.
- **WCCS-063 / F10:** o bloco "Campos do checkout" na edição do pedido mostra o label histórico e sinaliza
  `is_current` falso, sem reformatar o valor.
- **F12:** nenhuma rota de exportação ou projeção de API pode perder uma entrada cujo `source` seja
  `unlabelled`; a regra do `§13` de que chave com underscore não é controle de acesso continua a valer.
- Qualquer tarefa que acrescente campos ao snapshot sobe `OrderFieldsService::FORMAT` e mantém a leitura do
  formato anterior, como o formato 1 continua a ser lido.
