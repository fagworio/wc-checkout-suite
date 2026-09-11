# WC CheckoutSuite
## Roadmap técnico, escopo de produto e identidade visual

**Versão do planejamento:** 1.0 · **Data:** 11/09/2026  
**Status:** especificação e protótipo visual; não é um plugin implementado ou homologado.  
**Produto:** WC CheckoutSuite · **Slug técnico proposto:** `wc-checkoutsuite`  
**Namespace PHP:** `WCCheckoutSuite\\` · **Prefixo:** `wccs_`  
**REST:** `wc-checkoutsuite/v1` · **Text domain:** `wc-checkoutsuite`

## Leitura do pacote

`ROADMAP.md` é a especificação principal. `BACKLOG.json` contém fases e tarefas identificadas. `PROTOTIPO.html` é uma referência visual interativa sem WordPress, backend, cobrança ou upload real. `DESIGN-TOKENS.json` contém os tokens de interface. `AUDITORIA-REFERENCIA.md` documenta a leitura estática do ZIP. `MATRIZ-REQUISITOS.md` faz a rastreabilidade do anexo.

O nome adotado é o solicitado. Disponibilidade de domínio, slug no diretório e registro de marca não foram certificados. A revisão comercial de marca/licenças é um gate de distribuição, não uma mudança de nome decidida neste documento.

## Origem das decisões

**Requisitos obrigatórios [R1]:** editor de campos padrão e personalizados; ordenação; máscaras IMask; documentos brasileiros; validação local e no servidor; uploads; condições; persistência; Classic/Blocks/HPOS; página de checkout customizada opcional; pagamentos reais; interface administrativa moderna; APIs e novos tipos de campo.

**Referência visual [R2]:** página em duas colunas, contato, entrega, pagamento em accordion e resumo do pedido. Não é código transacional pronto.

**Referência de implementação [R3]:** leitura estática dos arquivos do ZIP. Não houve instalação, execução de PHP, pedido real ou auditoria de segurança dinâmica.

**Verificações externas [S1–S11]:** APIs e restrições técnicas oficiais consultadas em 11/09/2026. Não confundir documentação atual com recursos efetivamente presentes no ZIP.

**Decisões propostas:** arquitetura, tokens, limites iniciais, tarefas, fases e critérios de aceite a seguir. Não são alegações de funcionalidades já implementadas.

---

# 1. Visão geral e limite do projeto

Criar um plugin modular para editar e estender os campos do checkout e, opcionalmente, aplicar uma página de checkout moderna, sem substituir a engine de transação do WooCommerce.

### Incluído na versão 1.0

1. Core extensível, cadastro de tipos, presets, validações e renderizadores.
2. Gerenciamento de campos nativos e personalizados, seções, regras, máscaras e layout responsivo.
3. Classic Checkout e Checkout Blocks, com matriz explícita de suporte.
4. Upload privado e persistência compatível com HPOS.
5. Pedidos, administração, Minha Conta, e-mails e APIs com visibilidade por público.
6. Página de checkout customizada opcional, preservando carrinho, frete, impostos, cupons e gateways.
7. Admin moderno, prévia, rascunho, publicação, histórico, importação/exportação e diagnóstico.
8. SDK mínimo documentado para novos tipos, testes e pacote comercial instalável.

### Fora desta versão

**Checkout Sidebar**, drawer lateral de compra, minicarrinho transacional, floating cart e finalização por overlay. Não haverá tela, botão desabilitado, pacote, rota, componente vazio nem feature flag dessa função na versão 1.0.

Também não fazem parte da entrega atual: gateways próprios, construtor geral de páginas, checkout headless, upsell/downsell, order bumps, recuperação de carrinho, teste A/B, cobrança por opções de campo, repetidores complexos, CRM e serviço SaaS de licenciamento.

O resumo lateral do pedido dentro da página de checkout **faz parte do layout atual**. Isso não é o Checkout Sidebar futuro. O painel de propriedades do editor administrativo também não é esse produto futuro.

### Preparação suficiente para evolução

Manter os serviços de campos, validação, persistência e contexto independentes da página. Um checkout lateral futuro poderá reutilizar esses contratos. Não desenvolver integração de sidebar agora e não assumir que ela herdará compatibilidade com todos os gateways.

---

# 2. Revisão da referência e ajustes necessários

No ZIP, o cabeçalho declara “Checkout Field Editor for WooCommerce”, versão 2.2.0, GPLv2 ou posterior. O nome do arquivo contendo “pro” não comprova que todos os recursos comerciais estejam no pacote. Foram encontrados modelos de campos, adaptadores Classic/Blocks, salvamento com `WC_Order`, configuração em options e ordenação administrativa. [R3]

A factory `create_field()` em `includes/utils/class-thwcfd-utils-field.php:374–435` usa uma sequência de condições por tipo. Ela referencia classes de arquivo, multiselect e outros tipos que não constam entre os arquivos de modelos presentes. Isso não permite afirmar que o ZIP implementa integralmente essas funções. Também não basta para concluir uma vulnerabilidade ou falha de execução sem teste do fluxo. [R3]

**Aproveitar conceitualmente:** separação entre definição e valor; integração com hooks oficiais; adaptadores para checkouts diferentes; APIs CRUD de pedido.

**Não reproduzir:** factory fechada, dependência de seletores DOM internos do Blocks, armazenamento duplicado sem autoridade definida, ou promessa de paridade visual irrestrita.

**Correção de requisito brasileiro:** a máscara numérica de CNPJ do anexo não é suficiente. A Receita informa início efetivo do uso alfanumérico em 31/07/2026. O plugin deverá aceitar CNPJ numérico e alfanumérico, mantendo letras e zeros à esquerda. [S9]

**Correção de ordem do desenvolvimento:** Blocks, SDK e testes entram no início como provas de integração e contratos. A conclusão dos respectivos módulos ocorre depois; eles não podem ser descobertos apenas no fim.

---

# 3. Princípios arquiteturais

**Monólito modular em PHP, com React/TypeScript no admin e nos componentes Blocks.** Sem Next.js, Node em produção, microserviços ou dependência de serviço externo para concluir uma compra.

Separação recomendada:

```text
Admin React
    ↓ API administrativa autenticada
Application services
    ↓
Domain
    FieldDefinition / FieldType / FieldPreset / Section
    Validation / Conditions / Normalization / Formatting
    ↓
Adapters
    Classic | Blocks Native | Blocks Custom
    ↓
WooCommerce lifecycle
    Cart / Checkout / Customer / Order / Payment

Presentation
    Default appearance | Suite page layout
    (Não é outra engine de checkout)
```

O checkout customizado é uma **camada de apresentação sobre Classic ou Blocks**, não um terceiro fluxo que cria pedidos por conta própria. O mesmo schema pode ter mapeamentos de apresentação por adapter sem duplicar suas regras de negócio.

Contrato público deve ser pequeno: registries de tipos, presets, validadores, máscaras e renderizadores; services explícitos; injeção simples pelo bootstrap. Não criar um framework genérico maior que o plugin.

---

# 4. Definição canônica de campos e seções

Cada campo possui identificador permanente, chave de integração estável e versão de definição. Renomear o label não renomeia a chave de armazenamento. Mudar o tipo ou a normalização exige uma operação de migração, não apenas um select no painel.

```json
{
  "id": "billing_document",
  "integration_id": "wc-checkoutsuite/billing-document",
  "origin": "custom",
  "type": "text",
  "preset": "br.cnpj",
  "label": "CNPJ",
  "section": "billing",
  "enabled": true,
  "required": true,
  "position": 40,
  "layout": { "desktop": 6, "tablet": 6, "mobile": 12 },
  "mask": { "key": "br.cnpj", "version": 1 },
  "normalizer": "br.cnpj",
  "validators": [{ "key": "br.cnpj" }],
  "conditions": {
    "visible": {
      "all": [{
        "source": "field",
        "path": "billing_person_type",
        "operator": "equals",
        "value": "pj"
      }]
    }
  },
  "hidden_value_policy": "discard",
  "storage": { "scope": "order", "sensitivity": "personal" },
  "visibility": {
    "admin_order": true,
    "customer_order": false,
    "customer_email": false,
    "admin_email": false,
    "public_api": false
  },
  "schema_version": 1
}
```

Esse exemplo é conceitual; `storage` será compilado conforme o adapter. Um registro nativo que persiste automaticamente no cliente não pode apresentar no admin a opção “somente pedido” como se fosse equivalente. Se a política não for representável nativamente, o compilador deverá escolher a implementação própria ou impedir a publicação.

