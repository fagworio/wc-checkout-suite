# WC CheckoutSuite — Seções reais de Minha Conta e formulários do cliente

## 1. Base e limite desta revisão

Repositório: `fagworio/wc-checkout-suite`.
Snapshot analisado: `58f888ae6f4cbbdd76d9fbb756faf0091ad60004`.
Base: três capturas fornecidas pelo lojista, leitura dos arquivos atuais pelo conector GitHub e documentação primária WordPress/WooCommerce.
Método: análise estática. Não foi acessado o WordPress local, consultado o banco da loja ou executado o checkout.
Nenhuma alteração foi enviada ao repositório. As tarefas abaixo são propostas, não funcionalidades já entregues.

O título da seção passou a aparecer no editor, mas isso não equivale a registrar uma entrada pública em Minha Conta.

## 2. Correção do entendimento do produto

Uma seção não é apenas uma aba do editor nem apenas um agrupamento para imprimir respostas de um pedido.

Neste caso, criar “User Profile New Fields” para a conta do cliente deve criar:
- uma entrada no menu da página Minha Conta;
- uma URL/endereço próprio dentro da conta;
- uma página com os campos associados à seção;
- um formulário que o cliente autorizado possa preencher e atualizar;
- persistência vinculada ao cliente, independentemente de existir pedido;
- ícone e posição de navegação configuráveis.

É necessário retirar a restrição “campos só podem ser criados no Checkout”. O lojista deve poder criar um campo diretamente em uma seção de Minha Conta ou vincular um campo existente a ela.

As antigas propostas que limitavam “Cliente” a Minha Conta → Pedidos → Ver pedido são insuficientes para esse requisito.

## 3. Evidências encontradas

### A01 — Customer Profile existe como opção, mas não como funcionalidade completa

Arquivo: `docs/validation/WCCS-076.md`, seção de achados e limites.

O documento registra que `customer_profile` não tem superfície e que o escopo `customer` é declarado, mas não recebe valores nesse fluxo. O texto é evidência histórica do próprio projeto; o código de exibição revisado permanece centrado nos pedidos.

Arquivo: `src/Checkout/CustomerOrderFields.php`.

A classe registra `woocommerce_order_details_after_order_table`, seleciona `customer_order` ou `order_received`, lê respostas de `WC_Order` e não registra uma rota de Minha Conta. Esse código não implementa uma página de perfil editável.

Consequência: marcar o destino na configuração não cria o menu, a rota, o formulário nem a persistência que o usuário espera.

### A02 — O modelo de seção não descreve uma aba da conta

Arquivo: `src/Domain/Sections/SectionDefinition.php`.

Campos atuais: `id`, `title`, `description`, `position`, `location`, `areas`.
Não existem nesse modelo configurações de endpoint, slug público, apresentação como aba da conta ou ícone escolhido.

O modelo atual representa agrupamento/localização. Falta um contrato de apresentação por destino.

### A03 — A interface diz coisas diferentes sobre Customer Profile

Arquivo: `resources/admin/app/FieldsScreen.js`, constantes `EDITOR_AREAS` e `SECTION_AREA_REFERENCE`.

A área “Cliente” corresponde a `customer_order`, descrita como Minha Conta → Pedidos → Ver pedido.
A descrição de `customer_profile` diz que aparece no perfil do cliente no painel administrativo.

Arquivo: `src/Domain/Fields/DefinitionVocabulary.php`.

O destino `customer_profile` é descrito como a conta do cliente, fora de um pedido; suas ações modeladas são de consulta, não de edição de formulário.

A mesma expressão está misturando:
1. perfil público autenticado do próprio cliente;
2. perfil do cliente consultado pelo administrador;
3. detalhes de um pedido consultado pelo cliente.

Não resolver isso apenas traduzindo um label.

### A04 — O editor impede criar campos fora do Checkout

Arquivo: `resources/admin/app/FieldsScreen.js`, callback `onCreateField`.

A condição `if ('checkout' !== area)` recusa a criação e orienta a vincular um campo existente.

