# Registro de validação — WCCS-064

**Tarefa:** WCCS-064 · "Medir performance e estabilidade"
**Fase:** F12 · Hardening, acessibilidade e matriz final
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Budgets reportados; zero loops/handlers duplicados; memória após refresh estável."

**Resultado:** **16 asserções de integração** (prova nova `tests/Integration/F12-wccs-064-performance-proof.php`) e **11 asserções de browser** (novo `tests/browser/performance-observation.mjs`), 0 falhas; a varredura passa a **56 provas / 1306 asserções / 0 falhas**.

## 2. Os orçamentos da secção 21, medidos

| orçamento (ROADMAP §21) | medido | veredicto |
|---|---|---|
| bundle Classic até 80 KB gzip | **22,7 KB gzip** (78,2 KB cru) | dentro |
| bundle Blocks até 120 KB gzip | **17,1 KB gzip** (62,6 KB cru) | dentro |
| nada no catálogo quando não há uso | 0 handles enfileirados fora do checkout, com 50 campos publicados | dentro |
| nenhuma consulta externa obrigatória para campos básicos | 158 pedidos no carregamento, **3** dos ficheiros do plugin, todos da própria loja | dentro |
| zero AJAX por tecla em validadores locais | **0 pedidos** em 12 teclas no campo do checkout e **0** em 4 teclas no componente do plugin | dentro |
| nenhuma chamada `save()` por campo | **0 pedidos** às rotas do plugin (`/wp-json/wc-checkoutsuite/`) em toda a execução | dentro |
| cache do schema sem dados pessoais | sem identificadores de cliente/pedido, **sem o valor submetido** (`ABC-123` ausente), e nenhuma opção do schema é autoload | dentro |
| latência p95 adicional ≤ 30 ms (50 campos / 100 regras) | **≈ 0 ms** — a diferença entre p95 com e sem as 100 regras fica **abaixo do ruído** da própria medição | dentro |

Medições de apoio, sem orçamento atribuído: bundle de admin 22,8 KB gzip; `checkout.css` 3,1 KB, `blocks.css` 2,2 KB, `tokens.css` 1,8 KB gzip.

## 3. Amostras e ambiente (o que a secção 21 exige documentar)

| item | valor |
|---|---|
| PHP | 8.2.1, opcache **desligado** |
| WordPress / WooCommerce | 7.1 / 11.1.0 |
| Base de dados | MariaDB no mesmo host, dentro do contentor do Devilbox |
| Método | `rest_do_request` em processo (o endpoint real), 120 amostras para o endpoint e 40 para a passagem clássica |
| Dataset | 50 campos `text`, 100 nós de comparação em 50 grupos `any`, fontes `country`, `cart_total` e outro campo |
| Hardware | máquina de desenvolvimento do autor, tudo em contentores — **não** é hardware de produção e os números não são uma promessa de produção |

| medição | p50 | p95 | máx |
|---|---|---|---|
| endpoint de validação, 100 regras | 0,94–1,11 ms | 1,11–2,01 ms | 1,69–2,26 ms |
| endpoint de validação, mesmo documento sem regras | — | 1,05–1,75 ms | — |
| passagem clássica dos 50 campos (normalização + validação) | 2,10–2,66 ms | 2,28–4,98 ms | 2,59–7,51 ms |

As regras custam menos do que a resolução desta medição: a diferença de p95 tem sinal que muda entre execuções. É isso que a prova reporta — "abaixo do ruído" — em vez de um número negativo apresentado como custo.

## 4. Estabilidade

- **Handlers duplicados: zero.** 29 callbacks do plugin em todos os hooks, nenhum `(tag, prioridade, callback)` repetido. `Plugin::boot()` chamado uma segunda vez **não acrescenta nem remove hook nenhum** (29 antes, 29 depois), que é o que torna o boot idempotente por construção e não por confiança.
- **Loops: nenhum iniciado por um pedido.** O plugin não agenda nenhum trabalho periódico no boot; o único trabalho agendado vive do cron da retenção de uploads, que já era o desenho (WCCS-045). No browser, 12 teclas produzem 12 eventos e **zero** pedidos — não há ciclo de atualização a alimentar-se de si próprio.
- **Memória após refresh, com grupo de controlo.** Seis recargas da mesma página, medindo depois de forçar uma recolha de lixo (`--js-flags=--expose-gc` + `gc()`), porque sem isso a série mede a cadência do coletor e não o que a página retém:

