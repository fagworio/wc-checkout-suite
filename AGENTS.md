# WC CheckoutSuite — Regras para Agentes de Desenvolvimento

## 1. Objetivo deste documento

Este documento define como agentes de desenvolvimento devem trabalhar no projeto WC CheckoutSuite.

O objetivo principal é reduzir:

* correções incompletas;
* alterações especulativas;
* retrabalho;
* regressões;
* loops de tentativa e erro;
* mudanças que passam nos testes, mas continuam erradas no navegador;
* alterações fora do escopo;
* inconsistências entre estado, interface e persistência.

Uma tarefa não está concluída apenas porque o código compila ou porque os testes unitários passam.

O comportamento real do usuário é a principal referência para tarefas de interface.

---

# 2. Regra principal

## Nunca implemente primeiro e investigue depois

Para qualquer bug ou comportamento incorreto:

1. reproduza o problema;
2. identifique o estado envolvido;
3. identifique a causa raiz;
4. identifique quais arquivos participam do fluxo;
5. crie ou encontre um teste capaz de reproduzir o problema;
6. somente depois altere o código.

É proibido aplicar correções baseadas apenas em hipótese sem primeiro reproduzir o problema.

---

# 3. Ordem das fontes de verdade

Ao tomar decisões, use esta prioridade:

1. critérios de aceite da tarefa atual;
2. comportamento real reproduzível no navegador;
3. contratos de domínio e schemas;
4. testes de regressão relacionados ao comportamento esperado;
5. documentação funcional atual;
6. roadmap;
7. implementação existente.

A implementação existente não é prova de que o comportamento está correto.

Um teste existente também pode estar incompleto.

Nunca altere um teste simplesmente para fazer a implementação atual passar.

---

# 4. Princípio de escopo

Cada tarefa deve resolver um problema claramente delimitado.

Não aproveite uma correção para:

* redesenhar componentes não relacionados;
* renomear arquivos desnecessariamente;
* alterar comportamento de outras telas;
* fazer refatorações amplas;
* mudar schemas sem necessidade;
* criar abstrações especulativas;
* adicionar funcionalidades não solicitadas.

Prefira sempre o menor patch capaz de resolver a causa raiz.

---

# 5. Processo obrigatório para bugs

Antes de editar código, registre:

## Problema observado

Descreva exatamente o que ocorre.

Exemplo:

> Ao criar um campo dentro de Minha conta > Downloads, o campo aparece inicialmente na seção correta, mas depois de salvar e recarregar é associado a outra seção.

## Comportamento esperado

Descreva um resultado observável.

Exemplo:

> O campo criado em Downloads deve continuar associado à mesma seção depois de salvar, recarregar e navegar para outra aba.

## Passos de reprodução

Exemplo:

1. abrir Campos;
2. selecionar Minha conta;
3. selecionar Downloads;
4. criar um campo;
5. salvar;
6. recarregar a página;
7. verificar em qual seção o campo aparece.

## Estado envolvido

Identifique obrigatoriamente:

* `document`;
* `savedDocument`;
* `composition`;
* `profile`;
* `area`;
* `context`;
* `section`;
* `field`;
* `local draft`, quando aplicável.

## Causa raiz

Antes da implementação, explique qual parte do fluxo está errada.

Não escreva apenas:

> problema de sincronização.

Explique:

> o ID da seção selecionada é calculado usando o documento anterior enquanto a criação já retornou um novo documento, fazendo um effect selecionar novamente a primeira seção.

---

# 6. Regras específicas do Fields Editor

O editor de campos possui múltiplas fontes de estado.

Nunca trate os seguintes conceitos como equivalentes.

## `document`

Documento principal em edição.

## `savedDocument`

Último documento confirmado pelo servidor.

## `composition`

Documento efetivamente apresentado no contexto atualmente selecionado.

Pode representar uma composição diferente de `document`.

## `profile`

Checkout personalizado selecionado.

## `area`

Destino onde o campo será utilizado.

Exemplos:

* checkout;
* conta do cliente;
* pedido do cliente;
* pedido no admin;
* perfil do cliente;
* email.

## `context`

Subárea dentro de um destino.

Exemplo:

Dentro de Minha conta:

* Painel;
* Pedidos;
* Downloads;
* Endereços;
* Detalhes da conta;
* abas personalizadas.

## `section`