Essa decisão conflita diretamente com a criação de uma seção autônoma de perfil. Deve ser substituída por uma verificação de capacidades do destino: destinos editáveis podem criar/vincular campos; destinos somente de exibição usam vínculos.

### A05 — Seção não destinada ao checkout pode continuar listada nele

Arquivo: `resources/admin/app/schema/fieldOperations.ts`, `sectionGroups()`.

A função inclui seções oferecidas na área OU, no caso de Checkout, seções referenciadas por `field.section`. Isso explica um caminho pelo qual uma seção de perfil pode reaparecer no agrupamento Checkout quando contém campos associados pelo modelo antigo.

Importante: no callback atual de criação de seção, `areas: newSectionAreas` é enviado para `createSection()`. A função preserva arrays explícitos. Portanto, a captura “Ativa em: Checkout” não prova isoladamente que a seleção foi sobrescrita no banco.

Verificar em diagnóstico:
- configuração enviada;
- configuração ativa devolvida;
- seleção local restaurada;
- build instalado;
- cálculo do agrupamento e do texto “Ativa em”.

Não mudar automaticamente uma seção de perfil para checkout para fazê-la passar na validação.

### A06 — Uma seção por campo e um vínculo por destino limitam a reutilização

Arquivo: `resources/admin/app/FieldsScreen.js`, diálogo de vinculação.

A atualização usa `destinations[area] = {..., enabled: true, section}`. Vincular a outra seção da mesma área substitui a seção anterior.

Arquivo: `resources/admin/app/schema/fieldOperations.ts`, `surfacesFor()`.

Novos campos com valor recebem `storage.scope = order` por padrão; conteúdo visual recebe `none`.

É necessário representar múltiplos vínculos sem duplicar a definição do campo e escolher a autoridade do valor conforme o contexto de coleta.

### A07 — Uploads precisam de vínculo permanente ao cliente, além de sessão/pedido

Arquivos: `src/Http/Checkout/UploadController.php`, `src/Domain/Uploads/DownloadPolicy.php`.

O fluxo atual é de checkout; a política usa proprietário/sessão e cliente do pedido para autorização. Isso não basta como contrato de documento persistente do perfil, acessível em outra sessão sem pedido.

Não criar pedido fictício e não manter documento de perfil indefinidamente como upload temporário.

## 4. Requisitos de produto extraídos

| ID proposto | Requisito |
|---|---|
| ACCOUNT-R01 | Criar seções que gerem novas abas reais em Minha Conta. |
| ACCOUNT-R02 | Cada seção deve ter título, identificação estável, endereço, ícone e ordem. |
| ACCOUNT-R03 | Criar novos campos diretamente na seção, sem passar pelo checkout. |
| ACCOUNT-R04 | Vincular campos existentes a várias seções explicitamente. |
| ACCOUNT-R05 | Permitir preenchimento e atualização pelo cliente autenticado, quando configurado. |
| ACCOUNT-R06 | Persistir os dados do perfil independentemente de pedidos. |
| ACCOUNT-R07 | Exibir os dados salvos ao reabrir a seção e em outra sessão autenticada. |
| ACCOUNT-R08 | Suportar upload privado pertencente ao cliente, conforme permissões. |
| ACCOUNT-R09 | Separar perfil atual de informações históricas de pedidos. |
| ACCOUNT-R10 | Não ativar outros destinos, checkout, e-mails ou aprovação silenciosamente. |
| ACCOUNT-R11 | Manter uma única ação administrativa de salvar/atualizar a configuração. |
| ACCOUNT-R12 | Preservar menus nativos e entradas de outras extensões. |

O formato editável é a proposta para concretizar o caso de uso mostrado. Cada seção/vínculo deve permitir definir consulta ou edição, sem presumir que todo dado pode ser alterado pelo cliente.

## 5. Destinos e apresentação

“Seção” é o agrupamento reutilizável; seu destino determina como ela aparece.

