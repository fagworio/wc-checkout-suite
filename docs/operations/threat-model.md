# Modelo de ameaças — WC CheckoutSuite

- **Status:** Aceito
- **Data:** 11/09/2026
- **Tarefa:** WCCS-005 (F00) · **Revisões previstas:** F08 (upload) e F12 (hardening)
- **Base no planejamento:** `ROADMAP.md §20` (segurança, privacidade e operação) e `§25` (riscos)

> **Não é** certificação de segurança, não é auditoria dinâmica e não afirma invulnerabilidade.
> Nenhuma exploração foi executada: a verificação adversarial é **WCCS-061** (F12).

## 1. Ativos a proteger

| Ativo | Por que importa |
|---|---|
| Documentos pessoais (CPF, CNPJ, RG) e dados de cliente | Dado pessoal; vazamento tem impacto legal e reputacional |
| Arquivos enviados (uploads) | Podem conter documentos de identidade e comprovantes |
| Dados do pedido e histórico | Integridade comercial; precisa permanecer legível |
| Configuração do schema (draft/publicado/revisões) | Define o checkout; adulteração altera a coleta de dados |
| Capacidade administrativa e de leitura de documentos | Podem exigir permissões diferentes |
| Fluxo de pagamento e criação do pedido | Qualquer interferência é falha crítica |

## 2. Fronteiras de confiança

1. **Navegador → REST/AJAX administrativo** — entrada não confiável; nonce não é autorização.
2. **Navegador do comprador → Store API / checkout** — entrada não confiável; `required`, `enabled`,
   `price`, `role` e `visibility` enviados pelo cliente **não** são confiáveis.
3. **Sessão de convidado → uploads** — nonce de visitante **não** é identidade individual (S8).
4. **Pedido → download de arquivo** — fronteira de autorização por dono/capacidade.
5. **Plugin da Suite → gateway de pagamento** — fronteira que **não** pode ser atravessada: a Suite não
   coleta cartão/CVV nem clona iframes ou SDKs.
6. **Import/export → configuração** — entrada tratada como não confiável, mesmo vinda de administrador.

## 3. Ameaças, mitigação e gate