Container lógico onde campos são organizados.

## `field`

Definição do campo.

---

# 7. Regra crítica sobre document e composition

Nunca assuma que:

```text
document.sections === composition.sections
```

Essas estruturas podem representar contextos diferentes.

Antes de utilizar uma seção:

1. identifique qual composição está sendo editada;
2. identifique quem é o proprietário daquela seção;
3. execute a operação nesse documento;
4. escreva o resultado de volta no proprietário correto.

Nunca selecione uma seção usando o documento errado.

---

# 8. IDs retornados pelas operações

Quando uma operação de domínio cria:

* campo;
* seção;
* profile;
* binding;
* menu;
* qualquer outra entidade;

prefira utilizar o identificador retornado pela própria operação.

Não tente reconstruir o ID usando o estado anterior.

Errado:

```js
const id = uniqueSectionId(previousDocument, title);
```

quando a operação já criou a seção.

Preferível:

```js
const result = createSection(...);

const createdSection = result.createdSection;
```

ou outro valor diretamente retornado pela operação.

Se a API atual não retorna explicitamente a entidade criada, considere melhorar o contrato da operação.

---

# 9. Evitar estado duplicado

Não armazene em `useState` algo que pode ser calculado com segurança a partir do documento atual.

Exemplo perigoso:

```js
const [editingField, setEditingField] = useState(field);
```

Isso pode manter uma cópia antiga.

Prefira:

```js
const [editingFieldId, setEditingFieldId] = useState(null);

const editingField = useMemo(
    () => findField(document, editingFieldId),
    [document, editingFieldId]
);
```

O estado deve guardar identidade, não uma cópia da entidade.

---

# 10. Estado derivado

Sempre que possível:

```text
estado real
    ↓
estado derivado
    ↓
interface
```

Evite:

```text
estado A
estado B
estado C

todos representando praticamente a mesma informação
```

Quanto mais fontes de verdade existirem, maior o risco de inconsistência.

---

# 11. Regra para tentativa de correção

O agente pode realizar no máximo duas hipóteses de implementação para o mesmo bug.

Se duas tentativas falharem:

## Pare de editar código.

Não faça uma terceira tentativa especulativa.

Produza um relatório contendo:

### Sintoma original

O problema inicial.

### Evidências coletadas

Logs, estado, screenshot, resultado de teste ou comportamento observado.

### Hipótese 1

Causa considerada inicialmente.

### Resultado da tentativa 1

Por que não resolveu.

### Hipótese 2

Segunda causa considerada.

### Resultado da tentativa 2

Por que não resolveu.

### Estado atual

Descreva o fluxo real encontrado.

### Hipóteses restantes

Liste possibilidades que ainda precisam ser investigadas.

Depois reinicie o diagnóstico.

---

# 12. Teste antes da correção

Sempre que possível, um bug deve produzir um teste falhando antes da implementação.

Fluxo esperado:

```text
bug reproduzido
      ↓
teste falha
      ↓
correção
      ↓
teste passa
```

Evite:

```text
correção
      ↓
teste criado para confirmar a implementação
```

O primeiro modelo protege contra regressão.

O segundo pode apenas validar o código que acabou de ser escrito.

---

# 13. Testes da interface

Testes unitários e DOM tests não são suficientes para considerar uma alteração visual ou funcional concluída.

Os seguintes comandos podem passar:

```text
Jest
TypeScript
ESLint
build
```

e a interface ainda estar incorreta.

Por isso, tarefas de Fields devem incluir teste E2E quando alterarem comportamento observável pelo usuário.

---

# 14. Matriz obrigatória de teste — Minha Conta

Mudanças relacionadas aos campos da conta devem ser verificadas em:

* Painel;
* Pedidos;
* Downloads;
* Endereços;
* Detalhes da conta;
* abas personalizadas.

Para cada contexto afetado, verificar:

```text
abrir
→ criar
→ configurar
→ salvar
→ recarregar
→ verificar
→ editar
→ salvar
→ recarregar
→ verificar novamente
```

Quando aplicável:

```text
desativar
→ salvar
→ recarregar
→ verificar
→ reativar
→ salvar
→ recarregar
```

E:

```text
remover
→ salvar
→ recarregar
→ confirmar remoção
```

---

# 15. Abas personalizadas da Minha Conta

