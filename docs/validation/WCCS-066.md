# Registro de validação — WCCS-066

**Tarefa:** WCCS-066 · "Gerar pacote de release"
**Fase:** F13 · Release 1.0 e operação comercial
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "ZIP limpo com assets e autoload; instalação sem ferramentas de build."

**Resultado:** `dist/wc-checkoutsuite-1.0.0-rc.1.zip` — 150 ficheiros, 395,1 KB, SHA-256
`aa2afb9a…38d737`. Verificado por `bin/check-release.php`: completo, autocontido, **125 ficheiros PHP a
parsear**, **120/120 classes a resolver pelo autoloader do próprio plugin**, e **sem `vendor/` nem
`node_modules/`**. Confirmado por execução num processo PHP sem Composer e sem WordPress
(`bin/smoke-package.php`).

## 2. O que entra e o que não entra, e por quê

| entra | por quê |
|---|---|
| `src/` (120 ficheiros) | o plugin traz o seu próprio autoloader PSR-4 no ficheiro principal (ROADMAP §18): a release não precisa de `vendor/` |
| `build/` (8) | os bundles compilados **não são versionados** (ADR-0009), portanto o ZIP é construído a partir da árvore em disco — e o script **recusa construir** sem eles |
| `resources/` (10) | só os *assets*: as duas folhas de apresentação, os tokens (CSS e JSON), o registo de homologação de gateways e os binários. O JavaScript em `resources/` é a entrada do build, não o que corre |
| `docs/` (7) | o manual, a matriz, o registo de compatibilidade e a política de licenças: o pacote responde por si |
| root | ficheiro principal, `uninstall.php`, `readme.txt`, `CHANGELOG.md`, `LICENSE` |

Ficam de fora, por serem de desenvolvimento: `tests/`, `roadmap/`, `examples/`, `bin/`, `.github/`,
configuração de ferramentas, caches, `composer.json`/`package.json` e os registos de validação.

## 3. Instrumentos

- `bin/build-release.php` — constrói o ZIP com uma lista explícita do que entra, **falha** se algum
  ficheiro de runtime faltar, e imprime o manifesto, o tamanho e o SHA-256. A versão é lida do cabeçalho
  e **conferida contra `WCCS_VERSION`**: um ZIP cujo nome discorda da versão que o WordPress reporta é um
  bilhete de suporte à espera de acontecer.
- `bin/check-release.php <zip>` — verifica um pacote já construído: completude por nome, ausência de
  ficheiros de desenvolvimento, mapa do autoloader, e sintaxe de cada PHP pelo tokenizer do próprio PHP.
- `bin/smoke-package.php <dir>` — carrega o pacote extraído com nada além de PHP.

## 4. Fecho

Sem ferramentas de build na loja, e sem nada no pacote que só faça sentido em desenvolvimento.
