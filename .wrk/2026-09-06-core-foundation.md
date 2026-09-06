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
this milestone does not claim that the complete installer or administration is ready.
