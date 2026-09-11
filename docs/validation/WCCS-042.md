# Registro de validação — WCCS-042

**Tarefa:** WCCS-042 · "Criar upload e tokens por sessão"
**Fase:** F08 · Upload privado nos dois checkouts
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "MIME, bytes, quota e ownership validados; sessão A não usa token B."

**Resultado:** **347 testes unitários PHP** (8 novos) · **952 asserções de integração** em 38 provas, 0 falhas (18 novas) · **568 testes de JS** em 33 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Uploads/UploadRules.php` | As regras: tipo detectado, bytes, quota |
| `src/Domain/Uploads/UploadRepository.php` | A tabela, sempre por token **e** por dono |
| `src/Domain/Uploads/UploadService.php` | Aceitar, ler e remover — com o dono derivado no servidor |
| `src/Http/Checkout/UploadController.php` | A rota: aceitar, perguntar, remover |
| `tests/Integration/F08-wccs-042-upload-tokens-proof.php` | As quatro palavras do aceite |

## 3. O gate vem primeiro, e neste ambiente ele manda

A primeira asserção da prova é o que esta loja faz **hoje**:

```
code=not_available  message=A file placed in the private directory was served over HTTP, so this environment does not protect it.
```

O upload é recusado **antes de o ficheiro ser lido**: uma loja cujo diretório é público não deve gastar um byte a descobrir se o ficheiro era aceitável, e uma recusa que nomeia o ambiente é mais útil do que uma que nomeia o ficheiro. É a WCCS-041 a decidir, como foi desenhado.

Para exercitar o resto, a prova **injeta a observação** de "protegido" e **diz que o faz** no seu próprio output: está a provar as regras, não a afirmar que este ambiente protege algo. Tudo o que escreve é apagado, e o harness afirma que a tabela ficou como estava.

## 4. MIME, bytes e quota

**A declaração não é evidência.** Um ficheiro chamado `document.txt` com `type: text/plain` no pedido e conteúdo de shell script é **recusado** — o tipo vem do `finfo` sobre os bytes, não do browser nem da extensão. É o caso que a prova exercita, com a deteção à vista: `text/x-shellscript`.

**A quota é do dono, não do ficheiro.** Um limite por ficheiro trava um upload grande e não faz nada contra mil pequenos, que é a forma que o abuso de armazenamento realmente toma. A prova enche a quota com uma **linha** (a quota é uma soma sobre o que o dono guardou, e uma loja que contasse só o que cabe em memória estaria a contar outra coisa) e verifica a recusa e o limite exato.

**A ordem das verificações** põe a pergunta mais barata primeiro: um ficheiro grande e de tipo recusado é reportado como grande, que é a resposta sobre a qual o cliente consegue agir.

## 5. Ownership: o dono é uma sessão, derivado no servidor

O identificador de dono é **gerado por sessão** e guardado na sessão; o browser nunca o vê e não o pode escolher. Deliberadamente **não** é o id do cliente: um visitante não tem id, e um cliente autenticado pode finalizar de dois dispositivos — um identificador que dois checkouts partilham é um identificador que deixa um deles ler os documentos do outro.

A prova demonstra-o pela única via que o demonstra: **perguntar como outra pessoa**.

- a sessão que submeteu lê o token de volta;
- outra sessão recebe `not_yours` **e nenhum registo**;
- um token que não existe recebe `unknown_token` — os dois são respostas diferentes de propósito, porque colapsá-las esconderia uma tentativa de sondagem dentro de um erro comum;
- outra sessão também não o consegue remover, e o ficheiro continua lá depois da tentativa.

O repositório torna isto uma propriedade da **consulta** e não de uma verificação que alguém tem de se lembrar de escrever: os métodos que respondem "de quem é isto" recebem sempre token **e** dono.

## 6. A rota

Uma rota, três verbos, uma forma de resposta fixa (`status`, `code`, `message`, `token`), para o cliente ter uma coisa só para interpretar e para o endpoint não poder responder com o conteúdo de um ficheiro. A prova afirma isso diretamente: a resposta de leitura **não contém** os bytes do documento, e é marcada `no-store`.

O nonce é verificado como no endpoint de validação, e pela mesma razão: é uma rota pública que aceita um documento de um browser. É um guarda de CSRF e não uma autorização — a autorização é o dono, que a sessão fornece e o browser nunca vê.

**Uma separação que a prova obrigou a fazer:** `is_uploaded_file()` esteve no serviço e tornava-o inutilizável fora de um pedido real (oito asserções falharam com `empty_file` contra ficheiros que o próprio harness tinha escrito). Passou para o **controlador**, que é a fronteira e o único sítio que tem um pedido de verdade: o serviço verifica *o que o ficheiro é*, a fronteira verifica *que ele foi mesmo enviado*. Não é um enfraquecimento — um caminho que não veio de um upload não chega ao serviço pela rota — e é o que permite exercitar as regras sem browser.

## 7. O que NÃO foi provado, e porquê

**Um upload a partir de um browser.** O ficheiro é submetido por `$_FILES` real na prova? Não: o harness usa um ficheiro temporário com a forma de uma entrada de `$_FILES` através do serviço, e a rota é exercida com um pedido REST construído. O caminho completo — um browser a enviar `multipart/form-data` — pertence à WCCS-043 (o componente) e à revisão em browser.

**A privacidade do ficheiro guardado.** Continua dependente do bloqueador `UPLOAD-PRIVACY-ENV`: nesta loja o recurso está desligado.

**O vínculo ao pedido e a política de download.** São a WCCS-044. O que esta tarefa entrega é um token que pertence a uma sessão; transformá-lo num documento que um pedido pode ser lido é a tarefa seguinte.

**A retenção.** A coluna `expires_at` é escrita (24 horas) e ainda ninguém expira nada: a WCCS-045.

## 8. Próxima tarefa

**WCCS-043 — "Criar componente único/múltiplo"** (aceite: *progresso, cancelar, remover, retry e erros acessíveis sem upload duplicado*): o componente nos dois checkouts, que é onde o endpoint desta tarefa passa a ser chamado por um cliente.