Além disso: label traduzível, descrição, placeholder, opções de valor/label, default, classes permitidas, atributos HTML permitidos, min/max/step, comprimento, nome acessível, política de visibilidade, capacidades por adapter e origem do campo.

`SectionDefinition`: ID estável, título, descrição, posição, localização lógica, regras e mapeamento de inserção. Billing, Shipping, Contact, Account e Order são conceitos do domínio; não correspondem automaticamente a slots idênticos em todos os checkouts.

---

# 5. Tipos de campos e presets brasileiros

| Grupo | Tipos da versão 1.0 | Regras principais |
|---|---|---|
| Texto | text, textarea, email, tel/phone, url | Comprimento, atributos permitidos e formato |
| Número | number | Número real, min/max/step; não usar para documento |
| Escolha | select, multiselect, radio, checkbox, checkbox-group | Opções com chaves estáveis; arrays para múltiplos |
| Datas | date, time, datetime | Formato canônico e política de fuso explícita |
| Conteúdo | hidden, heading, paragraph, HTML restrito | Conteúdo visual não gera meta de valor |
| Arquivos | file, único ou múltiplo | Upload privado com token |
| Endereço | country, state | Usar dados e dependências nativas quando aplicável |

`password` não será um campo genérico de pedido. Criação de conta e senha pertencem ao fluxo nativo; senhas nunca são gravadas como order meta, mostradas no admin ou enviadas por e-mail. Month/week podem ser extensões posteriores e não bloqueiam os tipos expressamente exigidos no anexo.

**Presets:** CPF, CNPJ, RG, CEP, telefone fixo, celular, tipo de pessoa PF/PJ, razão social, nome fantasia, número, bairro e complemento. Inscrição estadual/municipal e nascimento são presets opcionais, desativados inicialmente.

CPF/CNPJ/CEP são strings. CNPJ aceita os dois formatos e mantém letras em caixa alta. RG não recebe um “validador nacional universal” inventado; regras específicas dependem de UF/tipo e permanecem opcionais. Validação matemática de documento não comprova titularidade, situação cadastral nem identidade.

Número, bairro e complemento precisam de mapeamento explícito para integrações de entrega e faturamento; não duplicar silenciosamente campos que outro plugin brasileiro já criou.

Datas: `date` é `YYYY-MM-DD`, sem conversão para UTC. `time` é hora local com contrato explícito. `datetime` guarda instante e informação de fuso conforme o uso. Valores como `0`, `false`, array vazio e ausência precisam de semânticas distintas.

---

# 6. Criação de novos tipos customizados

Dois níveis de extensão:

**Sem código:** o lojista cria um preset a partir de um tipo existente, combina máscara, opções, validação, condições e aparência. Não pode colar PHP/JavaScript executável.

**Com código:** outro plugin registra um novo tipo com schema, normalização, validação, formatação e renderizadores. O editor de configurações é gerado por schema ou por um componente JS registrado.

Contrato conceitual:

```php
interface FieldTypeInterface {
    public function key(): string;
    public function valueSchema(): array;
    public function settingsSchema(): array;
    public function normalize(mixed $value, FieldContext $context): mixed;
    public function validate(mixed $value, FieldContext $context): ValidationResult;
}
```

Renderizadores e formatação ficam em contratos separados para não acoplar o domínio a React, HTML ou `WC_Order`.

A declaração do tipo inclui versão do contrato, tipos de valor, recursos suportados, settings schema, assets, renderizador Classic, renderizador Blocks, formatter de texto/e-mail e adapter de persistência quando necessário.

**Critério de extensão:** um plugin de exemplo “Código de associado” deve surgir no seletor, validar no servidor, aparecer nos pedidos e funcionar nos adapters declarados sem alterar o core.

Ausência de componente Blocks não pode ser disfarçada com `supports: true`. Tipo indisponível porque a extensão foi desativada gera diagnóstico e bloqueio de publicação. Pedidos históricos continuam legíveis pelo snapshot/formatter de fallback seguro.

Hooks propostos, não APIs já existentes:

```text
wccs_register_field_types
wccs_register_presets
wccs_register_validators
wccs_register_masks
wccs_register_renderers
wccs_schema_before_publish
wccs_field_validation_result
wccs_order_fields_saved
wccs_upload_bound_to_order
```

Eventos JS usam namespace `wccs/`. Documentar argumentos, ordem, versões, políticas de depreciação e ausência de dados pessoais em eventos globais.

---

# 7. Classic Checkout

A implementação transforma o schema em configurações/hook callbacks compatíveis com o checkout clássico. Pontos de integração incluem `woocommerce_checkout_fields`, validação antes da finalização e criação de pedido no ciclo oficial; os hooks são confirmados também na referência anexada. [R3]

Aplicar alterações aos campos padrão de forma seletiva, preservando requisitos do país, regras de endereço, checkout de visitante, cadastro e comportamento de plugins de terceiros.

Requisitos: labels, descrições, opções, prioridades, larguras, conteúdo informativo, seções em posições homologadas, máscaras, condições, erros por campo e resumo de erros.

Preservar IDs, names e contratos necessários ao WooCommerce. Custom fields devem usar nomes exclusivos. Campo obrigatório estrutural não pode ser removido sem diagnóstico do impacto no frete, fiscal ou pagamento.

Inicialização dos componentes deve ser idempotente após refresh do checkout. Não duplicar handlers, IMask ou uploads a cada `updated_checkout`. Destruir instâncias de elementos removidos e não provocar loops de atualização.

Sem JavaScript, os campos simples devem continuar permitindo o fluxo clássico quando o gateway suportar; mensagens server-side permanecem. Recursos intrinsicamente JS, como upload antecipado e validação remota, têm fallback explícito ou aviso, nunca sucesso falso.

---

# 8. Checkout Blocks e matriz de capacidades

A documentação atual da API de campos adicionais lista `text`, `select`, `checkbox` e `date`, em `contact`, `address` ou `order`. O contrato e a versão instalada devem ser verificados em execução. `address` envolve os endereços de entrega e cobrança; isso não é sinônimo de “somente billing”. [S2]

Para tipos fora desse contrato: inner blocks/componentes próprios e dados no namespace `extensions`, integrados à Store API; a API pública oferece esse caminho. [S5]

| Recurso | Classic | Blocks nativo | Blocks próprio |
|---|---|---|---|
| Text/select/checkbox/date | Adapter PHP | Usar quando versão e política permitirem | Só quando necessário |
| CPF/CNPJ/CEP | Preset textual | Validação possível; máscara depende de extensão suportada | Campo controlado quando preciso |
| Textarea/radio/multiselect/time/datetime | Renderer próprio quando preciso | Não assumir suporte da API de additional fields | Componente próprio |
| Upload | Token + campo | Não usar como upload binário | Upload separado + token |
| Ordenação de campos da Suite | Prioridades | Dentro do que a API permitir | Dentro do contêiner da Suite |
| Reordenação arbitrária de campos core | Avaliar dependências | Não prometer | Não substituir DOM core |
| Novas seções | Hooks homologados | Localizações limitadas | Locais permitidos pelo editor |
| Estilo e larguras | CSS escopado | Não garantir controle irrestrito | Controle nos componentes próprios |
| Condições | Motor compartilhado | Compilação suportada | Motor compartilhado + servidor |

**Não conectar IMask diretamente a inputs React controlados pelo core sem um ponto de extensão oficial.** Para um campo que exige máscara sem suporte de renderização, usar o renderer próprio em slot permitido. Não recorrer a MutationObserver para “dominar” o DOM do checkout.

O admin mostra: “Nativo”, “Componente da Suite”, “Limitado” ou “Não suportado”, com motivo específico. Esses estados refletem versões e testes, não apenas a preferência visual.

Gate obrigatório no início: criar um pedido em Blocks com um campo customizado, erro server-side, tipo próprio e leitura via HPOS. Nenhum compromisso de paridade total de posicionamento antes dessa prova.

---

# 9. Máscaras e normalização

Usar IMask como dependência local versionada, sem CDN em runtime. O guia oferece masks de pattern, número, data e dinâmicas; a configuração do produto deverá ser declarativa e permitida por lista. [S7]

CPF: formato visual conhecido, persistência normalizada em string. CEP: dígitos com zeros preservados. Telefone: formato por país; não impor máscara brasileira a pedidos internacionais. CNPJ: 12 posições alfanuméricas e dois dígitos verificadores, aceitando também o legado numérico. [S9]

