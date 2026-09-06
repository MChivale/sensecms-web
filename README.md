# Sense CMS

A general-purpose PHP CMS, web installer and independently installable extensions.
This repository also owns the Sense CMS product website and package distribution.

## Current implementation

The development Core implements signed extension archives and planning, Chivale
licensing, a license-first web installer and an initial owner Workspace (login,
activity, site settings and license recovery). The first browser screen contains
only the license field and verification action; requirements and database/owner
setup remain inaccessible until the license is accepted for the canonical domain.

The Sense CMS product theme now contains ten public pages including technical
documentation. Signed themes support operator-CLI installation, versioned storage
and rollback; Core serves the selected theme independently of administration.

This is **not yet a finished CMS release**: the general extension lifecycle,
content/media editing, the package-management UI and release distribution are not
integrated. No unfinished application or public installer is deployed. Existing
production HTTPS is separate. SenseCMS.com takes priority; Cambo Jumbo is deferred.

## Repository boundaries

| Path | Responsibility |
| --- | --- |
| `.cms/source` | Portable Core and web installer source |
| `.modules` | Independently installed application features |
| `.plugins` | Event-driven integrations and extensions |
| `.addons` | Optional administrative/operational tools |
| `.themes` | Public presentation packages, including `cambo-jumbo` |
| `web` | Sense CMS product website and distribution application |
| `deploy` | Server configuration and deployment tooling |
| `scripts`, `tests` | Repository build and verification tools |

The administrative Workspace belongs to Core, not to any public theme. Eduvixo
is the reference for its design and existing implementation. Educational data,
campus functions and Cambo Jumbo's previous custom administration are not Core.
Package source folders are not public web directories.

## Development

PHP 8.5+ with Sodium and ZIP is required for package tooling. No framework or
third-party dependency has been introduced.

```text
php tests/packages.php
php tests/licensing.php
php tests/themes.php
php scripts/build-installer.php
php scripts/package.php build .modules/<slug> <release.zip> module
php scripts/package.php verify <release.zip> <trust-store.json> 0.1.0
```

The builder requires `SENSE_PACKAGE_SIGNING_KEY_FILE` pointing to a protected
Base64 Ed25519 secret key supplied by the release operator. It never creates or
trusts a signing key automatically. The verifier reads an independently provisioned
JSON map of key IDs to Base64 public keys. The development Core compatibility
version `0.1.0` is not a licensed product version.

Licensing diagnostics use ignored `.cfg/License.txt`:
`php scripts/license.php https://www.sensecms.com --test-storage`. This validates the
real license and exercises encrypted storage only in a temporary private directory.

See [the package contract](docs/packages.md) and [implementation decisions](.wrk/2026-09-06-core-foundation.md).

## Installer development build

`scripts/build-installer.php` builds `.cms/releases/sensecms-install-0.1.0-dev.zip`
from allowed Core source folders only. Private storage, keys, configuration
credentials, test fixtures and installed state are excluded. Existing ZIPs are
never overwritten. Follow the [portable installation guide](.cms/source/README.md)
for canonical hostname preparation, HTTPS, permissions and browser setup.

`tests/installer-http.py` exercises real licensing and database-backed HTTP flows
only inside an isolated Linux `sensecms-install-test.XXXXXXXX` directory. It needs
a private `.cfg/License.txt`, PHP, Python and local MariaDB administrative access.
It creates and removes only a uniquely named disposable database/user; never run
it against a production installation. The tested ZIP contains no test credentials.

## Product website and themes

`.themes/sensecms` owns the product website, not Core. Local presentation preview:

```text
php -S 127.0.0.1:8872 -t web/public scripts/preview-site.php
```

This loopback-only preview does not install or bypass production licensing.
For an installed Core, build a signed theme with the existing package CLI, then
run as the PHP runtime user from the Core installation directory:

```text
php scripts/theme.php install /private/theme.zip /private/trust.json
php scripts/theme.php rollback /private/trust.json
```

Provision publisher trust separately. The declared `sensecms-release` identifier
does not itself establish trust; no production signing key is shipped or generated
automatically. Only theme packages without unresolved dependencies are supported
by this lifecycle. Older release directories are retained for recovery. This is
not yet a general module/plugin installer or an admin package-management screen.
