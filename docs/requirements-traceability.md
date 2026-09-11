# Rastreabilidade de requisitos → fases → tarefas

WC CheckoutSuite · Planejamento 1.0 · artefato da tarefa **WCCS-001** (F00).

**Natureza:** artefato derivado e verificável. **Não substitui** `roadmap/MATRIZ-REQUISITOS.md`, que continua sendo a fonte de rastreabilidade do anexo; esta página apenas desce a rastreabilidade até o nível de tarefa (`WCCS-nnn`), que a matriz original não alcança.

**Regra de derivação (determinística):** cada linha da tabela de `roadmap/MATRIZ-REQUISITOS.md` declara as fases que cobrem aquela fonte/seção do anexo. Uma tarefa é considerada coberta por uma fonte quando a fase da tarefa está entre as fases declaradas por essa fonte. Ranges (`F00–F04`) são expandidos de forma inclusiva; `Todo o roadmap` expande para `F00–F13`.

**Resultado:** 27 fontes/seções · 70 tarefas · **cobertura 70/70** (nenhuma tarefa sem fonte).

---

## 1. Fontes do anexo → fases → tarefas

| # | Fonte / seção | Cobertura | Capítulos | Fases declaradas | Tarefas |
|---|---|---|---|---|---|
| 1 | Objetivo e escopo principal | Campos padrão e próprios; arquitetura independente | 1–4 | F00–F04 | WCCS-001…WCCS-025 (25) |
| 2 | 1. Tipos de campos | Tipos básicos, escolhas, datas, informativos, arquivo e Brasil | 5–6 | F01/F05/F07/F08 | WCCS-006…WCCS-045 (20) |
| 3 | 2. Máscaras de entrada | IMask, configuração e validação separada | 9–10 | F05 | WCCS-026…WCCS-030 (5) |
| 4 | 3. Gerenciamento dos campos | CRUD, labels, opções, required, classes, larguras e seção | 4/16 | F03 | WCCS-016…WCCS-020 (5) |
| 5 | 4. Ordenação Drag and Drop | Ordem visual e teclado; diferença Classic/Blocks | 7–8/16 | F03/F07 | WCCS-016…WCCS-040 (10) |
| 6 | 5. Seções do checkout | Billing, Shipping, Account, Order e customizadas | 4/7–8 | F03/F04/F07 | WCCS-016…WCCS-040 (15) |
| 7 | 6. Validação AJAX | Erros por campo, local vs remoto e servidor autoritativo | 10 | F05 | WCCS-026…WCCS-030 (5) |
| 8 | 7. Upload de arquivos | MIME, tamanho, múltiplos, privado, pedido, acesso e retenção | 12 | F08 | WCCS-041…WCCS-045 (5) |
| 9 | 8. Conditional Logic | AND/OR, operadores, contextos e ocultos | 11 | F06 | WCCS-031…WCCS-035 (5) |
| 10 | 9. Persistência | Pedidos, conta, e-mail e APIs com CRUD/HPOS | 13–14 | F04/F10 | WCCS-021…WCCS-055 (10) |
| 11 | 10. Classic e Checkout Blocks | Domínio comum, adaptadores e limitações | 7–8 | F00/F04/F07 | WCCS-001…WCCS-040 (15) |
| 12 | 11. Checkout customizado | Layout opcional mantendo engine e hooks | 15 | F09 | WCCS-046…WCCS-050 (5) |
| 13 | 12. Formas de pagamento | Accordion usando gateways registrados | 15 | F09/F12 | WCCS-046…WCCS-065 (10) |
| 14 | 13. Interface administrativa | Editor, configuração, layout e settings modernos | 16–17 | F02/F03 | WCCS-011…WCCS-020 (10) |
| 15 | 14. Segurança | Sanitização, escaping, permissões, nonce, uploads e manipulação | 12/19–20 | F01/F08/F12 | WCCS-006…WCCS-065 (15) |
| 16 | 15. Performance | Assets locais sob demanda, cache e AJAX mínimo | 21 | F12 | WCCS-061…WCCS-065 (5) |
| 17 | 16. Compatibilidade | WordPress/Woo/PHP, HPOS, Blocks, temas/gateways/cache | 21/24 | F00/F12 | WCCS-001…WCCS-065 (10) |
| 18 | 17. Extensibilidade | PHP/JS, registries, API pública e novo tipo | 6/19 | F01/F11 | WCCS-006…WCCS-060 (10) |
| 19 | 18. Arquitetura | Módulos, PHP, JS e diretórios | 3/18/22 | F01 | WCCS-006…WCCS-010 (5) |
| 20 | 19. Resultado esperado | Especificação completa antes de implementar | 1–28 | Todo o roadmap | WCCS-001…WCCS-070 (70) |
| 21 | 20. Roadmap | Fases, componentes, dependências, riscos e aceite | 23 | F00–F13 | WCCS-001…WCCS-070 (70) |
| 22 | Referências obrigatórias | ZIP, HTML, requisitos e documentação oficial | 2/28 + auditoria | F00 | WCCS-001…WCCS-005 (5) |
| 23 | Nova direção: nome | WC CheckoutSuite em todo o projeto | Identidade/17 | F00/F02 | WCCS-001…WCCS-015 (10) |
| 24 | Nova direção: identidade moderna | Design system e protótipo admin/frontend | 16–17 + PROTOTIPO.html | F02/F09 | WCCS-011…WCCS-050 (10) |
| 25 | Nova restrição: Checkout Sidebar futuro | Excluído da V1; sem implementação antecipada | 1/27 | F00/F13 | WCCS-001…WCCS-070 (10) |
| 26 | Ajuste técnico verificado | CNPJ alfanumérico desde o início | 2/5/9 | F05 | WCCS-026…WCCS-030 (5) |
| 27 | Robustez adicional proposta | Revisões, conflitos, políticas de dados e recuperação | 13/19–20/25 | F01/F03/F12 | WCCS-006…WCCS-065 (15) |

