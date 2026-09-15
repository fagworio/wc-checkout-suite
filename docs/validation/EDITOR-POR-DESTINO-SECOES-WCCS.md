# Editor por destino — base para as telas do roadmap

**Documento que passou a valer:** `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais/WC-CheckoutSuite-Especificacao-Funcional-UX-Arquitetura-Roadmap.md`
**Pergunta:** o editor deixou de organizar-se por áreas genéricas e passou a falar a língua de cada
destino — e o que falta para as telas das referências visuais?

## 1. O que foi implementado

O editor ganhou um registo de destinos próprio (`resources/admin/app/design/destinations.js`), porque
a mesma coisa tem sete nomes diferentes e o modelo tem um só:

| Destino | O que a interface chama ao grupo de campos | Botão de criação |
|---|---|---|
| Checkout | Seção | Nova seção |
| Minha conta (página do cliente) | Página da conta | Nova página |
| Pedido do cliente | Bloco do pedido | Novo bloco |
| Pedido (admin) | Painel do pedido | Novo painel |
| Perfil do cliente (admin) | Painel do cliente | Novo painel |
| Pedido recebido | Bloco da página | Novo bloco |
| E-mails (cliente e loja) | Bloco do e-mail | Novo bloco |

O registo é também a navegação: os destinos que o lojista usa a toda a hora ficam à vista e os dois
conjuntos (`Admin`, `Mais destinos`) abrem as suas próprias entradas numa segunda linha, em vez de
mostrar todas as áreas numa só fila de checkboxes. A escolha de áreas no formulário de criação
**saiu**: o container pertence ao destino em que o lojista está a trabalhar, e o formulário diz onde
ele vai aparecer. Um documento antigo que ofereça a mesma seção em mais de um destino continua a
carregar-se, e o editor mostra os destinos em vez de os perguntar outra vez.

A criação de campo passou a ser possível em qualquer destino (o que existia só no Checkout e em
Minha Conta), com o destino a decidir onde o valor é recolhido: no Checkout, ou — nas duas
superfícies do cliente — no armazenamento do cliente. Num destino que só mostra valores, o campo é
criado com o Checkout como casa de recolha, para não nascer uma definição que ninguém pode preencher.

Os nomes dos destinos na aba de vínculos passaram a ser os do editor (`Pedido`, `Painel do
cliente`, …) em vez dos rótulos que o servidor escreve para a política de privacidade — a chave
guardada no documento não muda, muda o que o lojista lê. E, seguindo a tabela de nomenclatura do
documento, `Title` → **Nome** e `Location` → **Local**.

## 2. Prova

| Prova | Resultado |
|---|---|
| `tests/js/design/destinations.test.js` (novo, 10 asserções) | passa: cinco entradas de navegação, os dois grupos com os seus membros, o vocabulário por destino, e a frase «Aparece em: …» |
| `tests/js/screens/FieldsScreen.test.js` (3 testes novos) | passa: a barra mantém os destinos de uso frequente, um grupo abre os seus, cada destino fala as suas palavras, o container é criado no destino ativo e um campo criado num destino de exibição nasce recolhido no Checkout |
| `npx jest` | 42 suites, **647 testes**, 0 falhas |
| `npx tsc --noEmit`, `npm run lint:js`, `npm run build` | limpos |
| `composer check` | phpcs e phpstan sem erros; 447 testes PHP |
| `tests/browser/f14-links-observation.mjs` (browser real) | **22/0** — inclui a barra, o submenu do grupo, o formulário sem áreas, o salvamento único e o valor lido de volta |
| Varredura de integração | 67 harnesses, 1541 asserções, 0 falhas |

## 3. Deltas para as referências visuais (o que a próxima fase muda)

O roadmap novo fixa a casca e as telas; este trabalho é a camada por baixo dela. Fica registado o
que diverge, para ser corrigido na ordem de dependências:

1. **Abas planas.** As referências mostram seis abas de destino com ícone (Checkout, Minha conta,
   Pedido do cliente, Pedido (admin), Perfil do cliente (admin), E-mails). O editor agrupa hoje
   `Admin` e `Mais destinos`; a barra passa a plana e a página de agradecimento passa a contexto
   dentro de «Pedido do cliente».
2. **Chave do destino.** O documento fixa **`admin_customer_profile`**; o código usa
   `admin_customer`. Renomear com migração, mantendo `customer_account` (que já coincide).
3. **`bindings[]` múltiplos.** Hoje há um vínculo por destino (`destinations[key]`); o modelo final
   permite vários no mesmo destino, com `visible`, `editable`, `required_override`,
   `label_override`, `description_override`, `permissions` e `conditions`.
4. **Container rico.** `ContainerDefinition` acrescenta `destination`, `presentation`, `enabled`,
   `show_title`, `display_title`, `target` e `settings` ao que hoje é `SectionDefinition`
   (id/título/descrição/posição/local/áreas).
5. **Subnavegações e telas novas.** Perfis de checkout (01), páginas existentes homologadas (02),
   blocos por audiência (06), status personalizados (07) e automação em passos (08) ainda não
   existem; a casca do §4 (Visão geral, Campos e seções, Status e automações, Prévia da loja,
   Pedidos, Clientes, Regras e condições, Diagnóstico, Configurações) também não.

## 4. Nota de mockup

A referência `04-pedido-admin.png` mostra, na subnavegação, perfis de checkout — que pertencem à
tela de Checkout. Pela regra de precedência do documento (§1.3), vale `§9.2`: ali a subnavegação são
**painéis** (`+ Novo painel`).