Registrar regras de normalização no servidor e reproduzir apenas a UX no cliente. Remover somente pontuação reconhecida; caracteres ilegais devem produzir erro, não ser descartados de forma que um dado incorreto vire “válido”.

Máscaras são desvinculadas da validação. Estados incompletos durante digitação são permitidos. Não marcar o campo como inválido antes da interação. Tratar colagem, autocomplete, teclado móvel, cursor, exclusão, composição de texto e edição de valores salvos.

No React usar wrapper controlado apropriado ao componente próprio, com cleanup. No Classic reidratar somente elementos novos. Testar vetores oficiais e fixtures compartilhadas de CNPJ. [S11]

---

# 10. Validação local, AJAX e finalização

Pipeline: captura → normalização → validação estrutural → avaliação de condições → validações de valor → validações dependentes do servidor → persistência no ciclo autorizado do pedido.

Frontend: required, comprimento, e-mail básico, CPF/CNPJ matemáticos, formato de CEP e limites visíveis de upload. AJAX apenas quando houver informação exclusiva do servidor ou provedor externo. A mesma regra crítica é revalidada no submit.

Requisitos de UX: erro junto do campo, mensagem útil, não apagar valor; remover erro quando corrigido; focar primeiro erro no submit; resumo com links; `aria-invalid`, `aria-describedby` e anúncio moderado.

Validação remota: debounce inicial proposto de 400 ms, abort de requisição anterior, request ID, descarte de resposta fora de ordem, limite por sessão/campo, timeout e estados `idle/checking/valid/invalid/unavailable`.

O response não pode funcionar como autorização permanente. A versão do schema e o valor atual precisam ser validados novamente antes do pagamento. Um “valid” antigo não autoriza valor novo.

Falhas: consulta opcional de CEP pode permitir endereço manual; regra crítica não pode liberar silenciosamente por timeout. Deve apresentar alternativa explícita ou bloquear com orientação. Não usar endpoint de validação para revelar se CPF/e-mail pertence a outro cliente.

Endpoint aceita field IDs e valores; regras e tipos são carregados do servidor. Não aceitar regex, callback, caminho de arquivo ou endpoint remoto fornecido pelo comprador.

---

# 11. Regras condicionais

Editor visual de grupos AND/OR, operadores iguais/diferentes, contém/não contém, maior/menor, preenchido/vazio e pertinência a conjunto. Tipos incompatíveis com o operador devem ser rejeitados na configuração.

Fontes da V1: outros campos, país, estado, cliente logado, itens/categorias do carrinho, valor do carrinho, frete e método de pagamento, desde que o adapter exponha esse contexto. Qualquer fonte indisponível recebe bloqueio ou restrição explícita.

Uma AST declarativa é avaliada em PHP e JS com fixtures comuns. Nos Blocks, compilar condições representáveis para os mecanismos nativos de `hidden/required`, que usam JSON Schema. [S3]

Detectar ciclos, dependências removidas, contradições e regras que ocultem campos estruturais necessários. O servidor recalcula com contexto confiável do carrinho e do cliente.

Campo customizado oculto por regra: padrão `discard`, sem erro required e sem persistência do valor residual. Alternativa `preserve` só por configuração explícita, com política de privacidade. Hidden input estático é diferente de campo condicionalmente ocultado: nunca carrega informação confiável de preço, permissão ou identidade.

---

# 12. Upload privado de arquivos

Tratar como subsistema: `UploadService`, `UploadRepository`, `StorageProvider`, `DownloadPolicy`, `CleanupJob` e renderers de upload.

Configuração: extensões, MIME, bytes máximos, quantidade, único/múltiplo, required, visibilidade, retenção, descrição da finalidade e permissão de acesso.

Fluxo: selecionar → pré-validação → enviar ao endpoint → validar conteúdo → área temporária privada → token opaco → submit → vínculo idempotente com pedido → acesso autorizado. Upload ainda não vinculado não é dado definitivo do pedido.

**Armazenamento padrão:** fora do webroot, quando possível. Alternativa sob uploads somente com bloqueio HTTP efetivo verificado no ambiente Apache/Nginx/CDN. `.htaccess` isolado não protege Nginx. Se a proteção não puder ser garantida, desabilitar upload e explicar a causa.

Não registrar documentos pessoais como mídia pública nem presumir que um plugin de offload para CDN pública preservará sigilo. Provider de object storage exige bucket privado e acesso assinado/autorizado.

Metadados operacionais em tabela própria `{$wpdb->prefix}wccs_uploads`: ID, token hash, owner/session hash, estado, chave privada, nome original sanitizado, MIME, bytes, created/expires, order_id e política. Tabela é proposta para tokens, índices, limpeza e concorrência; definições dos campos continuam em options.

Proteções: whitelist, inspeção MIME/conteúdo, tamanho, limites de quantidade e quota, nome aleatório, zero execução, path traversal, arquivos duplamente extensos, arquivos de compressão/bombas e concorrência. Não aceitar executáveis, HTML, SVG bruto ou arquivos compactados por padrão. Scanning pode ser integração adicional, sem prometer invulnerabilidade.

Convidados: vínculo real à sessão WooCommerce, token imprevisível e proteção CSRF por sessão. Nonce padrão de visitante não é identidade individual; a documentação do WordPress explicita essa limitação. [S8]

Downloads: administrador autorizado ou dono autenticado do pedido; visitante usa fluxo de acesso verificado e temporário. Nunca autorizar por `order_id` isolado. Preferir download como attachment com `nosniff`, cache privado e sem path exposto.

Retenção inicial proposta: temporários não vinculados expiram em 24h, configurável. Pedidos rascunho, pagamentos falhos e pedidos efetivos têm políticas distintas. Retry do pagamento deve reutilizar o vínculo no mesmo pedido; token não pode servir a outro pedido. Limpeza idempotente, agendada e com exclusão segura de objetos/metadados.

E-mails: mostrar informação ou link de acesso controlado apenas se configurado. Não anexar documentos pessoais por padrão.

---

# 13. Persistência e banco de dados

### Configurações

Options sem autoload para schema publicado, draft e settings. Histórico limitado e configurável. Armazenar arrays/JSON de dados, não objetos PHP serializados de extensões.

Campos têm IDs estáveis; settings possuem schema version, revision e migration history. Cache do schema compilado é indexado por revisão, adapter, idioma e capacidades relevantes.

Salvar rascunho não altera checkout público. Publicar realiza validação integral e substituição atômica. `expected_revision` impede sobrescrita entre administradores; retorno `409` exige comparar/recarregar. Não basta ler uma revisão e depois gravar sem exclusão mútua/CAS atômico no repositório.

Checkout aberto em revisão antiga: servidor revalida contra a revisão publicada e informa mudanças. Não cobrar com dados incompatíveis nem descartar silenciosamente campos novos obrigatórios.

### Valores

| Origem | Autoridade de persistência |
|---|---|
| Campo padrão WooCommerce | Fluxo/CRUD nativo; não criar meta paralela |
| Additional field nativo Blocks | Helpers e política da API WooCommerce |
| Campo próprio via Classic/Store API | `WC_Order` + armazenamento canônico da Suite |
| Perfil de cliente explicitamente persistente | CRUD/serviço apropriado do cliente |
| Arquivo | Referência de token/registro; nunca bytes no order meta |

A documentação HPOS orienta o uso do CRUD do WooCommerce; acesso direto a tabelas de posts para pedido pode ler/gravar a fonte errada. A Suite seguirá esse contrato. [S4]

Proposta de meta customizada: `_wccs_fields` com valores tipados; `_wccs_schema_revision`; snapshot mínimo de labels/opções/formatadores necessários à leitura histórica. Um `OrderFieldsService` oculta detalhes de backend e evita duplicação Classic/Blocks.

Chave com underscore **não é controle de acesso**. Projeções em APIs e filtros de meta precisam de autorização e testes; dados pessoais não podem vazar por endpoints públicos ou exportações genéricas.

Não modificar os pedidos históricos em massa para apenas trocar label. Exclusão do campo significa arquivar a definição para novas compras, preservando histórico. Migração destrutiva exige dry-run, backup, relatório e confirmação.

---

# 14. Integração com pedidos, clientes e e-mails

Administradores: bloco “Campos do checkout” na edição do pedido, origem, label, valor formatado, edição autorizada e trilha de alterações sem logs desnecessários do conteúdo pessoal.

Cliente: detalhe do pedido, thank-you e Minha Conta somente conforme política do campo. Separar valor do pedido de preferência atual do perfil; editar perfil não reescreve pedido passado.

