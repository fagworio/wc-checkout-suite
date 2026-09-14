# WC CheckoutSuite - Auditoria do fluxo de uso

## Base e limites

- Gravação analisada: `wc-checkout01.webm`, aproximadamente 2min30s.
- Imagens: quadros extraídos com timestamps; as capturas selecionadas estão em `evidence/`.
- Repositório: `fagworio/wc-checkout-suite`, branch main, commit `bd1aff432912791c7010c904a3c799bfe5a2bee2`.
- Comparativo: commit anterior `e09006ceae5542c4e45be3691840840c098f8618`.
- O SHA instalado no ambiente filmado não foi comprovado. Código remoto e gravação são evidências complementares, não a mesma execução.
- A faixa de áudio existe, mas não foi possível obter uma transcrição nesta sessão. Não há afirmações atribuídas à narração.
- Não houve alteração no repositório nem execução de pedidos no Devilbox nesta auditoria.

## Conclusão

O problema central é a divergência entre o que a interface permite configurar, o que salva/publica e o que consegue aplicar. Corrigir componentes isolados não corrige o percurso do lojista. A prioridade deve voltar a editar os campos reais do checkout, publicar uma configuração executável e verificar o resultado na página real.

## Correções presentes no commit novo

O diff registra a substituição do renderizador que consumia `elements.shift()` por um componente associado a cada campo; assinatura do armazenamento de valores; envio por `setExtensionData`; exclusão de textos mascarados do caminho nativo quando classificados como controlados; desacoplamento do carregamento de apresentação da existência de campos; mudança do ponto que retém pedidos para análise.

Essas são mudanças de código verificadas, não homologação independente do checkout completo. A alteração da aprovação, em particular, ainda precisa provar que não libera atendimento antes da retenção nem confunde `on-hold` com pagamento confirmado.

## Evidências da gravação

| Momento aproximado | O que aparece | Interpretação e limite |
|---|---|---|
| 00:02 | Prévia com um Email, pagamentos ilustrativos e produto fictício | Não é prova do checkout real; a própria tela identifica dados fictícios. |
| 00:12 | `Published.` coexistindo com alterações não salvas | O usuário não sabe a qual versão cada aviso se refere. |
| 00:17-00:36 | Criação de seção, Location e Areas no mesmo modal | Retorno e destino da nova seção não ficam claros. O vídeo não comprova a gravação no banco. |
| 00:47 | Sections abre `Not built yet` | Rota de produto oferecida sem implementação. |
| 00:56 | Ao retornar, a prévia mostra nenhum campo | Sequência compatível com perda de edição local na navegação interna. |
| 01:02-01:05 | Revisão sem alterações oferece publicar | A interface não distingue operação sem efeito de publicação real. |
| 01:12-01:18 | Adição mostra Billing; lista mostra Pedido | Existe divergência entre o local visualizado e o local efetivo. |
| 01:23-01:36 | Tela em branco/carregamento e autenticação WordPress | Não é prova isolada de falha de salvamento; sessão e rede precisam de diagnóstico. |
| 01:41-01:48 | Dois botões de publicação e mensagens conflitantes | Um controle bloqueia, outro pode permanecer habilitado; relatórios não concordam. |
| 02:01 | Checkout page abre `Not built yet` | A função principal tem um caminho de configuração sem destino. |
| 02:03 | Settings mostra Custom checkout desmarcado, modo store e gateways desabilitados | Não houve demonstração de falha do layout customizado ativado. |
| 02:07 | Navegador mostra conexão recusada | Causa não estabelecida; não atribuir automaticamente ao plugin. |
| 02:14-02:29 | Checkout real com campos nativos, widgets do tema e sem meios de pagamento | Não corresponde à prévia. A falta de meios de pagamento é coerente com Settings. Não há pedido concluído. |

## Achados de código associados ao fluxo

### UX-01 - Contrato quebrado no seletor de seção [P0]

`resources/admin/app/FieldsScreen.js` monta `sectionOptions` como `{ key, label }`.
`resources/admin/app/views/FieldManagerView.js` entrega essa lista a `FieldPicker`.
`resources/admin/app/components/FieldPicker.js` renderiza `<option value={entry.id}>`.

O consumidor espera `id` e recebe `key`. Padronizar o contrato ponta a ponta. A seleção exibida, o estado React, o JSON salvo, a lista e o checkout precisam carregar o mesmo identificador. Não corrigir apenas o texto da aba.

