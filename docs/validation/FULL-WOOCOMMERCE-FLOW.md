# Fluxo completo de validação no WooCommerce

`tests/browser/full-woocommerce-flow.mjs` executa uma compra real em um WooCommerce
local com um cliente temporário, produto temporário e schema publicado pelo mesmo
`SchemaRepository` usado pelo plugin.

## Executar

Antes de executar, iniciar o ambiente conforme [`docs/operations/testing.md`](../operations/testing.md):

```bash
cd /home/joaofagner/workfolder/devilbox
docker compose up -d httpd php mysql
```

Com Devilbox/PHP, WooCommerce e o Chrome local disponíveis:

```bash
npm run build
WCCS_KEEP_DATA=1 npm run test:e2e:woocommerce
```

Sem `WCCS_KEEP_DATA=1`, o fixture restaura as opções e a página de checkout e remove
cliente, produto e pedido criados para o teste. Com `WCCS_KEEP_DATA=1`, os dados ficam
disponíveis para inspeção manual; as opções e o conteúdo original da página ainda são
restaurados.

Variáveis úteis:

- `WCCS_ORIGIN`: origem do navegador, por padrão `http://wpagf.dvl.to:8080`.
- `WCCS_PHP_CONTAINER`: container PHP, por padrão `devilbox-php-1`.
- `WCCS_OUT`: diretório de screenshots e `report.json`.

## Cobertura

O schema temporário inclui texto mascarado com normalizador/validador, textarea,
email, telefone, número, URL, select, radio, checkbox, data, hora, datetime, arquivo,
multiselect, checkbox-group, hidden, condição de visibilidade, destinos de pedido,
página de recebimento, conta do cliente e e-mails, além de fluxo de aprovação.

O relatório diferencia:

- `filled`: campo preenchido no formulário e submetido no pedido;
- `rendered-unavailable`: campo visível, mas bloqueado pela política de uploads;
- `not-rendered`: tipo não desenhado pelo adaptador Classic ou campo de layout;
- `degraded`: tipos que o Classic traduz para texto.

O navegador valida a autenticação, produto no carrinho, checkout, método de pagamento,
criação do pedido, página de recebimento e pedido na conta do cliente. O relatório e as
imagens ficam no diretório indicado por `WCCS_OUT` e não são adicionados ao repositório.

O fluxo usa o checkout Classic para cobrir uma compra completa. Os controles próprios
de `date`, `time`, `datetime`, `textarea`, `radio`, `multiselect` e `checkbox-group` no
checkout Blocks dependem de blocos internos `wc-checkoutsuite/*` presentes na página;
eles não são implicitamente injetados em uma página Blocks arbitrária. A cobertura
Blocks permanece nos testes de Store API e nos testes unitários dos componentes.