| série | nós de DOM | heap (KB), 6 recargas | deriva |
|---|---|---|---|
| com o documento do plugin publicado | 892 nas seis | 18113 → 18356 → 18463 → 18684 → 18784 → 19000 | **+4,9%** |
| **controlo**: sem nada publicado | 885 nas seis | 17343 → 24417 → 17669 → 17892 → 17989 → 19170 | **+10,5%** |

O DOM é **exatamente** estável nas seis recargas, com e sem o plugin. A deriva de heap existe **também sem o plugin** e é maior sem ele: o que cresce é a página e o browser (o admin bar, as caches de sessão, os outros plugins ativos) e não este plugin. A contribuição deste plugin para a página são **7 nós** — o campo que a API de campos adicionais da WooCommerce desenha a partir do documento publicado.

- **Nada foi escrito ao medir:** o número de opções `wccs_%` no fim é o mesmo do início, e o schema publicado não é autoloaded, portanto uma página que nunca o lê não paga nada por ele.

## 5. O que a medição apanhou — e fica registado, não corrigido aqui

A medição de teclas no componente do próprio plugin encontrou algo que **não** é de performance: o campo controlado não conserva o que foi escrito. Quatro teclas, quatro leituras: o valor volta a vazio a cada uma.

A causa está no caminho de registo por omissão e é precisa: o componente é controlado pelo `value` que **o chamador** lhe passa (é o que `resources/blocks/fields.js` documenta), e o registo por omissão constrói os elementos **uma vez** com o valor que a loja tinha nesse momento e entrega-os com `component: () => elements.shift() ?? null`. Como nada volta a renderizar o componente com o valor que ele acabou de reportar, o React repõe o valor da prop — e um segundo render do mesmo bloco recebe `null`.

Isto pertence a `BLOCKS-CONTROLLED-FIELD-PLACEMENT`, a lacuna já registada: o registo é válido e o componente desenha, mas a colocação por omissão é a costura que o próprio bundle documenta como sendo da tarefa de integração. Fica registado em `docs/compatibility.json` com a evidência, e **não** é corrigido nesta tarefa: o critério de aceite de WCCS-064 é orçamentos e estabilidade, e fechar a integração é uma mudança de desenho com provas próprias (F07).

## 6. O que não foi medido, e é dito

- **Não é hardware de produção nem tráfego real**: os números vêm de contentores na máquina do autor, com um pedido de cada vez. O p95 de 30 ms é um objetivo de engenharia cumprido neste ambiente, não um desempenho comprovado em produção — a própria secção 21 o diz.
- **Sem provedores remotos**: nenhuma validação remota foi exercitada, portanto o custo de rede não está nesta medição.
- **O checkout clássico não é servido por esta loja** (`CLASSIC-TEST-SURFACE`), portanto o "zero AJAX por tecla" do bundle clássico é afirmado pela suíte jsdom (WCCS-025, WCCS-040) e não num browser; o que o browser mediu do lado clássico foi o comportamento no componente do Blocks e no campo do checkout de blocos desta loja.
- **A deriva de heap não é atribuível com precisão**: o grupo de controlo mostra que ela não é deste plugin, mas ambas as séries correm com PayPal e Mercado Pago ativos, e separá-los exigiria desativar plugins — fora da raiz deste plugin.

## 7. Como reproduzir

```bash
# integração (entra na varredura)
wp eval-file tests/Integration/F12-wccs-064-performance-proof.php

# browser (a loja está em "coming soon": ver WCCS-063)
wp eval-file tests/Integration/support/browser-fixture.php seed
wp eval-file tests/Integration/support/admin-session.php create admin
WCCS_PRODUCT=2777 WCCS_COOKIE="<cookie_name>=<cookie_value>" node tests/browser/performance-observation.mjs
wp eval-file tests/Integration/support/browser-fixture.php clear
```

O grupo de controlo da memória é a mesma execução depois do passo `clear`, sem nada publicado.