| Destino explícito | Apresentação |
|---|---|
| Checkout | Grupo de campos dentro do formulário de compra, em posição suportada. |
| Minha Conta — nova aba | Item de navegação, endereço e página própria. |
| Minha Conta — detalhes de um pedido | Painel daquele pedido, com respostas históricas. |
| Pedido no admin | Painel no pedido administrativo. |
| Perfil do cliente no admin | Grupo consultado/editado por equipe autorizada, separado da conta pública. |
| E-mail | Bloco da mensagem, não uma nova página. |

Criar uma seção para Minha Conta já é uma autorização explícita para criar sua aba. Não exigir um segundo checkbox escondido para materializar a mesma intenção.
Isso NÃO autoriza exibição em outros destinos.

Recomendação de navegação administrativa:
- Checkout.
- Minha Conta.
- Pedidos/admin.

Dentro de Minha Conta, separar “Abas personalizadas” de “Detalhes dos pedidos”. Configurações avançadas podem conter destinos secundários.

Não usar `customer_order` como identificador genérico de toda a área do cliente.

## 6. Fluxo administrativo proposto

1. Abrir Minha Conta → Nova seção.
2. Informar o nome “User Profile New Fields”.
3. Escolher um ícone da biblioteca.
4. Confirmar endereço sugerido `user-profile-new-fields`.
5. Escolher posição no menu, por exemplo depois de Detalhes da conta.
6. Escolher modo: formulário editável ou consulta.
7. Adicionar Email e File Upload, ou vincular definições existentes.
8. Usar Salvar/Atualizar configuração uma única vez.
9. Receber confirmação e link “Abrir seção em Minha Conta”.

No formulário dessa seção, não mostrar Billing/Shipping como posição obrigatória. Esses controles pertencem ao checkout.

Adicionar seção/campo durante a edição altera o documento local. O único salvamento aplica o conjunto válido. Não recriar “rascunho → revisão → publicação”.

A configuração salva deve gerar automaticamente menu, rota e formulário. Não exigir criar páginas WordPress manualmente nem adicionar código ao tema.

## 7. Fluxo do cliente

Resultado esperado no menu:

Painel
Pedidos
Downloads
Endereços
Detalhes da conta
User Profile New Fields
Sair

Ao abrir a nova aba:
- título configurado;
- campos associados em sua ordem;
- valores atuais do próprio cliente;
- upload e lista dos arquivos dele;
- Salvar informações, quando houver permissão de edição;
- mensagens de validação e confirmação.

Uma seção editável com campos sem resposta precisa aparecer. Não usar a regra “não há respostas no pedido, então não renderiza nada” para esse formulário.
A política para uma seção sem nenhum campo é diferente: avisar ao lojista que ela está vazia ou permitir página vazia explicitamente, mas não confundir ausência de definição com valores ainda não preenchidos.

Aceite principal: um cliente autenticado SEM PEDIDOS consegue abrir, preencher, salvar, sair, entrar novamente e ler os dados.

## 8. Contrato de dados proposto

Não é necessário criar um framework de páginas. Evoluir os modelos existentes com três responsabilidades:

### Definição da seção
Identidade estável, título e configuração de apresentação por destino. Para nova aba, incluir tipo `account_endpoint`, slug, rótulo do menu, posição e chave do ícone.

### Definição do campo
Identidade estável, tipo, validação, máscara e demais características reutilizáveis. Criar o campo não deve obrigá-lo a pertencer ao checkout.

### Vínculo/apresentação do campo
Referência à seção e ao destino, ordem, modo de consulta/edição, obrigatoriedade e autoridade do valor. Uma lista permite dois vínculos no mesmo destino sem sobrescrever o anterior.

Os nomes a seguir são conceituais e precisam de migração versionada:

