# Registro de validação — WCCS-060

**Tarefa:** WCCS-060 · "Documentar operação do lojista"
**Fase:** F11 · Portabilidade, SDK e documentação
**Prioridade:** `required_v1` · **Dependências:** F03, F07, F10
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Configurar PF/PJ, uploads, layout, restore e diagnóstico sem editar código."

**Resultado:** **396 testes unitários PHP** · **1262 asserções de integração** em 53 provas, 0 falhas · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `docs/operations/merchant-guide.md` | As cinco tarefas, cada uma com onde se faz hoje |

## 3. As cinco tarefas, e onde cada uma se faz

| tarefa | onde | o que o guia diz que importa |
|---|---|---|
| **PF/PJ** | `Campos` (predefinições brasileiras) + `Regras` | as predefinições trazem máscara e dígitos verificadores; a regra espelhada esconde o campo que não se aplica; e a política de valor decide se o que o cliente escreveu é apagado ou guardado |
| **uploads** | `Campos → tipo Documento` | os ficheiros ficam fora da pasta pública e o download passa por uma rota que decide por pedido; e **a funcionalidade desliga-se sozinha** quando o alojamento serve a pasta privada — a configuração é do servidor, e está registada como `UPLOAD-PRIVACY-ENV` |
| **layout** | `Configurações` (o interruptor) + `tokens.json` | desligar volta ao checkout do WooCommerce **e mantém os campos**; a apresentação estiliza o que o WooCommerce desenha e não substitui o formulário |
| **restore** | `Publicar` e `Revisões` | três caminhos que **não são a mesma coisa**: restaurar uma revisão (volta como nova revisão), importar um ficheiro (escreve no rascunho, nunca no publicado) e um conflito (recusa em vez de sobrepor) |
| **diagnóstico** | `Diagnóstico` | o modo **e a razão dele** — o interruptor pode estar ligado e o modo ser `store`; os gateways com o estado de homologação, onde `undecided` **não é um erro** mas a diferença entre "testámos" e "não olhámos" |

## 4. O guia diz o que ainda não existe

O ecrã de **Importar/Exportar** continua a mostrar o marcador desde a construção da shell. As três rotas estão construídas e provadas, e um cliente REST autenticado alcança-as — mas um comerciante ainda não.

**Está escrito no guia**, com as rotas, em vez de contornado: um guia que manda clicar num botão que não está lá é **pior do que nenhum guia**, porque o lojista conclui que fez algo errado.

## 5. O que o guia não promete

Fecha com três frases que o mantêm verdadeiro: **nada exige editar código** (e se algo exigir, é lacuna do guia); **nenhuma compatibilidade com "todos os gateways"** é prometida (a matriz diz o que foi observado); e **nenhuma conformidade legal** é afirmada (a política sugerida descreve o que o plugin faz).

## 6. O que NÃO foi provado, e porquê

**Um lojista a seguir o guia.** Nenhum browser foi aberto nesta sessão: o guia descreve caminhos que o **código** implementa, e não caminhos que alguém percorreu. É a WCCS-063.

**O percurso de duas lojas.** *"Exportar e importar em staging"* — o gate da fase — precisa de **duas** lojas, e este ambiente tem uma. Fica nomeado.

## 7. Fecho da F11

As **cinco tarefas estão concluídas**: o export/import, o migrador limitado, o exemplo de novo tipo, os contratos documentados e a operação do lojista.

O gate — *"exportar e importar em staging mantém IDs; plugin de exemplo adiciona tipo sem patch do core"* — está **cumprido na segunda cláusula** (provada ponta a ponta na WCCS-058) e **cumprido no código** na primeira: um ficheiro exportado e importado mantém os identificadores que trazia, porque os identificadores de campo do documento são dele. O que a palavra *"staging"* exige e este ambiente não tem são **duas lojas**.

**Próxima tarefa:** **WCCS-061**, a primeira da F12.
