# Registro de validação — WCCS-070

**Tarefa:** WCCS-070 · "Publicar versão e registro de homologação"
**Fase:** F13 · Release 1.0 e operação comercial
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Checksums, versões testadas, resultados, changelog e plano de hotfix presentes."

**Resultado:** `docs/operations/release-1.0.0-rc.1.md`, o registo de release.

## 2. Onde está cada elemento exigido

| exigido | onde |
|---|---|
| Checksums | §1 — SHA-256 `aa2afb9a537e17672f26dbc3a0002b4c0cc969f594c3df3e54ffc1ad00b8d737`, e o comando que o reconfere |
| Versões testadas | §3 — WordPress, WooCommerce, PHP, HPOS, tema, base de dados, Node, browser |
| Resultados | §4 — todos os gates com números, incluindo as duas observações de browser e a prova do pacote |
| Changelog | §1/§7 apontam para `CHANGELOG.md` e para a secção de changelog do `readme.txt` |
| Plano de hotfix | §7 — seis passos, incluindo a regra de que **nenhuma migração de base de dados faz parte de um hotfix** |

E ainda: o **registo de homologação** (§5) diz o que está aprovado e o que não está — os cinco pontos não
aprovados estão nomeados, começando pelos gateways; o **plano de rollback** (§6) cobre esquema, versão e
dados; e o **gate da F13** (§8) é verificado cláusula a cláusula.

## 3. A decisão que o registo toma

A versão é **`1.0.0-rc.1`** e não `1.0.0`, por uma razão registada: as linhas de pagamento da matriz
funcional não foram executadas (`SANDBOX-PAYMENT`). Chamar 1.0.0 a isto seria afirmar o que a evidência
não sustenta; chamar-lhe candidata é o mesmo artefacto com um rótulo honesto, e é distribuível para
homologação tal como está.
