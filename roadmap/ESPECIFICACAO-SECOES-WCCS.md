# WC CheckoutSuite — Especificação de Seções, Páginas e Destinos

## 1. Objetivo

Simplificar a criação e reutilização de campos customizados no WC CheckoutSuite.

A interface deve responder apenas a quatro perguntas:

1. Onde quero usar estes campos?
2. Como quero apresentar esse grupo de campos nesse local?
3. Quais campos fazem parte dele?
4. O título deve aparecer para o cliente/equipe ou servir apenas para organização no admin?

Fluxo principal:

**Escolher destino → criar container → adicionar/vincular campos → salvar alterações**

---

## 2. Conceito de interface

Internamente o plugin pode manter um modelo comum de `SectionDefinition`, mas a interface deve usar nomes diferentes conforme o contexto.

| Destino | Nome usado na interface |
|---|---|
| Checkout | **Seção** |
| Minha Conta | **Página da conta** |
| Pedido do cliente | **Bloco do pedido** |
| Pedido administrativo | **Painel do pedido** |
| Perfil do cliente no admin | **Painel do cliente** |
| E-mail | **Bloco do e-mail** |

A regra é: o usuário não precisa entender `areas`, `destinations`, `customer_profile` ou outros identificadores técnicos.

---

## 3. Organização do editor

Tela principal:

```text
Campos e seções

[ Checkout ] [ Minha Conta ] [ Pedido do cliente ] [ Admin ] [ Mais destinos ]
```

Dentro de `Admin`:

```text
Admin
├── Pedido
└── Perfil do cliente
```

Dentro de `Mais destinos`:

```text
Mais destinos
├── Pedido recebido
├── E-mails do cliente
└── E-mails da loja
```

Não mostrar todos esses destinos como checkboxes dentro de um único modal.

---

## 4. Checkout

O Checkout continua sendo o local principal para campos preenchidos durante a compra.

### Criar seção

```text
Nova seção do checkout

Nome
[ Documentação da compra ]

Posição
[ Informações do pedido ▼ ]

Exibir título no checkout
[ OFF ]

Descrição
[ opcional ]

[ Cancelar ] [ Adicionar seção ]
```

### Regra

- `Nome` é obrigatório para organização no admin.
- **Exibir título no checkout** vem desativado por padrão.
- Desativar o título não esconde os campos.
- O nome continua aparecendo no editor.
- O título deve ser metadado da seção, não um campo `heading` falso.

Exemplo com título oculto:

```text
CPF
Registro
Autorização
```

Exemplo com título visível:

```text
Documentação da compra

CPF
Registro
Autorização
```

---

## 5. Minha Conta

Minha Conta precisa ter dois modos:

1. **Criar uma nova página no menu**
2. **Usar uma página existente**

### 5.1 Nova página

```text
Nova página em Minha Conta

Nome no menu
[ Dados extras ]

Ícone
[ Usuário ▼ ]

Posição
[ Depois de Detalhes da conta ▼ ]

Exibir título dentro da página
[ ON ]

Título exibido
[ Dados extras ]

Opções avançadas
Slug
[ dados-extras ]

[ Cancelar ] [ Criar página ]
```

Resultado:

```text
Minha Conta
├── Painel
├── Pedidos
├── Downloads
├── Endereços
├── Detalhes da conta
├── Dados extras
└── Sair
```

Ao abrir:

```text
Dados extras

Email
[                     ]

Arquivo
[ Selecionar arquivo ]

[ Salvar informações ]
```

### 5.2 Página existente

Exemplo:

```text
Adicionar grupo de campos

Página
[ Detalhes da conta ▼ ]

Nome do grupo
[ Dados profissionais ]

Exibir título
[ ON ]
```

Resultado:

```text
Detalhes da conta

Nome
Sobrenome
Email
Senha

Dados profissionais
-------------------
Registro profissional
Empresa
Cargo
```

---

## 6. Pedido do cliente

Destino:

**Minha Conta → Pedidos → Ver pedido**

Não deve ser confundido com uma nova página principal em Minha Conta.

Interface:

```text
Pedido do cliente

[ Informações enviadas ] [ Documentos enviados ] [ + Novo bloco ]
```

Criação:

```text
Novo bloco no pedido

Nome
[ Documentos enviados ]

Exibir título
[ ON ]

Posição
[ Após detalhes do pedido ▼ ]

[ Cancelar ] [ Criar bloco ]
```

