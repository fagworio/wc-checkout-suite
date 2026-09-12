# Registro de validação — WCCS-056

**Tarefa:** WCCS-056 · "Implementar export/import com preview"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "JSON versionado, diff, limites, conflitos e rollback testados; sem segredos."

**Resultado:** **392 testes unitários PHP** · **1223 asserções de integração** em 51 provas, 0 falhas (24 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Schema/SchemaTransfer.php` | O envelope versionado, os limites, a inspeção |
| `src/Http/Admin/TransferController.php` | Exportar, pré-visualizar, importar |
| `src/Plugin.php` · `src/Admin/Routes.php` | Registo e composição das rotas |
| `tests/Integration/F10-wccs-056-transfer-proof.php` | As cinco cláusulas |

## 3. Cláusula a cláusula

| cláusula | como está |
|---|---|
| **JSON versionado** | o envelope nomeia o formato, a versão e de onde veio (revisão, versão do esquema, plugin) |
| **diff** | o preview devolve o `SchemaDiff` entre o documento atual e o que o ficheiro traria — `added`, `removed`, `changed`, ordem e settings, a mesma máquina da WCCS-019 |
| **limites** | bytes, campos e secções, verificados **antes** de o ficheiro se tornar documento, e a recusa **nomeia os números** |
| **conflitos** | o ficheiro traz a revisão de origem; um ficheiro de outra revisão é reportado com esse aviso, e a importação escreve com o compare-and-swap contra a revisão do preview |
| **rollback** | caminhado, não inventado (abaixo) |
| **sem segredos** | o ficheiro é varrido por `token`, `nonce`, `password`, `secret`, `api_key`, `authorization`, `wp-content` e o caminho do plugin, e o envelope é afirmado ter **exatamente** as cinco chaves do documento |

Um ficheiro de **outra versão mais nova** é recusado, com a razão escrita: lê-lo como se fosse esta versão aplicaria as partes que por acaso coincidem e reportaria sucesso pelo resto.

## 4. O preview e a importação são o mesmo trabalho, lido duas vezes

Os dois chamam a mesma inspeção; um devolve o relatório, o outro escreve. Dois caminhos que decidissem separadamente discordariam sobre o que o ficheiro significa — e a discordância apareceria como uma alteração que o comerciante não viu.

E a validação é a **mesma do editor**: um documento que a interface recusaria não pode entrar como ficheiro. A prova publica um campo com um tipo inexistente e afirma que o preview o recusa, com os códigos do validador de definições (`unknown_type`, `storage_scope_requires_value`).

Há duas asserções que existem para o preview não ser uma promessa: **escreve zero** (a revisão do rascunho é a mesma antes e depois) e **nada chega ao checkout antes de uma publicação** (o documento publicado não é tocado).

## 5. O rollback é o caminho do editor, não um segundo "desfazer"

Importar escreve no **rascunho**; nada chega ao checkout antes de ser publicado; publicar guarda uma revisão; e `restore()` traz uma de volta **como nova revisão**. A prova percorre-o inteiro:

```
publicar o documento A  → histórico: A
importar B (rascunho)   → conflito reportado se a revisão não bate
publicar B              → histórico: A, B
restore(A)              → ok, e o publicado volta a ser A
                        → e o histórico passa a ter três, porque é acrescentado, nunca reescrito
```

Um segundo mecanismo de desfazer construído para importações seria um segundo histórico, e dois históricos discordam na primeira vez que um deles é restaurado.

## 6. Os defeitos que a prova apanhou

**Na importação.** O `report()` removia o documento antes de responder — e a importação usava esse mesmo relatório para escrever, pelo que escrevia `null`. O caminho só foi exercitado porque a prova **conduz a rota** e não a classe: um teste ao `SchemaTransfer` diretamente teria passado. É a diferença entre testar a peça e testar o caminho.

**Na própria prova, duas vezes.** A primeira versão esperava que o `diff` devolvesse identificadores onde ele devolve definições; e o rollback começava de um estado escrito **diretamente no slot**, que é um estado que a loja nunca registou — portanto não estava no histórico e não havia o que restaurar. O estado inicial passou a ser publicado pelo caminho de publicação, que é o que escreve histórico.

E a contagem de options voltou a ser uma comparação com limpeza prévia: a terceira prova a receber a mesma correção da WCCS-008, o que a torna um padrão e não um acidente.

## 7. O que NÃO foi provado, e porquê

**Um ecrã.** As três rotas são exercitadas contra o servidor REST real, mas a secção **Importar/Exportar** da administração continua a renderizar o marcador que renderiza desde que a shell foi construída: o ficheiro é alcançável por um cliente e **ainda não por um comerciante**. Construir esse ecrã fica nomeado em vez de implícito.

**Um ficheiro a sério.** O ficheiro é produzido e consumido em memória; o download, o upload e o limite do servidor web (que pode ser mais apertado do que este) não foram exercitados.

**Migração de versões antigas.** A versão 1 é a única que existe. A estrutura para migrar uma versão anterior está prevista na leitura e **não foi exercitada**, porque não há nada para migrar.

## 8. Fecho

Seis das oito tarefas da F10 estão feitas. Esta devolve ao comerciante uma capacidade que ele espera de qualquer plugin de campos: levar a configuração para outro site — e fá-lo pela via que não pode perder trabalho, porque um ficheiro é um rascunho e um rascunho precisa de ser publicado.

**Próxima tarefa:** **WCCS-057**.
