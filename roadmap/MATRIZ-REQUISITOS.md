# Matriz de rastreabilidade

WC CheckoutSuite · Planejamento 1.0

O anexo determina o escopo. Propostas adicionais são identificadas como tal. Todos os itens estão **planejados**, não implementados.

| Fonte / seção | Cobertura | Capítulos do roadmap | Fases |
|---|---|---|---|
| Objetivo e escopo principal | Campos padrão e próprios; arquitetura independente | 1–4 | F00–F04 |
| 1. Tipos de campos | Tipos básicos, escolhas, datas, informativos, arquivo e Brasil | 5–6 | F01/F05/F07/F08 |
| 2. Máscaras de entrada | IMask, configuração e validação separada | 9–10 | F05 |
| 3. Gerenciamento dos campos | CRUD, labels, opções, required, classes, larguras e seção | 4/16 | F03 |
| 4. Ordenação Drag and Drop | Ordem visual e teclado; diferença Classic/Blocks | 7–8/16 | F03/F07 |
| 5. Seções do checkout | Billing, Shipping, Account, Order e customizadas | 4/7–8 | F03/F04/F07 |
| 6. Validação AJAX | Erros por campo, local vs remoto e servidor autoritativo | 10 | F05 |
| 7. Upload de arquivos | MIME, tamanho, múltiplos, privado, pedido, acesso e retenção | 12 | F08 |
| 8. Conditional Logic | AND/OR, operadores, contextos e ocultos | 11 | F06 |
| 9. Persistência | Pedidos, conta, e-mail e APIs com CRUD/HPOS | 13–14 | F04/F10 |
| 10. Classic e Checkout Blocks | Domínio comum, adaptadores e limitações | 7–8 | F00/F04/F07 |
| 11. Checkout customizado | Layout opcional mantendo engine e hooks | 15 | F09 |
| 12. Formas de pagamento | Accordion usando gateways registrados | 15 | F09/F12 |
| 13. Interface administrativa | Editor, configuração, layout e settings modernos | 16–17 | F02/F03 |
| 14. Segurança | Sanitização, escaping, permissões, nonce, uploads e manipulação | 12/19–20 | F01/F08/F12 |
| 15. Performance | Assets locais sob demanda, cache e AJAX mínimo | 21 | F12 |
| 16. Compatibilidade | WordPress/Woo/PHP, HPOS, Blocks, temas/gateways/cache | 21/24 | F00/F12 |
| 17. Extensibilidade | PHP/JS, registries, API pública e novo tipo | 6/19 | F01/F11 |
| 18. Arquitetura | Módulos, PHP, JS e diretórios | 3/18/22 | F01 |
| 19. Resultado esperado | Especificação completa antes de implementar | 1–28 | Todo o roadmap |
| 20. Roadmap | Fases, componentes, dependências, riscos e aceite | 23 | F00–F13 |
| Referências obrigatórias | ZIP, HTML, requisitos e documentação oficial | 2/28 + auditoria | F00 |
| Nova direção: nome | WC CheckoutSuite em todo o projeto | Identidade/17 | F00/F02 |
| Nova direção: identidade moderna | Design system e protótipo admin/frontend | 16–17 + PROTOTIPO.html | F02/F09 |
| Nova restrição: Checkout Sidebar futuro | Excluído da V1; sem implementação antecipada | 1/27 | F00/F13 |
| Ajuste técnico verificado | CNPJ alfanumérico desde o início | 2/5/9 | F05 |
| Robustez adicional proposta | Revisões, conflitos, políticas de dados e recuperação | 13/19–20/25 | F01/F03/F12 |

## Limites explícitos

Paridade de tipos não equivale a reordenação livre de campos core no Blocks. O tipo `date` depende da versão suportada. Upload privado exige prova do ambiente. Campos adicionais nativos têm política de persistência própria. Checkout Sidebar está fora de todas as fases da V1.
