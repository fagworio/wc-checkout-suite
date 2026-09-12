# Guia do lojista — configurar sem editar código

**Artefato da tarefa WCCS-060** (F11) · **Data:** 11/09/2026
**Referência no planejamento:** `ROADMAP.md §14`, `§15`, `§16` e `§21`

> O aceite de WCCS-060 é *"configurar PF/PJ, uploads, layout, restore e diagnóstico sem editar código"*.
> Este guia está escrito por tarefa, e cada secção diz **onde** a tarefa se faz hoje. Onde o ecrã ainda
> não existe, diz-se que não existe e por que via a mesma coisa é alcançável — porque um guia que manda
> clicar num botão que não está lá é pior do que nenhum guia.

## 0. O que muda na loja quando o plugin é ativado

**Nada.** O checkout customizado é opt-in e vem desligado; um plugin recém-ativado entrega os campos
que o lojista configurar, no checkout do próprio WooCommerce. Ver `docs/validation/WCCS-050.md` para o
que ligar e desligar prova.

## 1. PF/PJ — pessoa física e jurídica

**Onde:** `WooCommerce → WC CheckoutSuite → Campos`, e depois `Regras`.

Uma loja brasileira costuma pedir CPF a pessoa física e CNPJ a pessoa jurídica, e esconder o campo que
não se aplica. São três passos, todos no editor:

1. **Campos → Adicionar campo → predefinição.** As predefinições brasileiras já trazem o CPF e o CNPJ
   com máscara e validação de dígitos verificadores; escolher a predefinição é o que evita escrever
   uma expressão regular à mão.
2. **Um campo de escolha** (PF/PJ) — `Seleção` ou `Botões de opção` — com duas opções.
3. **Regras**, no campo do documento: *mostrar quando* o campo PF/PJ *é igual a* `pf`; e a regra
   espelhada no campo do CNPJ. O campo escondido tem uma **política de valor**: `discard` apaga o que
   o cliente tinha escrito, `preserve` guarda o que a loja aceitar.

O editor oferece apenas os operadores e as fontes que o servidor aceita — se uma regra não puder ser
guardada, ela não é oferecida. Ver `docs/validation/WCCS-032.md`.

**Depois de configurar:** publicar. Nada chega ao checkout antes de uma publicação, e publicar guarda
uma revisão (ver §4).

## 2. Uploads — documentos privados

**Onde:** `Campos → Adicionar campo → tipo Documento`.

Dois factos que o lojista precisa de saber antes de usar isto:

1. **Os ficheiros ficam fora da pasta pública do site** e o download passa por uma rota que decide, por
   pedido, se quem pede pode ler aquele ficheiro. Nunca há um URL directo para o documento.
2. **A funcionalidade desliga-se sozinha quando o alojamento serve a pasta privada.** O plugin verifica
   isso e, se a verificação falhar, **recusa uploads e diz por quê** em vez de aceitar ficheiros que
   qualquer pessoa poderia descarregar. Nesse caso há uma configuração de servidor a fazer (bloquear o
   acesso à pasta), e ela é do alojamento e não do plugin — está registada como
   `UPLOAD-PRIVACY-ENV` em `docs/compatibility.json`.

**Cota e retenção.** Há um limite por ficheiro e uma cota por sessão, e a retenção tem três regras: um
upload temporário (que não chegou a um pedido) expira; um ligado a um pedido fica enquanto o pedido
existir; um órfão (pedido apagado) vai com ele. A política sugerida ao cliente está escrita em
`docs/validation/WCCS-055.md` e na política de privacidade que o WordPress oferece.

## 3. Layout — a apresentação

**Onde:** `WC CheckoutSuite → Configurações` (o interruptor) e o ficheiro de tokens para as cores.

- **O interruptor `Custom checkout`** liga ou desliga a apresentação. Desligado, a loja volta ao
  checkout do WooCommerce **e mantém todos os campos configurados** — desligar não é desinstalar.
- **As cores e o espaçamento** vêm de `resources/design-tokens/tokens.json`; um tema que queira mudar
  um valor muda-o no ficheiro de tokens, e um teste falha se o CSS e o JSON divergirem.
- **A largura de um campo** configura-se por campo (`12`, `6`, `4` ou `3` colunas) no inspetor.
- **A apresentação nunca substitui o formulário**: ela estiliza o que o WooCommerce desenha, e os
  hooks ficam onde estavam. Ver `docs/validation/WCCS-046.md` e `WCCS-047.md`.

## 4. Restore — voltar atrás

**Onde:** `Campos → Publicar` mostra o que muda antes de publicar, e `Revisões` lista o histórico.

Três formas de voltar atrás, e a diferença entre elas:

1. **Restaurar uma revisão** — cada publicação guarda uma revisão; restaurar traz essa versão de volta
   **como nova revisão**, portanto o histórico nunca é reescrito.
2. **Importar um ficheiro** — `Exportar` produz um ficheiro com a configuração publicada; `Importar`
   mostra o que o ficheiro mudaria **antes** de escrever, e escreve no **rascunho**, nunca no
   publicado. Nada chega ao checkout até uma publicação.
3. **Um conflito não é um erro** — se alguém editou desde o ficheiro, o preview diz-o e a importação é
   recusada em vez de sobrepor. Ver `docs/validation/WCCS-056.md`.

**O ecrã de Importar/Exportar ainda não existe** na administração: as três rotas estão construídas e
provadas, e a secção mostra o marcador desde a construção da shell. Enquanto isso, um cliente REST
autenticado alcança-as (`/wc-checkoutsuite/v1/schema/export`, `/schema/import/preview`, `/schema/import`).

## 5. Diagnóstico

**Onde:** `WC CheckoutSuite → Diagnóstico`.

O que o lojista lê ali:

- **O modo do checkout** e **por que razão** é esse — o interruptor pode estar ligado e o modo ser
  `store`, porque um gateway marcado indisponível faz a apresentação sair de cena (ver §3 e
  `docs/validation/WCCS-050.md`).
- **Os gateways da loja e o estado de homologação de cada um.** Um gateway sem registo de homologação
  aparece como `undecided`, e isso **não é um erro**: é a diferença entre "testámos" e "não olhámos".
  Nada é prometido sobre um gateway que ninguém correu. Ver `docs/validation/WCCS-049.md`.
- **O que o store suporta** por tipo de campo: nativo, componente da Suite, ou limitado com o motivo.

## 6. Privacidade — pedidos de titulares

**Onde:** `Ferramentas → Exportar dados pessoais` e `Apagar dados pessoais`, as telas do próprio
WordPress. O plugin responde por elas: um pedido devolve os valores pessoais que os pedidos daquela
pessoa carregam, e um pedido de eliminação apaga-os **dizendo o que ficou e por quê** (o pedido em si
fica: é o registo da venda). Ver `docs/validation/WCCS-055.md`.

Um documento entregue num pedido **não é anexado** ao e-mail nem copiado para o ficheiro de exportação:
o pedido diz que existe, e a loja decide como entregar.

## 7. O que este guia não promete

1. **Nada é configurado por código.** Se uma tarefa exigir editar um ficheiro que não seja o
   `tokens.json` do tema, é uma lacuna deste guia e não uma instrução.
2. **Nenhuma compatibilidade é prometida com "todos os gateways".** A matriz diz o que foi observado.
3. **Nenhuma conformidade legal é prometida.** A política sugerida descreve o que o plugin faz; o que a
   loja deve dizer e guardar é decisão dela.
