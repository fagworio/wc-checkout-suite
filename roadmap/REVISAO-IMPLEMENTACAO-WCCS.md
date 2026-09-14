# WC CheckoutSuite — Revisão da implementação e plano de correção

**Repositório:** fagworio/wc-checkout-suite  
**Snapshot:** `e09006ceae5542c4e45be3691840840c098f8618` (`main`)  
**Objeto:** comparar os requisitos com o fluxo real representado no código: configurar → salvar rascunho → publicar → renderizar → coletar → persistir → exibir conforme vínculos.  
**Método:** leitura estática pelo conector GitHub, confronto com documentação primária WooCommerce e reprodução isolada de dois padrões JavaScript.  
**Limites:** não foi executado o WordPress do lojista, nem a suíte completa do repositório. Não foi confirmado o commit instalado, o banco, os assets compilados ou os plugins ativos do Devilbox. Resultados de testes descritos nos documentos do projeto são evidência relatada pelos autores, não testes repetidos nesta auditoria. Nenhum arquivo do repositório remoto foi alterado.

## Conclusão

Há backend real de configuração/publicação, registros de tipos, adaptadores, tratamento de dados e destinos opt-in. O problema não é simplesmente uma interface demonstrativa sem PHP. Contudo, o contrato entre a configuração publicada e sua aplicação no checkout está incompleto. Componentes isolados, rotas existentes e provas com dados preparados não garantem o percurso do lojista e do comprador.

A prioridade é concluir uma funcionalidade ponta a ponta, começando por um campo textual próprio, antes de ampliar o produto. Preservar a arquitetura e corrigir as integrações: não reiniciar o plugin nem adicionar Checkout Sidebar.

## Requisitos que permanecem obrigatórios

- O editor modifica o checkout WooCommerce utilizado pela loja.
- Salvar rascunho não modifica o checkout público.
- Publicar aplica uma configuração executável no checkout ativo, ou explica e bloqueia incompatibilidades impeditivas.
- Campos nativos não são substituídos por campos adicionais com o mesmo label.
- Campos próprios são renderizados uma única vez e têm uma única autoridade de dados.
- Desligar o layout customizado não desliga os campos, validações ou persistência.
- Ligar o layout customizado deve funcionar inclusive sem campos personalizados.
- Destinos pós-compra são opt-in por campo. Sem vínculo, não inserir informação ou painel adicional.
- Perfil, pedido, cobrança e entrega não são autoridades de armazenamento intercambiáveis.
- Pagamento, análise documental e liberação operacional são estados distintos.
- Sidebar, gateways próprios, headless e construtor geral de páginas continuam fora do escopo.

## O que já existe e deve ser aproveitado

1. Admin React com operações reais sobre schema e cliente REST.
2. Separação entre `wccs_schema_draft`, `wccs_schema_published` e revisões.
3. Validação estrutural, controle de revisão e gravação com compare-and-swap.
4. Registries de tipos, presets, máscaras, normalizadores e validadores.
5. Adaptador Classic e registro de campos adicionais nativos no Blocks.
6. Componentes próprios de Blocks e um schema de extensão Store API, embora a ligação entre ambos esteja incompleta.
7. Contrato de upload com tokens, serviços de acesso e destinos de documentos.
8. Vínculos de exibição e aprovação opt-in no modelo.
9. Serviço de leitura dos valores dos pedidos, incluindo correção recente de leitura dos valores nativos Blocks.
10. Apresentação CSS Classic/Blocks, tokens e opção `wccs_custom_checkout`.
11. Documentos de validação que registram testes limitados e falhas abertas.

## Achados

### WCCS-AUD-01 — Publicação não assegura aplicação no checkout ativo
**Prioridade:** P0 para campos obrigatórios/segurança; P1 para apresentação.

**Evidência:** `docs/adr/ADR-0008-incompatibility-is-not-validation.md` declara que incompatibilidades avisam, mas não impedem publicação. `SchemaRepository::publish()` valida definições e grava, sem exigir um plano de renderização executável do checkout ativo.

**Impacto:** o lojista pode publicar um campo cujo adaptador recusa a renderização. O sucesso significa “schema gravado”, não “funcionalidade aplicada”.

**Correção:** manter a distinção entre validação estrutural e capacidades. Acrescentar resultado executável por campo/destino, consultado antes da publicação. Bloquear ausência de renderizador, uploader indisponível, perda de política de privacidade, regra crítica ignorada e perda de valor. Degradação apenas cosmética pode gerar aviso. Incompatibilidade de um checkout que a loja não usa não deve bloquear arbitrariamente o checkout ativo.

