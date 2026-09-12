# Release record — WC CheckoutSuite 1.0.0-rc.1

**Task:** WCCS-070 · "Publicar versão e registro de homologação"
**Phase:** F13 · Release 1.0 e operação comercial
**Date:** 11/09/2026

## 1. The artefact

| | |
|---|---|
| Version | `1.0.0-rc.1` |
| Archive | `dist/wc-checkoutsuite-1.0.0-rc.1.zip` |
| Size | 400.8 KB, 150 files |
| **SHA-256** | `e8c9c9b665b5a6de68c967fe77224d55f56f7a816ca5963d6835ae98263f489b` |
| Root folder inside the archive | `wc-checkout-suite/` |
| Contents | `src/` 120 files · `resources/` 9 assets · `build/` 8 · `docs/` 6 · `languages/` 1 · root 5 |

Reproduce it exactly:

```bash
npm run build                      # the compiled bundles are not versioned
php bin/build-release.php          # writes dist/ and prints the SHA-256
php bin/check-release.php dist/wc-checkoutsuite-1.0.0-rc.1.zip
php bin/smoke-package.php <extracted-dir>   # a bare PHP process, no Composer, no WordPress
sha256sum dist/wc-checkoutsuite-1.0.0-rc.1.zip   # must be the value above
```

Two properties of that checksum are worth stating, because both were made true rather
than assumed:

- **The release record is not inside the archive.** A document that states the archive's
  own checksum cannot be part of the archive without invalidating itself, so
  `bin/build-release.php` excludes `docs/operations/release-*.md` and the record lives in
  the repository beside the package it describes.
- **Rebuilding the same tree reproduces the same bytes.** The manifest entry carries the
  main plugin file's timestamp instead of the moment of the build, so two builds a second
  apart are byte-identical and the checksum above can be checked by anyone with the tree.
  A fresh checkout may carry different file timestamps, so the bytes can differ across
  machines while the content does not — which is why `bin/check-release.php` verifies the
  content properties as well as the checksum. What is reproducible is the content: `bin/check-release.php` asserts the
required files, the autoloader mapping and that every PHP file parses, and the file list
above is what a release is compared against.

## 2. Why a release candidate and not 1.0.0

The version is `1.0.0-rc.1` for one recorded reason: **the payment rows of the functional
matrix are untested.** No gateway in this store has sandbox credentials
(`SANDBOX-PAYMENT`), so every gateway answers `undecided` and the custom presentation
deliberately steps aside rather than presenting a checkout around a gateway nobody ran.
Everything else in `docs/operations/functional-matrix.md` states, row by row, what was
executed and what was not.

Calling this 1.0.0 would be a claim the evidence does not support; calling it a release
candidate is the same artefact with an honest label, and it is distributable for
homologation exactly as it is.

## 3. Versions tested

| component | version |
|---|---|
| WordPress | 7.1 |
| WooCommerce | 11.1.0 |
| PHP | 8.2.1 (opcache off in the benchmark) |
| Order storage | HPOS authoritative (HPOS-off is the recorded gap) |
| Theme | Storefront 4.6.2 (classic) |
| Checkout served by the validated store | Checkout Blocks |
| Database | MariaDB 10.6.12 |
| Node / npm (build only) | 22.21.1 / 10.9.4 |
| Browser used for the observations | Google Chrome, driven by Playwright 1.63.0 |

The floors in the plugin header (`Requires at least: 7.1`, `Requires PHP: 8.2`,
`WC requires at least: 11.1`) are the versions above, which is the only runtime this
project could verify locally. They are provisional and are ratified by the compatibility
record (`docs/compatibility.json`).

## 4. Results — the gates at this version