```json
{
  "sections": [
    {
      "id": "profile_extra",
      "title": "User Profile New Fields",
      "mounts": [
        {
          "id": "profile_extra_account",
          "surface": "my_account",
          "kind": "account_endpoint",
          "enabled": true,
          "slug": "user-profile-new-fields",
          "menu_label": "User Profile New Fields",
          "icon": "user",
          "position": 60
        }
      ]
    }
  ],
  "bindings": [
    {
      "id": "profile_extra_email",
      "field_id": "contact_email",
      "section_id": "profile_extra",
      "mount_id": "profile_extra_account",
      "mode": "edit",
      "value_source": "customer",
      "required": false,
      "position": 10
    }
  ]
}
```

Não introduzir esse JSON como uma segunda configuração paralela. Definir uma autoridade única, adaptar importação/exportação e converter o contrato antigo.

O schema do tipo e a definição do campo são compartilhados; a obrigatoriedade e a permissão podem variar por vínculo. Um formulário parcial não deve exigir todos os campos cadastrados para todos os destinos.

## 9. Persistência e reutilização

### Perfil do cliente
Um serviço `CustomerFieldsService` proposto centraliza leitura, normalização, validação e escrita dos metadados tipados do cliente, através do CRUD de `WC_Customer` quando aplicável.

O usuário-alvo vem da sessão autenticada. Não aceitar `user_id` arbitrário enviado pelo formulário público.

Guardar apenas os campos do formulário autorizado. Preservar os outros campos do perfil. Tratar `false`, `0`, vazio e ausência sem confundi-los.

### Mesmo campo em duas seções de perfil
Pode ler/escrever a mesma resposta do cliente, se ambos os vínculos apontarem para a mesma autoridade. Um formulário pode ser editável e outro apenas leitura.

### Perfil e checkout
Quando o lojista vincular explicitamente:
- oferecer o valor do perfil como preenchimento inicial;
- validar novamente conforme o checkout;
- capturar uma cópia histórica no pedido quando solicitado;
- atualizar o perfil a partir do checkout somente por política explícita.

Não usar automaticamente o último pedido como se fosse o perfil.

### Histórico de pedido
Depois de criado, o pedido mantém o valor/versão capturado naquela compra. Editar perfil não altera pedido passado, documento aprovado nem decisão de revisão.

Um campo somente do pedido exige contexto de pedido para ser exibido. Em aba genérica da conta, oferecer uma listagem por pedidos como um recurso separado, não escolher arbitrariamente um pedido.

### E-mail e dados de conta
Um campo customizado do tipo e-mail é dado de formulário. Não altera login, e-mail principal, senha nem e-mail de cobrança apenas por ter o tipo `email`.
Mapeamentos para propriedades nativas requerem configuração explícita e validação do serviço nativo.

## 10. Integração real com Minha Conta

Reutilizar os mecanismos oficiais:
- `add_rewrite_endpoint()` para reconhecer o endereço;
- integração com os query vars do WooCommerce quando necessária ao mapeamento;
- `woocommerce_account_menu_items` para inserir a entrada;
- `woocommerce_account_{endpoint}_endpoint` para renderizar o conteúdo;
- `wc_get_account_endpoint_url()` para gerar o link a partir da página de conta configurada.

Não hardcodar domínio, porta ou `/minha-conta/` no plugin. Os exemplos de URL são ilustrativos.

Registrar rotas a partir da configuração ativa. Atualizar regras de URL somente quando o conjunto de endpoints muda; não executar flush a cada visita ou a cada alteração de label.

Validar colisões com endpoints nativos, seções próprias e outras extensões. Renomear o título não deve alterar o ID ou o slug silenciosamente.
Troca explícita do slug precisa de política para endereço anterior. Remoção/arquivamento precisa de resposta controlada para URLs antigas.

A aba da conta não depende de Checkout Classic ou Blocks. Não gatear sua inicialização por `is_checkout()`.

## 11. Ícones

Oferecer biblioteca pequena, pesquisável e consistente com a identidade da Suite.
Persistir a chave do ícone; não HTML, PHP, JavaScript, URL externa ou SVG arbitrário fornecido no admin.

