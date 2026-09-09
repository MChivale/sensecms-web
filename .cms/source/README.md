# Sense CMS — development installer 0.1.0

This development candidate includes licensing-first installation, the initial owner
console, the general-purpose Workspace source and its migration files, translations
and interface assets. It is not a stable public distribution. Fresh browser installs
now create the full general-purpose Workspace after all migrations succeed on MariaDB.
Upgrading an existing initial console remains a separate, operator-controlled deployment
gate. Installing the panel does not install optional packages or certify integrations.

The ZIP includes `installer-manifest.json`, an exact SHA-256 file inventory, and no
installation state, credentials or theme packages. The inventory detects accidental
changes; it is not a publisher signature. Do not trust an installer from an unknown
source merely because its embedded checksums match. Themes and optional extensions
remain separately distributed packages. No demo content is installed by this build.

## Server preparation

Use PHP 8.5+ with curl, pdo_mysql, sodium, zip, mbstring, session and Argon2id,
at least 128 MB memory, and MariaDB 10.11+ for a new full Workspace installation.
The earlier initial Core supported MySQL 8.0.29+, but full Workspace migration SQL
is not yet verified on MySQL; this candidate rejects a new MySQL installation before
creating tables. Existing initial-Core installations are not automatically upgraded.
Provision a new empty UTF-8 database and a dedicated user with schema-scoped
permissions. Never point the installer at an existing application's database.

Extract outside the public document root. Point Nginx/Apache at `public`, enable
HTTPS, route missing public paths to `public/index.php`, and pass HTTPS status to
PHP-FPM. Nginx does not read `.htaccess`. The included Apache rules require
mod_rewrite and AllowOverride support; a `public` document root remains preferred.
Only `public/index.php` should execute as a web PHP script.

As the PHP runtime user, run:

```text
php scripts/prepare.php https://your-canonical-domain.example
```

This binds the installation to an operator-controlled hostname before any browser
request. Set server redirects for aliases separately. Give the PHP user write
access to `storage` only, never the source tree. Private storage needs Unix 0700
directories/0600 files (equivalent Windows ACLs); backups must preserve its keys.
Keep the installer privately accessible until the owner has completed setup.

## Browser installation

1. Open `/install`: enter and verify the license key for the canonical domain.
2. Resolve server requirement failures and continue.
3. Enter database details, site name and owner account; use a 14–200 character password.

The key is sent directly from this installation to Chivale over verified HTTPS,
then stored encrypted in private storage, never in URLs or distribution files.
No database schema or owner is created before successful license validation.
After all migrations and the installation-local encryption identity are ready,
`/install` is closed and `/login` opens the Workspace. The owner can manage facilities,
content, access and configuration; optional themes/extensions are installed separately.
No sample facility, educational data or demo package is created. CAPTCHA remains enabled.

## Existing initial-Core upgrade

Back up the database and entire private storage together and rehearse on a protected
clone first. Deploy matching source without replacing installation state, license,
theme releases or uploads. Run as the installation's runtime user, not as root:

```text
php scripts/migrate-workspace.php --status
php scripts/migrate-workspace.php
php scripts/migrate-workspace.php --status
php scripts/migrate-workspace.php --enable
```

All three operations require the installed Core and a valid license. `--status`
only reads the database (license validation may refresh its private cache); its JSON
reports pending migrations and missing tables. It is a journal/table readiness check,
not an exhaustive column/index audit. Default execution applies the bundled additive
migrations without enabling the panel. `--enable` requires a completed schema; it does
not run migrations. Both mutating commands share a database-scoped concurrency lock.
An unknown journal entry, changed checksum or history gap blocks migration before DDL.
This candidate requires MariaDB 10.11+; MySQL Workspace upgrades remain unsupported.

Activation preserves an existing installation-local encryption identity and verifies
that it decrypts saved Core secrets. A new identity is created only when the file is
absent and no encrypted Workspace data exists. Invalid, empty or mismatched identities
must be restored from the matching backup, never regenerated or copied from another
installation. Extensions with independent secret stores require their own upgrade
checks. Do not create `workspace.json` manually to bypass deployment safeguards.
After activation, verify login, facility permissions, public routes and logs before
opening access. If rollback is necessary, restore matching source, database and private
storage backups together; do not delete migration rows or run destructive down SQL.

If installation is interrupted, retry with the same database and owner details.
The installer preserves the owner password, completed migration journal and existing
valid Workspace encryption identity. Its recovery record pins the exact migration
files: restore them if a deployment changed the source mid-installation. A partial
schema does not enable the panel or create the completed-installation marker.
An unfinished installation from the older initial-Core installer retains that original
mode when resumed; it is not silently converted into a Workspace upgrade.
Do not delete `installing.json` or existing tables to bypass recovery safeguards.
Back up the entire private storage directory together with the database.

Loopback-only development preview can use `SENSE_LOCAL_HTTP=1` with the PHP built-in
server. Never set this option in production or expose the development server.
