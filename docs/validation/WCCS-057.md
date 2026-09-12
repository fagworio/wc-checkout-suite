# Registro de validação — WCCS-057

**Tarefa:** WCCS-057 · "Criar migrador ThemeHigh limitado"
**Fase:** F10 · Pedidos, Minha Conta, APIs e privacidade
**Prioridade:** `required_v1` · **Dependências:** F04, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Mapeia apenas estruturas comprovadas; unsupported gera relatório e não descarte silencioso."

**Resultado:** **392 testes unitários PHP** · **1244 asserções de integração** em 52 provas, 0 falhas (21 novas) · **607 testes de JS** em 37 suítes · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Migration/ThemeHighAdapter.php` | O mapeamento declarado, com a evidência de cada chave, e o relatório |
| `tests/Integration/F10-wccs-057-migration-proof.php` | O que mapeia, o que é relatado, e o que não é inventado |

## 3. "Comprovada" tem um registo, e o registo tem ficheiros

O roadmap é explícito: *"mapear configurações comprovadas no ZIP"* e *"não importar configurações Premium ausentes por suposição"*. A tabela de mapeamento **não foi escrita de memória** sobre como um editor de campos de checkout costuma funcionar: foi lida numa instalação do plugin de origem, e **cada chave que mapeia é uma chave que essa instalação usa**. O registo está em `EVIDENCE`, entrada a entrada, com o ficheiro de onde cada uma veio:

- a configuração vive em **options**, uma por secção — `wc_fields_billing`, `wc_fields_shipping`, `wc_fields_additional` (as constantes `OPTION_KEY_*` de `includes/utils/class-thwcfd-utils.php`);
- uma entrada de campo é um array indexado pelo nome, com `type`, `label`, `required`, `enabled`, `custom`, `show_in_email`, `show_in_order`;
- campos de escolha guardam as opções numa **string codificada**, descodificada por tipo (`prepare_options_array`);
- os tipos observados são `text`, `textarea`, `select`, `radio`, `checkbox`, `email`, `tel`, `country`, `state`.

**A versão fica dita.** As estruturas foram lidas numa instalação que declara **2.1.5**; o planeamento referenciava um ZIP declarado **2.2.0**. O adaptador regista as duas e não as confunde — um mapeamento verificado numa versão é um mapeamento sobre essa versão, e a diferença é o que separa um mapeamento de um palpite.

## 4. "Não descarte silencioso" tem três formas, todas afirmadas

1. **Um tipo que este plugin não tem não vira `text`.** É o default tentador e é o que perde dados: um campo que o comerciante configurou como algo que este plugin não tem passa a caixa de texto, ele não nota, e a migração reporta sucesso. O `color` da prova é recusado **com o tipo nomeado** e o campo não entra no documento.
2. **Uma configuração sem equivalente é relatada ao lado do campo** — `placeholder`, `class`, `priority`, `position` — e **uma chave que ninguém declarou também**: `something_new` é o que uma versão posterior da origem se parece daqui, e a loja é informada em vez de a ver desaparecer.
3. **Uma entrada que não é um mapeamento é relatada como tal**, e não ignorada.

E o invariante que fecha a cláusula: **cada campo acaba numa das duas listas** — `seen = mapped + não-mapeados`, com as linhas sobre *configurações dentro de* um campo contadas à parte. A primeira versão da asserção comparava contagens de coisas diferentes; a versão correta compara campos com campos.

## 5. Sem origem, nada é inventado

O plugin de origem **não está instalado nesta loja**, e isso é a primeira coisa que a prova afirma. Ler options que não existem devolve vazios, e vazios não são uma migração: o relatório diz que a origem está ausente e o documento sai **sem campos**, em vez de sair vazio a parecer uma loja configurada sem nada.

## 6. O defeito que a prova apanhou

A primeira versão da secção escrevia a palavra da origem através do mapeamento: `location => 'billing'|'shipping'|'additional'` e `'label'` em vez de `'title'`. O **validador** recusou:

```
section_missing_title, section_missing_title, section_unknown_location
```

`additional` não é um local deste plugin — a secção onde a origem guarda as notas do pedido é `order` aqui — e as secções deste plugin têm `title`. A migração passou a falar o vocabulário do destino, com um `location()` que traduz, e há uma asserção a exigir que as secções saiam em `billing`/`order`.

É a segunda vez na fase em que a validação compartilhada apanha uma camada nova: um documento que a interface recusaria não pode entrar por um ficheiro — e também não pode **sair** de uma migração.

## 7. O resultado entra pelo caminho que já existe

O adaptador devolve um **`SchemaDocument` válido** e uma função que o transforma no **mesmo envelope da WCCS-056**. A prova verifica as duas coisas: o documento passa o validador, e o `SchemaTransfer::inspect()` aceita o ficheiro — portanto uma migração é **pré-visualizada antes de ser guardada**, pela máquina que já faz isso, e não por uma segunda. O envelope diz de onde veio (`migrated_from: themehigh/checkout-field-editor@2.1.5`), porque um ficheiro que chega a uma loja meses depois tem de dizer o que o produziu.

E **migrar não toca na loja**: ler e mapear não escreve uma única option — afirmado.

## 8. O que NÃO foi provado, e porquê

**Uma migração a sério.** A origem não está instalada aqui, portanto o mapeamento é exercitado com uma origem com a forma que as estruturas registadas descrevem, e o caminho de leitura é afirmado encontrar nada e inventar nada. Correr isto contra uma loja que tem a origem instalada é um passo que precisa dessa loja: fica **nomeado**, não simulado.

**Os valores históricos.** A secção 21 di-lo e a migração di-lo no relatório: **importar esquema não migra valores de pedidos passados**. As notas do pedido e os documentos que já existem ficam onde estão.

**As configurações Premium.** *"Não importar configurações Premium ausentes por suposição"*: se o ZIP não as tinha, não há nada a mapear — e o adaptador recusa-se a inventar o que não leu.

## 9. Fecho

Sete das oito tarefas da F10 estão feitas. Esta é a única que trata dados de **outro plugin**, e a sua dificuldade não era técnica: era resistir a completar o mapeamento com o que se sabe sobre editores de campos em geral.

**Próxima tarefa:** **WCCS-058**, que fecha a fase com a auditoria por papel, endpoint e e-mail.