**Aceite:** campo incompatível no checkout ativo não recebe confirmação genérica de sucesso; draft continua editável; o motivo e a ação corretiva aparecem junto do campo.

### WCCS-AUD-02 — Componentes Blocks usam uma fila destrutiva para renderização
**Prioridade:** P0.

**Evidência:** `resources/blocks/index.js`, `register()`: `component: () => elements.shift() ?? null`.

**Impacto:** a chamada de renderização consome um elemento compartilhado. Um segundo render do mesmo componente pode devolver o próximo campo ou `null`.

**Correção:** componente estável vinculado ao ID do campo; nenhuma mutação de fila durante render. Valores devem vir de estado reativo.

**Aceite:** rerender, remount e StrictMode não duplicam, trocam ou apagam campos. Reprodução reduzida incluída em `isolated-blocks-reproduction.cjs`; não é execução do plugin.

### WCCS-AUD-03 — Falta ponte reativa e envio dos valores próprios Blocks
**Prioridade:** P0.

**Evidência:** `resources/blocks/index.js` monta elementos com valores iniciais; `resources/blocks/values.js` atualiza um Map sem notificar React; `StoreApiExtension.php` espera `extensions['wc-checkoutsuite']`. O entrypoint inspecionado não liga `checkoutExtensionData.setExtensionData` ao onChange.

**Impacto:** declarar o schema no PHP não envia o dado digitado. Um componente pode manter valor visual estático ou não chegar ao payload do pedido.

**Correção:** host/componente real do Blocks, estado controlado ou store com assinatura e sincronização via API oficial. Enviar chaves raw do schema, sem confundir ID de integração com chave de payload. Conectar erros e condições ao mesmo fluxo.

**Aceite:** preencher pela interface real; observar payload de `/wc/store/v1/checkout`; confirmar valor no pedido e nas projeções autorizadas, sem seed de resposta diretamente no banco.

### WCCS-AUD-04 — Roteamento inconsistente entre Blocks nativo e próprio
**Prioridade:** P0/P1.

**Evidência:** `BlocksAdapter::mode_for()` envia texto mascarado para `controlled`; `BlocksAdapter::build()` usa `native_type()` e não exclui esse mesmo campo. `BlocksRenderer::fields()` o seleciona como próprio.

**Impacto:** um campo mascarado pode entrar nos dois caminhos, gerando duplicação de controle e de autoridades.

**Correção:** uma decisão compartilhada de renderização/armazenamento por definição. Separar sobreposição core, additional field nativo e componente próprio.

**Aceite:** CPF e outro texto mascarado aparecem uma vez e produzem uma única resposta canônica.

### WCCS-AUD-05 — Alterar campos nativos não cumpre o contrato do editor
**Prioridade:** P1.

**Evidência:** `ClassicAdapter::apply()` ignora definições disabled; `apply_to_core()` altera somente apresentação e procura a chave na seção de destino. `CoreFieldGuard` impede desativar qualquer core previamente adotado. `BlocksAdapter` não distingue `origin=core` no registro nativo.

**Impacto:** desativar um core não o remove; mudar seção pode ser ignorado; required não é aplicado pelo Classic; no Blocks um core text pode virar um additional field, sem alterar o original.

**Correção:** políticas por campo, país e integração. Preservar IDs, dados e regras necessárias; permitir modificações legítimas de campos opcionais. Implementar suporte próprio de alteração de core Blocks onde a API permitir; impedir ou explicar opções não suportadas, nunca criar um substituto duplicado.

**Aceite:** renomear campo nativo altera o original; desativar um campo opcional suportado realmente o retira; não aparecem duplicatas; regra estrutural informa motivo concreto.

### WCCS-AUD-06 — Configurações exibidas no admin são perdidas no render
**Prioridade:** P1.

**Evidência:** registro nativo Blocks não transporta parte das propriedades do editor; payload próprio não transporta todas as configurações de default, placeholder, largura e ordem. `ClassicAdapter::apply_to_core()` só aceita descrição/placeholder não vazios e substitui classes.

**Correção:** tabela propriedade → validação → persistência → adaptação → render → leitura. Distinguir ausência de configuração de limpar valor. Preservar classes e atributos operacionais.