| # | Ameaça | Ativo | Mitigação decidida | Gate |
|---|---|---|---|---|
| T1 | **IDOR em download**: terceiro acessa arquivo de outro pedido | Uploads | Autorizar por capacidade administrativa **ou** dono autenticado/sessão verificada; **nunca** por `order_id` isolado; download como attachment, `nosniff`, cache privado, sem expor caminho | F08 / WCCS-044 |
| T2 | **Arquivo privado servido publicamente** (URL direta ou CDN) | Uploads | Armazenamento fora do webroot por padrão; fallback sob `uploads/` só com prova HTTP real; sem prova → recurso desabilitado (*fail-closed*) — ADR-0002 | F08 / WCCS-041 |
| T3 | **Replay de token** de upload ou uso cruzado entre sessões | Uploads | Token opaco e imprevisível, vinculado a proprietário/sessão; vínculo idempotente ao pedido; token não serve a outro pedido | F08 / WCCS-042, WCCS-061 |
| T4 | **Upload malicioso**: executável, HTML, SVG bruto, dupla extensão, path traversal, bomba de compactação | Servidor | Lista de permissão, inspeção de conteúdo (não só extensão/MIME declarado), nome aleatório, zero execução, limites de tamanho/quantidade/quota, rejeição de compactados por padrão | F08 / WCCS-041, WCCS-061 |
| T5 | **CSRF em endpoint de convidado** | Sessão/checkout | Nonce **mais** vínculo à sessão WooCommerce; nonce não substitui autenticação/autorização | F01/F08 |
| T6 | **Mass assignment / injeção no schema** | Configuração | Schema validado, limites de payload, `expected_revision`, proibição de chamar callback PHP por nome recebido em JSON, regex sem execução de código e sem padrão ilimitado | F01/F03 / WCCS-008 |
| T7 | **Injeção por import** | Configuração | JSON versionado, **sem** PHP/JS executável, sem credenciais, tokens ou documentos; preview de diff; limites; backup anterior | F11 / WCCS-056 |
| T8 | **XSS por label, HTML restrito ou valor de campo** | Admin/frontend | Sanitizar por tipo, escapar no contexto, HTML restrito, atributos HTML permitidos por lista | F01/F03 / WCCS-061 |
| T9 | **Dois administradores sobrescrevem a configuração** | Schema | Revisão + escrita atômica + conflito **409** com `expected_revision` (CAS) | F01/F03 / WCCS-008 |
| T10 | **Checkout aberto em revisão antiga** publica dados incompatíveis | Pedido | Servidor revalida contra a revisão publicada e informa mudanças; não cobra com dados incompatíveis | F12 / WCCS-065 |
| T11 | **Servidor confia em validação de navegador** | Pedido | A mesma regra crítica é revalidada no submit; POST adulterado falha sem JavaScript | F04/F07 / WCCS-022 |
| T12 | **Validação remota obsoleta autoriza valor novo** | Pedido | *Response* não é autorização permanente; schema e valor revalidados antes do pagamento; request ID, *abort* e descarte fora de ordem | F05 / WCCS-029 |
| T13 | **Enumeração de clientes** (descobrir se CPF/e-mail pertence a outro cliente) | Dados pessoais | Endpoint de validação não revela existência de terceiros | F05 / WCCS-029 |
| T14 | **Vazamento de dado pessoal em REST, e-mail ou exportação** | Dados pessoais | Visibilidade por público, autorização por campo/pedido, nenhum meta exposto automaticamente, chave com underscore não é controle de acesso | F10 / WCCS-054 |
| T15 | **PII em logs ou em diagnóstico exportado** | Dados pessoais | Log com request ID, tipo de erro, módulo e versão; **sem** CPF/CNPJ integral, RG, token, cartão, CVV ou conteúdo de arquivo | F01/F12 |
| T16 | **Cache público de REST/checkout/download** | Pedido/dados | Nada de cache público em REST, AJAX, checkout, downloads e sessão; verificar CDN/WAF por ambiente | F12 / WCCS-064 |
| T17 | **Checkout expresso ignora campo obrigatório crítico** | Pedido | Gate de segurança funcional: integrar, pedir preenchimento antes, ou indisponibilizar o caminho com explicação. Uma flag no campo não cria integração | F09 / WCCS-049 |
| T18 | **Suite coleta cartão/CVV ou clona gateway** | Pagamento | Proibido por projeto: sem campos próprios de cartão/CVV, sem clonar inputs/iframes/SDK, sem re-registrar gateways de terceiros | F09 / critério de aceite nº 17 |
| T19 | **Submissão duplicada cria pedido repetido** | Pedido | *Retry* reutiliza vínculo no mesmo pedido; concorrência e idempotência testadas | F08/F12 / WCCS-061 |
| T20 | **Extensão de terceiro removida torna pedido ilegível** | Histórico | Diagnóstico, bloqueio de publicação e leitura histórica por *snapshot*/formatter de fallback seguro | F11 / WCCS-058 |
| T21 | **Uninstall destrói histórico** | Pedido | Desativação preserva dados; uninstall remove configuração apenas sob política explícita; pedido histórico nunca é apagado | F13 / WCCS-068 |
| T22 | **Falha de licença bloqueia compra** | Operação | Expiração/falha de licença comercial **jamais** bloqueia a compra nem remove validadores/arquivos de pedidos existentes | F13 |

## 4. Riscos residuais aceitos explicitamente

1. **Varredura antimalware não é prometida.** Integração de *scanning* é adicional e não elimina o risco;
   nenhuma alegação de invulnerabilidade é feita.
2. **Object storage depende do provedor.** Bucket privado e acesso assinado são exigidos, mas a
   configuração efetiva está fora do controle do plugin; sem essa garantia, o recurso deve ser desabilitado.
3. **Plugins de terceiros** (frete, fiscal, assinaturas, multilíngue, gateways) só são considerados nos
   cenários explicitamente testados e registrados por versão. Não há garantia fora deles.
4. **Nenhum teste adversarial foi executado até F12.** Este documento é um plano de mitigação, não um
   relatório de resultados.
5. **Conformidade legal não é alegada.** A loja define base e finalidade; o produto não anuncia
   conformidade automática (`§20`).

## 5. Como este modelo será verificado

| Camada | Onde |
|---|---|
| Testes de segurança (IDOR, XSS, CSRF, replay, payloads, MIME, concorrência) | WCCS-061 |
| Matriz funcional por versão de gateway e cenário | WCCS-062 |
| QA visual, teclado, leitor de tela, zoom, movimento reduzido | WCCS-063 |
| Performance, loops de handler e memória após *refresh* | WCCS-064 |
| Recuperação: rollback, schemas antigos, checkout aberto, jobs, extensão desativada | WCCS-065 |

Critério de teste que rege todos os itens acima (literal de `ROADMAP.md §24`):
*"relatório informa o que passou, falhou e não foi testado. Código compilado, badge HPOS ou ausência de
erro no console não constituem homologação de pagamento."*
