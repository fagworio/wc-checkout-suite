# Registro de validação — WCCS-068

**Tarefa:** WCCS-068 · "Preparar manual e release notes"
**Fase:** F13 · Release 1.0 e operação comercial
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Limitações, privacy, uninstall, backup e suporte claramente documentados."

**Resultado:** o manual (`docs/operations/merchant-guide.md`, 11 secções) cobre os cinco pontos, e as
release notes vivem em `CHANGELOG.md` e na secção de changelog de `readme.txt`.

## 2. Onde está cada um dos cinco

| exigido | onde | o que diz |
|---|---|---|
| Limitações | manual §8 | oito limitações, incluindo a homologação de pagamento vazia e o que acontece por causa dela na loja |
| Privacidade | manual §6 | as telas do próprio WordPress, o que um pedido devolve e o que fica, e que um documento entregue não é anexado nem copiado |
| Uninstall | manual §9 + `uninstall.php` | a tabela do que **sai** e do que **fica**, com a razão: um pedido histórico não é apagado por uma desinstalação |
| Backup | manual §10 | o que guardar, onde vive cada coisa, e o que acontece se a base for reposta sem os ficheiros privados |
| Suporte | manual §11 | os cinco elementos a juntar antes de pedir ajuda, todos obtidos sem editar código; o canal em si é decisão comercial e está dito que não está definido |

## 3. Notas

- O `readme.txt` segue o formato do WordPress.org e é o que um lojista lê antes de instalar; o
  `CHANGELOG.md` segue *Keep a Changelog* e diz explicitamente que **o esquema publicado é interface
  pública**, portanto uma mudança na sua forma é uma mudança maior mesmo sem API PHP a mover-se.
- O manual não promete o que o produto não faz: a secção 7 já dizia o que o guia não promete, e a 8
  acrescenta as limitações desta versão — incluindo a costura do campo controlado, registada em
  `docs/compatibility.json`.