**Aceite:** cada propriedade habilitada na UI tem teste na página final. Propriedade não implementada deve ser desabilitada/explicada, não aceita silenciosamente.

### WCCS-AUD-07 — Catálogo mais amplo que os renderizadores
**Prioridade:** P1.

**Evidência:** `CoreTypes.php` registra multiselect, checkbox-group, heading, paragraph e HTML; o caminho Classic não possui render para todos. `ClassicAdapter::DEGRADED` converte number/url/date/time/datetime em texto alegando limitação do WooCommerce. A referência oficial atual de `woocommerce_form_field()` suporta number, url, date, time e datetime-local, entre outros.

**Correção:** alinhar capacidade real por versão; usar tipos corretos; implementar renderizadores para os controles prometidos; não apresentar como limitação da plataforma uma parte não implementada pela Suite.

**Aceite:** matriz de tipos testada em Classic e Blocks com o campo publicado pela UI e preenchido no navegador.

### WCCS-AUD-08 — Seções e posição configuradas não têm correspondência completa
**Prioridade:** P1.

**Evidência:** Classic mapeia seções próprias em grupos nativos. Blocks mapeia billing/shipping em address; componentes de address usam shipping-address como parent no JS. O editor inicia no modo visual Classic, sem que esse seletor altere o checkout real.

**Impacto:** uma prévia pode sugerir local diferente; carrinho virtual ou ausência do parent pode impedir mostrar o componente.

**Correção:** identificar checkout ativo no bootstrap; distinguir modo de prévia de integração instalada. Resolver seções e hosts de forma explícita. Não realocar campos em silêncio. Preservar cabeçalhos e ordem prometidos, onde suportados.

**Aceite:** testar endereço único, entrega separada e produto virtual; campo sempre no local anunciado ou publicação impedida com motivo.

### WCCS-AUD-09 — Upload no Blocks não tem percurso completo
**Prioridade:** P1.

**Evidência:** `FileFieldType` existe, mas file não entra na classificação controlada do Blocks e não declara control compatível. A Store API de campos próprios seleciona apenas os tipos classificados como controlled.

**Correção:** componente de upload integrado, transporte de tokens, status de envio, validação de sessão e vínculo ao pedido; nunca binário/base64 dentro de additional fields.

**Aceite:** usuário escolhe um arquivo, conclui o upload, envia o checkout e encontra exatamente o arquivo na área autorizada; obrigatório em andamento impede finalização; outro cliente não acessa.

### WCCS-AUD-10 — Layout customizado depende indevidamente de existir campo renderizável
**Prioridade:** P1.

**Evidência:** `ClassicAssets::should_enqueue()` exige checkout e campos; `BlocksRenderer::enqueue()` retorna quando não há campos próprios, antes do CSS. A opção `wccs_custom_checkout` é separada, porém a entrega dos assets reintroduz dependência.

**Impacto:** ligar layout com schema vazio não ativa a apresentação; Blocks com somente campos nativos também não passa pelo carregamento atual desse CSS.

**Correção:** separar assets funcionais de campos, estilos básicos dos componentes e página customizada. Opt-in de layout governa somente apresentação; não pode depender de quantidade de campos próprios.

**Aceite:** quatro casos por engine: layout off/zero campos, off/com campos, on/zero campos, on/com campos. Comparação visual com protótipos e funcional com frete/cupom/gateway.

### WCCS-AUD-11 — Gate de build precisa ser diagnóstico, não silêncio
**Prioridade:** P1 operacional.

**Evidência:** frontend espera `build/checkout/index.js` e `build/blocks/index.js`; se ausentes, retorna sem carregar. `npm start` inicia apenas admin; `npm run build` compila os três entrypoints.

**Situação:** não foi confirmado se o ambiente do lojista tem arquivos ausentes ou desatualizados. A ausência de build versionado no Git, por si só, não é um bug.

**Correção:** registrar hash/versão dos bundles e checagem de integridade; empacotamento deve incluir assets; workflow de desenvolvimento deve acompanhar todos os entrypoints.

**Aceite:** diagnóstico identifica bundle ausente/antigo; pacote instalável funciona sem Node em produção.

### WCCS-AUD-12 — Painel HPOS e perfil não estão concluídos
**Prioridade:** P1.

**Evidência:** `docs/validation/F14-fluxos-de-utilizador.md` mantém aberto o F-3: metabox registrada, mas callback não desenhado no admin real. O mesmo documento informa que customer_profile não tem superfície/persistência completa.

