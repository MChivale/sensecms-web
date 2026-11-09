# Sense CMS

Standalone development repository for a general-purpose PHP CMS, its administration,
official product website and independently installable extensions.

**Project directory:** `F:/Git/MChivale/sensecms-web`.
No other project checkout is required to build or develop Sense CMS.

## Source layout

| Directory | Responsibility |
| --- | --- |
| `.cms/source` | Portable Core, Workspace and licence-first installation |
| `.themes/sensecms` | Official Sense CMS website theme |
| `.plugins` | Analytics, calendar integrations and Telegram packages |
| `.addons` | Calendar addon |
| `.modules` | Independent application modules |
| `.src` | Official-site catalogue, Telegram services and branding |
| `web` | Product-site entry point/assets and private local runtime |
| `.install/web` | Generated `index.php` + `install.zip`, ignored by Git |
| `scripts`, `tests`, `deploy` | Build, verification and server tooling |
| `docs`, `.wrk` | Contracts and preserved engineering history |
| `.cfg` | Private local configuration; never committed/distributed |

Administration belongs to Core and works independently of public themes.
Website-only distribution and shared-bot services are not bundled in customer Core.
Optional packages/themes are independently installed, not copied from production.

## Current status

Core build **1.0.0** includes a general-purpose Workspace, facilities, content,
permissions, package lifecycle and notification channels. The product website and
Marketplace are managed through Sense CMS. See
[the main work log](.wrk/2026-09-06-workspace-migration.md) for implementation evidence.

On 2026-09-13, both independent production installations moved to Core 1.0.0.
The official site uses theme, Calendar and Telegram 1.0.0. Analytics 0.1.2 and
Google/Microsoft/Apple calendar 0.1.1 retain development status pending external-account
acceptance. Exact signed package installation, upgrade, coordinated rollback and
re-upgrade passed private QA. Demo does not inherit the official site's packages/data.
The existing `.install/web` files are deliberately unchanged older artifacts, not a
1.0.0 installer. Rebuild only after the user's production review and explicit request.

**This is not yet a Stable release.** The two-file bootstrap has extraction,
integrity, resumption and licence-first HTTP coverage. Complete newly licensed setup
with a new database using the exact artifact and target Nginx/Apache acceptance remain
release gates. External calendar-account acceptance and further catalogue adaptations
remain separate tasks. Verify live production before treating history as current.

