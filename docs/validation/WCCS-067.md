# Registro de validação — WCCS-067

**Tarefa:** WCCS-067 · "Revisar licenças, nome e distribuição"
**Fase:** F13 · Release 1.0 e operação comercial
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Dependências inventariadas; política de atualização, suporte e marca revisada."

**Resultado:** `docs/operations/licensing-and-distribution.md`, com o inventário produzido por
`bin/licence-inventory.php` a partir dos ficheiros de lock — não da memória de ninguém.

## 2. O que foi decidido

- **Licença `GPL-2.0-or-later`**, declarada em `LICENSE` e no cabeçalho do plugin. Não é preferência
  comercial: um plugin que corre dentro do WordPress tem de ser compatível com GPL para ser instalável.
  Autor, Author URI e Plugin URI **continuam omitidos** de propósito — são identidades de distribuição e
  inventá-las seria pior do que não as ter.
- **Inventário:** **0 dependências de runtime**; 1507 pacotes de build (npm, dominados por MIT/ISC/
  Apache-2.0, com o *tooling* da WordPress em GPL-2.0-or-later, como o plugin) e 41 de teste (Composer,
  com cinco em LGPL-3.0-or-later). Nenhum deles entra no ZIP. Uma dependência transitiva de build,
  `svg-tags`, não declara licença — o que é um problema para *distribuir* e não para *construir*, e o
  verificador de release afirma que não é distribuída.
- **Política de atualização:** atualizações são ficheiros, não um serviço; **nenhum servidor de licença
  no MVP** (ROADMAP §27), e por consequência estrutural nada pode expirar e bloquear uma compra.
  Verificado: `grep -rni 'licen[cs]e' src/ resources/` não devolve código de licenciamento, e a única
  chamada remota do plugin (`wp_remote_get` em `PrivateStorage`) pergunta a esta loja se o diretório
  privado é servido por HTTP — a verificação que decide se os uploads são aceites.
- **Marca e nomes:** nome, pasta, ficheiro principal, domínio de texto, namespace REST e prefixo de
  armazenamento estão tabelados no documento, cada um com o sítio onde é decidido uma só vez.

## 3. O gate da F13, verificado

"Sidebar ausente" está afirmado com evidência: `grep -rni sidebar src/ resources/` devolve apenas os
tokens da navegação da **administração** e dois comentários sobre a coluna de resumo da WooCommerce. Não
existe Checkout Sidebar: nem tarefa, nem componente, nem rota, nem opção.