Aqui a ação principal tende a ser **Usar campo existente**, porque os valores normalmente já foram coletados anteriormente.

---

## 7. Pedido administrativo

Destino:

**WooCommerce → Pedidos → Editar pedido**

Interface:

```text
Admin → Pedido

[ Informações adicionais ] [ Documentação para análise ] [ + Novo painel ]
```

Criação:

```text
Novo painel do pedido

Nome
[ Documentação para análise ]

Exibir título
[ ON ]

Posição
[ Dados do pedido ▼ ]

[ Cancelar ] [ Criar painel ]
```

Aprovação manual é outro recurso. Exibir um campo no painel não ativa aprovação automaticamente.

---

## 8. Perfil do cliente no admin

É diferente de Minha Conta.

Destino:

**Admin → Cliente/Usuário**

Exemplo:

```text
Dados profissionais
-------------------
Registro profissional
Empresa
Documento
```

Evitar usar apenas o nome ambíguo `Customer Profile`.

---

## 9. Adicionar campo x usar campo existente

Dentro de qualquer container editável:

### Adicionar novo campo

```text
+ Adicionar novo campo
```

Cria uma nova definição e já a vincula ao container atual.

### Usar campo existente

```text
Usar campo existente
```

Reutiliza um campo já definido.

Exemplo:

```text
Campo: Registro profissional

Usado em:
✓ Minha Conta → Dados profissionais
✓ Checkout → Informações profissionais
✓ Admin → Pedido → Dados da compra
```

Não criar três definições independentes.

---

## 10. Modelo conceitual

### Field

Define o dado:

```text
id
type
label
description
mask
validators
type_settings
```

### Container / Section

Define onde e como o grupo aparece:

```text
id
name
destination
presentation
position
show_title
display_title
icon
enabled
```

### Presentation

Valores possíveis:

```text
checkout_group
my_account_endpoint
my_account_existing_page
customer_order_block
admin_order_panel
admin_customer_panel
email_block
```

### Binding

Liga um campo ao container:

```text
id
field_id
container_id
position
visible
editable
required_override
label_override
permissions
```

Isso permite o mesmo campo ser:

- editável em Minha Conta;
- obrigatório no Checkout;
- somente leitura no pedido;
- oculto em e-mails.

---

## 11. Persistência: cliente x pedido

Campos preenchidos em Minha Conta pertencem ao cliente.

Exemplo:

```text
Registro profissional = ABC123
```

Quando o Checkout usar esse campo, pode preencher inicialmente com esse valor, se configurado.

Ao criar um pedido, o pedido guarda um snapshot:

```text
Order #1234
Registro profissional = ABC123
```

Se o cliente alterar o perfil depois:

```text
Perfil atual = XYZ999
```

o pedido antigo continua com:

```text
Order #1234 = ABC123
```

---

## 12. Upload em Minha Conta

Upload em uma página da conta precisa funcionar sem pedido.

Fluxo:

```text
Selecionar arquivo
→ upload privado
→ validar
→ salvar formulário
→ vincular definitivamente ao cliente
```

Se esse documento for usado em uma compra, o pedido deve registrar a referência/versão usada naquela compra.

Trocar o arquivo do perfil futuramente não altera pedidos históricos.

---

## 13. Ícones

Oferecer uma biblioteca controlada.

Exemplos:

```text
user
document
folder
building
badge
shield
check
```

Salvar apenas a chave. Não aceitar HTML ou SVG arbitrário.

---

## 14. Nomenclatura nova

| Atual | Novo |
|---|---|
| Add a section | Nova seção / Nova página / Novo painel |
| Title | Nome |
| Location | Local / Posição |
| Areas | Remover do modal |
| Customer Profile | Separar em Minha Conta e Perfil do cliente no admin |
| Order screen, for staff | Admin → Pedido |
| Order screen, for customer | Minha Conta → Pedido |
| Order received page | Página de pedido recebido |

---

## 15. Botão de criação por destino

| Tela | Botão |
|---|---|
| Checkout | **+ Nova seção** |
| Minha Conta | **+ Nova página** |
| Página existente da conta | **+ Nova seção** |
| Pedido do cliente | **+ Novo bloco** |
| Pedido administrativo | **+ Novo painel** |
| Perfil administrativo | **+ Novo painel** |
| E-mail | **+ Novo bloco** |