| gate | result |
|---|---|
| PHP coding standards | clean, exit 0 |
| PHP static analysis | no errors |
| PHP unit suite | **402 tests, 1453 assertions**, 0 failures |
| JavaScript suite | **612 tests, 38 suites**, 0 failures |
| JavaScript lint / types / build | 0 errors / 0 errors / 3 bundles |
| Integration proofs | **58 harnesses, 1357 assertions, 0 failures** |
| Browser — delivery and a11y (WCCS-063) | 50 assertions on the store, 37 on the component, 0 failures |
| Browser — performance and stability (WCCS-064) | 11 assertions, 0 failures |
| Performance budgets (WCCS-064) | Classic 22.7 KB gzip / 80 KB · Blocks 17.1 KB / 120 KB · 0 requests per keystroke · additional validation p95 below the measurement noise |
| Recovery (WCCS-065) | 28 assertions, 0 failures |
| Release package (WCCS-066/069) | complete, self-contained, 125 PHP files parsing, 120/120 classes resolving with a bare PHP process |

## 5. Homologation record — what is approved and what is not

**Approved for this release:** the administration and the schema lifecycle; the field
types and the Brazilian presets; masks and server-side validation; conditional logic;
private uploads with their retention and download authorization; the order data and its
four projections; HPOS authoritative; the Blocks checkout, its capability matrix and the
delivery of the payload and the presentation; the recovery situations; the package
itself.

**Not approved, and therefore not claimed:**

1. **Every payment gateway.** No sandbox run happened; all rows are `undecided`. Payment
   homologation is explicitly *not* implied by a compiled bundle, a badge or a quiet
   console (ROADMAP §24).
2. **The classic checkout in a browser.** The validated store serves Checkout Blocks; the
   Classic rows are covered at the seam and by the unit suite.
3. **HPOS switched off**, because toggling the store's order storage is a site setting
   outside this plugin's root.
4. **A second store**, which WCCS-062's staging exercise needs.
5. **The private directory's HTTP protection** in this environment: the plugin detects
   that the directory is served over HTTP and refuses uploads, and that refusal is itself
   the tested behaviour.

## 6. Rollback plan

1. **A schema change** is rolled back inside the plugin: *Histórico → Restaurar* publishes
   the old content as a new revision. Verified in `docs/validation/WCCS-065.md`.
2. **A plugin version** is rolled back by reinstalling the previous ZIP over this one.
   Nothing in the schema is versioned by the plugin in a way that a downgrade breaks: a
   document written by a newer build is refused **whole** and the store keeps its own
   checkout, while the orders keep every value they hold (verified in WCCS-069 and 065).
3. **Data** is never removed by an update or a deactivation. Deletion of the plugin keeps
   the schema, the opt-in, the order values and the private files (`uninstall.php`).
4. **Before any rollback**, take the two backups that matter: the database and
   `wp-content/wc-checkoutsuite-private/`. The manual says the same in §10.

## 7. Hotfix plan

A fix that has to ship before the final 1.0.0 is cut as a new release candidate and never
by editing the published ZIP:

1. Fix on `main` with its proof, as every other change in this project.
2. Bump the version in **two places that must agree** — the `Version:` header and
   `WCCS_VERSION` in `wc-checkoutsuite.php`; `bin/build-release.php` refuses to build when
   they disagree, and so does `bin/check-release.php` when checking an archive.
3. Add the entry to `CHANGELOG.md` and to the readme's changelog section.
4. `npm run build` → `php bin/build-release.php` → `php bin/check-release.php` →
   `php bin/smoke-package.php` → the full sweep.
5. Record the new checksum and the versions tested in a new release record next to this
   one, and keep this one: the record of what was distributed is not rewritten.
6. **A database migration is never part of a hotfix.** If a change needs one, it needs a
   release with its own recovery proof.

## 8. The F13 gate

| clause | state |
|---|---|
| Release 1.0 distributable | **met** — the package above, verified by `bin/check-release.php` and by a bare-PHP smoke run |
| Sidebar ausente | **met** — `grep -rni sidebar src/ resources/` finds only the administration's own navigation tokens and two comments about WooCommerce's order-summary column |
| Cobrança continua independente de servidor de licença | **met** — no licence check exists anywhere in the plugin, and the only remote call asks this store about its own private directory |

What the gate does not claim is that 1.0.0 final is ready: the payment rows of the matrix
remain untested, and that is the one thing between this candidate and the final version.