---

## 2. As 70 tarefas e suas fontes de requisito

| Tarefa | Fase | Entrega | Fontes do anexo | Critério de aceite (literal do backlog) |
|---|---|---|---|---|
| WCCS-001 | F00 | Inventariar requisitos e ambiente | 1, 11, 17, 20, 21, 22, 23, 25 | Requisitos rastreados; versões de WordPress/Woo/PHP e gateways alvo registradas. |
| WCCS-002 | F00 | Provar fluxo Classic e HPOS | 1, 11, 17, 20, 21, 22, 23, 25 | Um campo de teste é validado e persistido com HPOS ligado e sincronização desligada. |
| WCCS-003 | F00 | Provar fluxo Blocks | 1, 11, 17, 20, 21, 22, 23, 25 | Text, campo customizado controlado e erro server-side chegam ao pedido sem manipulação do DOM core. |
| WCCS-004 | F00 | Provar layout e gateways | 1, 11, 17, 20, 21, 22, 23, 25 | Pagamento de sandbox, refresh e retorno verificados no conjunto inicial; slots permitidos documentados. |
| WCCS-005 | F00 | Congelar decisões arquiteturais | 1, 11, 17, 20, 21, 22, 23, 25 | ADRs aprovadas para storage, upload privado, CNPJ alfanumérico e exclusão de Sidebar. |
| WCCS-006 | F01 | Criar bootstrap e build | 1, 2, 15, 18, 19, 20, 21, 27 | Ativação segura sem Woo; assets compiláveis; namespace e i18n definidos. |
| WCCS-007 | F01 | Implementar schema e registries | 1, 2, 15, 18, 19, 20, 21, 27 | Tipos, valores e settings validados; registro externo funciona sem editar factory. |
| WCCS-008 | F01 | Implementar draft, publicação e revisões | 1, 2, 15, 18, 19, 20, 21, 27 | Gravação atômica e conflito 409 testados; publicar não perde histórico. |
| WCCS-009 | F01 | Criar normalizadores e contratos | 1, 2, 15, 18, 19, 20, 21, 27 | Interfaces versionadas; mesmo contrato serve Classic/Blocks/pedidos. |
| WCCS-010 | F01 | Ativar CI desde o início | 1, 2, 15, 18, 19, 20, 21, 27 | PHPCS, análise estática, lint, typecheck e testes mínimos obrigatórios no merge. |
| WCCS-011 | F02 | Formalizar tokens e estados | 1, 14, 20, 21, 23, 24 | Tokens claros/escuros, campos e botões documentados sem contraste reprovado. |
| WCCS-012 | F02 | Criar shell dentro do WordPress | 1, 14, 20, 21, 23, 24 | Navegação e assets restritos à tela da Suite; responsividade funcional. |
| WCCS-013 | F02 | Criar componentes básicos | 1, 14, 20, 21, 23, 24 | Botões, diálogos, tabs, mensagens, badges e formulários têm teclado e foco. |
| WCCS-014 | F02 | Implementar client REST | 1, 14, 20, 21, 23, 24 | Nonce, erro 403/409/422, retry seguro e estado não salvo tratados. |
| WCCS-015 | F02 | Criar preview visual | 1, 14, 20, 21, 23, 24 | Desktop/tablet/mobile identificados como prévia; nenhum dado real necessário. |
| WCCS-016 | F03 | Criar Field Picker e CRUD | 1, 4, 5, 6, 14, 20, 21, 27 | Criar/editar/duplicar/arquivar; campos core protegidos; busca por categorias. |
| WCCS-017 | F03 | Criar inspector por tipo | 1, 4, 5, 6, 14, 20, 21, 27 | Máscaras, opções, descrição, largura, storage e visibilidade somente onde suportados. |
| WCCS-018 | F03 | Criar seções e ordenação | 1, 4, 5, 6, 14, 20, 21, 27 | Ordem por seção salva; mover por teclado e botões preserva foco. |
| WCCS-019 | F03 | Implementar draft e PublishDiff | 1, 4, 5, 6, 14, 20, 21, 27 | Salvar draft não afeta a loja; publicação mostra diferenças e incompatibilidades. |
| WCCS-020 | F03 | Criar estados e ações em lote | 1, 4, 5, 6, 14, 20, 21, 27 | Vazio, erro, rede, conflito e permissão tratados; desfazer local e revisão separados. |
| WCCS-021 | F04 | Implementar Classic adapter | 1, 6, 10, 11, 20, 21 | Tipos básicos, seções e larguras refletem schema; campos core mantêm contratos. |
| WCCS-022 | F04 | Integrar normalização e validação final | 1, 6, 10, 11, 20, 21 | POST adulterado falha mesmo sem JS; erros apontam campos corretos. |
| WCCS-023 | F04 | Implementar OrderFieldsService | 1, 6, 10, 11, 20, 21 | Valores tipados, zeros e false preservados; autoridade única por campo. |
| WCCS-024 | F04 | Implementar histórico de valores | 1, 6, 10, 11, 20, 21 | Renomear/arquivar campo não torna pedido antigo ilegível. |
| WCCS-025 | F04 | Tratar lifecycle e refresh | 1, 6, 10, 11, 20, 21 | Refresh preserva valores e cria apenas uma instância por componente. |
| WCCS-026 | F05 | Implementar presets brasileiros | 2, 3, 7, 20, 21, 26 | CPF, CNPJ numérico/alfanumérico, RG, CEP, telefone e endereço com contratos próprios. |
| WCCS-027 | F05 | Integrar IMask localmente | 2, 3, 7, 20, 21, 26 | Colar, apagar, autocomplete, mobile e refresh não quebram cursor/valor. |
| WCCS-028 | F05 | Implementar validadores PHP/JS | 2, 3, 7, 20, 21, 26 | Mesmos fixtures passam/falham; DV não é apresentado como validação de identidade. |
| WCCS-029 | F05 | Implementar endpoint de validação | 2, 3, 7, 20, 21, 26 | Sessão, rate limit, timeout, abort e request ID impedem estado obsoleto. |
| WCCS-030 | F05 | Implementar UX de erro | 2, 3, 7, 20, 21, 26 | Mensagens por campo; primeiro erro focado; regra crítica revalidada no submit. |
| WCCS-031 | F06 | Criar AST e tipos de operadores | 9, 20, 21 | AND/OR e operadores tipados; ciclos e referências inválidas rejeitados. |
| WCCS-032 | F06 | Criar editor de regras | 9, 20, 21 | Regras legíveis, preview de resultado e mensagens de contradição. |
| WCCS-033 | F06 | Implementar evaluators PHP/JS | 9, 20, 21 | Paridade em vazio, zero, false, arrays, contexto e negações. |
| WCCS-034 | F06 | Implementar discard/preserve | 9, 20, 21 | Valor residual oculto é descartado por padrão; required segue estado no servidor. |
| WCCS-035 | F06 | Criar compilador de condições nativas | 9, 20, 21 | Somente condições representáveis são compiladas; demais capacidades ficam explícitas. |
| WCCS-036 | F07 | Implementar native additional fields | 2, 5, 6, 11, 20, 21 | Text/select/checkbox/date com feature detection e políticas de localização/storage. |
| WCCS-037 | F07 | Implementar campos controlados próprios | 2, 5, 6, 11, 20, 21 | Textarea, radio, multiselect, time/datetime e presets mascarados nos slots permitidos. |
| WCCS-038 | F07 | Integrar Store API e validação | 2, 5, 6, 11, 20, 21 | Erro final bloqueia pagamento; payload tipado; retry não duplica gravação. |
| WCCS-039 | F07 | Implementar matriz no admin | 2, 5, 6, 11, 20, 21 | Limites de core, largura, seção e tipo visíveis antes de publicar. |
| WCCS-040 | F07 | Testar edição e lifecycle Blocks | 2, 5, 6, 11, 20, 21 | Troca de endereço/frete, remount e rerender preservam valores sem DOM hacks. |
| WCCS-041 | F08 | Criar storage e tabela operacional | 2, 8, 15, 20, 21 | Privacidade HTTP/CDN comprovada; ambiente sem proteção desativa recurso. |
| WCCS-042 | F08 | Criar upload e tokens por sessão | 2, 8, 15, 20, 21 | MIME, bytes, quota e ownership validados; sessão A não usa token B. |
| WCCS-043 | F08 | Criar componente único/múltiplo | 2, 8, 15, 20, 21 | Progresso, cancelar, remover, retry e erros acessíveis sem upload duplicado. |
| WCCS-044 | F08 | Vincular ao pedido e proteger download | 2, 8, 15, 20, 21 | Vínculo atômico/idempotente; dono autorizado acessa e terceiro recebe negação. |
| WCCS-045 | F08 | Criar retenção e limpeza | 2, 8, 15, 20, 21 | Temporários expiram; draft/falha/efetivo têm regras testadas; cleanups podem repetir. |
| WCCS-046 | F09 | Implementar apresentação Classic | 12, 13, 20, 21, 24 | Layout responsivo do anexo com gap correto; hooks preservados. |
| WCCS-047 | F09 | Implementar apresentação Blocks | 12, 13, 20, 21, 24 | Layout nas regiões suportadas; não clona campos nem componentes de pagamento. |
| WCCS-048 | F09 | Integrar accordion e resumo | 12, 13, 20, 21, 24 | Radios reais; totals/cupons/frete vêm do Woo; teclado e focus funcionam. |
| WCCS-049 | F09 | Homologar gateways e express | 12, 13, 20, 21, 24 | Sandbox, 3DS/redirect, tokens salvos, retry e dados required verificados. |
| WCCS-050 | F09 | Criar opt-in, diagnóstico e fallback | 12, 13, 20, 21, 24 | Ativar/desativar não muda engine nem apaga campos; falha tem retorno seguro. |
| WCCS-051 | F10 | Criar editor de dados do pedido | 10, 20, 21 | CRUD, edição autorizada e validação idêntica nos dois backends de pedidos. |
| WCCS-052 | F10 | Criar exibição ao cliente | 10, 20, 21 | Visibilidade por campo respeitada; conta atual não reescreve pedido passado. |
| WCCS-053 | F10 | Criar e-mails HTML/texto | 10, 20, 21 | Públicos distintos; documentos sem anexos por padrão; links autorizados. |
| WCCS-054 | F10 | Criar API pública de integração | 10, 20, 21 | Contrato autenticado; nenhum dado pessoal aparece em Store API pública. |
| WCCS-055 | F10 | Implementar exportação/eliminação de dados | 10, 20, 21 | Fluxos por titular e política da loja testados, com retenção explicável. |
| WCCS-056 | F11 | Implementar export/import com preview | 18, 20, 21 | JSON versionado, diff, limites, conflitos e rollback testados; sem segredos. |
| WCCS-057 | F11 | Criar migrador ThemeHigh limitado | 18, 20, 21 | Mapeia apenas estruturas comprovadas; unsupported gera relatório e não descarte silencioso. |
| WCCS-058 | F11 | Concluir exemplo de novo tipo | 18, 20, 21 | Código de associado aparece no picker, nos dois checkouts declarados e no pedido. |
| WCCS-059 | F11 | Documentar hooks e contratos | 18, 20, 21 | Ordem, assinaturas, versão, depreciação e componente React demonstrados. |
| WCCS-060 | F11 | Documentar operação do lojista | 18, 20, 21 | Configurar PF/PJ, uploads, layout, restore e diagnóstico sem editar código. |
| WCCS-061 | F12 | Executar suíte de segurança | 13, 15, 16, 17, 20, 21, 27 | IDOR, XSS, CSRF, token replay, payloads, MIME e concorrência exercitados. |
| WCCS-062 | F12 | Executar matriz funcional | 13, 15, 16, 17, 20, 21, 27 | Classic/Blocks/HPOS, tipos de carrinho, clientes e gateways registrados por versão. |
| WCCS-063 | F12 | Executar QA visual e a11y | 13, 15, 16, 17, 20, 21, 27 | 320/375/768/1280/1440px, zoom, foco e reduced motion revisados. |
| WCCS-064 | F12 | Medir performance e estabilidade | 13, 15, 16, 17, 20, 21, 27 | Budgets reportados; zero loops/handlers duplicados; memória após refresh estável. |
| WCCS-065 | F12 | Executar testes de recuperação | 13, 15, 16, 17, 20, 21, 27 | Rollback, schemas antigos, checkout aberto, jobs e extensão desativada tratados. |
| WCCS-066 | F13 | Gerar pacote de release | 20, 21, 25 | ZIP limpo com assets e autoload; instalação sem ferramentas de build. |
| WCCS-067 | F13 | Revisar licenças, nome e distribuição | 20, 21, 25 | Dependências inventariadas; política de atualização, suporte e marca revisada. |
| WCCS-068 | F13 | Preparar manual e release notes | 20, 21, 25 | Limitações, privacy, uninstall, backup e suporte claramente documentados. |
| WCCS-069 | F13 | Executar smoke de instalação/upgrade | 20, 21, 25 | Instalação limpa e upgrade/rollback com pedidos existentes aprovados. |
| WCCS-070 | F13 | Publicar versão e registro de homologação | 20, 21, 25 | Checksums, versões testadas, resultados, changelog e plano de hotfix presentes. |

---

## 3. Verificação de cobertura

- Tarefas no `BACKLOG.json`: 70
- Tarefas listadas nesta página: 70
- Tarefas sem fonte de requisito: **0**
- Divergência entre `MATRIZ-REQUISITOS.md` e `BACKLOG.json` detectada nesta derivação: **nenhuma**

> Limite: este artefato verifica **cobertura documental**, não implementação. Todas as 70 tarefas permanecem com `status: planned` no backlog.
