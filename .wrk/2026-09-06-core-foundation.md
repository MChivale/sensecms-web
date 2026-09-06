# Sense CMS foundation — 2026-09-06

## Verified source and constraints

- Source reference: `F:/Git/ChivaleGroup/eduvixo-cms/.cms/source`, release metadata
  `1.0.25`, engine `1.0`. Source inspected read-only; no Eduvixo installation changed.
- PHP CLI available locally: 8.5.5. Production has PHP 8.5-FPM, Nginx and MariaDB.
- Source installer depends on education seeds and AI seeds. Its public entry point
  eagerly constructs campuses, AI, surveys and other optional services; therefore
  a blanket rename/copy is not a clean general-purpose Core.
- Existing package architecture uses manifests, SHA-256 inventories, Ed25519 and
  private staging. Preserve those security principles, but isolate new package
  code from the education database/controllers and add the requested module type.
- Do not deploy a partial CMS, expose an unclaimed installer or publish a placeholder
  download. Existing HTTPS remains operational, while Core development is local.

## Boundaries and sequence

1. Signed extension contract, build/verification, dependency planning (implemented).
2. Generic Core configuration, migrations, installation and owner claim; adapt
   Eduvixo's authentication and Workspace UI with tests, without public-theme coupling.
3. Package staging/activation/rollback and module-owned migrations; then extract
   content/media, forms, surveys, messaging and education-specific functionality.
4. Build/install Sense CMS product-site theme and real package distribution. Public
   downloads require an end-to-end verified clean installation release.
5. Integrate `cambo-jumbo` as a separate public theme; migrate production content
   only after a backed-up, verified rehearsal. Do not transfer Cambo's custom admin.

Core owns bootstrap/configuration, authentication/authorization, users/roles,
Workspace shell, site settings, audit log, migrations, extension lifecycle and
theme contracts. Optional applications own their routes, data and navigation.
Core may not instantiate optional package services merely to serve a request.

The new portable source stays under `.cms/source`; top-level `.modules`, `.plugins`,
`.addons`, `.themes` are authoring sources, not public runtime directories. `web`
owns the product website/distribution. No framework has been selected or installed.

## Licensing identity and integration

The user completed `.cfg/License.txt`: ProductName `Sense CMS`, ProductModel
`Sense CMS System`, ProductVersion `1.0`. Chivale validated these exact values for
`https://www.sensecms.com`; the response has no expiry date. `config/product.php`
contains only public identity, not the key. Core compatibility remains `0.1.0`.

`LicenseClient` retains the existing HTTPS form-based Chivale protocol, disallows
redirects, bounds response size and never exposes provider error bodies. Success
requires boolean `error=false`, matching product identity and explicit validity
fields. Provider timestamps use the existing `Y-m-d H:i:s` format and are interpreted
as UTC. No client identifiers or provider URLs are retained by Core.

`LicenseService` stores the license, canonical domain, identity and cache timestamp
in one authenticated-encryption envelope (`SENSECMS-LIC-1`, Sodium secretbox). This
prevents unencrypted cache metadata from bypassing expiry/domain checks. Installation
key stays in private `key.bin`, not in any source or distribution archive. Files are
0600 under a 0700 directory on Unix; Windows deployments must use equivalent ACLs.
Writes use same-directory atomic replacement and a persistent lock inode.

Remote validation is cached for at most seven days (as in the source system), capped
by license expiry; expiry or stale cache triggers a fresh check. There is no additional
offline grace after that point. Provider-side revocation can therefore take up to
seven days to be observed. Backward clock movement, mismatched products/domains and
tampered data fail closed. The format intentionally does not import Eduvixo license
files or encryption keys.

Operator diagnostics: `php scripts/license.php https://www.sensecms.com`, optionally
`--test-storage` to exercise real validation, encrypted writes/reads and replacement
in an isolated temporary directory. Output excludes the key and customer data.

## Current checks

The package test suite generates ephemeral keys and fixtures in a uniquely named
OS temporary directory, and removes only that directory after validation. No signing
secret, seeded customer data or installer state is added to source or releases.

No Core database migration, application deployment or public release has occurred
in this milestone. Existing user source images and root favicons remain untouched.

Completed verification: 55 package checks passed on Windows PHP 8.5.5 and production
host Debian PHP 8.5.10 in an isolated root-owned test directory, outside the website.
All six PHP source/CLI/test files passed lint on both platforms. The remote test
directory and local transport archive were removed after the checks. No new system
package or PHP dependency was installed. Source/key-directory exclusion was also
added to the release CLI, keeping private signing keys out of the payload source.

Licensing verification: 41 isolated checks plus 55 package checks passed on both
Windows PHP 8.5.5 and Debian PHP 8.5.10. Live Chivale validation and encrypted storage
round-trip/replacement passed on both machines for the supplied Sense CMS identity.
Remote tests ran outside the webroot; their temporary credential copy, source files
and transport archive were removed. No database, public application or live license
installation was altered. Licensing is ready for integration into the web installer;
this licensing milestone did not yet include the installer or administration.

## License-first installer and initial Workspace

Implemented the next development milestone without changing the live website:

- First browser screen: license key and verification only. Real Chivale acceptance
  for the canonical domain is required before server checks or DB/owner setup.
