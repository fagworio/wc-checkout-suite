# Registro de validação — WCCS-044

**Tarefa:** WCCS-044 · "Vincular ao pedido e proteger download"
**Fase:** F08 · Upload privado nos dois checkouts
**Prioridade:** `required_v1` · **Dependências:** F04, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Vínculo atômico/idempotente; dono autorizado acessa e terceiro recebe negação."

**Resultado:** **354 testes unitários PHP** (7 novos) · **971 asserções de integração** em 39 provas, 0 falhas (20 novas) · **581 testes de JS** em 34 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Uploads/UploadRepository.php` | `bind()`: o vínculo, **numa só instrução** |
| `src/Checkout/Classic/ClassicOrderUploads.php` | Os tokens submetidos, presos ao pedido criado |
| `src/Domain/Uploads/DownloadPolicy.php` | Quem pode ler, numa função |
| `src/Http/Checkout/DownloadController.php` | A única porta por onde um ficheiro sai |
| `tests/Integration/F08-wccs-044-order-binding-proof.php` | As duas cláusulas, sobre linhas e sobre a rota |

## 3. O vínculo: as três garantias estão na instrução, não no código à volta

```sql
UPDATE wp_wccs_uploads SET order_id = %d, status = 'ordered'
 WHERE token IN ( … ) AND owner = %s AND order_id = 0
```

- **Atómico** — um só `UPDATE`: não há janela em que um token esteja meio preso, e não há leitura-e-escrita para dois pedidos entrelaçarem.
- **Idempotente** — `order_id = 0` faz parte da condição, portanto correr outra vez depois de um retry **não corresponde a nada e não muda nada**. É isso que torna um cliente que submete duas vezes seguro, e é por isso que a resposta é uma **contagem**: a primeira chamada diz quantos prendeu, a segunda diz zero, e nenhuma delas é um erro.
- **Com dono** — `owner = %s` está na mesma condição, portanto um token de outra sessão não pode ser preso por esta, seja como for obtido. Uma verificação escrita em PHP teria de ser lembrada em cada sítio; nesta forma não se pode esquecer.

A prova afirma-o sobre as linhas: prende **1** de dois tokens (o da outra sessão fica com `order_id = 0`), a segunda chamada muda **0**, o registo depois é o mesmo e não um segundo vínculo, um token com forma inválida é ignorado sem sequer chegar à consulta, e prender nunca cria linhas.

## 4. A política: uma função decide, e a recusa é uma só

`DownloadPolicy::allows()` responde por **sessão que carregou** (antes ou depois de o pedido existir), por **cliente do pedido** e por **quem gere a loja**. Todos os outros recebem a mesma recusa — `not_allowed`, 404, e uma mensagem que não nomeia ficheiro, token nem existência. Distinguir "não é teu" de "não existe" deixaria um estranho descobrir quais dos handles são reais; a prova pede um token inexistente e o token de outra sessão e afirma que **as duas respostas são a mesma**.

A comparação do dono não é um teste de prefixo (`hash_equals`), e um upload sem dono não é legível por ninguém.

## 5. A porta

Um só controlador lê um ficheiro, e fá-lo **a partir do plugin** em vez de o entregar ao servidor: o objetivo do armazenamento é precisamente que o servidor não o possa servir, portanto a política só pode ser aplicada aqui. A resposta sai como `attachment`, com `nosniff` e `Cache-Control: private, no-store` — um documento que um cliente carregou não é uma página e nunca pode ser interpretado como uma.

Provado na rota real: a sessão que carregou recebe os bytes (200); outra sessão recebe 404 com a recusa; um token inexistente recebe a mesma recusa; e quem gere a loja recebe os bytes.

## 6. Uma manutenção que já se estava a repetir

Ao prender os uploads ao pedido, **quatro provas** que enumeram exatamente os hooks da loja voltaram a falhar — a segunda vez em duas tarefas (a WCCS-043 já as tinha obrigado a mudar). A lista de hooks esperados passou a viver **num só sítio** (`tests/Integration/support/storefront-hooks.php`), que os quatro harnesses leem. A asserção continua a ser um inventário deliberado — um hook novo tem de ser nomeado — mas deixou de ser algo que quatro ficheiros têm de lembrar no mesmo commit.

## 7. O que NÃO foi provado, e porquê

**Um pedido real de checkout a prender os uploads.** O vínculo é provado sobre as linhas e a política sobre a rota; o caminho completo — o formulário a submeter o campo oculto dos tokens, o hook de criação do pedido a lê-lo — está implementado mas não foi exercido a partir de um browser (WCCS-063). O hook é registado e está no inventário de hooks que as provas afirmam.

**O campo de upload no checkout Blocks.** Continua por entregar, como a WCCS-043 registou; o vínculo que esta tarefa construiu é independente do checkout que o submete.

**A privacidade do ficheiro em repouso.** Inalterada: o bloqueador `UPLOAD-PRIVACY-ENV` mantém o recurso desligado nesta loja, e a prova desta tarefa injeta a observação para poder exercitar o caminho de aceitação — dizendo-o no seu próprio output.

## 8. Próxima tarefa

**WCCS-045 — "Criar retenção e limpeza"** (aceite: *temporários expiram; draft/falha/efetivo têm regras testadas; cleanups podem repetir*): onde a coluna `expires_at`, escrita desde a WCCS-042 e ainda sem ninguém que a leia, passa a ter consequência — e onde uma limpeza que corre duas vezes tem de ser tão segura como um vínculo que corre duas vezes.
