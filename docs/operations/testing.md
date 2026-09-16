# Testes do WCCS

Os testes que dependem de WordPress, WooCommerce, banco de dados ou navegador real devem
ser executados com o Devilbox ativo. Nesta instalação, a raiz do ambiente é:

```text
/home/joaofagner/workfolder/devilbox
```

## Preparar o ambiente local

Em um terminal, iniciar os serviços necessários antes de executar qualquer E2E ou prova de
integração:

```bash
cd /home/joaofagner/workfolder/devilbox
docker compose up -d httpd php mysql
docker compose ps
```

Confirmar que a aplicação responde antes de abrir o navegador:

```bash
curl -I --max-time 10 \
  'http://wpagf.dvl.to:8080/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields'
```

Durante o desenvolvimento, habilitar o versionamento por timestamp no `wp-config.php`:

```php
define( 'WCCS_DEV_MODE', true );
```

Com isso, os assets do admin usam `?ver=dev-<timestamp>` novo em cada carregamento, evitando que
uma aba mantenha um bundle antigo durante o desenvolvimento. Em produção, não definir essa
constante: o plugin usa `WCCS_VERSION`, evitando uma nova chave de cache a cada requisição.
`WP_ENVIRONMENT_TYPE=local|development` também ativa o modo de desenvolvimento; `WP_DEBUG` é
usado como fallback.

Se o domínio local não resolver, verificar o DNS/hosts usado pelo Devilbox e a porta configurada
no `.env`. O teste deve ser executado no host que possui o Chrome e o Docker do Devilbox.

## Testes JavaScript e build

Esses comandos não precisam do Devilbox:

```bash
cd /home/joaofagner/workfolder/devilbox/data/www/wpagf/htdocs/wp-content/plugins/wc-checkout-suite
npm run check-types
npm run lint:js -- --no-fix
npm run test:unit-js -- --runInBand --silent
npm run build
```

## Testes funcionais no Admin

Depois de iniciar o Devilbox, executar a navegação real do editor por destino:

```bash
cd /home/joaofagner/workfolder/devilbox/data/www/wpagf/htdocs/wp-content/plugins/wc-checkout-suite
npm run test:e2e:design
```

O fluxo acessa `http://wpagf.dvl.to:8080/wp-admin/admin.php?page=wccs-checkoutsuite&section=fields`,
captura as seis superfícies, confirma que a lateral de Minha conta veio do WooCommerce, abre
“Detalhes da conta” para conferir os campos nativos (incluindo a alteração de senha), abre
“Endereços” para conferir o aviso de Cobrança/Entrega, cria um container temporário e verifica o
estado de alterações não salvas. Em “Detalhes da conta”, também edita o rótulo de um campo nativo
e exercita ocultar/restaurar a linha. Ele requer uma sessão administrativa. Para uma sessão
fora do perfil padrão, informar os cookies:

```bash
WCCS_COOKIE='nome=valor' \
WCCS_AUTH_COOKIE='nome=valor' \
npm run test:e2e:design
```

Também é possível deixar o Playwright fazer o login com uma conta administrativa temporária:

```bash
WCCS_ADMIN_USER='usuario-admin' \
WCCS_ADMIN_PASSWORD='senha-local' \
npm run test:e2e:design
```

Se nenhuma credencial nem cookie forem informados, o teste falha de forma explícita ao detectar o
redirecionamento para `wp-login.php`.

Para provar especificamente a criação de um upload na Minha conta, usar uma conta administrativa
temporária ou uma sessão de teste:

```bash
WCCS_ADMIN_USER='usuario-admin' \
WCCS_ADMIN_PASSWORD='senha-local' \
npm run test:e2e:account-file
```

Esse fluxo cria uma nova página apenas no rascunho do navegador, adiciona um campo `File` com
extensão `pdf`, confirma que ele aparece na seção e verifica que uma nova abertura da modal volta
para `Todos os campos`. Ele não salva nem publica a configuração.

As capturas são gravadas em `tmp/wccs-visual/`. O teste não clica em Salvar, portanto a alteração
temporária fica apenas na sessão do navegador.

Se o editor mostrar o aviso **Há uma edição local não salva preservada nesta sessão**, páginas e
campos criados nessa sessão ainda não estão no WordPress. Para remover esse conteúdo, clique em
**Descartar edição local** no próprio aviso e confirme. Isso limpa `sessionStorage` e restaura o
último rascunho confirmado pelo servidor. O equivalente manual, caso a interface antiga esteja
em cache, é executar no console da mesma aba:

```js
sessionStorage.removeItem('wccs-local-draft');
location.reload();
```

Essa ação descarta todas as alterações não salvas da sessão, não apenas uma página individual.
Uma página já salva pode ser removida pelo editor: selecione-a, abra **Ações da página**, use o
botão vermelho no rodapé, confirme as dependências e clique em **Salvar alterações**.
Nas versões atuais, páginas personalizadas também exibem **Remover** diretamente no cabeçalho,
ao lado de **Ações da página**; a confirmação de dependências continua sendo aplicada quando
necessário.

## Testes de integração PHP

As provas PHP usam o container do Devilbox e o caminho montado dentro dele:

```bash
docker exec -u devilbox devilbox-php-1 bash -lc \
  'cd /shared/httpd/wpagf/htdocs/wp-content/plugins/wc-checkout-suite && composer check'
```

Quando um teste precisar preparar fixtures do WordPress, executar o harness indicado no próprio
arquivo ou usar `docker exec` com `--path=/shared/httpd/wpagf/htdocs`.

## Encerrar o ambiente

Depois dos testes, os serviços podem ser interrompidos com:

```bash
cd /home/joaofagner/workfolder/devilbox
docker compose stop httpd php mysql
```