Core release checks use a signed Stable catalogue. `/system/update` supports manual
checks; the notification panel checks every six hours and a CLI/cron worker checks
without a browser. Independently provision the public verification key first; see
[release-check operations](docs/packages.md#core-release-checks-and-the-update-page).
Automatic Core **installation** remains unavailable. Use tested operator deployment
with backups, not the new-install bootstrap, until an update/recovery contract passes
isolated acceptance. Development packages are never silently promoted to Stable.

## Private configuration

All local operator configuration is in ignored `.cfg`:

- `License.txt`: Core product/model/protocol/key; preserved CLI-compatible format.
- `Package-licenses.txt`: product-name/key map for Core and paid packages.
- `Telegram.txt`: Sense CMS bot username/token.
- `SSH.txt`, `DNS.txt`, `Email.txt`, `Catalog.txt`, `Production-owner.json`:
  existing environment, delivery and ownership configuration.

Never print these contents or copy another installation's encrypted runtime.
Publisher signing secrets and independent trust remain privately provisioned on the
server. Git ignores `.cfg`, private storage, `.local` and generated archives.

## Development and checks

Use PHP 8.5+; Python for HTTP tests and Node for JavaScript tests. Server requirements:
[installation guide](.cms/source/README.md). No new framework is required.

```text
php tests/project-boundaries.php
php tests/packages.php
php tests/release-versions.php
php tests/licensing.php
php tests/security-regressions.php
php tests/ip-country.php
node tests/demo-mode.cjs
php tests/maintenance-regressions.php
node tests/system-update.cjs
php tests/core-releases.php
node tests/update-website.cjs
php tests/media-limits.php
php tests/builder-defaults.php
php tests/popup-defaults.php
python tests/public-home-http.py
node tests/media-upload.cjs
python tests/media-http.py
php tests/themes.php
php tests/installer-package.php
python tests/web-installer.py
git diff --check
```

Operator-only chat country lookup is offline and optional. Build a private
`storage/geoip/country.bin` from a locally downloaded monthly DB-IP Country Lite CSV
with `python scripts/build-ip-country.py <source.csv.gz> <country.bin>`. Keep the
source checksum and CC BY 4.0 attribution in operator records. The displayed result
must retain the DB-IP link; a missing or invalid private index safely shows an unknown
country and never sends a visitor address to a geolocation service.

Inspect database fixture guards first; never target production or an existing database.
Security regression tests use local SQLite in memory (`pdo_sqlite`). On private Linux
QA, `php tests/security-regressions.php --mysql` creates/removes a random disposable
MariaDB database and additionally verifies concurrent access-management locking.
`php tests/maintenance-regressions.php --mysql` likewise uses a new disposable
database to verify package-toggle transactions, failure rollback and shared locking.
Its local SQLite checks cover honest update status and unrestricted workflow totals.
`tests/demo-access-http.py` is operator-only HTTPS acceptance for the two configured
installations, using their private credentials. Demo is active on demo.sensecms.com
and disabled on www.sensecms.com; Owner alone controls activation in Access control /
Users. The test verifies login, menu access and rejected writes without changing data.

## Installer artifacts

Build an installer only when the user explicitly requests it. The current workflow
is source changes, production verification, then an explicitly requested installer
build; routine fixes must not regenerate `.install/web` or its ZIP automatically.

```text
php scripts/build-installer.php --web
```

The output directory `.install/web` must be empty; replacement is refused.
Upload matching `index.php` and `install.zip` to a NEW empty `public` document root.
Opening the HTTPS domain verifies/extracts Core privately, then opens `/install`
with licence verification first. See [Core installation requirements](.cms/source/README.md).
This is not an updater; never replace the production entry point with the bootstrap.

The full browser installer is at `F:/Git/MChivale/sensecms-web/.install/web`:
upload both `index.php` and `install.zip`, without manually extracting the ZIP.
Use HTTPS and an installation directory containing only `public` and, optionally,
an empty writable `storage` directory (not a symbolic link); set the
web server's DocumentRoot to that `public` directory. During extraction the PHP
runtime user needs write access to both directories; afterwards restrict writes
to private `storage`. Keep setup accessible only to the operator until finished.
Prepare a licence valid for the canonical domain and a NEW empty MariaDB 10.11+ or
PostgreSQL 17+ database with its own schema-scoped user. PostgreSQL requires
pgvector and prepares optional semantic retrieval; MariaDB uses keyword retrieval.
The browser performs extraction, licence
verification, requirements checks, database setup and owner creation; no PHP CLI
preparation command is needed for this two-file path.

After installation, verify owner login, dashboard, facilities, access management,
page creation and media; `/install` must no longer permit setup. Optional themes,
packages and external accounts are configured separately. A clean Core is not a
copy of the official website. This remains a DEVELOPMENT installation candidate,
not a Stable production release or an automatic updater.

`python tests/web-installer.py --nginx` runs the same exact-artifact extraction,
resumption and licence-first checks plus private-path denial through isolated
loopback Nginx/PHP-FPM processes on Linux (root required). It does not modify
system services or use a production database. This covers preparation only;
licensed database completion uses the guarded `tests/installer-http.py` fixture.
It can target an isolated loopback Nginx/Apache server using
`SENSE_INSTALL_TEST_URL`; HTTPS requires normal certificate verification, with an
optional fixture CA in `SENSE_INSTALL_TEST_CA`. Never point these fixtures at an
existing installation or production database.

On 2026-10-31 the current 433-file candidate passed exact-artifact extraction,
licensed fresh-database installation, and isolated Nginx/Apache acceptance. The
reviewed two-file pair is retained in `.local/sensecms-web-installer-20261031`;
its `install.zip` SHA-256 is
`c797a7052c71f242c6631103b8c63ce89f211337129fd6a6a4f054d50283bd6e`.
The older `.install/web` pair was not replaced. See the latest work-log entry for
test evidence and scope. The candidate remains unsigned DEVELOPMENT; these checks
do not publish a signed Stable release or enable automatic Core updates.

## Product website and packages

Loopback presentation preview, without production changes:

```text
php -S 127.0.0.1:8872 -t web/public scripts/preview-site.php
```

Package tooling: `scripts/package.php`, independently provisioned Ed25519 key/trust.
See [package contracts](docs/packages.md). Core identity:
`Sense CMS` / `Sense CMS System` / protocol `1.0`; release versions do not change it.
Free downloads require Core licensing; paid packages their own identity/validity.

## Continuing work

ISPConfig 3 owns the Apache2 vhosts, certificates and PHP 8.5 FPM pools. The www
site runs as `web128:client9` with PostgreSQL 17 database `sensecms_site`. Demo
runs as `web129:client9` with its own database `sensecms_demo`. Both have pgvector.
Each `/home/<domain>/web` is public. The application and its credentials belong in
`/home/<domain>/private/sensecms`. PHP CLI and FPM have both `pdo_pgsql` for the
live installations and `pdo_mysql` for compatibility with the archived installer.

On the www server, `sh deploy/install-production.sh --check` checks the existing
production environment. From a private `/root/sensecms-deploy.*` staging directory,
`sh deploy/publish-site.sh --check` validates the Apache entry points and `--apply`
publishes them with a backup and HTTP check. Core files and frontend assets are deployed
separately to their release-specific destinations. Use ISPConfig for changes to vhosts
and pools. Fresh installation instructions above describe the preserved MariaDB edition.

The www chatbot combines keyword search with cosine similarity over published, ready
knowledge fragments in the visitor's language or shared across languages. Semantic
search is enabled per installation through private `storage/semantic-search.json`
with `model` set to `text-embedding-3-large` and `provider_slug` naming the site's
enabled official OpenAI provider. It uses the existing encrypted key. Apply
`deploy/postgresql/semantic-search.sql` as that site's database owner before enabling.
The Knowledge Base cron fills missing 1536-dimensional vectors in bounded batches.
Unchanged content reuses cached vectors after a rebuild. Removing the private
configuration disables both embedding calls and semantic retrieval. The keyword
path continues to work when the embedding service is unavailable.
`scripts/deploy-demo-isolation.py` records the completed, pinned one-shot migration;
it is not a general re-runnable deploy command. Inspect current state before changes.

Read [AGENTS.md](AGENTS.md), `.info` and latest work-log sections first.
The repository already has its own Git history and `sensecms-web` origin remote.
Local pending changes are preserved, not automatically committed. Historical import
and provenance records stay in `.wrk`; they are not dependencies. Do not rerun the
archived importer or copy unrelated websites/education databases into this project.