**Correção:** reproduzir no admin real e resolver integração; não tomar o comentário “a plataforma não desenhou” como diagnóstico final de causa. Preservar opt-in e testar ausência de painel vazio quando não há vínculos.

**Aceite:** equipe abre o pedido sem chamar callback manualmente e vê só os campos vinculados. Perfil fica indisponível enquanto não houver escrita/leitura/UI implementadas.

### WCCS-AUD-13 — Aprovação interfere no momento financeiro errado
**Prioridade:** P0 quando habilitada.

**Evidência:** `ReviewStatus.php` altera status em `woocommerce_checkout_order_processed`; o comentário afirma que o gateway já atuou. No fluxo oficial Classic, esse hook precede `needs_payment()` e o processamento do pagamento. O método também usa o primeiro campo respondido, sem representar decisões agregadas por versão de documento.

**Risco inferido do código:** um status próprio antes da cobrança pode interferir em needs_payment, callbacks e conclusão financeira. Não houve transação real nesta auditoria; não se afirma que dinheiro foi perdido.

**Correção:** separar estado documental de financeiro; definir explicitamente pagar antes/aprovar antes; usar transições adequadas e idempotentes; preservar confirmação, transaction_id, date_paid, estoque e notificações. Completar aprovação/correção/reenvio por versão e bloqueio operacional.

**Aceite:** aprovação desligada não muda nada. Ligada não permite escapar da cobrança nem cobrar novamente; webhook repetido não repete efeitos; liberar atendimento depende das condições, não só do nome do status.

### WCCS-AUD-14 — Condições nativas mudam obrigatoriedade e podem ser descartadas
**Prioridade:** P1.

**Evidência:** `BlocksAdapter::build()` substitui required pelo schema da condição quando compilável, mesmo para um campo configurado opcional. Regra não compilável gera aviso e campo sem hidden condition.

**Correção:** visibilidade e obrigatoriedade precisam ser independentes. Campo opcional mostrado continua opcional. Condição crítica sem representação deve ir para componente capaz de executá-la ou impedir publicação.

**Aceite:** testes de tabela: optional/required × visible/hidden × PF/PJ; revalidação server-side e remoção de valor residual conforme política.

## Correções já registradas: não reabrir como se ainda estivessem ausentes

F14 documenta correção de:
- F-1: normalização de field/section antes de decidir o escopo no adaptador nativo.
- F-2: desativação da exibição automática WooCommerce em confirmação/conta.
- F-4: leitura de valores nativos sem copiá-los para uma segunda meta.

Confirmar primeiro se o ambiente do usuário roda o snapshot que contém essas mudanças. A matriz funcional antiga não representa toda a evidência posterior: F14 já relata testes com navegador e pedido offline. Isso não certifica os campos próprios nem gateways reais.

## Ordem de trabalho recomendada

### Etapa A — Diagnóstico observável, sem mudar a loja
- Confirmar commit instalado, plugin ativo, checkout configurado, conteúdo/template efetivos e bundles.
- Ler revisões draft/published e rastrear um ID específico.
- Relatar campos solicitados, selecionados pelo adapter, renderizados, recusados e suas razões.
- Não escrever respostas de comprador no banco para fazer a projeção parecer funcional.
- Suspender uso de aprovação em produção até revisar sua interação financeira.

### Etapa B — Um percurso vertical funcionando
- Criar campo texto próprio pela UI.
- Salvar: não aparece.
- Publicar: aparece uma vez na página real.
- Preencher, enviar e confirmar persistência.
- Ligar admin_order e customer_order separadamente; verificar inclusão/exclusão.
- Repetir no Classic e no Blocks com campo nativo e controlado.

### Etapa C — Completar o editor prometido
- Corrigir core overrides.
- Completar tipos e propriedades.
- Resolver seções/ordem e matriz do checkout ativo.
- Corrigir compilação de condições e exclusividade de renderização.

### Etapa D — Página customizada
- Desacoplar layout de campos.
- Integrar grid, resumo, pagamentos e estados sem recriar engine financeira.
- Testar os quatro cenários de layout/campos em ambas as engines.
- Não adicionar Sidebar.

### Etapa E — Documentos, áreas e aprovação
- Finalizar upload Blocks.
- Resolver admin HPOS e destinos opcionais.
- Aprovação e reenvio completos só depois de testar cobrança, permissão e versão.
- Customer profile somente quando realmente implementado.

## Critérios de conclusão

