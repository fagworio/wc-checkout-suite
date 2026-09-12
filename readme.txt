=== WC CheckoutSuite ===
Tags: woocommerce, checkout, checkout fields, checkout blocks, brazil
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 11.1
WC tested up to: 11.1
Stable tag: 1.0.0-rc.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced checkout field management for WooCommerce: field types, Brazilian presets, masks, server-side validation, conditional logic, private uploads and order persistence — on the classic checkout, on Checkout Blocks and on HPOS.

== Description ==

WC CheckoutSuite lets a store design its checkout without taking the checkout away
from WooCommerce. Fields are configured in the administration, published as a versioned
schema, and rendered by whichever checkout the store uses.

* **Field types and Brazilian presets** — text, e-mail, telephone, select, radio,
  checkbox, multiple choice, text area, date and time, with CPF/CNPJ validation for both
  the numeric and the alphanumeric formats, and masks that never replace validation.
* **Server-side validation** — every value is normalized and validated again on the
  server, so a value changed in the browser is rejected rather than stored.
* **Conditional logic** — show or hide a field based on the country, the cart, the
  shipping method, the payment method or another field, with the same rules applied in
  the browser and on the server.
* **Private uploads** — files are stored outside the public directory, bound to an order
  through a token, and served through an authorized download route.
* **Order fields** — what the customer answered is stored on the order and shown on the
  order screen, on the customer's own order page, in My Account and in the order
  e-mails, each with its own exposure policy.
* **HPOS and the classic storage** — both order backends are read and written through the
  order APIs, so the same data means the same thing on either.
* **Blocks and Classic** — the same published schema drives both checkouts, with a
  capability matrix that says in advance what each one can render.
* **Privacy** — the schema is configuration and holds no customer data; order values are
  exported and erased through WordPress's own privacy tools.

== Installation ==

1. Upload the ZIP through *Plugins → Add New → Upload Plugin*, or copy the
   `wc-checkout-suite` folder into `wp-content/plugins/`.
2. Activate the plugin. WooCommerce must be active; without it the plugin stays inactive
   and says why instead of breaking the site.
3. Open **WooCommerce → CheckoutSuite** to configure the fields.
4. Publish the schema when the fields are ready: nothing reaches a customer before it is
   published.

The package is installed as it is shipped. It needs no Composer, no npm and no build
step on the store.

== Frequently Asked Questions ==

= Does it replace the WooCommerce checkout? =

Only if you ask it to. By default this plugin contributes fields and leaves the
checkout, its gateways and its scripts exactly as WooCommerce renders them. The custom
presentation is an opt-in, and turning it off keeps every field and returns the original
checkout.

= Does it collect card or CVV data? =

No. No field of this plugin collects card numbers, security codes or any other payment
instrument, and it ships no payment button of its own. Payment stays with the gateway
you installed.

= What happens to the fields if I deactivate the plugin? =

Nothing is deleted. Deactivation takes the plugin's own scheduled job off the calendar
and leaves the schema, the settings and the order values in place. Deleting the plugin
removes only its own transient state and its privacy-directory verdict; the schema,
the opt-in, the order values and the private files are kept — see the manual.

= Can I undo a schema change? =

Yes. Every publication is kept in a history and can be restored, which publishes the old
content as a new revision. Nothing a customer already submitted is rewritten.

= Does it work without JavaScript? =

The classic fields and their server-side validation work without JavaScript. Masks,
conditional visibility, file uploads and remote validation are JavaScript features and
degrade explicitly instead of pretending to succeed.

== Screenshots ==

Screenshots are not shipped with this release.

== Changelog ==

= 1.0.0-rc.1 =
First release candidate. See CHANGELOG.md in the package for the complete list, and
docs/operations/functional-matrix.md for exactly which combinations were executed and
which were not.

== Upgrade Notice ==

= 1.0.0-rc.1 =
First published version. Nothing to migrate from.
