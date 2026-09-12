# Registro de validação — WCCS-065

**Tarefa:** WCCS-065 · "Executar testes de recuperação"
**Fase:** F12 · Hardening, acessibilidade e matriz final
**Prioridade:** `required_v1` · **Data:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Rollback, schemas antigos, checkout aberto, jobs e extensão desativada tratados."

**Resultado:** **28 asserções** numa prova nova (`tests/Integration/F12-wccs-065-recovery-proof.php`), 0 falhas, que executam as cinco situações em sequência; **3 testes unitários** novos para o par matriz/adaptador; a varredura passa a **57 provas / 1334 asserções / 0 falhas**. Dois defeitos reais encontrados e corrigidos.

## 2. As cinco situações

| situação | o que foi executado | resultado |
|---|---|---|
| **Rollback** | publicar a revisão 1, publicar a 2, restaurar a 1 | a restauração publica uma **nova** revisão (3) com o conteúdo da 1; a loja passa a ler exatamente esse conteúdo; o histórico mantém as três; restaurar uma revisão inexistente é recusado com `unknown_revision` e não muda o que a loja está a correr |
| **Schemas antigos** | ler um documento de uma versão mais nova, ler um documento anterior ao carimbo de versão | o documento da versão mais nova é reportado `unsupported_version` e lido como **nada** — o checkout cai na forma da própria WooCommerce em vez de metade de um schema; o documento sem carimbo é carimbado, migrado e a migração fica no histórico |
| **Checkout aberto** | uma página na revisão N enquanto o lojista publica N+1 | a submissão é julgada pelas regras **vivas** (o campo que passou a obrigatório é recusado com `required`) e a resposta traz `schema_changed=true`; uma página na revisão viva é julgada por ela e não é avisada de nada; um valor para um campo que o documento já não tem **não é guardado em lado nenhum** |
| **Jobs** | correr a retenção, correr outra vez, lote de 5 com 7 expirados, desativar o plugin | correr agenda exatamente **uma** próxima execução e correr de novo não empilha uma segunda; o lote pára no limite e deixa o resto; a execução seguinte leva o resto (nenhum dos sete fica); **desativar o plugin tira o trabalho agendado do calendário** |
| **Extensão desativada** | registar o tipo de exemplo pela API pública, publicar um documento que o usa, medir o estado sem a extensão | o documento continua lá **com o tipo que declarou** — nada foi reescrito nem perdido; o adaptador clássico reporta o campo e não o desenha como outra coisa; a matriz de capacidades diz `unsupported` com a razão; os outros campos continuam a render; e o relatório de incompatibilidades avisa **antes** de publicar |

## 3. Os dois defeitos que a execução encontrou

**1. O trabalho agendado sobrevivia à desativação.** `Plugin::deactivate()` estava deliberadamente vazio, e o roadmap (§20) pede "jobs são cancelados/limpos conforme lifecycle". O evento `wccs_uploads_cleanup` continuava agendado depois de o plugin sair: o WordPress continuaria a disparar uma ação sem callback nenhum, e uma loja que nunca reativasse ficava com uma entrada de calendário que ninguém limparia. Corrigido limpando **apenas** o hook do próprio plugin, e apenas o agendamento — nenhuma opção, tabela ou pedido é tocado, e a política de uninstall continua a ser uma decisão explícita (WCCS-068), como o código já dizia.

**2. A matriz de capacidades prometia o que o adaptador recusa.** `AdapterCapabilities::for_type( $type, 'classic' )` respondia `native` para **qualquer** tipo, com a razão "o checkout clássico renderiza isto através dos hooks do próprio plugin". Para um tipo cuja extensão foi desativada, o `ClassicAdapter::can_render()` responde que não e o campo é saltado com uma razão — as duas respostas à mesma pergunta, e a que o lojista lê era a errada. Agora a matriz pergunta ao adaptador, e o relatório de incompatibilidades do caso da extensão desativada passou de 1 para **2** entradas (as duas superfícies). Há um teste unitário novo que afirma a regra geral: para **todos** os tipos publicados pelo registo, matriz e adaptador concordam.

## 4. Como as situações foram produzidas, quando não é o caso real

- **A extensão desativada** é produzida pedindo aos adaptadores uma definição cujo tipo nenhum registo conhece. Este processo não sabe desregistar um tipo, e reescrever a opção à mão seria inventar o estado em vez de o medir; a definição é a mesma forma que o documento publicado tem, com a chave do tipo que deixou de existir. O que se mede — adaptador, matriz, relatório e os restantes campos — é o comportamento real nesse estado.
- **O checkout aberto** é medido no endpoint de validação e na passagem clássica, não num browser com uma aba deixada aberta: o que a situação exige é que a submissão seja julgada pelas regras vivas, e é isso que está afirmado. A observação de browser de WCCS-063 já cobre a página que carrega a revisão publicada.
- **Os jobs** correm com linhas e ficheiros reais na tabela e no diretório privados. O diretório **não está protegido** pelo servidor nesta loja (`UPLOAD-PRIVACY-ENV`, já registado), o que não afeta o agendamento nem a limpeza — o que afeta é a privacidade do ficheiro, e isso continua registado como lacuna de ambiente.

## 5. Fecho

As cinco situações estão tratadas e duas delas deixaram o produto diferente do que estava. Com 063, 064 e 065 fechadas, a F12 tem quatro das cinco tarefas cumpridas e uma (062, a matriz funcional) parcialmente cumprida e bloqueada por `SANDBOX-PAYMENT`: o que falta não é trabalho por fazer, é uma credencial de sandbox que esta loja não tem.