Internamente todos podem compartilhar a mesma infraestrutura.

---

## 16. Salvamento

Ação principal:

**Salvar alterações**

Não usar:

- Salvar rascunho;
- Revisar publicação;
- Publicar alterações.

Depois do sucesso:

```text
Alterações salvas com sucesso.
```

Com link contextual:

- Ver checkout;
- Abrir em Minha Conta;
- Abrir destino configurado.

---

## 17. Exclusão

Excluir o container não pode apagar dados históricos.

### Container vazio

```text
Excluir seção?
[ Cancelar ] [ Excluir ]
```

### Container com campos

Oferecer:

```text
Mover campos para:
[ Outra seção ▼ ]

ou

[ Remover estes campos deste local ]
```

### Campo reutilizado

Excluir o container remove apenas o vínculo. O campo continua existindo nos outros destinos.

Nunca apagar automaticamente:

- valores de pedidos;
- uploads usados em pedidos;
- snapshots;
- decisões de aprovação.

---

## 18. Mudanças necessárias no admin

Arquivos principais:

```text
resources/admin/app/FieldsScreen.js
resources/admin/app/views/FieldManagerView.js
resources/admin/app/schema/fieldOperations.ts
resources/admin/app/design/sectionMeta.js
```

Mudanças:

1. remover o modal genérico com múltiplos `Areas`;
2. criar registro de destinos;
3. adaptar o formulário conforme o destino;
4. permitir criar campos fora do Checkout quando o destino for editável;
5. suportar múltiplos vínculos do mesmo campo;
6. adicionar `show_title`;
7. adicionar `display_title`;
8. adicionar `icon`;
9. separar Minha Conta de Admin → Perfil do cliente;
10. usar nomenclatura contextual: seção, página, painel e bloco.

---

## 19. Mudanças necessárias no PHP

Evoluir:

```text
src/Domain/Sections/SectionDefinition.php
```

ou criar um modelo equivalente de container com:

```text
destination
presentation
show_title
display_title
icon
target
```

Para Minha Conta, criar integração própria para:

- registrar endpoints;
- inserir item no menu;
- renderizar formulário;
- persistir dados do cliente;
- validar campos;
- processar uploads.

Responsabilidades sugeridas:

```text
MyAccountSections
AccountSectionForm
CustomerFieldsService
AccountSectionController
```

Não colocar essas responsabilidades dentro de `CustomerOrderFields`.

---

## 20. Migração

Não apagar nem recriar IDs existentes.

Configurações antigas de `customer_profile` são ambíguas e não devem virar automaticamente uma página pública em Minha Conta.

Quando necessário:

```text
Esta configuração antiga usa "Customer Profile".

Escolha o destino correto:

○ Minha Conta
○ Admin → Perfil do cliente
```

---

## 21. Critérios de aceite

### Checkout

- criar seção;
- adicionar campo;
- salvar;
- campo aparece no local configurado;
- `show_title = false`: título não aparece;
- `show_title = true`: título aparece;
- campo continua funcionando nos dois casos.

### Minha Conta

- criar `Dados extras`;
- escolher ícone;
- salvar;
- nova entrada aparece no menu;
- cliente sem pedidos consegue acessar;
- formulário aparece vazio na primeira utilização;
- salvar valor;
- recarregar;
- valor permanece;
- outro cliente não lê os dados.

### Reutilização

- criar campo no perfil;
- vinculá-lo ao Checkout;
- nenhuma segunda definição é criada;
- valor pode preencher o Checkout quando configurado;
- pedido armazena snapshot;
- alteração do perfil não muda pedido antigo.

### Upload

- enviar arquivo em Minha Conta;
- arquivo permanece acessível em nova sessão;
- outro cliente não acessa;
- arquivo não depende de pedido;
- pedido que utilizar o documento registra sua própria referência histórica.

### Exclusão

- remover uma página não destrói campo usado em outro destino;
- remover vínculo não remove a definição;
- dados históricos permanecem.

---

## 22. Regra principal do produto

> O lojista escolhe onde quer criar um espaço para campos. O WC CheckoutSuite adapta esse espaço ao contexto: seção no Checkout, página em Minha Conta, painel no pedido ou bloco em outra superfície. Depois ele adiciona ou reutiliza campos e salva uma única vez.