E-mail: configuração independente para lojista/cliente e HTML/texto puro. Arquivos e documentos são privados inicialmente. Heading/HTML não são valores de pedido; sanitizar em cada contexto.

REST de integrações: schema documentado, tipos, autenticação, permissão por pedido/campo e paginação onde couber. Não expor automaticamente todos os order metas. Webhooks só com projeções autorizadas.

Reembolso/cancelamento não deve apagar automaticamente dados necessários ao histórico. Tratamento e retenção de dados pessoais requerem política explícita da loja, compatível com sua finalidade, não uma promessa genérica de conformidade.

---

# 15. Página customizada e gateways

`Enable Custom Checkout` é opt-in e vem desligado. A desativação restaura a apresentação original preservando o editor de campos.

Dois modos de apresentação: página clássica com hooks/templates mínimos; padrão/estilo de página Blocks com os componentes e regiões oficiais. Ambos usam os próprios fluxos transacionais. Uma incompatibilidade não autoriza trocar silenciosamente a engine da página.

Visual: cabeçalho compacto, contato, dados pessoais/empresa, entrega quando aplicável, frete, pagamento, consentimentos e resumo do pedido em coluna. No mobile, resumo expansível na própria página, seguido do formulário.

Grid 12 colunas, larguras 12/6/4/8 por viewport, DOM na mesma ordem visual. Não usar CSS `order` para fingir uma reordenação acessível.

**Problema identificado no HTML original:** `.field + .field { margin-top:9px; }` também afeta o segundo/terceiro elemento dentro do grid e provoca desalinhamento. A nova referência usa `gap` no contêiner e wrappers de campo, sem margem entre inputs irmãos. [R2]

Produto, valores, impostos, moeda, cupons e envio do HTML são demonstrativos. Em produção vêm do carrinho WooCommerce. Botões “Shop Pay”, PayPal etc. da referência não são garantia de disponibilidade e não serão copiados como integração falsa. [R2]

Pagamentos em accordion: radios reais e conteúdo do gateway, sem recolher cartão/CVV em campos próprios da Suite. Não duplicar inputs, clonar iframes, interceptar tokens ou recriar SDK de pagamento. Garantir inicialização, tokens salvos, redirecionamento, retorno, 3DS, retry e método sem campos.

No Blocks, os métodos normais e expressos têm integração registrada própria; a Suite deve preservar esses componentes e seus ciclos. Não deve registrar novamente gateways de terceiros apenas para estilizar. [S6]

Modo compatível deve existir quando um gateway homologado não tolerar determinada decoração. Registrar exatamente gateway, versão, modo e cenário testados. Não prometer compatibilidade com “todos os gateways”.

Express checkout é gate de segurança funcional: um fluxo não pode concluir sem um campo crítico obrigatório. Dependendo do gateway, integrar, pedir preenchimento antes ou indisponibilizar esse caminho com explicação. Uma flag no campo não cria integração automaticamente.

---

# 16. Interface administrativa

Navegação dentro de `WooCommerce → WC CheckoutSuite`:

**Campos · Seções · Regras · Aparência · Página de checkout · Importar/Exportar · Diagnóstico · Configurações.**

Evitar homepage de métricas inventadas. A primeira tela mostra o trabalho principal: estrutura e campos configurados.

Editor em três áreas, quando houver espaço: navegação compacta; lista/canvas de campos; inspetor de propriedades. Não manter três colunas comprimidas em telas pequenas: inspetor vira modal/página, navegação recolhe.

Topo: nome da configuração, estado “Rascunho / Publicado”, contexto Classic/Blocks, prévia, salvar rascunho e publicar alterações. A publicação mostra diferenças, validações e incompatibilidades.

Campo na lista: handle, label, chave técnica, tipo/preset, required, ativo, seção, compatibilidade e menu. Ícones sempre têm nome acessível. Editar, duplicar, mover, arquivar; campos core não têm exclusão destrutiva.

Criar campo: modal com busca e categorias; presets Brasil não ficam misturados aleatoriamente com primitivas. Um novo tipo de extensão aparece automaticamente.

Inspetor: Geral; Aparência; Máscara/validação; Condições; Armazenamento/visibilidade; Avançado. Exibir somente propriedades suportadas. Configuração de arquivo mostra limites e política de privacidade, não opções de campo textual.

Prévia: desktop/tablet/mobile, PF/PJ, logado/convidado e comparação Classic/Blocks quando representável. Selo “Prévia” e dados sintéticos; testes de pedido são outra etapa. Limites do adapter não podem desaparecer na prévia.

Drag-and-drop tem alternativa “Mover para cima/baixo”, anúncio da posição e suporte por teclado. Reordenação preserva foco, grupo e ID.

Estados obrigatórios: vazio; carregando; salvo; alterado; erro inline; conflito 409; falha de rede; permissão insuficiente; recurso incompatível; extensão ausente; versão não suportada; restauração de rascunho. Falha não descarta trabalho.

Operações em lote: habilitar/desabilitar, seção, visibilidade e arquivamento de customizados, com confirmação de impacto. Undo local de edição e histórico de publicação são mecanismos separados.

---

# 17. Identidade visual e design system

**Direção:** interface clara, profissional, com violeta como ação e superfícies neutras. Tipografia legível, pouca decoração e estados funcionais. O acabamento vem de alinhamento, hierarquia e consistência, não de gradients, glow ou excesso de cards.

Marca: “WC” como prefixo secundário; “CheckoutSuite” como wordmark principal. Símbolo conceitual simples de linhas de formulário e confirmação; não usar o logotipo oficial WooCommerce para sugerir vínculo.

### Tokens claros

| Token | Valor | Uso |
|---|---|---|
| Brand | `#6D28D9` | Botões primários, seleção e foco |
| Brand hover | `#5B21B6` | Hover ativo |
| Brand subtle | `#F3EDFF` | Fundo leve de seleção |
| App background | `#F6F7FB` | Fundo do admin |
| Surface | `#FFFFFF` | Editor, inputs e painéis |
| Ink | `#202334` | Texto principal |
| Muted | `#626A7B` | Ajuda e texto secundário |
| Decorative border | `#E5E7EF` | Separação de superfícies |
| Control border | `#888F9F` | Contorno identificável de inputs |
| Success | `#087F70` | Sucesso, acompanhado de texto |
| Warning | `#9A5B00` | Aviso, acompanhado de texto |
| Danger | `#B42338` | Erro, acompanhado de texto |

Admin pode ter modo escuro com tokens próprios. A preferência escura do admin não muda o checkout da loja. No frontend, o lojista escolhe estilo Suite ou herança do tema.

Tipografia: stack de sistema sem download externo por padrão; família de marca customizada é opcional e hospedada localmente, com licença adequada. Corpo administrativo 14px; formulário público 16px; títulos 24–28px; descrição 13px com contraste; nunca usar tamanho pequeno para esconder informação.

Escala: 4/8/12/16/24/32/48px. Campos 48px de altura, ações primárias 44–48px, raio de campo 10px, card 16px, bordas discretas, sombra só para modais/elevação necessária.

Estados do campo: vazio, hover, foco, preenchido, válido, inválido, desativado, somente leitura e validação remota. Label fica visível; placeholder não substitui label. Asterisco tem explicação; erro não depende só de cor.

Pagamentos: linha inteira selecionável com radio real, total do método quando fornecido pelo gateway, conteúdo expandido sem deslocamento brusco, ícones discretos. Sem contagem regressiva ou mensagem de escassez artificial.

Acessibilidade é meta WCAG 2.2 AA, não certificação atual: contraste de texto, controles e foco; teclado; leitores de tela; zoom; reflow; reduced motion; área de toque preferencial de 44px; erro identificável. [S10]

### Componentes previstos

`AppShell`, `PageHeader`, `Button`, `IconButton`, `StatusBadge`, `FieldPicker`, `FieldRow`, `FieldInspector`, `SortableList`, `SectionCard`, `ConditionBuilder`, `MaskEditor`, `UploadSettings`, `VisibilityMatrix`, `CompatibilityBadge`, `PreviewFrame`, `PublishDiff`, `RevisionList`, `Dialog`, `Toast`, `EmptyState`, `ErrorSummary`.

Frontend: `FieldShell`, `TextField`, `SelectField`, `RadioGroup`, `MultiSelect`, `CheckboxGroup`, `DateField`, `FileDropzone`, `InlineError`, `SectionHeading`, `PaymentFrame`, `OrderSummary`.