O renderizador resolve a chave em um ícone seguro e mantém o texto do menu como nome acessível.
Aplicar CSS/markup apenas às entradas da Suite, sem substituir todos os ícones do tema.
Prever compatibilidade com templates que já adicionam ícones e fallback textual sem quebrar a navegação.
Não prometer aparência idêntica em qualquer tema sem teste.

## 12. Upload no perfil

Extender os serviços atuais, não duplicar um segundo sistema de upload:

Selecionar arquivo → validar regras no servidor → temporário privado vinculado ao cliente → confirmar formulário → vínculo definitivo ao perfil.

O documento precisa ser recuperável em outra sessão por seu dono, sem carrinho e sem pedido.
Downloads verificam o dono, a seção/vínculo, a ação permitida e a versão solicitada. Saber o ID não autoriza o acesso.

Substituição cria nova versão/referência. Arquivo já referenciado por um pedido histórico não é apagado pela simples edição do perfil.
Retenção diferencia temporários, perfil e pedidos. A rotina de limpeza não deve eliminar perfis por ausência de `order_id`.

Ao reutilizar documento do perfil no checkout, criar associação explícita ao pedido e capturar a versão. Uma aprovação de pedido não aprova automaticamente todas as versões futuras do perfil.

## 13. Segurança e comportamento operacional

- Autenticação e autorização no servidor para leitura, edição e download.
- Proteção CSRF adicional; nonce não substitui autorização.
- Campos e destinos derivados da configuração ativa, não do payload do cliente.
- Sanitização e escape por tipo/contexto.
- Somente seções habilitadas e vínculos autorizados.
- Página pública de perfil não pode expor dados de outro cliente.
- Não armazenar valores pessoais em cache compartilhado.
- E-mails e APIs não recebem os novos campos automaticamente.
- Logs sem conteúdo de documentos, tokens ou dados pessoais desnecessários.
- Controles administrativos e formulários públicos são operações distintas.
- Conflito de edição em duas abas deve ser detectado; não sobrescrever silenciosamente o perfil.
- Cadastro de conta, pagamento, pedido e aprovação permanecem processos independentes.

## 14. Migração

Não converter indiscriminadamente todo `customer_profile` legado em aba pública: a interface antiga descrevia esse destino como painel administrativo.

Criar migração que:
1. inventarie as seções e vínculos existentes;
2. preserve IDs, valores, documentos e permissões;
3. destaque os registros semanticamente ambíguos;
4. peça uma escolha pontual de destino quando necessário;
5. só crie novas abas para configurações explicitamente destinadas a Minha Conta;
6. não acrescente Checkout como fallback;
7. converta a estrutura de vínculo único para múltiplos vínculos sem duplicar respostas.

Esse passo é específico de migração. Não reintroduzir revisão/publicação obrigatória no uso diário.

Atualizar ROADMAP, BACKLOG, matriz de capacidades, testes e os limites documentados em WCCS-076.

## 15. Plano de implementação proposto

IDs abaixo são locais desta análise, não tarefas já existentes no backlog.

| Tarefa | Entrega | Aceite |
|---|---|---|
| ACCOUNT-01 | Corrigir conceitos, destinos e schema versionado. | Perfil público, perfil admin e pedido têm identidades distintas. |
| ACCOUNT-02 | Criar registro de endpoints, menu e página de seção. | Cliente sem pedidos vê uma aba real configurada. |
| ACCOUNT-03 | Renderizar e salvar campos de perfil. | Valor persiste ao recarregar e entrar em nova sessão. |
| ACCOUNT-04 | Habilitar criação contextual e múltiplos vínculos. | Campo criado na conta não aparece no checkout; vínculo adicional não substitui anterior. |
| ACCOUNT-05 | Reutilizar upload privado com dono cliente. | Upload sem pedido, download autorizado e isolamento entre contas. |
| ACCOUNT-06 | Conectar perfil/checkout/pedido conforme política. | Prefill explícito e snapshot histórico estável. |
| ACCOUNT-07 | Ícones, ordem, renomeação, arquivamento e migração. | Menu preserva nativos e outras extensões; alterações não perdem dados. |
| ACCOUNT-08 | Testes ponta a ponta, permissões e compatibilidade. | Percurso completo aprovado no browser em ambiente de teste. |

