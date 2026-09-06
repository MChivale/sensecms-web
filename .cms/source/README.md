# Sense CMS — development installer 0.1.0

This build implements licensing-first installation and the initial owner Workspace.
It is not a finished public-site CMS release. Extension activation, content/media
modules and public themes are not yet integrated.

## Server preparation

Use PHP 8.5+ with curl, pdo_mysql, sodium, zip, mbstring, session and Argon2id,
at least 128 MB memory, and MySQL 8.0.29+ or MariaDB 10.11+.
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
After installation, `/install` is closed and `/login` opens the Workspace.
The owner can view activity, change the site name and update the license.

If installation is interrupted, retry with the same database and owner details.
Do not delete `installing.json` or existing tables to bypass recovery safeguards.
Back up the entire private storage directory together with the database.

Loopback-only development preview can use `SENSE_LOCAL_HTTP=1` with the PHP built-in
server. Never set this option in production or expose the development server.
