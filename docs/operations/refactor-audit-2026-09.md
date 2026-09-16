# Auditoria de refatoração — setembro de 2026

## Resultado

A base foi auditada antes da remoção. Não há arquivo PHP ou módulo JavaScript
órfão no grafo de execução atual: os entrypoints `resources/admin/index.js`,
`resources/checkout/index.js` e `resources/blocks/index.js` alcançam todos os
módulos de recursos, e o bootstrap PHP registra os módulos de domínio,
integração, checkout, conta, uploads, privacidade, status e workflow.

Os componentes `Tabs`, `ErrorSummary`, `EmptyState`, `SortableList` e os badges
continuam no projeto porque fazem parte da biblioteca documentada e dos testes
de acessibilidade, mesmo quando uma tela ainda não os consome diretamente.
As rotinas de migração e compatibilidade também foram preservadas: elas leem
formatos antigos para permitir atualização segura de lojas existentes.

## Removido

Foi removido de `resources/admin/app/components/components.css` o CSS sem
consumidor no desenho atual:

- áreas antigas do editor (`wccs-editor-areas`);
- layout antigo do picker (`wccs-picker__*`);
- estrutura de campos anterior (`wccs-fields__*`);
- painel de publicação substituído (`wccs-publish__*`);
- ações em massa do módulo removido (`wccs-bulk__*`);
- variações de linhas de revisão que não existem no markup atual.

O container de revisões e sua lista foram mantidos porque `RevisionsList` ainda
os utiliza. Os arquivos históricos em `docs/validation` e
`docs/compatibility.json` não foram reescritos: são registros de decisões e
execuções passadas, não código ativo.

Também foram corrigidos os avisos introduzidos no fluxo de remoção de seção:
o identificador é tipado como anulável, a confirmação explícita é documentada
e o efeito de sincronização usa um id estável, sem depender da identidade de um
objeto de contexto recriado a cada renderização.

## Validação

Executado no host:

```text
npm run check-types
npm run lint:js -- --no-fix
npm run build
npm run test:unit-js -- --runInBand
```

Resultado: TypeScript sem erros, ESLint sem erros ou warnings, os três bundles
compilados e **51 suítes / 827 testes JavaScript aprovados**.

Como o host não possui o binário PHP, os gates PHP foram executados no Devilbox:

```text
docker compose exec -T -w /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite php composer check
```

Resultado: PHPCS aprovado, PHPStan sem erros e **623 testes / 2.156 asserções
PHP aprovados**.

## Próxima etapa segura

Uma remoção posterior de componentes só deve ocorrer junto com a alteração do
contrato da biblioteca e de seus testes. O próximo candidato é uma decisão de
produto sobre componentes mantidos para reutilização futura, não uma remoção
automática baseada apenas em ausência de import direto.
