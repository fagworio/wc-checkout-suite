# Registro de validação — WCCS-029

**Tarefa:** WCCS-029 · "Implementar endpoint de validação"
**Fase:** F05 · Presets Brasil, IMask e validação remota
**Prioridade:** `required_v1` · **Dependências:** F04
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Sessão, rate limit, timeout, abort e request ID impedem estado obsoleto."

**Resultado:** **215 testes unitários PHP** · **742 asserções de integração** em 25 provas, 0 falhas (34 novas) · **397 testes de JS em 26 suites** (14 novos) · os **7 gates** verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Http/Checkout/ValidationController.php` | A rota `POST /wc-checkoutsuite/v1/validate` |
| `resources/checkout/remote.js` | O transporte: debounce, abort, request id, timeout |
| `ClassicAssets::bootstrap_data()` | Endereço, nonce e revisão entregues ao bundle |

## 3. Os cinco, um a um

| | Onde é provado |
|---|---|
| **Sessão** | o limite conta contra `WC()->session`; o harness afirma que a sessão existe e que 20 pedidos passam e o resto não |
| **Rate limit** | 20 por campo por 60 s; os 3 seguintes recebem `429` com um código que o cliente sabe tratar |
| **Timeout** | o transporte desiste e responde `unavailable` — **nunca `valid`** |
| **Abort** | uma segunda pergunta sobre o mesmo campo aborta a que está em voo |
| **Request ID** | o servidor devolve o id que recebeu; uma resposta com outro id é descartada, não mostrada |

Provado em dois lugares porque são duas metades: o servidor em integração, o transporte em jsdom, com um `fetch` que o teste mantém aberto — porque o trabalho do transporte é exatamente comportar-se bem à volta de um pedido que é lento, substituído ou nunca respondido.

## 4. O que o endpoint deliberadamente não é

O `§10` descreve o endpoint por aquilo que ele **não** pode ser, e cada uma dessas frases virou uma asserção.

**Não é uma autorização.** A resposta não carrega token, nem `expires`, nem assinatura, nem `validated_at` — há sete asserções contra sete nomes de token possíveis — e leva `Cache-Control: no-store`. Um "valid" em cache é exatamente a autorização permanente que o `§10` proíbe, e é por isso que a revisão viaja na resposta: ela diz a que estado a resposta se aplica.

**Não é um oráculo sobre outras pessoas.** A chave da resposta é fixa e todas as chaves estão sempre presentes, portanto não se aprende nada por qual chave chegou. O valor digitado **nunca** é devolvido: já está no ecrã de quem o escreveu, e repeti-lo poria um documento num corpo de resposta que não precisa de carregar nenhum. E todas as regras que o endpoint pode correr são aritmética sobre o próprio valor — não há consulta, nem registro, nada que saiba a quem um documento pertence.

**Não é um lugar para correr código do comprador.** O `§10` lista quatro coisas que o endpoint não pode aceitar. A verificação envia as quatro — uma regex, um callback, um caminho e um endpoint remoto — e afirma que a resposta é **a mesma** que o mesmo valor recebe sozinho. Não há onde as pôr no contrato, e é assim que não são aceitas.

**Não é gratuito.** Vinte perguntas por campo por sessão por minuto. O que isso protege não é o cliente, é o serviço.

## 5. Um formulário desatualizado é informado, não recusado

O `§13` é explícito: *"Checkout aberto em revisão antiga: servidor revalida contra a revisão publicada e informa mudanças."*

A primeira versão do controlador **recusava** quando a revisão do cliente não era a publicada. Está errado por duas razões opostas: recusar descarta a resposta de um cliente por uma diferença que ele não pode ver, e julgar o valor por regras que ele nunca viu seria pior. A resposta passou a ser calculada **contra o documento publicado** — que é o que será usado — e a diferença é **reportada** em `schema_changed`, com a revisão a que a resposta realmente se aplica.

## 6. Um defeito que a prova apanhou

O limitador tinha **um balde por sessão**, não um por campo. Um cliente que corrige um documento várias vezes gastava a permissão de que o resto do formulário precisava, e o segundo campo a ser conferido era recusado por causa do primeiro.

A prova apanhou-o porque perguntou por um segundo campo depois de o primeiro ter esgotado a sua permissão — se tivesse afirmado apenas o limite, o defeito passava. O `§10` diz "limite por sessão/campo" e o comentário do código agora diz porquê, incluindo que a primeira versão tinha exatamente este erro.

## 7. O defeito mais sério não estava no código

**A WCCS-028 foi reportada com os sete gates verdes e o PHPUnit estava a falhar.**

Um teste da WCCS-026 afirmava que nenhum preset declara um validador. A WCCS-028 tornou isso falso — corretamente — e o teste falhou. O que escondeu a falha foi a forma como li o gate:

```
composer check 2>&1 | grep -E "OK \(|No errors|ERROR|FOUND|WARNING" | head -5
```

O `|` substitui o código de saída do `composer` pelo do `grep`, e o padrão não incluía `FAILURES!` nem `Tests:`, portanto a única linha coincidente era o `[OK] No errors` do PHPStan — que se lê como um gate a passar.

É a **terceira vez nesta sessão** que um gate falha sem se ver, e a raiz é a mesma das outras duas: **um gate cuja falha não é visível é pior do que um gate ausente, porque parece estar a funcionar.**

Corrigido como prática, não como remendo: **cada gate é lido pelo seu código de saída**, e filtrar serve para ler, nunca para julgar. A varredura de integração passou também a verificar o código de saída de cada harness, não só a linha `RESULT` — um harness que morre a meio imprime `NO RESULT` e sai diferente de zero, e a verificação anterior teria contado isso como "sem resultado" em vez de falha.

O que teria apanhado isto: a CI, que corre cada gate como passo separado com o seu próprio código de saída. A falha era invisível para mim, não para o projeto.

O registo da WCCS-028 foi corrigido em vez de reescrito: o documento diz agora que a sua afirmação de gates verdes era falsa quando foi escrita.

## 8. O que NÃO foi provado, e porquê

**O fio entre as duas metades.** O bundle recebe o endereço, o nonce e a revisão, e o transporte existe e está provado — mas **nada no checkout o chama ainda**. A interface que reage à resposta é a WCCS-030, e criar o transporte sem chamador seria um componente ligado a nada.

**O comportamento real de um timeout num browser.** O `§10` exige que uma regra crítica não libere em silêncio por timeout: a interface tem de oferecer alternativa explícita ou bloquear com orientação. O estado de que essa interface precisa — distinto de válido e de inválido — é o que esta tarefa produz; usá-lo é a WCCS-030.

**Uma página de checkout renderizada.** O endpoint é exercido pela rota REST real, com sessão real; o transporte é exercido em jsdom. Observar os dois a funcionar numa página é a `CLASSIC-TEST-SURFACE`.

## 9. Próxima tarefa

**WCCS-030 — "Implementar UX de erro"**. Aceite: *"Mensagens por campo; primeiro erro focado; regra crítica revalidada no submit."*

É a última tarefa da fase, e é onde as duas metades desta se ligam: o transporte é criado com o que o bundle já recebe, a resposta vira estado do campo, e o submit revalida no servidor em vez de confiar no que foi respondido antes.
