# Registro de validação — WCCS-050

**Tarefa:** WCCS-050 · "Criar opt-in, diagnóstico e fallback"
**Fase:** F09 · Página customizada e pagamento
**Prioridade:** `required_v1` · **Dependências:** F02, F07, F08
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Ativar/desativar não muda engine nem apaga campos; falha tem retorno seguro."

**Resultado:** **392 testes unitários PHP** · **1088 asserções de integração** em 45 provas, 0 falhas (24 novas) · **607 testes de JS** em 37 suítes (6 novos) · os gates verdes, verificados por código de saída.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `src/Domain/Settings/CheckoutSettings.php` | O opt-in, desligado por omissão, e a decisão de recuar |
| `src/Http/Admin/SettingsController.php` | Uma rota: o interruptor e o estado da compatibilidade |
| `src/Admin/Routes.php` | A composição das rotas, num sítio só |
| `resources/admin/app/SettingsScreen.js` | O painel de Configurações e o de Diagnóstico |
| `src/Checkout/Classic/ClassicAssets.php` · `Blocks/BlocksRenderer.php` | O interruptor governa a apresentação, não os campos |
| `tests/Integration/F09-wccs-050-opt-in-proof.php` | As duas cláusulas, por comparação |

## 3. A frase que dá forma a tudo

> `Enable Custom Checkout` é opt-in e vem desligado. A desativação restaura a apresentação original **preservando o editor de campos**.

Duas frases e ambas decidem o desenho:

- **Opt-in e desligado**: um plugin que muda o checkout de uma loja no momento em que é ativado tomou uma decisão que é do comerciante — e ele não consegue ver o que está a escolher antes de ver o editor. Com a opção ausente, a resposta é "não", e qualquer valor estranho também: `'yes' === get_option(..., 'no')`. O default de um interruptor que muda um checkout tem de ser o que não muda nada.
- **O editor é preservado**: o interruptor governa a **apresentação** e mais nada — não os campos, não o esquema, não a validação. Desligá-lo desliga uma folha de estilo e dois componentes, nunca uma funcionalidade do produto.

## 4. "Não muda engine nem apaga campos" — afirmado por comparação

A prova não afirma isto, **compara-o**:

| comparado | como |
|---|---|
| os três slots de esquema (`draft`, `published`, `revisions`) | lidos **em bruto** da tabela de options antes e depois de ligar e desligar, e comparados byte a byte |
| o documento publicado | o campo que o comerciante configurou continua lá, pelo `id` |
| a engine | tipos, validadores, normalizadores, sources e operadores, todos iguais antes e depois |
| a opção do interruptor | é uma opção própria, e o nome dela não aparece dentro do documento |

E a outra metade, a que impede o interruptor de ser um interruptor de funcionalidades: **com o opt-in desligado o bundle dos campos continua a ser entregue**. O payload continua a levar `masks`, `rules`, `conditions`, `uploads` e `validation`; o que não chega é a folha de estilo e os dois componentes de apresentação. Um comerciante que desligue a apresentação fica com todos os campos que configurou, no checkout do WooCommerce.

A leitura é feita **em bruto na tabela** e não pelo repositório, de propósito: a comparação é sobre o que está guardado, não sobre o que um leitor devolve.

## 5. "Falha tem retorno seguro" — e a frase que o define

> Uma incompatibilidade não autoriza trocar silenciosamente a engine da página.

Um gateway que o registo de homologação marca `unavailable` é um gateway que esta apresentação não deve oferecer. O retorno seguro é **a loja**: o WooCommerce continua a desenhar o checkout que sempre desenhou — campos e validação incluídos — e o motivo viaja com o identificador do gateway, para que o comerciante possa agir sobre ele. A apresentação sai de cena; a engine não muda.

O recuo está afirmado em três planos: a regra (uma decisão que nomeia o gateway em `blocked_by` e no texto), a página (o payload que leva essa decisão) e o ecrã (o aviso de compatibilidade com os gateways que a bloquearam).

