# Example: a field type contributed by an external plugin

This directory is a documentation artefact. It proves that a third-party plugin
can add a field type to WC CheckoutSuite **without editing a single core file**.

## What it demonstrates

| Part of the contract | Where |
|---|---|
| Registration through the public hook | `wccs-example-field-type.php` |
| Stable key, label and contract version | `src/MembershipCodeType.php` |
| Value schema and settings schema | `src/MembershipCodeType.php` |
| Declared capabilities | `supports()` |
| Normalization before validation | `normalize()` |
| Server-side validation with a settings-aware rule | `validate()` |

## How to try it

1. Activate WC CheckoutSuite.
2. Copy this directory to `wp-content/plugins/` and activate it.
3. Read the registered types:

```bash
wp eval 'print_r( \WCCheckoutSuite\Domain\Registries::instance()->types()->labels() );'
```

`example.membership-code` appears alongside the core types, and
`\WCCheckoutSuite\Domain\Registries::instance()->types()->source_of( "example.membership-code" )`
returns `wccs-example`.

4. Confirm that a definition using it validates, and that a value is normalized
   and validated by the contract the core itself uses:

```bash
wp eval '
$r = \WCCheckoutSuite\Domain\Registries::instance();
$t = $r->types()->type( "example.membership-code" );
$c = new \WCCheckoutSuite\Domain\Fields\FieldContext( array(), "classic", array( "prefix" => "WCCS-" ) );
var_dump( $t->normalize( " wccs-42 ", $c ) );        // string(7) "WCCS-42"
var_dump( $t->validate( "XX-1", $c )->error_codes() ); // ["missing_prefix"]
'
```

## What it deliberately does not do

It ships **no renderer**. Declaring that an adapter can draw this field would be
a capability claim, and renderers only exist once F04 (Classic) and F07 (Blocks)
deliver them. Until then the capability query answers "unsupported" for every
adapter, which is the honest answer.

## Where this is heading

WCCS-058 (F11) turns this into the documented example, with the Classic and
Blocks renderers attached and the field visible in a real checkout and in the
order.
