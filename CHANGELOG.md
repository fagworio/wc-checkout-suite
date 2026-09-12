# Changelog

All notable changes to WC CheckoutSuite are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) with one addition that
matters for a WordPress plugin: the **published schema** is a public interface, so a
change to its shape is a major change even when no PHP API moved.

## [1.0.0-rc.1] — 2026-09-11

The first published version. It is a release candidate and not the final 1.0.0 for one
recorded reason: the payment rows of the functional matrix are untested, because no
gateway in this store has sandbox credentials. Everything else in
`docs/operations/functional-matrix.md` states, row by row, what was executed and what was
not.

### Added

- **Schema editor** — create, edit, duplicate, archive, enable, disable and order fields,
  with a draft/publication model, a diff before publishing, a conflict check and a
  publication history that can be restored.
- **Field types** — text, e-mail, telephone, URL, number, password, text area, select,
  radio, checkbox, multiple choice, date and time, plus any type contributed by another
  plugin through the public registration hook.
- **Brazilian presets and masks** — CPF and CNPJ in both the numeric and the
  alphanumeric formats, postal code, telephone, state registration and the document
  presets, with check digits validated on the server, never only in the browser.
- **Validation** — normalization, validators declared per type, error messages per field,
  a summary of errors, and a remote validation endpoint that answers against the
  published revision.
- **Conditional logic** — show/hide rules over country, state, cart items, cart
  categories, cart total, shipping method, payment method, customer login state and
  other fields, with cycle detection and a hidden-value policy per field.
- **Private uploads** — storage outside the public directory, per-order binding,
  retention rules, an authorized download route with a per-session rate limit, and a
  fail-closed verdict when the environment serves the private directory over HTTP.
- **Order data** — the answers stored on the order, shown on the order screen, on the
  customer's order page, in My Account and in the order e-mails, with a per-field
  exposure policy, plus export and erasure through WordPress's own privacy tools.
- **HPOS and classic order storage** — both backends supported through the order APIs.
- **Classic and Blocks** — the same published schema drives both checkouts, with a
  capability matrix and a report of everything an adapter could not render.
- **Import, export and preview** of the schema as a JSON file, with the same validation
  the editor applies.
- **Administration** — the Suite screen with its own design tokens, scoped so nothing of
  it reaches any other screen.

### Security

- Capability checks on every administrative route, nonces on every write, and a
  per-order authorization check on the customer-facing and integration routes.
- Private uploads are never returned as public URLs; the download route authorizes per
  order and per session.
- The schema holds configuration only: no customer value is written to it.

### Known limitations at this version

- The **Checkout Sidebar** is not part of this version, by decision (ROADMAP.md
  section 27), and no incomplete version of it ships.
- A field type contributed by another plugin that is **deactivated** keeps its data and
  is reported by the adapters instead of being rendered as something else; it needs its
  extension active to be drawn again.
- The **classic checkout** is not served by the store where this version was validated,
  so its rows in the functional matrix are covered at the seam and by the unit suite
  rather than by a browser.
- **Payment gateway homologation** is empty: every gateway row is untested.
- The custom presentation is opt-in and requires that every offered gateway be
  homologated for the presentation; until then the plugin deliberately steps aside and
  leaves WooCommerce's own checkout in place.

[1.0.0-rc.1]: https://example.invalid/wc-checkoutsuite/releases/1.0.0-rc.1
