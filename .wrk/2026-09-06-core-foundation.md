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

## Licensing dependency

The supplied `.cfg/License.txt` contains only a key. Eduvixo licensing also requires
the exact ProductName, ProductModel and licensed ProductVersion. The user was asked
to supply Sense CMS's registered identity in `.cfg/License-product.txt`; do not guess,
reuse the Eduvixo identity or bypass verification. This does not block package work.

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