Priorizar um corte vertical simples: criar aba → renderizar um texto → salvar → reler com cliente comum sem pedidos. Só depois expandir para todos os tipos e reutilização de arquivos.

### Pontos do código para alterar/reutilizar
- `SectionDefinition.php`: apresentação/endpoints/ícone.
- `DefinitionVocabulary.php`: destinos e capacidades inequívocos.
- `FieldsScreen.js`, `FieldManagerView.js`: contexto Minha Conta e criação direta.
- `fieldOperations.ts`, `types.ts`: múltiplos vínculos e remoção segura.
- `SectionValidator.php`: validação por superfície e referências.
- `Plugin.php`: registrar a nova integração de conta.
- `CustomerOrderFields.php`: manter responsável por pedidos, não ampliar para formulário de perfil.
- UploadService/Repository/Policy/Retention: suportar dono cliente e vínculos versionados.

Novos componentes sugeridos, ainda não existentes:
- `Account/MyAccountSections`
- `Account/SectionForm`
- `Customers/CustomerFieldsService`
- `Http/Account/SectionFieldsController`

A divisão pode ser adaptada aos padrões do repositório; evitar um framework genérico maior que a necessidade.

## 16. Testes de aceite

1. Criar “User Profile New Fields”, selecionar nova aba em Minha Conta, escolher ícone, adicionar Email e Upload e salvar.
2. Entrar como cliente comum sem pedidos: novo item aparece, sem depender do papel administrador.
3. Abrir pelo menu e diretamente pela URL: mesmo formulário, sem erro 404.
4. Formulário aparece mesmo sem respostas anteriores; valores iniciais não vêm de outro cliente.
5. Salvar e-mail e arquivo, sair e entrar de novo: valores e download permanecem disponíveis.
6. Outro cliente não acessa valores nem arquivo por URL ou API.
7. Campo criado na conta não aparece no checkout, admin do pedido ou e-mails sem vínculo explícito.
8. Vincular o mesmo campo a duas seções da conta: ambas permanecem e usam a política de valores configurada.
9. Vincular ao checkout: prefill somente quando habilitado; pedido captura a versão correta.
10. Editar perfil depois da compra: pedido antigo e decisão de aprovação não mudam.
11. Renomear seção ou trocar ícone não altera ID nem perde arquivos.
12. Remover um vínculo não exclui o campo de outras seções.
13. Arquivar seção retira a entrada e bloqueia acesso conforme política, sem apagar histórico.
14. Seção só de consulta não aceita alterações por requisição forjada.
15. Regras de URL não são regeneradas em toda visita.
16. Checkout padrão/customizado e conta continuam independentes.
17. Testar tema da loja e um tema de referência; ícones não duplicam e navegação é acessível.

## 17. Fontes da análise

Código e documentação do snapshot:
- `src/Domain/Sections/SectionDefinition.php`
- `src/Domain/Fields/DefinitionVocabulary.php`
- `resources/admin/app/FieldsScreen.js`
- `resources/admin/app/schema/fieldOperations.ts`
- `resources/admin/app/views/FieldManagerView.js`
- `src/Checkout/CustomerOrderFields.php`
- `src/Http/Checkout/UploadController.php`
- `src/Domain/Uploads/DownloadPolicy.php`
- `docs/validation/WCCS-076.md`

Documentação primária consultada:
```text
https://developer.wordpress.org/reference/functions/add_rewrite_endpoint/
https://developer.wordpress.org/reference/functions/flush_rewrite_rules/
https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-account-functions.html
https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-template-functions.html
https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-query.html
```

Conclusão: corrigir o agrupamento no editor é necessário, mas não suficiente. A entrega exige uma aba real de Minha Conta, formulário próprio, persistência do cliente, acesso privado e vínculos reutilizáveis — sem depender de um pedido e sem ativar destinos não escolhidos.
