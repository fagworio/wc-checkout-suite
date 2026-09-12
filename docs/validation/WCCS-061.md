# Registro de validação — WCCS-061

**Tarefa:** WCCS-061 · "Executar suíte de segurança"
**Fase:** F12 · Hardening, acessibilidade e matriz final
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "IDOR, XSS, CSRF, token replay, payloads, MIME e concorrência exercitados."

**Resultado:** **396 testes unitários PHP** · **1277 asserções de integração** em 54 provas, 0 falhas (15 novas) · **607 testes de JS** em 37 suítes · os gates verdes.

## 2. As sete classes

| classe | o que foi exercitado |
|---|---|
| **IDOR** | um utilizador da loja sem permissão sobre **aquele** pedido é recusado, e a recusa é **igual** à de um pedido inexistente — duas respostas diferentes seriam um oráculo de enumeração |
| **XSS** | o mesmo valor (`<script>alert(1)</script>`) guardado no pedido e lido nas **três** projeções: a página do cliente, o ecrã do staff e o corpo do e-mail |
| **CSRF** | uma submissão sem nonce **e** com nonce errado não escreve nada; e uma rota de escrita sem sessão é recusada |
| **token replay** | ligar um token que não é do dono liga **zero**, e repetir a submissão é idempotente |
| **payloads** | um ficheiro acima do limite é recusado **antes** de ser analisado, e um de versão mais nova é recusado em vez de lido em parte |
| **MIME** | a regra decide pelo que o ficheiro **é**: um `application/x-php` é recusado |
| **concorrência** | um escritor com uma revisão que já não é a atual é recusado, e não autorizado a sobrepor |

## 3. O que a suíte não pôde produzir, e é dito

**Nenhum upload multipart foi feito**, portanto o MIME é afirmado na **regra** que o decide, e não através de um ficheiro real: a recusa de um shell script chamado `document.txt` foi exercitada onde a funcionalidade foi construída (WCCS-042) e não é repetida aqui. **Nenhum processo PHP concorrente** foi usado: a concorrência é o compare-and-swap que as escritas tomam, exercitado com dois escritores no mesmo pedido, que é o que a loja consegue observar. E a recusa do **download** (um token que não existe e um que é de outro respondem o mesmo) foi provada onde foi construída (WCCS-044) — repeti-la exigiria um upload guardado, e esta loja não aceita nenhum (`UPLOAD-PRIVACY-ENV`).

## 4. O que a prova apanhou — e era dela

Quatro das suas próprias asserções estavam erradas antes de a suíte passar, e as quatro são a mesma família:

1. um pedido dito "anónimo" corria com o utilizador administrador ainda em sessão — o `200` estava **certo** e a asserção é que estava errada;
2. o harness não limpava os slots que possui antes de medir, o que faz uma execução falhada envenenar a seguinte — a quinta prova a receber esta correção;
3. a asserção de concorrência exigia que o **primeiro** escritor ganhasse, o que depende do estado de partida e não é o que a loja garante; passou a afirmar a garantia — um escritor com revisão obsoleta é recusado;
4. e duas asserções usavam métodos que **não existem** (`DownloadPolicy::refusal_status`, `UploadRules::ALLOWED_MIMES`), inventados de fora; a primeira morreu em silêncio (a sexta vez nesta sessão) e as duas foram substituídas por asserções sobre o que existe.

## 5. Fecho

A F12 abre com a suíte de segurança. As tarefas seguintes são a matriz funcional (062), a observação de browser e acessibilidade (063, onde convergem todas as lacunas registadas), a medição de performance (064) e os testes de recuperação (065).