CSS escopado em `.wccs-admin` e `.wccs-checkout`. Não redefinir globalmente `input`, `.button`, `h2` ou controles de gateways. Campos nativos e os da Suite devem parecer coesos sem quebrar CSS do tema.

---

# 18. Arquitetura JavaScript e PHP

PHP: Composer/PSR-4, namespace próprio, serviços testáveis, contratos pequenos, bootstrap defensivo, registro em hooks apropriados e traduções. Proposta de baseline PHP 8.3; confirmação do ambiente alvo e versões suportadas faz parte da fase F00.

Admin: React/TypeScript usando pacotes do WordPress quando apropriado; API client com nonce, mensagens e concorrência; dependências extraídas no build para não embutir outra cópia de React.

Classic: bundle leve e específico, preservando eventos nativos. Blocks: componentes controlados, registry e stores públicos. Não instalar/embutir pacotes internos Woo como se fossem bibliotecas autônomas; mapear dependências para handles disponibilizados no ambiente. [S6]

DS: tokens JSON e CSS variables como fonte compartilhada. Lógica de condição e validações locais testada com fixtures derivadas dos contratos PHP, sem distribuir segredos ou regras privadas.

Build em desenvolvimento/CI; release inclui assets compilados. O lojista instala o ZIP sem executar npm ou Composer.

---

# 19. APIs e configuração segura

### Endpoints administrativos propostos

```text
GET/PUT  /wc-checkoutsuite/v1/schema/draft
POST     /wc-checkoutsuite/v1/schema/validate
POST     /wc-checkoutsuite/v1/schema/publish
GET      /wc-checkoutsuite/v1/schema/revisions
POST     /wc-checkoutsuite/v1/schema/restore
GET      /wc-checkoutsuite/v1/registry
POST     /wc-checkoutsuite/v1/import/preview
POST     /wc-checkoutsuite/v1/import/apply
GET      /wc-checkoutsuite/v1/export
GET      /wc-checkoutsuite/v1/diagnostics
```

Uma atualização de draft pode reunir várias operações de campos e ordem de maneira atômica. CRUD por campo é fachada opcional, não exigência de endpoints redundantes.

Capability administrativa `manage_woocommerce`, nonce para cookie auth, validação de schema, limites de payload, `expected_revision` e controles contra mass assignment. Administrador da configuração e leitor de documentos podem exigir permissões diferentes.

### Endpoints de sessão/cliente

```text
POST     /wc-checkoutsuite/v1/checkout/validate
POST     /wc-checkoutsuite/v1/uploads
DELETE   /wc-checkoutsuite/v1/uploads/{token}
GET      /wc-checkoutsuite/v1/orders/{id}/files/{file}
```

O endpoint final depende de autorização por pedido e sessão. Nenhuma rota pública retorna draft, regras privadas, dados de outro comprador ou caminhos físicos.

Não criar endpoint próprio para cobrar/criar pedido fora do fluxo WooCommerce.

### Importação/exportação

JSON versionado; sem PHP, JS executável, credenciais, tokens de download ou documentos. Preview de diff, mapeamento de IDs, avisos de incompatibilidade e backup anterior.

Migração do ThemeHigh é um escopo limitado: mapear configurações comprovadas no ZIP e relatar campos não suportados. Não importar configurações Premium ausentes por suposição. Importar schema não migra automaticamente valores históricos de pedidos.

---

# 20. Segurança, privacidade e operação

Sanitizar por tipo; validar no servidor; escapar no contexto; HTML restrito; schemas com limites; regex segura sem execução de código e sem padrões ilimitados de alto custo.

Não confiar em names, required, enabled, price, role ou visibility enviados pelo cliente. Não permitir chamar callbacks PHP por nome recebido em JSON.

Nonces protegem contra CSRF, não substituem autenticação/autorização. Endpoints de convidados usam sessão e proteção própria adequada. [S8]

Dados pessoais: minimização, finalidade visível, retenção configurável, exportação/eliminação pelos mecanismos de privacidade do WordPress, e logs redigidos. A loja decide a base e finalidade; o produto não deve anunciar conformidade legal automática.

Logs: request ID, tipo de erro, módulo e versão; não CPF/CNPJ integral, RG, tokens, cartão, CVV ou conteúdo de arquivos. Dados de diagnóstico exportados precisam ser sanitizados e revistos.

REST, AJAX, checkout, downloads e sessão não podem ser cacheados publicamente. Configuração por ambiente deve verificar CDN/WAF e respostas, sem assumir acesso universal.

Ativação: checar WooCommerce, versões e diretórios antes de habilitar módulos. Desativação preserva dados. Uninstall remove configurações somente mediante política explícita; pedido histórico não é apagado. Jobs são cancelados/limpos conforme lifecycle.

Ambientes: local → staging → produção. Banco de produção continua fonte dos pedidos; não enviar banco de staging sobre produção. Deploy migra somente estruturas/configurações aprovadas com backup e rollback.

---

# 21. Performance e compatibilidade

Assets sob demanda: nada no catálogo quando não há uso; admin só na tela da Suite; frontend apenas em checkout/Mínha Conta que renderizam seus campos; IMask/Upload/Conditions carregados conforme uso.

Orçamentos iniciais propostos, a medir em F13: bundle Classic adicional até 80 KB gzip; Blocks adicional até 120 KB gzip, excluindo runtime compartilhado; nenhuma consulta externa obrigatória para campos básicos; zero AJAX por tecla em validadores locais; nenhuma chamada `save()` por campo; cache do schema sem dados pessoais.

Meta de regressão: latência p95 adicional de validação local no servidor até 30ms em benchmark controlado com 50 campos/100 regras, sem provedores remotos. É objetivo de engenharia, não desempenho comprovado. Documentar ambiente, hardware, dataset e amostras.

Testar configuração de referência de 50 campos e 100 regras; limites de segurança reais são configuráveis e testados. UI virtualizada só se medição justificar.

Compatibilidade: Classic; Blocks; HPOS on/off com sync off em teste HPOS; temas clássicos/blocos; visitante/logado; produtos virtuais/físicos/mistos; fretes, cupons, impostos e moeda; endereço separado; países; gateways selecionados e suas versões.

Versões mínimas e máximas reais de WordPress/Woo/PHP/Node são fixadas na F00 em `compatibility.json`, a partir da matriz de CI e capabilities necessárias. Não usar o “tested up to” do ZIP como teste do nosso produto. `date`, condições e APIs Blocks exigem feature detection e versão mínima confirmada.

Plugins brasileiros, frete, notas fiscais, memberships/subscriptions e multilíngue são mapeados como “testado / não testado / incompatível”. Recursos avançados específicos de terceiros não são garantidos sem cenários explícitos.

---

# 22. Estrutura de diretórios proposta

```text
wc-checkoutsuite/
├── wc-checkoutsuite.php
├── uninstall.php
├── composer.json
├── package.json
├── src/
│   ├── Plugin.php
│   ├── Domain/{Fields,Sections,Validation,Conditions}
│   ├── Application/{Schema,Checkout,Orders,Uploads}
│   ├── Infrastructure/{WordPress,WooCommerce,Storage,Repositories}
│   ├── Integrations/{Classic,Blocks,Gateways,Privacy}
│   ├── Http/{Admin,Checkout,Downloads}
│   ├── Admin/
│   ├── Migrations/
│   └── Compatibility/
├── resources/
│   ├── admin/
│   ├── classic/
│   ├── blocks/
│   ├── shared/
│   └── design-tokens/
├── build/
├── templates/
│   ├── checkout/
│   ├── order/
│   └── emails/
├── languages/
├── tests/{Unit,Integration,E2E,Fixtures,Security,Visual}
├── examples/custom-field-type/
├── docs/{adr,api,operations,compatibility}
└── .github/workflows/
```

Não existe diretório `sidebar-checkout`. A estrutura acima é planejada, não um scaffold de plugin incluído neste pacote.


# 23. Roadmap de execução

Cada fase inclui entregáveis, dependências, riscos e gate. Esforço é relativo, não prazo prometido. QA participa desde F00 e CI desde F01. F10 pode avançar em paralelo à página customizada depois de suas dependências.

## F00 · Escopo, inventário e provas técnicas

**Objetivo:** Fixar capacidades reais antes de desenvolver o editor completo.

