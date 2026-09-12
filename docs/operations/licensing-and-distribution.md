# Licences, naming and distribution

**Task:** WCCS-067 · "Revisar licenças, nome e distribuição"
**Phase:** F13 · Release 1.0 e operação comercial

## 1. What this document decides, and what it does not

It decides the three things a distributed plugin cannot ship without: the licence of the
code, the inventory of what the code depends on, and the naming that identifies it. It
**records** the commercial decisions — price, channel, who supports it and for how long —
as decisions for the product owner, because inventing them in a file would be inventing
an offer.

## 2. The licence: GPL-2.0-or-later

The plugin declares `License: GPL-2.0-or-later` and ships the notice in `LICENSE`.

This is not a commercial preference, it is the ecosystem's requirement: WordPress itself
is GPL-2.0-or-later, and a plugin distributed to run inside it has to be GPL-compatible
to be installable at all. Two consequences are worth stating because they are often
confused with restrictions the licence does not have:

- **Selling the plugin is compatible with GPL.** What the licence governs is the freedom
  of the code's recipients, not whether the distributor may charge for it.
- **The commercial licence check is a separate matter, and there is none.** ROADMAP.md
  section 27 requires that "expiração/falha de licença comercial jamais bloqueia a compra
  ou remove validadores/arquivos de pedidos existentes", and the strongest way to satisfy
  that is to have nothing to expire: **no code in this plugin contacts a licensing
  service, and there is no licence check of any kind.** Verified rather than promised —

  ```
  grep -rni 'licen[cs]e' src/ resources/ --include=*.php --include=*.js   # nothing but the licence header text
  grep -rn 'wp_remote_' src/                                              # one call, and it tests this store's own private directory
  ```

  The only remote call in the plugin is `PrivateStorage` asking this store's own web
  server whether the private directory is served over HTTP — the check that decides
  whether uploads are accepted at all (WCCS-041), against the store's own URL.

## 3. The dependency inventory

Reproduce with `php bin/licence-inventory.php` (add `--json` for the machine-readable
form). It reads `composer.lock`, `package-lock.json` and `composer.json`, so the answer
follows the lock files rather than anyone's memory. At this release:

| group | packages | what it means for the package |
|---|---|---|
| **runtime** | **0** | the shipped ZIP needs neither Composer nor npm: the runtime autoloader is the plugin's own, the front end uses globals WordPress already prints, and the bundles ship compiled |
| build-time | 1507 npm packages | produce `build/`; never shipped (`node_modules/` is excluded from the ZIP) |
| test-time | 41 Composer packages | run the suites and the static analysis; never shipped (`vendor/` is excluded) |

The build-time and test-time sets are dominated by MIT (1205), ISC (83), Apache-2.0 (93)
and BSD variants, with the WordPress tooling itself under **GPL-2.0-or-later** (26
packages), which is the same licence as the plugin. Two entries deserve naming rather
than burying:

- **`svg-tags` (build-time, transitive) declares no licence.** It is a data package of SVG
  element names, pulled in by the lint tooling, and it does not reach the store: nothing
  from `node_modules/` is inside the release. A dependency with no declared licence would
  be a problem to *ship*; it is not one to *build* with, and the release check asserts it
  is not shipped (`bin/check-release.php`).
- **LGPL-3.0-or-later appears in test-time only** (`phpcompatibility/*`,
  `phpcsstandards/*`). Those run the coding-standard checks and are absent from the ZIP.

Because the runtime set is empty, the released package carries no third-party code and
therefore needs no third-party notices file. The moment a runtime dependency is added,
this section and the package's notice file have to change together, and the release check
should be extended to look for it.

## 4. Naming

| thing | value | where it is decided once |
|---|---|---|
| Plugin name | `WC CheckoutSuite` | the plugin header; the readme's title must match |
| Folder / slug | `wc-checkout-suite` | the archive root built by `bin/build-release.php` |
| Main file | `wc-checkoutsuite.php` | the header and the archive |
| Text domain | `wc-checkoutsuite` | `WCCS_TEXT_DOMAIN`, the header, the `languages/` file name |
| REST namespace | `wc-checkoutsuite/v1` | `WCCS_REST_NAMESPACE` |
| Storage and option prefix | `wccs_` | every constant that writes state |

The four identifiers are not interchangeable and must not drift: the folder name is what
WordPress creates on install, the text domain is what every translated string resolves
against, and the prefix is what makes `uninstall.php` able to remove exactly its own
state. `docs/validation/WCCS-058.md` and the release check cover the pairs that would
break silently.

**Still open, and owned by the product owner:** the WordPress.org slug and contributor
account, the Author and Author URI headers, and the Plugin URI. They are distribution
identities, not code, and the release ships without them on purpose — a package that
invents an author is worse than one that omits it.

## 5. Update and support policy

What the release promises, and what it deliberately does not:

- **Updates are files, not a service.** A new version is a ZIP installed over the old
  one. The plugin's runtime autoloader means the ZIP needs no build step, and the update
  path is `bin/build-release.php` → `bin/check-release.php` → the checksums in
  `docs/operations/release-1.0.0-rc.1.md`.
- **No SaaS licensing for the MVP**, as section 27 requires. There is nothing to call
  home and nothing to expire.
- **An update never deletes fields or orders.** The schema is versioned and a document
  from a newer build is refused whole rather than read in part (WCCS-065), so a downgrade
  cannot half-configure a checkout.
- **Deactivation is not deletion.** Deactivation clears the plugin's own scheduled job and
  touches nothing else; deletion removes only its transients and its privacy-directory
  verdict (`uninstall.php`). Orders keep their values and the private files stay recorded.
- **Support channels are not defined in this release**, because they are a commercial
  decision: who answers, in what language, with what response time. What the release does
  provide is the material a support conversation needs — the manual, the functional matrix
  that says what was tested, and the compatibility record that says what was verified
  against which version.

## 6. The 1.0 gate, verified

The F13 gate has three clauses, and two of them are checkable here:

| clause | evidence |
|---|---|
| Release 1.0 distributable | `dist/wc-checkoutsuite-1.0.0-rc.1.zip`, checked by `bin/check-release.php`: complete, self-contained, 125 PHP files parsing, 120 classes resolving through the plugin's own autoloader |
| **Sidebar ausente** | `grep -rni sidebar src/ resources/` returns the administration's own navigation tokens (`--wccs-sidebar-surface`, the admin app's sidebar) and two comments in the Blocks stylesheet about WooCommerce's order-summary column. There is no Checkout Sidebar: no task, no component, no route, no setting |
| cobrança independente de servidor de licença | section 2 above: no licence check exists, and the only remote call asks this store about its own private directory |