1. Teste E2E deve começar no admin e terminar no pedido real.
2. Fixtures podem preparar produto/usuário/ambiente; não podem substituir a ação sob teste.
3. Testar um controle nativo e um próprio, não apenas native text.
4. Capturar requests/responses de salvar/publicar e payload de checkout.
5. Campo obrigatório inválido é rejeitado no servidor mesmo com POST adulterado.
6. Nenhum campo duplicado após atualização de endereço/frete/pagamento.
7. Alterar label/padrão/opções/largura tem resultado no controle final ou aviso de capacidade impeditiva.
8. Desligar destino retira exibição e acesso conforme política, sem apagar dados históricos.
9. Dois revisores ou webhooks concorrentes não duplicam efeitos.
10. Registrar por cenário: passou, falhou ou não testado. RC não significa homologação.

## Orientação ao agente implementador

Não recrie o plugin. Não mude decisões de produto unilateralmente. Trate o roadmap e a regra opt-in como requisitos. Revise ADR-0008 explicitamente, substituindo “qualquer incompatibilidade nunca bloqueia” por uma distinção entre aviso tolerável e incapacidade funcional/segurança no checkout ativo.

Antes de editar, confirme as instruções do repositório e as alterações locais. Trabalhe em tarefas pequenas, com teste de regressão por achado. Não altere produção, gateways reais, infraestrutura, tema ou outros plugins sem autorização. Configurações locais de teste devem ser explicitamente autorizadas e restauradas.

O objetivo de cada tarefa é um comportamento observável no WooCommerce, não criar mais classes, aumentar contagem de testes ou ajustar a documentação para justificar um requisito não entregue.

## Fontes
- [roadmap/ROADMAP.md](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/roadmap/ROADMAP.md)
- [docs/adr/ADR-0008-incompatibility-is-not-validation.md](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/docs/adr/ADR-0008-incompatibility-is-not-validation.md)
- [docs/validation/F14-fluxos-de-utilizador.md](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/docs/validation/F14-fluxos-de-utilizador.md)
- [docs/operations/functional-matrix.md](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/docs/operations/functional-matrix.md)
- [resources/admin/app/FieldsScreen.js](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/admin/app/FieldsScreen.js)
- [resources/admin/app/api/client.js](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/admin/app/api/client.js)
- [src/Domain/Schema/SchemaRepository.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Schema/SchemaRepository.php)
- [src/Domain/Schema/CoreFieldGuard.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Schema/CoreFieldGuard.php)
- [src/Checkout/Classic/ClassicCheckout.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Classic/ClassicCheckout.php)
- [src/Checkout/Classic/ClassicAdapter.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Classic/ClassicAdapter.php)
- [src/Checkout/Classic/ClassicAssets.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Classic/ClassicAssets.php)
- [src/Checkout/Blocks/BlocksCheckout.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Blocks/BlocksCheckout.php)
- [src/Checkout/Blocks/BlocksAdapter.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Blocks/BlocksAdapter.php)
- [src/Checkout/Blocks/BlocksRenderer.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Blocks/BlocksRenderer.php)
- [src/Checkout/Blocks/StoreApiExtension.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Blocks/StoreApiExtension.php)
- [resources/blocks/index.js](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/blocks/index.js)
- [resources/blocks/values.js](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/blocks/values.js)
- [resources/blocks/fields.js](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/blocks/fields.js)
- [src/Domain/Fields/CoreTypes.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Fields/CoreTypes.php)
- [src/Domain/Fields/Types/FileFieldType.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Fields/Types/FileFieldType.php)
- [src/Admin/Orders/OrderFieldsPanel.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Admin/Orders/OrderFieldsPanel.php)
- [src/Domain/Approval/ReviewStatus.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Approval/ReviewStatus.php)
- [src/Domain/Settings/CheckoutSettings.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Domain/Settings/CheckoutSettings.php)
- [src/Checkout/Presentation.php](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/src/Checkout/Presentation.php)
- [resources/checkout/presentation.css](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/resources/checkout/presentation.css)
- [package.json](https://github.com/fagworio/wc-checkout-suite/blob/e09006ceae5542c4e45be3691840840c098f8618/package.json)

### Referências oficiais externas
- [WooCommerce: campos próprios e envio pela Store API](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-custom-fields/)
- [WooCommerce: código de WC_Checkout](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-checkout.html)
- [WooCommerce: código de WC_Order](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-order.html)
- [WooCommerce: woocommerce_form_field](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-template-functions.html)