Abas criadas pelo usuário possuem comportamento diferente das páginas nativas do WooCommerce.

Testar obrigatoriamente:

```text
criar aba
→ salvar
→ recarregar
→ aba existe no menu
```

Depois:

```text
adicionar campo
→ salvar
→ recarregar
→ campo continua na aba correta
```

Depois:

```text
inativar aba
→ salvar
→ recarregar
→ aba deixa de ser apresentada ao cliente
```

Depois:

```text
reativar aba
→ salvar
→ recarregar
→ aba volta ao menu
```

Finalmente:

```text
excluir aba
→ confirmar
→ salvar
→ recarregar
→ aba deixa de existir
```

A exclusão deve remover apenas estruturas personalizadas pertencentes ao plugin.

---

# 16. Proteção das abas nativas do WooCommerce

Páginas nativas não devem ser tratadas como páginas criadas pelo usuário.

Exemplos:

* dashboard;
* orders;
* downloads;
* edit-address;
* edit-account.

Uma ação de remoção de aba personalizada nunca pode apagar um endpoint nativo do WooCommerce.

Quando uma ação não é permitida, a interface deve:

1. desabilitar ou remover a ação;
2. explicar o motivo ao usuário;
3. proteger também no domínio/backend.

Uma restrição apenas visual não é suficiente.

---

# 17. Campos nativos do WooCommerce

Campos nativos não devem ser destruídos quando o usuário deseja escondê-los.

Quando aplicável:

```text
remover da interface
```

deve significar:

```text
desabilitar / não mostrar
```

e não:

```text
apagar definição nativa do WooCommerce
```

A interface precisa diferenciar claramente:

* campo personalizado;
* campo nativo;
* campo adotado pelo plugin;
* campo desativado;
* campo arquivado.

---

# 18. Persistência

Nenhuma tarefa relacionada a editor pode ser considerada concluída sem teste de reload.

Um estado que funciona apenas antes de recarregar a página não está persistido corretamente.

Fluxo mínimo:

```text
alterar
→ salvar
→ confirmar resposta do servidor
→ reload
→ verificar estado novamente
```

---

# 19. Navegação

Também verifique navegação entre contextos.

Exemplo:

```text
criar campo em Downloads
→ salvar
→ abrir Pedidos
→ voltar para Downloads
→ campo continua correto
```

Mudanças de contexto não devem sobrescrever:

* section;
* area;
* context;
* profile;
* field selecionado;

de forma indevida.

---

# 20. Session Storage e drafts locais

O `sessionStorage` é apenas uma camada de recuperação de edição local.

Nunca deve substituir silenciosamente um documento diferente recebido do servidor.

Ao restaurar um draft local, valide pelo menos:

* revision;
* identidade/fingerprint do documento base;
* compatibilidade do schema.

Caso contrário, descarte o draft local.

---

# 21. Arquitetura desejada do Fields Editor

O objetivo arquitetural é aproximar a aplicação de:

```text
FieldsScreen
    |
    +-- useFieldsDocument()
    |
    +-- useFieldsNavigation()
    |
    +-- useSectionEditor()
    |
    +-- useFieldEditor()
    |
    +-- FieldManagerView
```

## `useFieldsDocument`

Responsável por:

* carregar;
* salvar;
* dirty state;
* revisões;
* drafts locais;
* conflito de versão.

## `useFieldsNavigation`

Responsável por:

* area;
* context;
* profile;
* section;
* parâmetros de URL.

## `useSectionEditor`

Responsável por:

* criar seção;
* editar;
* remover;
* ordenar;
* ativar;
* desativar.

## `useFieldEditor`

Responsável por:

* criar campo;
* editar;
* duplicar;
* remover;
* ativar;
* desativar;
* mover;
* ordenar.

Não é obrigatório executar toda essa refatoração durante uma correção.

Ela deve ser feita progressivamente quando ajudar a reduzir risco.

---

# 22. FieldManagerModel deve ser tipado

Evite contratos do tipo:

```js
model: any
```

O modelo entregue ao `FieldManagerView` deve possuir contrato explícito.

Exemplo:

```ts
export interface FieldManagerModel {
    document: SchemaDocument;
    composition: SchemaDocument;

    area: DestinationId;
    context: ContextId;
    section: string | null;

    editingField: FieldDefinition | null;

    dirty: boolean;
    saving: boolean;

    onSectionChange(sectionId: string): void;

    onChangeField(
        changes: Partial<FieldDefinition>
    ): void;

    onRemoveField(fieldId: string): void;

    onDuplicateField(fieldId: string): void;

    onSave(): Promise<void>;
}
```

