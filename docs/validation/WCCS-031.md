# Registro de validação — WCCS-031

**Tarefa:** WCCS-031 · "Criar AST e tipos de operadores"
**Fase:** F06 · Conditional Logic e política de valores
**Prioridade:** `required_v1` · **Dependências:** F03, F05
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "AND/OR e operadores tipados; ciclos e referências inválidas rejeitados."

**Resultado:** **237 testes unitários PHP** (22 novos) · **766 asserções de integração** em 27 provas, 0 falhas (11 novas) · 413 testes de JS · os **7 gates** verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Conditions/Operator.php` · `Operators.php` | As dez operações, tipadas |
| `src/Domain/Conditions/Source.php` · `Sources.php` | As nove fontes do `§11`, com o seu escopo |
| `src/Domain/Conditions/ConditionTree.php` | A forma: `all`/`any` sobre folhas |
| `src/Domain/Conditions/ConditionValidator.php` | As recusas, em duas camadas |

## 3. Duas camadas, porque sabem coisas diferentes

**Uma regra sozinha** é julgada contra os vocabulários: a forma, o operador, a fonte, se o operador leva valor e se esse valor tem um tipo que ele consegue comparar. É tudo o que se decide sem olhar para o resto do documento — e corre **em cada gravação**, não só na publicação.

**Regras em conjunto** são julgadas umas contra as outras: uma condição que nomeia um campo que não existe, e uma condição que nomeia um campo que, ao fim de uma cadeia, a nomeia de volta. Só o documento sabe isto, portanto é onde o documento é validado — o mesmo lugar onde as referências de secção são conferidas — e é lá que entra no relatório do painel de publicação.

Um ciclo é **recusado**, não tolerado. Dois campos cuja visibilidade depende um do outro não têm resposta — não uma resposta errada, **nenhuma** — e um motor que escolhesse uma mostraria o campo que calhasse avaliar primeiro.

## 4. Duas negações, três famílias

Os dez operadores do `§11` estão todos: igualdade, conteúdo, comparação, vazio e pertinência, cada um com a sua negação. E a negação **não é um nó**: é `not_equals`, `not_contains`, `not_in`. Uma regra que oculta um campo quando duas coisas diferem diz isso, em vez de dizer "não (são iguais)". Uma maneira de exprimir uma coisa é uma maneira de a errar.

## 5. O defeito que a prova apanhou

O `accepts_source()` tinha uma cláusula a mais:

```php
return in_array( $type, $this->sources, true ) || in_array( 'mixed', $this->sources, true );
```

`mixed` é o tipo que uma **referência a campo** declara antes de ser resolvida, e eu tratei-o como um curinga. O efeito era que **todos os operadores aceitavam todos os campos** — o oposto exato de um operador tipado, e a razão pela qual o teste "an operator reading a checkbox with greater_than is refused" falhou.

`mixed` é um valor do catálogo de fontes, não um curinga do operador. A verificação é agora uma pertença simples, e a comparação que só o documento consegue fazer — `greater_than` sobre um checkbox — é recusada.

Vale notar como apareceu: não por revisão, mas porque um teste afirmava a recusa e ela não veio.

## 6. As fontes, e o que significa "o adapter expõe"

O `§11` lista as fontes e acrescenta *"desde que o adapter exponha esse contexto"* e *"qualquer fonte indisponível recebe bloqueio ou restrição explícita"*. Implementar a regra exige saber quem expõe o quê, e essa matriz eu **não** inventei: não tenho evidência para afirmar que o checkout Blocks expõe o carrinho e o clássico não, e inventá-la seria uma afirmação sem fonte.

O que declarei é a divisão que a evidência sustenta, e que é a pergunta que o bloqueio tem de responder: **de quem é o valor**.

- `client` — o valor já está na página: o endereço do cliente, os métodos de envio e pagamento escolhidos, o campo que a regra lê, e o que o servidor inlina ao renderizar.
- `server` — só o servidor sabe: o que está no carrinho, o que custa, que categorias tem, e se alguém está autenticado no momento em que o pedido chega.

Uma regra que lê uma fonte de servidor não pode ser respondida ao vivo no browser, e isso **não** é uma lacuna a disfarçar: o `§11` exige que o servidor recalcule com contexto confiável de qualquer forma, e um browser que adivinhasse seria um browser que se pode forjar para esconder um campo que o servidor considera obrigatório.

## 7. O que NÃO foi provado, e porquê

**Avaliação.** Nada decide nada ainda: o motor que percorre esta árvore é a WCCS-033, e o avaliador que o pipeline pergunta continua a ser o permissivo. O que está provado é o vocabulário e as recusas — que é o que torna um avaliador digno de ser escrito.

**O editor de regras.** O `§11` pede um editor visual com grupos AND/OR; é a WCCS-032, e é lá que as "mensagens de contradição" entram.

**A matriz de adaptadores.** Registrada acima como uma decisão que precisa de evidência, e não como uma omissão.

## 8. Próxima tarefa

**WCCS-032 — "Criar editor de regras"** (aceite: *regras legíveis, preview de resultado e mensagens de contradição*). É a primeira tarefa da fase com uma metade visível no painel, e o vocabulário desta tarefa é o que o editor oferece: os operadores e as fontes que ele mostra são os mesmos que o validador aceita, lidos do mesmo lugar.

---

## 9. Correção posterior, encontrada na WCCS-032

O aceite desta tarefa diz **"operadores tipados"**, e a §3 acima descreve a verificação de tipo como estando feita em duas camadas. Estava feita **para referências a campos** — `greater_than` sobre um checkbox era recusado no nível do documento, e é isso que a prova desta tarefa exercitou. Para uma **fonte do catálogo**, nada verificava o par operador↔fonte.

A consequência era uma regra sem sentido ser gravável, desde que o valor fosse do tipo que o operador aceita:

```
country            + greater_than + 5    → aceite
cart_items         + greater_than + 5    → aceite
customer_logged_in + contains     + "x"  → aceite
cart_total         + contains     + "x"  → aceite
```

"O país é maior que 5" passava em todas as verificações que existiam, porque `5` é um número, e `greater_than` compara números.

Foi encontrado na WCCS-032, cuja prova afirma a igualdade entre **o que o editor oferece e o que o validador aceita** — uma igualdade afirmada falha quando um dos lados está errado, e era o validador que estava. A correção é o mesmo `condition_source_incompatible`, com a mesma forma de mensagem, aplicado onde a fonte do catálogo está: em `walk_leaf`, antes do valor. A prova desta tarefa passou a percorrer **todos** os pares (8 fontes diretas × 10 operadores) em vez de uma amostra, e a prova da WCCS-032 percorre os mesmos pares através do validador real.

Vale registar a forma do erro, porque é a terceira desta família no projeto: a asserção existia para metade do espaço, e a metade que faltava era exatamente aquela onde a verificação não tinha sido escrita. Uma verificação de tipo que só corre num dos dois caminhos lê-se como uma verificação de tipo.