- Canonical hostname is provisioned with `scripts/prepare.php`, not inferred from
  an untrusted Host header. HTTPS and exact host matching are required; the local
  HTTP exception requires an explicit environment flag and loopback host/client.
- CSRF, private sessions, 30-minute setup authorization, license-attempt limits,
  encrypted license persistence and installed-state closure protect installation.
- Seven generic InnoDB tables; schema-scoped DB account, empty-database guard,
  exclusive file/database locks, schema/fingerprint-bound recovery and transactional
  owner seeds. Recovery cannot silently change the original owner/password.
- Owner authentication uses Argon2id, rotating session IDs, idle/absolute expiry,
  session version checks, owner role checks and database-backed login rate limits.
- Initial Workspace follows Eduvixo geometry/controls and remains theme-independent.
  Its available routes are real: overview/activity, site name settings and license
  recovery. This is not yet the full Eduvixo feature set or user-management UI.
- AJAX/JSON forms; light responsive layout and supplied Sense CMS SVG logo.
  A browser check corrected excess mobile brand-panel spacing.
- Private configuration stores DB credentials outside `public`; owner passwords
  remain hashed in DB and license keys remain encrypted. Nginx must use `public`;
  Apache rewrite files are included but have not been tested on a running Apache.

Verification: 55 package checks, 41 licensing checks, all 19 PHP files linted and
JavaScript syntax checked locally. The clean 21-file development ZIP was extracted
outside the server webroot and passed 29 HTTP integration checks against a newly
created disposable MariaDB database. Tests include first-screen order, bypass/CSRF
rejection, real license validation, existing-data preservation, interrupted seed
recovery, mismatched-owner rejection, installer closure, login, settings, logout
and rate limiting. Desktop and 390px mobile first-screen browser inspection passed;
the mobile document has no horizontal overflow. Other admin screens were tested
through HTTP, not visually inspected in this milestone.

Archive: `.cms/releases/sensecms-install-0.1.0-dev.zip` (development only), SHA-256
`5329b8105abbca18a99745aaf7ad6a32d751e601ce54d5f21fcd5c8141e8e69d`.
The builder excludes storage, customer data and test credentials and refuses an
existing output. No stable download or application deployment was performed.
No production database was migrated and no framework/dependency was added.
Both isolated server test directories (including temporary credential copies),
their disposable databases/users and the local test transport archive were removed.
Remote PHP lint and archive checksum matched; the test log contained no PHP
warnings/fatal errors and Nginx, PHP-FPM and MariaDB remained active.

## SenseCMS.com first — product theme and runtime integration

The user changed priority: complete SenseCMS.com first, Cambo Jumbo last. No work
was performed in Cambo Jumbo. Resumed from installer commit `a46aef3`.

- Added `.themes/sensecms`: ten English public pages (home, platform, extensions,
  documentation index, four guides, release status and contact), supplied SVG logo,
  shared light/navy/blue tokens, mobile menu, canonical metadata, sitemap and 404.
- No fabricated download, price, testimonial or stable-release claim. Contact uses
  the supplied public mail address through mailto; no unconnected contact form.
- Added Core PublicTheme renderer and theme-only signed package lifecycle. Public
  requests enforce licensing before rendering, do not open admin sessions/DB and
  cannot replace reserved authentication/installer routes. Theme PHP stays private.
- CLI staging/activation uses independently trusted Ed25519 keys, rehashed payloads,
  versioned private directories and atomic active/previous references. Rollback
  reverifies trust, compatibility and payload hashes. No database changes involved.
- Signing keys used in tests were ephemeral only. Production publisher trust and
  signing key provisioning remain a release-operations prerequisite.
- Preview `http://127.0.0.1:8872/` is served by the explicit loopback-only developer
  script; it is not a production entry point or license bypass in the Core installer.
- Preserved user `.src` originals and root favicon files. Added no dependency.

Verification: 175 website/theme checks passed on Windows PHP 8.5.5 and Linux PHP
8.5.10; 55 package and 41 licensing checks remained passing locally. Expanded HTTP
suite passed 37 checks in an isolated server directory with a disposable MariaDB
database and real Chivale validation, then installed a test-signed theme through the
actual licensed CLI. Verified public homepage/docs/assets, no admin Set-Cookie,
private PHP denial, public 404, admin authentication and closed installer.
Computer Use checked 1440px desktop and 390px mobile, mobile menu opening/Escape,
documentation navigation and article readability. Public content has no database
editing interface yet. The current installer ZIP remains the previous milestone,
not an updated distribution containing this new runtime.

Next: module-owned page/content storage and owner editing UI, release catalog and
verified download publishing, then full SenseCMS.com deployment rehearsal. Do not
mark the complete product website/CMS delivered merely because the public theme
renders. General extension UI, migrations, content/media and production deployment
remain outstanding. The existing live website and infrastructure were not changed.
Removed the isolated server test directory (including temporary credential and
test-signing artifacts) and local transport archive. Disposable DB/user cleanup
completed; no PHP warnings/fatal errors appeared in the test log. Nginx, PHP-FPM
and MariaDB remained active. Browser console showed no warnings/errors on the
checked public pages, and the checked mobile pages had no horizontal overflow.