**Responsáveis:** Arquitetura e QA. **Dependências:** nenhuma. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-001 | Inventariar requisitos e ambiente | Auditoria, compatibility.json | Requisitos rastreados; versões de WordPress/Woo/PHP e gateways alvo registradas. |
| WCCS-002 | Provar fluxo Classic e HPOS | Classic, OrderFieldsService | Um campo de teste é validado e persistido com HPOS ligado e sincronização desligada. |
| WCCS-003 | Provar fluxo Blocks | Blocks, Store API | Text, campo customizado controlado e erro server-side chegam ao pedido sem manipulação do DOM core. |
| WCCS-004 | Provar layout e gateways | Page layout, payment | Pagamento de sandbox, refresh e retorno verificados no conjunto inicial; slots permitidos documentados. |
| WCCS-005 | Congelar decisões arquiteturais | ADRs, threat model | ADRs aprovadas para storage, upload privado, CNPJ alfanumérico e exclusão de Sidebar. |

**Risco principal:** Versões/API ou gateways sem suporte podem inviabilizar uma promessa de layout.

**Gate de conclusão:** Nenhuma capacidade crítica permanece apenas presumida; limitações de Blocks viram regras do produto.

## F01 · Core, schema e contratos de extensão

**Objetivo:** Criar o domínio independente dos renderizadores e a fundação de qualidade.

**Responsáveis:** Backend. **Dependências:** F00. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-006 | Criar bootstrap e build | PHP, Composer, WordPress | Ativação segura sem Woo; assets compiláveis; namespace e i18n definidos. |
| WCCS-007 | Implementar schema e registries | Domain, Types, Presets | Tipos, valores e settings validados; registro externo funciona sem editar factory. |
| WCCS-008 | Implementar draft, publicação e revisões | Repository, Migrations | Gravação atômica e conflito 409 testados; publicar não perde histórico. |
| WCCS-009 | Criar normalizadores e contratos | Validation, Conditions, Renderers | Interfaces versionadas; mesmo contrato serve Classic/Blocks/pedidos. |
| WCCS-010 | Ativar CI desde o início | Tests, CI | PHPCS, análise estática, lint, typecheck e testes mínimos obrigatórios no merge. |

**Risco principal:** IDs instáveis ou acoplamento ao frontend causam migração e retrabalho.

**Gate de conclusão:** Plugin-base instalável com schema versionado, concorrência e extensão de exemplo mínima.

## F02 · Design system e shell administrativo

**Objetivo:** Transformar a identidade visual em componentes reutilizáveis e acessíveis.

**Responsáveis:** Frontend e Design. **Dependências:** F01. **Complexidade:** Média.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-011 | Formalizar tokens e estados | Design system | Tokens claros/escuros, campos e botões documentados sem contraste reprovado. |
| WCCS-012 | Criar shell dentro do WordPress | Admin React | Navegação e assets restritos à tela da Suite; responsividade funcional. |
| WCCS-013 | Criar componentes básicos | UI library | Botões, diálogos, tabs, mensagens, badges e formulários têm teclado e foco. |
| WCCS-014 | Implementar client REST | Admin API | Nonce, erro 403/409/422, retry seguro e estado não salvo tratados. |
| WCCS-015 | Criar preview visual | PreviewFrame | Desktop/tablet/mobile identificados como prévia; nenhum dado real necessário. |

**Risco principal:** CSS global e componentes visuais inacessíveis contaminam o WordPress.

**Gate de conclusão:** Biblioteca visual aprovada em três larguras e com navegação por teclado.

## F03 · Field Manager, seções e publicação

**Objetivo:** Permitir configurar campos sem produzir schemas inválidos.

**Responsáveis:** Frontend e Backend. **Dependências:** F01, F02. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-016 | Criar Field Picker e CRUD | Admin, FieldDefinition | Criar/editar/duplicar/arquivar; campos core protegidos; busca por categorias. |
| WCCS-017 | Criar inspector por tipo | SettingsSchema | Máscaras, opções, descrição, largura, storage e visibilidade somente onde suportados. |
| WCCS-018 | Criar seções e ordenação | Sections, SortableList | Ordem por seção salva; mover por teclado e botões preserva foco. |
| WCCS-019 | Implementar draft e PublishDiff | Revisions, Preview | Salvar draft não afeta a loja; publicação mostra diferenças e incompatibilidades. |
| WCCS-020 | Criar estados e ações em lote | Admin state | Vazio, erro, rede, conflito e permissão tratados; desfazer local e revisão separados. |

**Risco principal:** Drag-and-drop e salvar automático podem alterar produção sem intenção.

**Gate de conclusão:** Lojista monta e publica um formulário completo, restaura revisão e não perde trabalho em conflito.

## F04 · Classic Checkout e persistência canônica

**Objetivo:** Entregar o primeiro fluxo transacional completo sem recriar checkout.

**Responsáveis:** Backend. **Dependências:** F03. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-021 | Implementar Classic adapter | Hooks, Renderers | Tipos básicos, seções e larguras refletem schema; campos core mantêm contratos. |
| WCCS-022 | Integrar normalização e validação final | Checkout lifecycle | POST adulterado falha mesmo sem JS; erros apontam campos corretos. |
| WCCS-023 | Implementar OrderFieldsService | Orders, WC CRUD | Valores tipados, zeros e false preservados; autoridade única por campo. |
| WCCS-024 | Implementar histórico de valores | Snapshots, Migrations | Renomear/arquivar campo não torna pedido antigo ilegível. |
| WCCS-025 | Tratar lifecycle e refresh | Classic JS | Refresh preserva valores e cria apenas uma instância por componente. |

**Risco principal:** Conflitos com locale/campos de terceiros e duplicação de metadados.

**Gate de conclusão:** Pedidos Classic válidos e inválidos testados em HPOS on/off, visitante/logado e carrinho físico/virtual.

## F05 · Presets Brasil, IMask e validação remota

**Objetivo:** Entregar documentos e máscaras corretos, com validação sem fricção.

**Responsáveis:** Backend e Frontend. **Dependências:** F04. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-026 | Implementar presets brasileiros | Presets, Fixtures | CPF, CNPJ numérico/alfanumérico, RG, CEP, telefone e endereço com contratos próprios. |
| WCCS-027 | Integrar IMask localmente | Classic, Shared fields | Colar, apagar, autocomplete, mobile e refresh não quebram cursor/valor. |
| WCCS-028 | Implementar validadores PHP/JS | Validation | Mesmos fixtures passam/falham; DV não é apresentado como validação de identidade. |
| WCCS-029 | Implementar endpoint de validação | Checkout API | Sessão, rate limit, timeout, abort e request ID impedem estado obsoleto. |
| WCCS-030 | Implementar UX de erro | FieldShell, ErrorSummary | Mensagens por campo; primeiro erro focado; regra crítica revalidada no submit. |

**Risco principal:** Validação numérica de CNPJ, falsos erros e respostas AJAX fora de ordem.

**Gate de conclusão:** Documento inválido não finaliza; CNPJ alfanumérico válido não é truncado nem convertido em número.

## F06 · Conditional Logic e política de valores

**Objetivo:** Executar as mesmas condições em PHP, JS e integração Blocks.

**Responsáveis:** Backend e Frontend. **Dependências:** F03, F05. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-031 | Criar AST e tipos de operadores | Condition engine | AND/OR e operadores tipados; ciclos e referências inválidas rejeitados. |
| WCCS-032 | Criar editor de regras | ConditionBuilder | Regras legíveis, preview de resultado e mensagens de contradição. |
| WCCS-033 | Implementar evaluators PHP/JS | Shared fixtures | Paridade em vazio, zero, false, arrays, contexto e negações. |
| WCCS-034 | Implementar discard/preserve | Checkout policies | Valor residual oculto é descartado por padrão; required segue estado no servidor. |
| WCCS-035 | Criar compilador de condições nativas | Blocks compiler | Somente condições representáveis são compiladas; demais capacidades ficam explícitas. |

**Risco principal:** Campo oculto obrigatório ou divergência de contexto causa pedidos bloqueados/incompletos.

**Gate de conclusão:** Fluxo PF/PJ funciona após múltiplas trocas; adulteração do browser não burla regra.

## F07 · Checkout Blocks nativo e tipos próprios

**Objetivo:** Suportar Blocks com componentes e APIs apropriados.

