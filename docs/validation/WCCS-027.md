# Registro de validação — WCCS-027

**Tarefa:** WCCS-027 · "Integrar IMask localmente"
**Fase:** F05 · Presets Brasil, IMask e validação remota
**Prioridade:** `required_v1` · **Dependências:** F04
**Data da execução:** 11/09/2026

## ✅ Status: CUMPRIDA

---

## 1. Critério de aceite (literal do `BACKLOG.json`)

> "Colar, apagar, autocomplete, mobile e refresh não quebram cursor/valor."

**Resultado:** **200 testes unitários PHP** · **671 asserções de integração** em 23 provas, 0 falhas (16 novas) · **364 testes de JS em 24 suites** (14 novos) · os **7 gates** verdes.

## 2. O que foi entregue

| Artefato | Papel |
|---|---|
| `resources/checkout/masks.js` | Traduz a máscara declarada e aplica-a por elemento |
| `resources/checkout/index.js` | Regista os dois componentes, **por ordem** |
| `src/Checkout/Classic/ClassicAssets.php` | Imprime no checkout as máscaras que a loja publicou |
| `imask@7.6.1` | Dependência local, versão exata, empacotada no bundle |

## 3. A ordem dos componentes é load-bearing

Este é o achado da tarefa, e foi **medido, não suposto**:

> Uma máscara criada sobre um elemento que **já tem** um valor formata esse valor e deixa o cursor no fim. Uma máscara criada sobre um elemento **vazio** não reformata um valor atribuído depois.

Ou seja: se a máscara fosse aplicada antes da reposição do valor, um campo substituído pelo refresh voltaria **vazio, receberia o valor antigo e ficaria sem formatação dentro de um campo mascarado** — que é exactamente a falha que o critério de aceite descreve.

Por isso os dois componentes são registados na entrada do bundle, um a seguir ao outro, com um comentário a dizer que a ordem é intencional: **o valor primeiro, a máscara por cima dele**. E há um teste que afirma o comportamento quebrado da ordem inversa, para que quem a trocar descubra porquê.

## 4. "Colar, apagar, autocomplete, mobile e refresh"

Provado em jsdom, contra um DOM real:

| Caso | O que é afirmado |
|---|---|
| **Colar** | um valor colado como um único `input` é formatado; colar demais é cortado no comprimento do documento |
| **Apagar** | apagar o último dígito reformata o resto (`123.456.789-0`) |
| **Autocomplete** | um valor preenchido e reportado como `input` é formatado; um valor que chega **sem** `input` não é formatado mas **não é perdido** — o servidor normaliza o que chegar |
| **Mobile** | um padrão só de dígitos pede `inputmode="numeric"`; o CNPJ alfanumérico **não** pede, porque um teclado numérico torna o documento impossível de digitar |
| **Cursor** | depois de formatar, o cursor fica no fim; e num campo que **sobreviveu** ao refresh o cursor fica onde estava |
| **Refresh** | um elemento substituído é mascarado de novo, e o valor reposto é formatado; um elemento que sobreviveu **não** é mascarado duas vezes |

O último par é o que o ciclo de vida da WCCS-025 tornou possível: a regra "uma vez por elemento" deixa de ser abstrata e passa a ter uma consequência observável — o cursor não salta porque nada reformata um input vivo.

## 5. A biblioteca é local, e isso é afirmado

O `§9` pede IMask como "dependência local versionada, sem CDN em runtime", e a razão não é pureza: um checkout que busca o seu JavaScript no servidor de outra pessoa falha quando esse servidor falhar, e a falha cai no lojista a meio de uma venda.

Provado:

- `package.json` declara `imask` como **dependência** (não devDependency — o comportamento em produção depende dela), fixada em `7.6.1`, sem intervalo: um intervalo deixaria uma reconstrução enviar uma biblioteca diferente da que foi testada;
- a versão instalada é a fixada;
- o bundle tem 60 KB e **carrega a biblioteca dentro dele**;
- a lista de dependências que o WordPress recebe continua a ser `jquery` e `wp-i18n`, isto é, **nada de outra origem**;
- a página imprime `window.wccsCheckout` **antes** do bundle, com as máscaras que a loja publicou, e a tag do script aponta para este site.

## 6. O que o servidor envia — e o que não envia

As máscaras vêm do **documento publicado**, não do registro: um campo desabilitado não é enviado (não é renderizado, não há nada a mascarar), e um campo que o checkout clássico não consegue renderizar também não (é omitido pelo adapter). Viaja a definição, a chave e a versão — a versão porque uma definição guarda aquela contra a qual foi configurada, e uma máscara que mude depois tem de ser detectável em vez de alterar valores em silêncio.

Uma máscara cuja forma o cliente não entende (por exemplo `type: 'number'`, que nenhuma máscara desta versão usa mas que a API pública permite) deixa o campo **sem máscara** e di-lo — uma máscara aplicada como a coisa errada formata um valor que ninguém consegue voltar a digitar, o que é pior do que nenhuma.

## 7. Uma limitação medida, não escondida

O token curinga do IMask é `/./` — **qualquer** caractere, não um alfanumérico. A máscara de CNPJ é, por isso, permissiva quanto ao que se pode digitar numa posição de dados.

Fica assim de propósito. Apertá-la exigiria entregar ao browser uma regra sobre o que um documento pode conter, e a camada que é dona dessa regra é o validador no servidor, que a WCCS-028 entrega. Um caractere errado é **recusado**, não escondido: o normalizador da WCCS-026 conserva-o precisamente para isso. Está registrado como nota na prova, não como sucesso.

## 8. Dois defeitos meus, na prova

**O array de scripts inline tem uma entrada falsa.** `wp_add_inline_script` guarda uma lista por posição, e o `wp_set_script_translations()` contribui com uma entrada vazia — que fica no índice 0. A asserção afirmava "exatamente uma entrada, no índice 0" e falhou com o payload presente. Passou a procurar a entrada não vazia. (O WordPress ignora a entrada falsa ao imprimir; verifiquei que não sai um `<script>false</script>`.)

**`do_item()` imprime e devolve um booleano.** A asserção "a página carrega o bundle" recebeu `1` bytes porque atribuí o valor de retorno em vez de capturar a saída. Corrigido com buffer de saída.

## 9. O que NÃO foi provado, e porquê

**Um checkout renderizado e um telemóvel real.** O teclado táctil é uma propriedade do dispositivo; o que o código controla é que a máscara é aplicada uma vez por elemento e que formata o valor que o cliente escreveu. Continua a ser a `CLASSIC-TEST-SURFACE`.

**A composição de texto (IME).** O `§9` a lista entre o que há a tratar; depende do motor de entrada do sistema e não aparece em jsdom. O IMask trata-a internamente; não a afirmo como provada.

**A validação.** Continua a ser a WCCS-028: um CPF errado é formatado e aceite.

## 10. Próxima tarefa

**WCCS-028 — "Implementar validadores PHP/JS"**. Aceite: *"Mesmos fixtures passam/falham; DV não é apresentado como validação de identidade."*

É a tarefa que fecha o gate da fase — *"documento inválido não finaliza"* — e a que resolve a decisão aberta `CNPJ-CHECK-DIGITS`, que o ADR-0003 encaminha para lá contra as fontes oficiais. As fixtures de normalização já estão partilhadas; as de validade juntam-se ao mesmo arquivo, e o `§9` avisa que os dígitos verificadores **não** provam titularidade, situação cadastral nem identidade — o que a interface não pode sugerir.
