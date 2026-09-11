# Registro de validação — WCCS-045

**Tarefa:** WCCS-045 · "Criar retenção e limpeza"
**Fase:** F08 · Upload privado nos dois checkouts
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Temporários expiram; draft/falha/efetivo têm regras testadas; cleanups podem repetir."

**Resultado:** **362 testes unitários PHP** (8 novos) · **985 asserções de integração** em 40 provas, 0 falhas (13 novas) · **581 testes de JS** em 34 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Uploads/UploadsRetention.php` | As regras e o trabalho que as aplica |
| `src/Domain/Uploads/UploadRepository.php` | `candidates()` e `forget()`: o que olhar, e esquecer |
| `src/Plugin.php` | O trabalho agendado |
| `tests/Integration/F08-wccs-045-retention-proof.php` | As regras sobre linhas e ficheiros reais |

## 3. As três vidas de um upload

- **temporário** — carregado num checkout que não foi finalizado. Não tem pedido a que pertencer, ninguém o vai ler, e é o caso que enche um disco: um cliente que abandona o carrinho deixa um documento atrás. **Expira.**
- **efetivo** — preso a um pedido que existe. O pedido é a razão de ter sido guardado, e enquanto existir o ficheiro faz parte dele. **Fica.**
- **órfão** — preso a um pedido que já não existe. A razão desapareceu, logo ele também. É o caso que uma loja que apaga pedidos de teste acumula sem notar.

**Uma falha não é um caso, e dizer isso faz parte da regra:** uma recusa no endpoint não escreve nem o ficheiro nem a linha, portanto não há nada para expirar. Um limpador que procurasse falhas estaria a procurar linhas que nunca existiram — e o teste unitário fecha o vocabulário nisto: três respostas, nenhuma que signifique "falhou".

E uma linha que diz ser `ordered` mas não nomeia pedido nenhum também é órfã: **um ficheiro que nada consegue justificar guardar não se guarda.**

## 4. `cleanups podem repetir` é a cláusula que se afirma, não se promete

- A segunda execução remove **zero** e deixa a tabela exatamente como estava;
- uma linha que **desapareceu entre a leitura e o apagamento** — que é o que duas limpezas sobrepostas produzem — não é um erro;
- o trabalho corre em **lotes**, porque uma loja que nunca limpou pode ter milhares e um cron que tentasse apagar tudo num pedido expirava e não apagava nada.

**O ficheiro vai antes da linha.** Ao contrário, uma falha entre os dois deixaria um ficheiro sem nada a apontar para ele — exatamente o órfão que este trabalho existe para remover, e que nada voltaria a encontrar. Assim o pior caso é uma linha cujo ficheiro já não está, que a execução seguinte apaga.

## 5. O trabalho agendado

Um **evento único** que se reagenda no fim de cada execução, e não um evento recorrente: um recorrente que continua a disparar enquanto o site não o consegue correr — um cron que não volta, um fatal num plugin vizinho — acumula execuções perdidas; um trabalho que agenda a sua própria ocorrência seguinte tem no máximo uma à espera. Agendar duas vezes não empilha uma segunda, e a prova afirm que o array de cron não cresce.

## 6. O defeito que a contagem no output denunciou

O `sweep()` incrementava o tally pela **decisão** em vez de pelo **resultado**:

```php
++$tally[ $decision ];   // 'expire', não 'expired'
```

Criava uma chave `expire` que ninguém lia e deixava `expired` a zero. Nenhum teste o teria apanhado — a limpeza funcionava, os ficheiros desapareciam — e foi visto porque a asserção **imprime o tally** que observou: `{"expired":0,…,"expire":1}`. É a terceira vez nesta sessão que o detalhe impresso, e não a condição, é o que denuncia um defeito.

A segunda falha era da própria prova: esperava `kept = 2` quando só **uma** linha é sequer candidata — o upload dentro do prazo não é examinado. A expectativa passou a dizer a verdade e a razão.

## 7. O que NÃO foi provado, e porquê

**Um cron a correr sozinho.** O trabalho foi exercitado chamando-o diretamente e através do seu hook, e o agendamento é afirmado no array de cron do WordPress; o que não foi observado é o WordPress a dispará-lo por tempo (o cron deste ambiente não corre em WP-CLI). A propriedade que importa — correr duas vezes é seguro — está provada.

**Uma retenção configurável.** Quanto tempo um pedido efetivo é guardado não é uma decisão desta tarefa: enquanto o pedido existir, o ficheiro faz parte dele. Uma política de retenção por loja (apagar documentos N dias depois do pedido) é uma configuração que ainda não existe, e fica registada como o que falta em vez de inventada.

**A limpeza num browser ou numa loja real.** Como o resto da fase.

## 8. Fecho da fase F08

As cinco tarefas estão concluídas: o armazenamento e a tabela (041), o endpoint e os tokens (042), o componente (043), o vínculo e o download (044) e a retenção (045).

O gate — *"upload privado permanece privado, cota e retenção aplicadas, download só com autorização"* — está **cumprido na costura e aberto na observação**, como todos os outros: a cota é aplicada, a retenção é aplicada, o download passa por uma política e a privacidade do armazenamento é **verificada a cada instalação** em vez de presumida. E é essa verificação que deixa esta loja com o recurso desligado: o bloqueador `UPLOAD-PRIVACY-ENV` continua a ser a primeira coisa a resolver antes de um único byte ser aceite aqui.

**Próxima tarefa:** **WCCS-046**, a primeira da F09 — a apresentação do checkout clássico com o layout do anexo.
