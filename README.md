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

Development Core includes a general-purpose Workspace, facilities, content,
permissions, package lifecycle and notification channels. The product website and
Marketplace are managed through Sense CMS. See
[the main work log](.wrk/2026-09-06-workspace-migration.md) for implementation evidence.

**This is not yet a Stable release.** The two-file bootstrap has extraction,
integrity, resumption and licence-first HTTP coverage. Complete newly licensed setup
with a new database using the exact artifact and target Nginx/Apache acceptance remain
release gates. External calendar-account acceptance and further catalogue adaptations
remain separate tasks. Verify live production before treating history as current.

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
php tests/licensing.php
php tests/themes.php
php tests/installer-package.php
python tests/web-installer.py
git diff --check
```

Inspect database fixture guards first; never target production or an existing database.

## Installer artifacts

```text
php scripts/build-installer.php --web
```

The output directory `.install/web` must be empty; replacement is refused.
Upload matching `index.php` and `install.zip` to a NEW empty `public` document root.
Opening the HTTPS domain verifies/extracts Core privately, then opens `/install`
with licence verification first. See [deployment/recovery instructions](.install/README.md).
This is not an updater; never replace the production entry point with the bootstrap.

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

Read [AGENTS.md](AGENTS.md), `.info` and latest work-log sections first.
The repository already has its own Git history and `sensecms-web` origin remote.
Local pending changes are preserved, not automatically committed. Historical import
and provenance records stay in `.wrk`; they are not dependencies. Do not rerun the
archived importer or copy unrelated websites/education databases into this project.
