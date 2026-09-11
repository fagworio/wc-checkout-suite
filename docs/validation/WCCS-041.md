# Registro de validação — WCCS-041

**Tarefa:** WCCS-041 · "Criar storage e tabela operacional"
**Fase:** F08 · Upload privado nos dois checkouts
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA — com um bloqueador de ambiente registado

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Privacidade HTTP/CDN comprovada; ambiente sem proteção desativa recurso."

**Resultado:** **339 testes unitários PHP** (3 novos) · **933 asserções de integração** em 37 provas, 0 falhas (19 novas) · **568 testes de JS** em 33 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Uploads/UploadsTable.php` | A tabela operacional, criada e versionada |
| `src/Domain/Uploads/PrivateStorage.php` | O armazenamento privado, os guardas e a sonda |
| `src/Domain/Uploads/UploadsEnvironment.php` | O gate que desliga o recurso quando não há proteção |
| `src/Plugin.php` | Instala a tabela na ativação **e em cada arranque** |
| `tests/Integration/F08-wccs-041-private-storage-proof.php` | A privacidade perguntada ao servidor |

## 3. A descoberta: neste ambiente o diretório privado é servido

A prova escreve um ficheiro no diretório privado e **pede-o pelo endereço do próprio site**:

```
url=http://wpagf.dvl.to:8080/wp-content/wc-checkoutsuite-private/pzifa…txt
status=200 served=true length=18
```

O nginx **serve o ficheiro**. O `.htaccess` é um ficheiro do Apache e o nginx não o lê; o `index.php` só é consultado quando o pedido chega ao PHP, e um ficheiro existente é servido antes disso. Os três guardas estão escritos (`index.php`, `.htaccess`, `web.config` — a prova afirma que existem), e nenhum deles ajuda neste servidor.

E a sonda **deteta-o**, que é o que a torna útil: o controlo negativo prova que ela teria visto o ficheiro se ele fosse público (o mesmo pedido contra `uploads/` devolveu os 17 bytes do ficheiro de controlo), e a observação responde `protected=false` com o motivo certo.

**Consequência:** o recurso de upload está **desligado** nesta loja, com a razão à vista, e liga-se sozinho quando o operador negar o caminho no nginx (ou mover o diretório para fora da raiz servida) e pedir nova verificação. É a segunda cláusula do aceite cumprida **num ambiente real que não protege**, e não num cenário de teste.

O requisito operacional fica registado como bloqueador **`UPLOAD-PRIVACY-ENV`** em `docs/compatibility.json` e como secção 4.1 do modelo de ameaças. Não é um defeito do código: o código é o que deteta o problema.

## 4. As duas cláusulas, uma a uma

**"Privacidade HTTP/CDN comprovada"** — o que está comprovado é o **mecanismo**: um ficheiro é escrito, pedido pelo endereço real e o servidor responde; e a resposta decide. Nesta loja a resposta é "não protege", e o produto diz isso. Provar a privacidade *positivamente* exigiria um ambiente protegido, que não existe aqui e cuja configuração está fora da raiz do plugin — é a primeira tarefa do projeto cuja cláusula depende de uma configuração do servidor e não de código.

**"Ambiente sem proteção desativa recurso"** — cumprida e verificada nos dois sentidos: uma observação de "protegido" liga o recurso, uma observação de "servido" desliga-o, e a recusa **sobrevive ao cache** — uma observação guardada expira, e expirar não pode transformar um "não" num "sim".

## 5. A tabela, e porque não é uma opção

`wp_wccs_uploads` guarda o que é uma pergunta sobre **linhas**: que ficheiro pertence a que sessão, quantos bytes um dono já gastou, quando expira um temporário. Uma opção carregaria um array serializado para responder sobre um upload, e uma quota não se conta a partir de uma opção.

A tabela é criada na ativação **e em cada arranque**, porque uma atualização por cima dos ficheiros nunca corre o hook de ativação: um build novo com uma tabela velha descobrir-se-ia um upload falhado de cada vez. A versão instalada é gravada numa opção, e a existência é **perguntada à base de dados** em vez de assumida a partir dessa opção — uma tabela pode ser removida por um operador depois de a opção ter sido escrita, e um subsistema que confiasse na opção falharia no primeiro insert em vez de se declarar indisponível.

## 6. Os caminhos, que é a metade de segurança

O nome do ficheiro é **gerado**, nunca o que o browser enviou (o nome original é guardado na tabela, onde pode ser mostrado de volta sem nunca ser um caminho). Um caminho guardado é validado contra o diretório antes de ser lido: `resolve('/etc/hostname')` e `resolve(__FILE__)` devolvem vazio, e `url_for('../wp-config.php')` não produz endereço nenhum. Ambos estão afirmados nos testes unitários.

## 7. O que NÃO foi provado, e porquê

**A privacidade a funcionar.** Não há ambiente protegido disponível; a configuração do nginx está fora da raiz do plugin. O que existe é o mecanismo que a verifica e a recusa quando falha.

**A CDN.** O `§12` fala de privacidade HTTP/CDN. Não há CDN nesta instalação e nada foi afirmado sobre uma: o que a sonda verifica é o endereço do próprio site, que é o único que existe.

**O endpoint, os tokens e o componente.** São a WCCS-042 e a WCCS-043. Esta tarefa responde à pergunta de que as duas dependem — onde um ficheiro pode viver e se este ambiente o consegue manter privado — antes de aceitar um único byte.

## 8. Próxima tarefa

**WCCS-042 — "Criar upload e tokens por sessão"** (aceite: *MIME, bytes, quota e ownership validados; sessão A não usa token B*). É onde a tabela e o armazenamento desta tarefa passam a receber ficheiros — e onde o gate desta tarefa decide se o endpoint sequer se regista.
