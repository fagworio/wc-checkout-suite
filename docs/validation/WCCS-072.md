# WCCS-072 · A aba "Vínculos e exibição" no inspetor

**Fase:** F14 · **Depende de:** WCCS-071
**Aceite:** "Por destino: habilitar, seção, título, ordem e ações; somente propriedades suportadas; a mesma linguagem do resto do inspetor."

## 1. O que foi entregue

O inspetor passou a ter **quatro abas** — Geral · Regras · **Vínculos** · Exibição — e a terceira é a
configuração que o roadmap nomeia. O nome completo ("Vínculos e exibição") aparece como título do bloco
dentro dela, porque a aba é estreita e precisa de um rótulo curto.

Por destino, a aba oferece: **habilitar**, **seção**, **título apresentado**, **ordem de exibição** e
**ações permitidas**. As ações oferecidas são apenas as que aquele destino pode executar — as que o
servidor publica —, portanto aprovar nunca aparece num destino de cliente.

Abaixo disso, e **separado**, o **fluxo de aprovação**: exigir análise manual, área, seção, estado,
permissão de correção, de reenvio e de mostrar a situação ao cliente. Desligado por defeito, e o texto
diz o que isso significa: sem o fluxo, o upload é apenas uma informação vinculada ao pedido.

Do lado do modelo, esta tarefa é a que **fecha a substituição**:

- `createField` deixa de nascer com `visibility: { admin_order: true }` e passa a não ter vínculo nenhum.
- O mapa booleano deixa de ser publicado (`to_array()` perdeu a chave `visibility`).
- Todos os leitores passaram a ler o modelo: `OrderFieldsController`, `CustomerOrderFields`,
  `OrderEmailFields` (dois pontos), `OrderFieldsPanel` (dois pontos) e a política de privacidade.
- `BulkActions` — o componente legado que ainda editava o mapa antigo — foi removido, com o re-export e
  os testes. A operação em lote passou a `setFieldsDestinations`, que é a que a barra de desenho usará
  quando ganhar a ação "destinos".

## 2. Achados desta tarefa

1. **O mapa antigo tinha cinco leitores que eu não tinha encontrado por leitura.** O `grep` por
   `['visibility']` mostrou um; a varredura de integração mostrou os outros quatro — `CustomerOrderFields`,
   `OrderEmailFields` e os dois pontos de `OrderFieldsPanel` liam o array guardado diretamente. Foi a
   varredura que os apanhou, não eu: `RESULT: 13 passed, 6 failed` no F10-052 e no F10-051 foi o que
   apontou o caminho. Cada um passou a `shows_in()`, que é o modelo.

2. **Um defeito de acessibilidade que a minha própria asserção criou.** A primeira versão dava a todos os
   interruptores o mesmo nome acessível — "Mostrar neste destino", sete vezes na mesma página. Um leitor de
   ecrã não conseguia distinguir as opções. O rótulo passou a nomear o destino ("Mostrar em Order screen,
   for staff") e o bloco ganhou `role="group"` com o nome do destino. O teste que falhou estava certo e o
   componente é que estava mal.

3. **Código legado que contradizia o modelo.** `BulkActions` continuava a oferecer "visibilidade por
   público" e a escrever a chave que deixou de existir. Mantê-lo era manter dois modelos para o mesmo
   facto — a classe de defeito que este projeto persegue desde o ADR-0007. Foi removido.

4. **A projeção de compatibilidade cumpriu o seu prazo.** Entrou em WCCS-071 com a data de saída marcada
   ("WCCS-072 move-os") e saiu nesta tarefa, no mesmo commit em que os leitores foram movidos. Sem isso,
   haveria dois lugares a decidir quem vê o quê.

## 3. Evidência

| Instrumento | Resultado |
|---|---|
| `composer check` | 407 testes, 1468 asserções |
| `tests/Integration/F14-wccs-072-links-inspector-proof.php` | **13 passaram, 0 falharam** |
| `tests/js/views/FieldProperties.test.js` | 12 especificações, 4 novas para esta aba |
| `npx jest` | 635 testes em 42 suites |
| `tsc`, `lint:js`, `build` | sem erros; três bundles |
| varredura de integração | 60 harnesses, 1390 asserções, zero falhas |

O harness prova o que o teste de browser não pode: que **não há deriva** entre o que a aba oferece e o que
o servidor aceita (cada destino e cada ação), que o que a aba escreve volta inteiro, que a aprovação
sobrevive como configuração separada, que um campo sem vínculo não é mostrado por nenhuma das três
superfícies que o mostrariam, e que um documento guardado com o mapa antigo continua a ser lido.

## 4. Limites

- A **ação em lote "destinos"** existe no modelo (`setFieldsDestinations`) mas ainda não tem controlo na
  barra do desenho; a barra continua com habilitar/desativar/arquivar. É trabalho da mesma fase.
- A **aprovação** está editável e validada; o fluxo que a executa é WCCS-075.
- A **projeção de compatibilidade** foi removida: uma integração de terceiros que lesse `visibility` do
  documento guardado deixaria de o encontrar. Não há contrato publicado para essa chave — o documento é
  interno — e a decisão está registada aqui em vez de num comentário.