**Responsáveis:** Frontend e Backend. **Dependências:** F04, F05, F06. **Complexidade:** Muito alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-036 | Implementar native additional fields | Blocks adapter | Text/select/checkbox/date com feature detection e políticas de localização/storage. |
| WCCS-037 | Implementar campos controlados próprios | Blocks renderers | Textarea, radio, multiselect, time/datetime e presets mascarados nos slots permitidos. |
| WCCS-038 | Integrar Store API e validação | Blocks, extensions | Erro final bloqueia pagamento; payload tipado; retry não duplica gravação. |
| WCCS-039 | Implementar matriz no admin | CapabilityResolver | Limites de core, largura, seção e tipo visíveis antes de publicar. |
| WCCS-040 | Testar edição e lifecycle Blocks | E2E, components | Troca de endereço/frete, remount e rerender preservam valores sem DOM hacks. |

**Risco principal:** Expectativa de posições livres, regressões de React e duplicação de valor.

**Gate de conclusão:** Todos os tipos não-file da V1 possuem renderer homologado ou restrição explícita coerente; nenhum modo é anunciado além do testado.

## F08 · Upload privado nos dois checkouts

**Objetivo:** Entregar arquivos privados, associáveis ao pedido e recuperáveis com autorização.

**Responsáveis:** Backend, Frontend e Segurança. **Dependências:** F07. **Complexidade:** Muito alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-041 | Criar storage e tabela operacional | Uploads, StorageProvider | Privacidade HTTP/CDN comprovada; ambiente sem proteção desativa recurso. |
| WCCS-042 | Criar upload e tokens por sessão | Upload API | MIME, bytes, quota e ownership validados; sessão A não usa token B. |
| WCCS-043 | Criar componente único/múltiplo | Classic, Blocks | Progresso, cancelar, remover, retry e erros acessíveis sem upload duplicado. |
| WCCS-044 | Vincular ao pedido e proteger download | Order binding, DownloadPolicy | Vínculo atômico/idempotente; dono autorizado acessa e terceiro recebe negação. |
| WCCS-045 | Criar retenção e limpeza | Scheduled jobs | Temporários expiram; draft/falha/efetivo têm regras testadas; cleanups podem repetir. |

**Risco principal:** Vazamento público, IDOR, quota, token reutilizado e exclusão de arquivo de pedido válido.

**Gate de conclusão:** Teste direto por URL, token copiado e pedido alheio não revela o arquivo; compra e retry conservam vínculo.

## F09 · Página customizada e pagamento

**Objetivo:** Aplicar o layout moderno à página sem assumir o papel do gateway.

**Responsáveis:** Frontend, Backend e QA. **Dependências:** F02, F07, F08. **Complexidade:** Muito alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-046 | Implementar apresentação Classic | Templates, scoped CSS | Layout responsivo do anexo com gap correto; hooks preservados. |
| WCCS-047 | Implementar apresentação Blocks | Pattern, styles, integration | Layout nas regiões suportadas; não clona campos nem componentes de pagamento. |
| WCCS-048 | Integrar accordion e resumo | PaymentFrame, OrderSummary | Radios reais; totals/cupons/frete vêm do Woo; teclado e focus funcionam. |
| WCCS-049 | Homologar gateways e express | Payment matrix | Sandbox, 3DS/redirect, tokens salvos, retry e dados required verificados. |
| WCCS-050 | Criar opt-in, diagnóstico e fallback | Settings, Compatibility | Ativar/desativar não muda engine nem apaga campos; falha tem retorno seguro. |

**Risco principal:** Iframe/tokenização, refresh, 3DS e express checkout podem quebrar.

**Gate de conclusão:** Pedidos reais de sandbox nos cenários homologados, sem inputs de cartão próprios e sem Checkout Sidebar.

## F10 · Pedidos, Minha Conta, APIs e privacidade

**Objetivo:** Disponibilizar valores apenas aos públicos autorizados.

**Responsáveis:** Backend e Frontend. **Dependências:** F04, F07, F08. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-051 | Criar editor de dados do pedido | Order admin | CRUD, edição autorizada e validação idêntica nos dois backends de pedidos. |
| WCCS-052 | Criar exibição ao cliente | My Account, thank-you | Visibilidade por campo respeitada; conta atual não reescreve pedido passado. |
| WCCS-053 | Criar e-mails HTML/texto | Email projections | Públicos distintos; documentos sem anexos por padrão; links autorizados. |
| WCCS-054 | Criar API pública de integração | REST, OrderFieldsService | Contrato autenticado; nenhum dado pessoal aparece em Store API pública. |
| WCCS-055 | Implementar exportação/eliminação de dados | Privacy, retention | Fluxos por titular e política da loja testados, com retenção explicável. |

**Risco principal:** Meta privada exposta em REST/e-mail e edição de perfil alterando histórico.

**Gate de conclusão:** Auditoria por papel/endpoint/e-mail confirma ausência de exposição indevida e leitura histórica.

## F11 · Portabilidade, SDK e documentação

**Objetivo:** Permitir migrar configuração e estender produto sem alterar seu core.

**Responsáveis:** Backend, Frontend e Documentação. **Dependências:** F03, F07, F10. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-056 | Implementar export/import com preview | Schema IO | JSON versionado, diff, limites, conflitos e rollback testados; sem segredos. |
| WCCS-057 | Criar migrador ThemeHigh limitado | Migration adapters | Mapeia apenas estruturas comprovadas; unsupported gera relatório e não descarte silencioso. |
| WCCS-058 | Concluir exemplo de novo tipo | SDK example | Código de associado aparece no picker, nos dois checkouts declarados e no pedido. |
| WCCS-059 | Documentar hooks e contratos | Developer docs | Ordem, assinaturas, versão, depreciação e componente React demonstrados. |
| WCCS-060 | Documentar operação do lojista | Merchant docs | Configurar PF/PJ, uploads, layout, restore e diagnóstico sem editar código. |

**Risco principal:** Importação executável, perda de IDs e falsa compatibilidade de tipo externo.

**Gate de conclusão:** Exportar e importar em staging mantém IDs; plugin de exemplo adiciona tipo sem patch do core.

## F12 · Hardening, acessibilidade e matriz final

**Objetivo:** Fechar a entrega por evidência, não por declaração de compatibilidade.

**Responsáveis:** QA, Segurança e Performance. **Dependências:** F09, F10, F11. **Complexidade:** Muito alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-061 | Executar suíte de segurança | Security tests | IDOR, XSS, CSRF, token replay, payloads, MIME e concorrência exercitados. |
| WCCS-062 | Executar matriz funcional | E2E, Compatibility | Classic/Blocks/HPOS, tipos de carrinho, clientes e gateways registrados por versão. |
| WCCS-063 | Executar QA visual e a11y | Visual, keyboard, screen reader | 320/375/768/1280/1440px, zoom, foco e reduced motion revisados. |
| WCCS-064 | Medir performance e estabilidade | Benchmarks | Budgets reportados; zero loops/handlers duplicados; memória após refresh estável. |
| WCCS-065 | Executar testes de recuperação | Release safety | Rollback, schemas antigos, checkout aberto, jobs e extensão desativada tratados. |

**Risco principal:** Combinações de plugins, acessibilidade, cache e corrida só aparecem na integração.

**Gate de conclusão:** Nenhum defeito crítico de pagamento, exposição de dados ou perda de configuração; matriz publicada.

## F13 · Release 1.0 e operação comercial

**Objetivo:** Distribuir um pacote instalável, documentado e suportável.

**Responsáveis:** Release e Produto. **Dependências:** F12. **Complexidade:** Alta.

| ID | Entrega | Componentes | Aceite da tarefa |
|---|---|---|---|
| WCCS-066 | Gerar pacote de release | CI, artifacts | ZIP limpo com assets e autoload; instalação sem ferramentas de build. |
| WCCS-067 | Revisar licenças, nome e distribuição | Commercial checklist | Dependências inventariadas; política de atualização, suporte e marca revisada. |
| WCCS-068 | Preparar manual e release notes | Docs, support | Limitações, privacy, uninstall, backup e suporte claramente documentados. |
| WCCS-069 | Executar smoke de instalação/upgrade | Staging | Instalação limpa e upgrade/rollback com pedidos existentes aprovados. |
| WCCS-070 | Publicar versão e registro de homologação | Release manifest | Checksums, versões testadas, resultados, changelog e plano de hotfix presentes. |

**Risco principal:** Update quebrado, licença bloqueando checkout e claim comercial não demonstrado.

**Gate de conclusão:** Release 1.0 distribuível; Sidebar ausente; cobrança continua independente de servidor de licença.


# 24. Testes automatizados e evidências

