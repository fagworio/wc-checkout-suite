# ADR-0002 — Armazenamento privado de arquivos enviados

- **Status:** Aceito
- **Data:** 11/09/2026
- **Tarefa:** WCCS-005 (F00) · **Fase de impacto:** F08
- **Decisão já fixada em:** `ROADMAP.md §12` e `§20`
- **Evidência de apoio:** WCCS-001 (ambiente), `docs/compatibility.json`

## Contexto

O upload de documentos é o subsistema de maior risco do produto. O `ROADMAP.md §25` classifica
"Arquivo privado acessível via CDN" como risco **Crítico** com mitigação *fail-closed*.

A verificação de ambiente (WCCS-001) estabeleceu dois fatos que determinam esta decisão:

1. **O servidor web é nginx 1.22.1**, não Apache. Um `.htaccess` **não protege nada** aqui — o
   `§12` já alerta explicitamente sobre isso.
2. **`wp-content/uploads` está sob o webroot** (`htdocs` é o document root do vhost). Gravar arquivos
   privados ali os torna publicamente acessíveis por URL direta.

## Decisão

1. **Armazenamento padrão fora do document root.** É o único caminho aceito por padrão.
2. **Fallback sob `uploads/` proibido sem prova.** Só é admissível se o bloqueio HTTP for **verificado
   empiricamente no servidor real** (nginx, com a configuração em uso, e também na CDN se houver).
   `.htaccess` isolado **não** é prova e não será aceito como tal.
3. **Se a inacessibilidade não puder ser garantida, o recurso de upload é desabilitado** e o admin
   explica a causa. Nunca "habilitar e avisar".
4. **Registro operacional em `{$wpdb->prefix}wccs_uploads`**: ID, hash do token, hash de
   proprietário/sessão, estado, chave privada, nome original sanitizado, MIME, bytes, created/expires,
   `order_id` e política. As **definições** de campo continuam em options.
5. **Token opaco e imprevisível.** Bytes de arquivo **nunca** entram em order meta e o arquivo **nunca**
   se torna anexo de mídia pública.
6. **Autorização de download** por capacidade administrativa **ou** por dono autenticado do pedido /
   sessão verificada. **Nunca** autorizar por `order_id` isolado.
7. **Lista de permissão por padrão.** Não aceitar executáveis, HTML, SVG bruto nem arquivos compactados
   por padrão. Inspecionar conteúdo, não só a extensão e o MIME declarado.
8. **Retenção:** uploads temporários não vinculados expiram (24h por padrão, configurável); pedidos em
   rascunho, pagamentos falhos e pedidos efetivos têm políticas distintas. Limpeza idempotente e agendada.
9. **Object storage** exige bucket privado e acesso assinado/autorizado. Não presumir que um plugin de
   offload para CDN pública preservará o sigilo.

## Consequências

- A ativação do recurso deve executar um **autoteste de acessibilidade HTTP** e recusar-se a habilitar
  quando não puder provar o bloqueio. O resultado do autoteste é diagnóstico visível ao lojista.
- O caminho de armazenamento padrão fica **fora da raiz do plugin** e fora do webroot. Sua criação é
  responsabilidade de implantação, não do pacote de release.
- O `UploadService` precisa de um `StorageProvider` abstrato desde o início, para que hospedagens sem
  diretório privado possam usar object storage privado em vez de cair no `uploads/`.
- Convidadas e convidados exigem vínculo real à sessão WooCommerce: o nonce padrão de visitante **não**
  é identidade individual (`§12`, referência S8).

## Alternativas consideradas e rejeitadas

- **Gravar em `wp-content/uploads` com `.htaccess`**: rejeitada — o servidor é nginx; a proteção não
  existiria. Emitir um aviso no admin não é mitigação suficiente para risco Crítico.
- **Gravar em `uploads/` e confiar em nome aleatório**: rejeitada — "URL difícil de adivinhar" não é
  controle de acesso, e o arquivo continuaria alcançável por CDN e por indexação.
- **Guardar os bytes em order meta**: rejeitada — inviabiliza retenção, limpeza e controle de acesso, e
  contradiz o `§13`.
- **Desabilitar upload permanentemente**: rejeitada como padrão — o recurso é requisito [R1]; a decisão
  correta é *fail-closed por ambiente*, não remoção do produto.

## Como verificar conformidade

- **WCCS-041:** prova de inacessibilidade com requisição HTTP real ao arquivo, no nginx em uso; cenário
  de ambiente sem proteção desliga o recurso.
- **WCCS-042:** sessão A não consegue usar token de B; MIME, bytes e quota validados no servidor.
- **WCCS-044:** dono autorizado acessa e terceiro recebe negação; vínculo atômico e idempotente.
- **WCCS-061:** IDOR, replay de token, path traversal, dupla extensão, bomba de compactação e concorrência
  exercitados explicitamente.
