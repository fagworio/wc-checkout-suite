# Auditoria estática da referência
## WC CheckoutSuite · 11/09/2026

**Arquivo:** `woo-checkout-field-editor-pro.2.2.0.zip`  
**SHA-256:** `f790d5870b1da2a1d0c13306162a43c7ee68ec6705df0d9a0f82e3965f956b9b`  
**Método:** inspeção estática dos arquivos extraídos; sem executar o PHP ou instalar em WordPress.  
**Limite:** não é auditoria de segurança dinâmica nem certificação de integridade/funcionalidade do fornecedor.

## Achados comprovados no ZIP

| Evidência | Caminho relativo no plugin | Linhas |
|---|---|---|
| Nome do plugin, versão 2.2.0, licença e metadados declarados | `checkout-form-designer.php` | 3–13 |
| Compatibilidade HPOS declarada pelo fornecedor | `checkout-form-designer.php` | 54–58 |
| Factory fechada em condições por tipo | `includes/utils/class-thwcfd-utils-field.php` | 374–435 |
| Registro de additional fields para endereço | `block/class-thwcfd-block.php` | 55–101 |
| Metadados e prioridade na configuração Blocks | `block/class-thwcfd-block.php` | 79–95 |
| Validação e hooks Classic | `public/class-thwcfd-public-checkout.php` | 49–66 |
| Uso de order CRUD para valores | `public/class-thwcfd-public-checkout.php` | 633–650 |
| Ordenação administrativa com sortable | `admin/assets/js/thwcfd-admin.js` | 250 em diante |
| Separação entre versão gratuita e convite à Pro | `readme.txt` | descrição e seção “FREE VERSION” |

As linhas são referências do arquivo extraído, não declarações de suporte testadas por este trabalho.

O pacote contém 132 entradas de ZIP (incluindo diretórios), com 1.412.966 bytes descompactados declarados. A versão 2.2.0 foi lida no cabeçalho e no readme. O nome “-pro” no arquivo/slug não deve ser usado como evidência isolada de todos os recursos Premium.

## Classes referenciadas sem arquivo de modelo correspondente presente

A factory referencia, entre outras, `WCFE_Checkout_Field_File`, `WCFE_Checkout_Field_Multiselect`, `WCFE_Checkout_Field_DatePicker`, `WCFE_Checkout_Field_TimePicker`, `WCFE_Checkout_Field_CheckboxGroup`, `WCFE_Checkout_Field_Hidden`, `WCFE_Checkout_Field_Password` e `WCFE_Checkout_Field_Label`.

A relação dos arquivos do ZIP não contém os respectivos modelos sob `includes/model/fields/`. Isso limita o que podemos extrair como implementação concreta desse pacote. Não foi feito um teste que demonstre a execução dessas ramificações; portanto, não se conclui aqui que todo o plugin falha ao funcionar.

## Consequências para o WC CheckoutSuite

Manter os conceitos de definição, seção, adaptadores e integração com order CRUD. Substituir a factory fechada por registro extensível. Não copiar estrutura de options de terceiros como schema interno definitivo. Não depender de APIs não documentadas só porque constam no código de referência.

Compatibilidade HPOS declarada é diferente de compatibilidade comprovada. Testar o novo plugin com HPOS ligado e sincronização de posts desligada, além do modo legado.

## HTML de referência

Arquivo: `index(20260911-132619).html`.

Layout: formulário e resumo em colunas; accordion de pagamento; campos de endereço e contato. Os métodos de pagamento e valores são mockados; o script impede submit e mostra uma mensagem, em vez de processar pedido.

Problema de alinhamento observado no CSS: `.field + .field` aplica margem superior também em irmãos dentro de `.row`. Um segundo/terceiro input recebe offset mesmo estando na mesma linha de grid. O projeto novo usa wrappers e `gap` no contêiner.

**Importante:** nenhum gateway real é criado a partir desse HTML. Ele é referência visual, não implementação de checkout.

## Fontes externas

A documentação oficial consultada está em `fontes.json` e no capítulo 28 de `ROADMAP.md`. A situação atual das APIs e do CNPJ é separada dos achados específicos do ZIP.