Aceite: criar campos separadamente em Contact, Billing e Order pela UI; confirmar a seção salva, recarregar e verificar o local permitido no checkout ativo.

### UX-02 - Duas autorizações diferentes para publicar [P0]

`FieldManagerView.js` cria um botão de rodapé desabilitado apenas por `publishing` e inclui `PublishPanel`, que também oferece ação de publicação. `PublishPanel.js` usa `invalid || dirty || publishing`. A revisão pode deixar um botão liberado enquanto o outro informa que o rascunho precisa ser salvo.

Manter uma única ação final. Salvar a versão que está na tela antes de calcular a revisão, sem publicar por isso. Publicar exatamente a revisão revisada. Bloquear configurações inexequíveis no checkout ativo. Falha de salvamento não pode continuar para publicação. Regras devem existir também no servidor.

Aceite: alterar label sem salvar e abrir a revisão; não pode publicar a versão anterior como se fosse a atual. Diff vazio não deve criar revisão sem necessidade.

### UX-03 - Navegação interna pode descartar edição [P0]

`AppShell.js` substitui `FieldsScreen` ao abrir Settings, Diagnostics ou telas ainda vazias. O estado de edição pertence ao componente removido. `useUnsavedChanges.js` protege somente `beforeunload`, não a troca de componentes feita por `setCurrent`/`replaceState`.

Elevar o estado do documento ao shell/store persistente e interceptar navegação que realmente descarte trabalho. Oferecer permanecer, salvar rascunho e sair, ou descartar explicitamente. Navegar não publica nada.

Aceite: adicionar campo sem salvar, visitar configurações e voltar. Campo preservado ou decisão explícita antes do descarte.

### UX-04 - O catálogo permite uma configuração que o checkout não executa [P0]

No vídeo, o próprio relatório informa que o tipo Email precisa de componente Blocks inexistente. No código atual, Email não está no mapa nativo nem na lista de tipos controlados de `BlocksAdapter.php`. Isso não significa que o WooCommerce não possua e-mail nativo; significa que o novo campo adicional desse tipo não recebeu um caminho completo na Suite.

O editor deve detectar o checkout utilizado e indicar disponibilidade antes de o lojista montar o campo. É possível preservar um rascunho para outra engine; não prometer aplicação na engine ativa. Distinguir limitação estética de falta de coleta/persistência.

### UX-05 - Diagnósticos contraditórios [P1]

Na mesma revisão aparece que a seção `order` não existe no documento, que não há incompatibilidades Classic e que há zero impedimentos. A tela não permite concluir se o campo aparecerá.

Uma resolução canônica deve alimentar editor, relatório e renderização. Resultado por campo: aplicável, aviso visual, bloqueio funcional, correção recomendada. Reportar a página e a engine reais.

### UX-06 - Funcionalidades oferecidas por rotas vazias [P1]

`AppShell.js::SectionContent` implementa as views do editor, Settings e Diagnostics, e retorna `Not built yet` nas demais. Sections e Checkout page aparecem assim na gravação.

Conectar Seções ao gerenciador real. Colocar a ativação/configuração do layout em Página de checkout. Não oferecer atalhos aparentemente funcionais para becos sem saída. Isso não autoriza retirar requisitos do produto.

### UX-07 - Prévia parcial parece o checkout inteiro [P1]

A prévia desenha campos da Suite e pagamentos fictícios, mas a loja ainda possui campos nativos, tema e gateways reais. Ela avisa que é ilustrativa; ainda assim, o nome e a apresentação fazem parecer que o resultado mostrado é o que será publicado.

Distinguir: prévia dos campos; prévia de uma revisão no renderer real; abrir checkout publicado. Mostrar a composição de campos nativos + alterações + campos próprios. Não ativar destinos adicionais automaticamente.

### UX-08 - Campos nativos ficam fora do ponto de partida [P1]

A lista mostra só Email personalizado enquanto o checkout possui contato e endereço. Campos nativos existem no catálogo do picker, mas exigir Adicionar campo para editar algo que já existe é um caminho indireto.

A tela inicial deve listar a estrutura existente como inventário, sem gravar overrides nem duplicar campos. Editar um nativo altera o original. Adicionar cria um campo novo. O usuário deve reconhecer a relação com a página real.

### UX-09 - Desativar e arquivar têm o mesmo significado interno [P1]