O objetivo é fazer o TypeScript ajudar a detectar ligações incorretas entre estado e interface.

---

# 23. Teste visual

Mudanças visuais importantes devem possuir evidência visual.

Não basta gerar screenshot.

Quando possível, use baseline visual e comparação de screenshot.

Estados recomendados:

* tela principal do Fields;
* checkout;
* Minha conta;
* seção selecionada;
* campo selecionado;
* inspector;
* criação de campo;
* criação de seção;
* aba personalizada;
* menu personalizado;
* viewport desktop;
* viewport reduzido.

Alteração visual deliberada deve atualizar baseline conscientemente.

Não atualize snapshots apenas porque falharam.

Primeiro confirme se a alteração é desejada.

---

# 24. Browser console

Antes de concluir tarefas de frontend, verificar:

* `pageerror`;
* erros de JavaScript;
* promises rejeitadas;
* erros de API relevantes;
* warnings novos relacionados ao código modificado.

Uma interface aparentemente correta, mas gerando erro de JavaScript, não está concluída.

---

# 25. Definition of Done para Fields

Uma tarefa do Fields Editor só pode ser marcada como concluída se todos os itens aplicáveis abaixo forem atendidos.

## Diagnóstico

* bug foi reproduzido;
* causa raiz foi identificada;
* arquivos envolvidos foram identificados.

## Teste

* existe teste de regressão;
* teste falhava antes da correção;
* teste passa depois da correção.

## Comportamento

* fluxo real funciona;
* salvar funciona;
* reload preserva resultado;
* navegar para outro contexto e voltar funciona;
* estados relacionados permanecem corretos.

## Qualidade

* Jest passa;
* TypeScript passa;
* lint passa;
* build passa.

## Interface

Quando aplicável:

* E2E passa;
* screenshot foi verificada;
* console não possui novos erros.

---

# 26. Não declarar sucesso prematuramente

Nunca finalize uma tarefa dizendo apenas:

```text
Jest passou.
Build passou.
Lint passou.
```

Isso não prova que o problema foi resolvido.

O relatório deve responder:

```text
O bug original ainda pode ser reproduzido?
```

Se a resposta não foi verificada, a tarefa não está validada.

---

# 27. Formato obrigatório do relatório final

Toda implementação deve terminar com este formato.

## Root cause

Explique a causa real.

## Reproduction

Explique como o problema era reproduzido.

## Fix

Explique a alteração realizada.

## Changed files

Liste arquivos alterados e o motivo.

## Regression protection

Informe qual teste protege contra o retorno do bug.

## E2E validation

Informe o fluxo real executado.

Exemplo:

```text
Minha conta
→ Downloads
→ criar campo
→ salvar
→ reload
→ editar
→ salvar
→ reload
→ navegar para Pedidos
→ voltar para Downloads
→ campo permaneceu correto
```

## Automated validation

Informe resultados de:

```text
Jest
TypeScript
ESLint
build
E2E
```

## Visual validation

Quando aplicável:

```text
Screenshot verificada.
Nenhuma regressão visual encontrada.
```

## Remaining risks

Informe explicitamente qualquer coisa que não pôde ser validada.

Nunca esconda uma validação não executada.

---

# 28. Trabalho em tarefas grandes

Não implemente uma grande especificação inteira de uma vez.

Divida por comportamento observável.

Exemplo incorreto:

```text
Corrigir todo o sistema de Minha Conta.
```

Preferível:

```text
Tarefa 1:
Persistência de campos em Downloads.

Tarefa 2:
Persistência de campos em Pedidos.

Tarefa 3:
Desativação de campos.

Tarefa 4:
Criação de aba personalizada.

Tarefa 5:
Inativação da aba personalizada.

Tarefa 6:
Exclusão da aba personalizada.
```

Cada tarefa deve possuir seus próprios critérios de aceite.

---

# 29. Fase de estabilização

Antes de adicionar novas funcionalidades importantes ao Fields Editor, priorize estabilização.

Criar E2E determinístico para:

## Minha Conta

* Painel;
* Pedidos;
* Downloads;
* Endereços;
* Detalhes da conta;
* abas personalizadas.