| Camada | Cobertura exigida |
|---|---|
| Unitários PHP | Schema, normalizadores, validadores, condições, capacidades, projeções de dados e decisões de download |
| Unitários JS | Campos controlados, máscaras, regras, estados, responses fora de ordem e formatação |
| Fixtures comuns | CPF/CNPJ, strings com zeros, bool, arrays, datas, país, condições e valores ocultos |
| Integração WordPress/Woo | Classic, Store API, order CRUD, HPOS on/off, cliente, permissões e migrations |
| E2E navegador | Criar campo → publicar → checkout → pedido → e-mail/conta; erros, reload, retry e mobile |
| Segurança | CSRF, IDOR, XSS, upload, MIME, path traversal, sessão, replay, quota e rate limit |
| Visual/a11y | Grid, tema claro/escuro do admin, estados de campo, zoom, leitor, teclado e movimento reduzido |
| Operação | Atualização, desativação, uninstall, backup, concorrência, cache e jobs |

**Casos que não podem faltar:** número `0`; checkbox false; UTF-8 e acentos; endereço internacional; CPF/CNPJ com zero inicial; CNPJ alfanumérico; troca PF→PJ→PF; hidden required; select adulterado; envio de chave inexistente; upload de outro cliente; duas abas; dois admins publicando; compra iniciada antes de publicar novo campo obrigatório.

**Pagamento:** crédito aprovado/recusado em sandbox; Pix e boleto pelos gateways testados; endereço alterado após seleção do pagamento; frete alterado; cupom aplicado/removido; carrinho sem pagamento; 3DS/redirect; retry; refresh; duplicidade de submit; checkout expresso com required crítico.

**Critério de teste:** relatório informa o que passou, falhou e não foi testado. Código compilado, badge HPOS ou ausência de erro no console não constituem homologação de pagamento.

# 25. Riscos e decisões de mitigação

| Risco | Severidade | Mitigação | Gate |
|---|---|---|---|
| Prometer paridade irrestrita no Blocks | Crítica | POC cedo, capability matrix e slots públicos | F00/F07 |
| Arquivo privado acessível via CDN | Crítica | Storage privado + teste HTTP efetivo + fail-closed | F08 |
| CNPJ truncado para somente dígitos | Alta | String alfanumérica, normalizador e fixtures | F05 |
| Gateway quebrado pela apresentação | Crítica | Preservar componente/SDK; matriz por versão; fallback | F09 |
| Express checkout ignora obrigatório | Crítica | Verificação por gateway e bloqueio explícito | F09 |
| Gravação duplicada Classic/Blocks | Alta | Autoridade única por tipo de storage | F04/F07 |
| Admin sobrescreve outra edição | Alta | Revisão + escrita atômica + 409 | F01/F03 |
| Publicação invalida checkout aberto | Alta | Revalidação e comunicação sem cobrança indevida | F12 |
| Tipo externo removido | Alta | Diagnóstico, bloqueio e leitura histórica segura | F11 |
| Conflito com plugin brasileiro/fiscal | Alta | Mapear campos existentes; não duplicar por nome | F00/F12 |
| Injeção por import/configuração | Crítica | JSON schema, limites e nenhum código executável | F11 |
| Expansão descontrolada para Sidebar | Média | Fora da V1, sem tasks/componentes disfarçados | F13 |

# 26. Critérios de aceite da versão 1.0

1. Instalar e ativar não quebra WordPress sem WooCommerce; pré-requisitos têm mensagens úteis.
2. O lojista cria, edita, duplica, arquiva, habilita e ordena campos com teclado ou mouse.
3. Campos padrão respeitam país, requisitos de negócio e limitações do adapter.
4. Todos os tipos exigidos no anexo são cobertos em locais homologados; suporte parcial está explícito no admin.
5. Presets Brasil funcionam, incluindo CNPJ numérico e alfanumérico.
6. Máscara não substitui validação; servidor rejeita dados adulterados.
7. AJAX não é disparado desnecessariamente e nunca autoriza um valor desatualizado.
8. Condições têm paridade PHP/JS; ocultar campo não deixa required residual nem dado indevido.
9. Arquivos não são públicos; tokens e downloads têm vínculo e autorização testados.
10. Classic e Blocks geram pedidos com o mesmo significado de dados, sem cópias divergentes.
11. HPOS e legado leem/gravam via APIs de pedido; sem dependência direta de postmeta para orders.
12. Pedido, admin, Minha Conta, e-mail e REST respeitam políticas distintas de exposição.
13. Trocar label/tipo/desativar campo não apaga silenciosamente dados históricos.
14. Rascunho, publicação, diff, conflito, restauração e importação são reproduzíveis.
15. Layout customizado é opcional; desativá-lo mantém os campos e o checkout original.
16. Frete, imposto, cupom, gateways, nonce, sessão, mensagens e criação do pedido continuam no fluxo WooCommerce.
17. Não existem campos próprios coletando cartão/CVV nem botão de gateway fictício.
18. Admin e frontend passam os testes visuais/responsivos e as verificações de acessibilidade definidas.
19. CSS/JS não vazam para páginas não relacionadas; bundles e latência são medidos.
20. Novo tipo registrado por plugin externo funciona sem alterar o core, nos adapters declarados.
21. Pacote final tem documentação, matriz de versões, changelog, build, checksum e rollback.
22. Nenhum Checkout Sidebar é incluído na versão 1.0, nem como funcionalidade incompleta.

# 27. Governança, releases e evolução futura

Merges exigem teste e revisão nos módulos afetados. Toda task informa requisito, alteração, risco e evidência. Mudança pública de schema/API segue versionamento e depreciação, não renomeação silenciosa.

Definição de pronto de cada task: implementada; coberta por testes proporcionais ao risco; revisada; documentada; traduzível; sem dados pessoais em logs; capacidades atualizadas; acessibilidade revisada quando visual.

Atualizações não podem apagar campos ou pedidos. Expiração/falha de licença comercial jamais bloqueia a compra ou remove validadores/arquivos de pedidos existentes. Política de distribuição e atualização deve ser definida antes da release; não implementar um serviço SaaS de licenciamento só para o MVP.

Desativar o plugin não significa apagar dados; uninstall explicita o que é removido. Dados de pedido devem permanecer legíveis/recuperáveis com exportação apropriada e política documentada.

**Checkout Sidebar futuro:** abrir projeto próprio somente após V1 estável. Reutilizar core, validação, storage, tokens e componentes possíveis; nova pesquisa de UX, contexto, acessibilidade, checkout expresso e gateways será necessária. Nenhuma estimativa ou compromisso dessa evolução está incluído na V1.

# 28. Referências e rastreabilidade

Os requisitos anexados continuam a fonte do escopo. As verificações externas complementam apenas contratos técnicos e a atualização de CNPJ. Tokens, fases, limites e módulos são decisões propostas deste planejamento.

- **R1** — Requisitos anexados: Markdown(5).md colado
- **R2** — Checkout HTML anexado: index(20260911-132619).html
- **R3** — Plugin ThemeHigh 2.2.0 anexado: woo-checkout-field-editor-pro.2.2.0.zip
- **S1** — ThemeHigh — Overview: https://www.themehigh.com/docs/overview-of-checkout-field-editor/
- **S2** — WooCommerce — Additional checkout fields: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/
- **S3** — WooCommerce — Conditional additional fields: https://developer.woocommerce.com/docs/block-development/tutorials/how-to-conditional-additional-fields/
- **S4** — WooCommerce — HPOS extension recipe book: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- **S5** — WooCommerce — Adding fields and passing values: https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-custom-fields/
- **S6** — WooCommerce — Payment method integration: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
- **S7** — IMask — Guide: https://imask.js.org/guide.html
- **S8** — WordPress — Nonces: https://developer.wordpress.org/apis/security/nonces/
- **S9** — Receita Federal — Primeiro CNPJ alfanumérico, 31/07/2026: https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/julho/receita-federal-gera-o-primeiro-cnpj-em-formato-alfanumerico
- **S10** — W3C — WCAG 2.2: https://www.w3.org/TR/WCAG22/
- **S11** — Receita Federal — Simulador de CNPJ: https://www.gov.br/pt-br/servicos/simulador-cnpj-alfanumerico

Ver `AUDITORIA-REFERENCIA.md` para caminhos e linhas de código observados no ZIP; `MATRIZ-REQUISITOS.md` para associação de cada seção do anexo às fases deste roadmap.

**Limitação desta entrega:** há análise estática, especificação e protótipo visual. Não foi implementado nem homologado o plugin WC CheckoutSuite, não houve integração com um WordPress real e nenhuma compra foi processada.