`FieldsScreen.js::bulk` encaminha as duas ações para `setFieldEnabled(..., false)`. A UI oferece verbos diferentes para o mesmo resultado.

Proposta: desativado continua na estrutura editável; arquivado sai da estrutura ativa, preserva histórico e pode ser restaurado desativado. Se o produto optar por não distinguir, deve oferecer uma única ação claramente nomeada. Nunca apagar histórico de pedidos por editar o formulário.

### UX-10 - Coleta e exibição pós-compra misturadas cedo demais [P1]

O modal de seção combina Location e múltiplos destinos. Para criar um campo simples, a decisão inicial deveria ser apenas o local de preenchimento.

Manter Vínculos e exibição como configuração explícita e avançada. Sem vínculo, não adicionar painéis ao pedido, e-mail ou conta. Simplificação de interface não é ativação automática.

### UX-11 - Linguagem e acabamento inconsistentes [P1]

A gravação mistura português e inglês, texto técnico de adapter, modais estreitos com valores truncados e telas de Settings sem o acabamento do editor. Adicionar campo não direciona claramente ao inspetor preenchido.

Padronizar o idioma da loja, os componentes e as mensagens operacionais. Gerar chave técnica por padrão e permitir edição avançada. Ao criar, selecionar o campo, navegar para sua seção e abrir propriedades.

## O que não deve virar diagnóstico falso

1. Em 02:03, Custom checkout está desativado. A diferença de visual ao final não prova falha de um layout que foi ativado.
2. A mesma tela mostra gateways desabilitados. O checkout sem pagamento é coerente com essa configuração; não há evidência de falha no processamento de um gateway.
3. O aviso de incompatibilidade WordPress/WooCommerce não identifica por si só o plugin responsável.
4. O login e a conexão recusada precisam de observação de sessão/rede. Não atribuir esses incidentes ao editor sem evidência.
5. Não houve pedido concluído, upload, visualização da conta ou análise administrativa na gravação. Esses fluxos não foram demonstrados.
6. Os widgets laterais de posts, comentários e arquivos pertencem à apresentação do tema observada. Não são o Checkout Sidebar futuro nem uma área de documentos da Suite.

## Fluxo de uso proposto

1. Abrir Campos: inventário da página existente, checkout ativo e layout atual identificados.
2. Editar um campo nativo ou clicar Adicionar campo para criar um adicional.
3. Selecionar tipo suportado; configurar label, local de coleta, obrigatoriedade e largura.
4. Mostrar propriedades do novo campo. Vínculos pós-compra ficam opcionais e desligados.
5. Salvar rascunho: feedback claro, sem modificar a loja.
6. Revisar e publicar: garantir que a revisão corresponde à edição exibida e validar o checkout ativo.
7. Um único botão publica; confirmar revisão, campos aplicados e eventuais avisos.
8. Abrir checkout real e comprovar local, propriedades, condições e valores.
9. Configurar Página de checkout separadamente: Padrão ou Personalizado. A escolha de layout não decide se campos funcionam.

## Testes de aceite prioritários

- Criar campo, mudar de tela e voltar sem perder trabalho.
- Criar campo em Billing e outro em Order; salvar, recarregar e conferir o mesmo ID de seção.
- Editar label de campo nativo sem criar duplicata.
- Abrir revisão com alteração local ainda não salva; nunca publicar a versão anterior como sucesso da atual.
- Publicação com zero mudanças não gera sucesso enganoso nem revisão desnecessária.
- Tipo sem renderer no checkout ativo pode ser salvo como rascunho, mas não publicado como funcional.
- Relatórios de local, compatibilidade e bloqueios concordam entre si.
- Layout original + campos personalizados funciona; layout personalizado sem campos novos também.
- Uma resposta digitada pelo comprador chega ao pedido por envio real, sem fixture que injete o valor.
- Destinos desabilitados não exibem resposta nem painel adicional.
- Sessão expirada apresenta recuperação sem apagar a edição.
- Repetir os cenários em Classic e Blocks, na mesma versão de build que será distribuída.

## Diretriz para o agente

Não criar mais protótipos nem novos módulos neste incremento. Corrigir primeiro os contratos e estados que já fazem parte do fluxo. Não mascarar problemas removendo avisos, liberando publicação incompatível, trocando o checkout da loja silenciosamente ou ativando vínculos por padrão. Registrar testes que realmente passaram, os que falharam e os não executados.
