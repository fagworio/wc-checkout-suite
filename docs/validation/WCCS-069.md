# Registro de validação — WCCS-069

**Tarefa:** WCCS-069 · "Executar smoke de instalação/upgrade"
**Fase:** F13 · Release 1.0 e operação comercial
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Instalação limpa e upgrade/rollback com pedidos existentes aprovados."

**Resultado:** **22 asserções** em `tests/Integration/F13-wccs-069-install-upgrade-proof.php`, 0 falhas.

## 2. Instalação — sobre o artefacto que é distribuído

O smoke corre **no ZIP**, não na árvore de desenvolvimento, que tem `vendor/` dentro. Extrai o pacote
para uma pasta que ele próprio possui, confirma que a pasta criada é a que o WordPress criaria
(`wc-checkout-suite/`), que não há `vendor/` nem `node_modules/`, e que todos os ficheiros lidos em tempo
de execução estão lá (bundles, folhas, tokens, registo de homologação, `uninstall.php`, `.pot`).

Depois corre `bin/smoke-package.php` num **processo PHP sem Composer e sem WordPress**, que carrega o
ficheiro principal do pacote com sete funções do WordPress substituídas (as únicas que ele chama ao ser
incluído) e resolve **120/120 classes** pelo autoloader do próprio plugin. É a única forma de afirmar
"instalação sem ferramentas de build" sem uma segunda loja.

A ativação é exercitada na loja que já tem o plugin a correr: completa, não deixa registo de
pré-requisitos em falta, e mantém a tabela de uploads.

## 3. Upgrade e rollback, com um pedido que já existe

1. Publica-se o esquema anterior, cria-se um **pedido real** (`wc_create_order`, com produto) e
   escrevem-se os valores pelo serviço de campos do próprio plugin.
2. **Upgrade:** o campo muda de tipo e um segundo campo aparece. O pedido continua a ler **exatamente**
   o que o cliente respondeu, e o campo que ainda não existia **não** ganha valor nenhum.
3. **Rollback:** `restore` publica o conteúdo anterior como revisão nova. O pedido continua igual, e
   continua legível pela API de pedidos.
4. **Downgrade:** um documento de uma versão mais nova é recusado por inteiro e o pedido mantém-se
   legível — que é o estado de uma loja que reverteu o plugin.
5. O pedido criado pelo harness é eliminado no fim, e nenhuma opção fica para trás.

## 4. O que não foi possível

Uma **instalação limpa numa loja nova** exigiria um segundo WordPress: escrever em `wp-content/plugins/`
de outra instalação sai da raiz deste plugin e não foi feito. O que se fez é o equivalente verificável —
o pacote, extraído, carregado e resolvido sem ferramentas — e está dito em vez de substituído por uma
afirmação.