## Operações

* criar campo;
* editar;
* salvar;
* reload;
* mover;
* ordenar;
* duplicar;
* desativar;
* reativar;
* remover.

## Seções

* criar;
* editar;
* salvar;
* recarregar;
* ordenar;
* desativar quando aplicável;
* remover.

## Abas personalizadas

* criar;
* salvar;
* reload;
* adicionar campos;
* inativar;
* reativar;
* excluir.

---

# 30. Isolamento dos testes

Cada teste E2E deve iniciar com estado conhecido.

Um teste não deve depender dos dados deixados pelo teste anterior.

Preferencialmente:

```text
setup
→ executar cenário
→ verificar
→ cleanup
```

ou utilizar fixtures determinísticas.

Evite testes que funcionam apenas quando executados em determinada ordem.

---

# 31. Estado do banco e servidor

Quando um teste falhar, descubra em qual camada o erro ocorreu.

Pergunte:

```text
O estado correto existe no React?
```

Depois:

```text
O payload enviado para API está correto?
```

Depois:

```text
O backend salvou corretamente?
```

Depois:

```text
O GET após reload retorna corretamente?
```

Depois:

```text
A interface reconstruiu corretamente o estado recebido?
```

Não tente corrigir frontend quando o erro real está no servidor.

Não tente alterar backend quando o erro é apenas de seleção de estado no React.

---

# 32. Diagnóstico de persistência

Para bugs relacionados a save/reload, valide separadamente:

```text
UI state
↓
request payload
↓
API response
↓
persisted document
↓
reload request
↓
loaded document
↓
derived composition
↓
rendered UI
```

Identifique exatamente onde o valor deixa de ser correto.

---

# 33. Evitar alterações direcionadas apenas ao teste

Não adicione:

* classes;
* IDs;
* texto;
* lógica;
* atributos;
* branches;

apenas para satisfazer um teste, quando isso não representa comportamento real necessário.

Os testes devem observar o sistema.

O sistema não deve ser moldado artificialmente para agradar o teste.

---

# 34. Comentários de código

Comentários devem explicar decisões importantes.

Bom:

```js
// The account destination is not the section location.
// Account sections always use the account location even when
// they are rendered inside a specific WooCommerce endpoint.
```

Ruim:

```js
// Set location.
```

Comentários devem explicar o "porquê", não repetir o código.

---

# 35. Não mascarar inconsistências

Não utilize fallback silencioso para esconder estado inválido.

Exemplo perigoso:

```js
section || firstSection
```

Quando `section` deveria obrigatoriamente existir.

Se o estado é inválido:

* detecte;
* registre;
* trate explicitamente;
* corrija sua origem.

Fallback é aceitável apenas quando faz parte do comportamento esperado.

---

# 36. Não inventar APIs

Antes de usar:

```text
result.field
result.created
result.section
result.id
```

verifique o contrato real da operação.

Não assuma que uma API retorna determinada propriedade.

Leia:

* implementação;
* tipo;
* testes;
* callers.

Caso o contrato seja inadequado, altere conscientemente e ajuste os consumidores.

---

# 37. Critério de conclusão

A pergunta final de qualquer tarefa é:

> O fluxo que estava quebrado agora funciona completamente para o usuário, inclusive depois de salvar e recarregar?

Se isso não foi demonstrado, a tarefa ainda não está concluída.

---

# 38. Prioridades atuais de estabilização

Para o estado atual do projeto, seguir esta ordem:

1. criar e manter estas regras no `AGENTS.md`;
2. estabilizar os testes E2E do Fields Editor;
3. cobrir todos os contextos da Minha Conta;
4. cobrir abas personalizadas;
5. garantir save + reload;
6. garantir navegação entre contextos;
7. adicionar regressão visual;
8. tipar o contrato do `FieldManagerView`;
9. reduzir responsabilidades de `FieldsScreen`;
10. somente depois continuar grandes expansões funcionais.

---

# 39. Regra final

Qualidade da correção é mais importante que velocidade.

Não faça várias alterações até alguma funcionar.

Faça:

```text
reproduzir
→ entender
→ provar
→ corrigir
→ validar
```

Nunca:

```text
tentar
→ testar
→ tentar outra coisa
→ alterar teste
→ tentar novamente
→ declarar sucesso
```

