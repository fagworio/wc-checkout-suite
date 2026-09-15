# Fase 15 — Hardening

**Documento:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md` §22, §23, §24, §28, §30 · **Fase 15**
**Gate:** «release» — WCAG, segurança, desempenho, privacidade, HPOS, migração, rollback, upgrade, matriz de browsers, matriz PHP/WP/WC.

## 1. O que a fase encontrou

Esta é a fase dos «já deve estar feito», e por isso o primeiro trabalho foi **inventariar onde cada
item já está provado** em vez de reescrever provas. O inventário deixou dois buracos:

1. **HPOS não estava declarado.** O plugin lê e escreve pedidos só por `WC_Order` — a API que as duas
   storages respondem, provada em `F00-wccs-002-classic-hpos-proof.php` — mas nunca o tinha dito à
   WooCommerce. Sem a declaração, o ecrã *Features* da própria loja lista o plugin como não testado e
   um comerciante que ligue a tabela de pedidos própria é avisado por causa de um plugin que, na
   verdade, não toca em tabela nenhuma.
2. **O contrato de acessibilidade das telas administrativas não estava medido.** O contraste tem
   prova desde a WCCS-011 (33 pares de tokens, nenhum reprovado), e os atributos `aria` estão no
   código — mas «está no código» não é o mesmo que «a página renderizada anuncia isto», e a diferença
   só aparece num browser.

## 2. O que foi mudado

### 2.1 A declaração de HPOS

`src/Support/PlatformCompatibility` regista a declaração no `before_woocommerce_init` — o hook que a
própria WooCommerce documenta e o único momento em que o contentor dela a aceita. `declare()`
pergunta primeiro se a classe existe: uma WooCommerce anterior à funcionalidade não tem nada para
declarar, e responder-lhe com uma exceção seria transformar compatibilidade em falha.

Não é uma promessa sobre código que não existe: é a plataforma a registar uma resposta que este
plugin já sabia dar. E é verificável de fora — a prova lê o registo da WooCommerce
(`FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables')`) e confirma que
`wc-checkout-suite/wc-checkoutsuite.php` está lá e não na lista dos incompatíveis.

### 2.2 O resto, onde já vivia

Nada foi reescrito. A tabela abaixo diz onde cada item da fase tem a sua prova, e todas correm na
varredura, portanto continuam a ser executadas a cada alteração:

| Item da fase | Onde vive a prova |
|---|---|
| Segurança e sanitização | `F12-wccs-061-security-proof.php` |
| Desempenho e entregas | `F12-wccs-063-delivery-proof.php`, `F12-wccs-064-performance-proof.php` |
| Recuperação e rollback | `F12-wccs-065-recovery-proof.php` |
| Privacidade (exportar e apagar) | `F10-wccs-055-privacy-flows-proof.php` |
| Migração e transferência de schema | `F10-wccs-056-transfer-proof.php`, `F10-wccs-057-migration-proof.php` |
| Instalação, atualização e upgrade | `F13-wccs-069-install-upgrade-proof.php` |
| Permissões de ficheiro e upload privado | `F14-wccs-074-file-permissions-proof.php` |
| HPOS com as duas storages | `F00-wccs-002-classic-hpos-proof.php` |
| Contraste dos tokens | `docs/validation/WCCS-011.md` (33 pares medidos, 0 reprovados) e `src/Support/Contrast.php` |

## 3. Prova

| Prova | Resultado |
|---|---|
| `tests/Integration/FASE15-hardening-proof.php` (novo) | **12/0**, 3 notas: o header declara as três versões mínimas e a linha testada, e a instalação satisfaz as três (a linha comparada é `11.1` e não `11.1.0`, porque um header que ficasse errado ao primeiro patch é um header que ninguém volta a ler); a declaração de HPOS está no `before_woocommerce_init` **e** no registo da WooCommerce, com um pedido lido e escrito pela API das duas storages; o diretório privado existe com os três ficheiros de negação e um `.htaccess` que nega, e quem decide uma leitura é uma só classe; nenhuma opção do plugin contém um valor com forma de e-mail ou de documento, e os dois fluxos de privacidade estão nos hooks da WordPress |
| `tests/browser/fase15-accessibility.mjs` (novo) | **20/0** nas quatro telas administrativas: um só `h1` por tela e nomeia-a; **todos** os controles alcançáveis têm nome acessível (calculado como o browser o calcula — `label`, `aria-label`, `aria-labelledby` com alvo, ou o próprio texto); as tiras de separadores são `tablist` com nome e `tab` com `aria-selected`; há uma região viva para os estados da §5.4; e um diálogo é anunciado como diálogo, recebe o foco ao abrir, fecha com Escape e devolve o foco à página |
| `tests/js/screens/FieldsScreen.test.js` | a espera por omissão do Testing Library (1 s) passou a 5 s, com a razão escrita: esta suíte renderiza o editor inteiro e corre ao lado da análise estática e da varredura, e um teste que falha por carga é lido como regressão no ecrã — que é o oposto do que esta suíte existe para apanhar. As asserções não mudaram |
| `composer check` | phpcs e phpstan sem erros; **619 testes, 2148 asserções** |
| `npx jest` / `npx tsc --noEmit` / `npm run lint:js` / `npm run build` | limpos (**818 testes**, 51 suites); build compila |
| Varredura de integração | **80 harnesses, 1856 asserções, 0 falhas** |

## 4. Limites que ficam registados

- **A matriz de browsers é um browser.** As provas de browser desta fase e das anteriores abrem um só
  Chrome, em Linux, numa só resolução. Uma matriz a sério é uma tabela de ambientes — Safari, Firefox,
  Edge, iOS, Android — e não uma frase; o que fica afirmado é o que este ambiente responde. Não é
  simulado.
- **A matriz PHP/WP/WC é uma linha de cada.** A loja corre PHP 8.2.1, WordPress 7.1 e WooCommerce
  11.1.0. O que a prova acrescenta é que o build **declara** os seus mínimos e que a instalação os
  satisfaz, e que a linha declarada como testada é a que está a correr — não que outras linhas
  funcionem.
- **A acessibilidade medida é a das telas administrativas.** O checkout do cliente tem as suas provas
  próprias de apresentação e de estados (`F09-wccs-046`, `F09-wccs-047`, `F09-wccs-048`), mas o
  contrato de nomes acessíveis e de foco ali não foi medido por um browser nesta fase. O que existe é
  o scope class, os rótulos dos campos e os estados anunciados — registado como o que é.
- **A declaração de HPOS não substitui a prova de HPOS.** Ela regista uma resposta; quem prova que as
  duas storages são equivalentes para este plugin é o harness `F00-wccs-002`, que continua a correr.