**O que este plano não alcança**, e está registado em vez de contornado: exercitar o recuo com um gateway **verdadeiramente oferecido** exige um `WC_Payment_Gateway` que se declare disponível — ou seja, uma loja com um gateway habilitado, que é o bloqueador `SANDBOX-PAYMENT` outra vez.

## 6. O ecrã, e o que ele não pode fazer

`SettingsScreen` serve **duas** secções — Configurações e Diagnóstico — porque as duas respondem à mesma pergunta: o checkout customizado está ligado, e como está a área de pagamento aos olhos dele. Separar dar-lhes-ia dois ecrãs a ler a mesma rota, a discordar na primeira vez que um fosse atualizado.

- A secção **Configurações** traz o interruptor; **Diagnóstico** traz o mesmo estado sem ele.
- O ecrã **mostra o modo que o servidor decidiu, não o interruptor**. É a parte que interessa: com o opt-in ligado e um gateway `unavailable`, o modo é `store` e o ecrã di-lo. Um ecrã que deduzisse o modo do interruptor mentiria exatamente no caso em que o comerciante mais precisa de saber.
- A metade da compatibilidade é **uma leitura**: seis testes de jsdom, e um deles conta os controles da página — **uma** checkbox (a apresentação) e **um** botão (recarregar). Um ecrã que pudesse escrever uma homologação seria um ecrã onde alguém promove um gateway a compatível.

O painel segue os componentes da casa (`Button`, `CheckboxField`, `Notice`), não `@wordpress/components`: a primeira versão importava de lá e o lint recusou-o — o admin deste plugin tem os seus próprios primitivos, e um ecrã que trouxesse outro conjunto estaria a desenhar com duas linguagens.

## 7. A asserção que ficou obsoleta, e o sítio onde a composição passou a viver

A WCCS-014 afirma que o payload publica os mapas de rotas dos controllers **verbatim** — e enumerava os dois controllers que existiam quando foi escrita. A rota nova quebrou-a, legitimamente:

```
FAIL  The bootstrap publishes the controller route maps verbatim  [draft, publish, …, settings]
```

A correção não foi acrescentar um terceiro nome à lista (que voltaria a partir no controller seguinte), mas **dar à composição um sítio só**: `Admin\Routes::all()`, que o `Assets` lê para publicar o payload e que a prova lê para o afirmar. É o mesmo movimento da lista partilhada de hooks da loja, feito no dia em que a duplicação se manifestou.

## 8. O que NÃO foi provado, e porquê

**Um comerciante a premir o interruptor.** O ecrã é exercitado em jsdom contra um cliente REST falso, e o servidor é exercitado a sério; o que não foi observado é o painel no wp-admin de verdade. É a WCCS-063.

**O recuo com um gateway a sério** — o `SANDBOX-PAYMENT`, como acima.

**O efeito no storefront.** Com o opt-in desligado, o que a prova afirma é que a folha de estilo **não é enqueued** e que o bundle dos campos **é**. A aparência da loja sem apresentação é, por construção, a que o WooCommerce sempre teve — e não foi observada num browser.

## 9. Fecho da fase F09

| tarefa | estado |
|---|---|
| WCCS-046 apresentação Classic | cumprida |
| WCCS-047 apresentação Blocks | cumprida |
| WCCS-048 accordion e resumo | cumprida |
| WCCS-049 homologação de gateways | **parcial — bloqueada** (`SANDBOX-PAYMENT`) |
| WCCS-050 opt-in, diagnóstico e fallback | cumprida |

O gate da fase — *"Pedidos reais de sandbox nos cenários homologados, sem inputs de cartão próprios e sem Checkout Sidebar"* — continua **aberto**, e agora por uma razão única e nomeada: **não há gateway habilitado nem credenciais de sandbox neste ambiente**. Das três cláusulas, duas estão afirmadas sobre o código: nenhum input de cartão próprio (registos e ecrãs) e a Checkout Sidebar está fora do âmbito da v1 desde o início.

**Próxima tarefa:** **WCCS-051**, a primeira da F10.
