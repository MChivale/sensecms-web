# Sense CMS — project handoff

Work in `F:/Git/MChivale/sensecms-web`. Product name: **Sense CMS**. This standalone
repository owns portable CMS, administration, official website and package distribution.
Other project checkouts are not build/runtime/configuration dependencies. Do not run
the historical importer archived in `.wrk`.

## Read first

- `.info`: scope and environment boundaries.
- `README.md`: source layout, private configuration and current commands.
- `.wrk/2026-09-06-workspace-migration.md`: main work log; read latest sections first.
- `.install/README.md`: installer contract and outstanding release gates.
- `docs/packages.md`: package lifecycle, identity and licensing contracts.
- Relevant `.cfg` and deployment files before operations; never display secrets.

## Architecture and identity

PHP 8.5+, custom PHP application. Discuss new frameworks with the user first.
Portable Core is `.cms/source`; administration must not depend on a public theme.
Use general-purpose facilities, roles and configurable calendar categories, not
education-specific entities. Preserve existing data, package identities and APIs.

`.themes`, `.plugins`, `.addons`, `.modules` contain independent package sources.
Product theme: `.themes/sensecms`. Website-only distribution/bot services belong to
`.src` and `web`, NOT customer Core. Never bundle private runtime, credentials,
demo content or installed packages in a Core archive.

Core licence identity: product `Sense CMS`, model `Sense CMS System`, protocol `1.0`.
Build versions are separate. Free downloads require a valid Core licence; paid
packages their own product licence. Preserve exact identity and validity checks;
do not tie keys to every package release version.

## Configuration and safety

All local operator files are in ignored `.cfg`. Core identity/key: `License.txt`;
product-key mappings: `Package-licenses.txt`; bot: `Telegram.txt`. SSH, DNS, email
and owner details retain their existing files. Never read another project's keys
as a fallback. Never expose secrets in output, screenshots, work notes or archives.
Publisher private keys/trust remain separately provisioned, not bundled.

Investigate before changing. Preserve dirty/untracked work, data and source provenance.
Use small changes, existing services/components/design tokens, no duplicate code.
Use apply_patch for source edits. Do not commit/push or publish simply because an
origin remote exists. No blanket rebranding of third-party dependencies/products.

## Verification and deployment

Run targeted tests, PHP lint and `git diff --check`. Installer checks:
`php tests/installer-package.php` and `python tests/web-installer.py`.
Inspect database fixture guards; never run destructive fixtures against production.
Use isolated QA for new installations and package lifecycle rehearsals.

Production, only when requested: www.sensecms.com, `/home/sensecms.com/web`, public
DocumentRoot `/home/sensecms.com/web/public`, Nginx/PHP-FPM/MariaDB. Inspect target,
back up and plan recovery before changes. Verify deployed hashes, HTTP/UI behaviour,
service health and fresh logs afterwards; record evidence in `.wrk`.
Nginx ignores `.htaccess`. Never expose private application roots.

`.install/web/index.php` + `install.zip` are generated DEVELOPMENT artifacts for a
NEW EMPTY installation, not a production update. Extract first, then the real
licence-first installer. Never overwrite production with the bootstrap.

At handoff, Telegram 0.1.1 publication and installer-preparation tests are recorded
as completed. Exact new-artifact licensed/database install and Nginx/Apache acceptance
remain release gates. Demo/customer themes remain deferred. Verify live state before
relying on historical package versions or deployment assertions.
