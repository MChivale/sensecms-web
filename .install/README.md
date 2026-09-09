# Sense CMS web installer — 0.1.0 development

`web/index.php` and `web/install.zip` are a matching pair. The bootstrap contains
the SHA-256 of the ZIP and every file. Obtain both from a trusted publisher; these
checks are integrity protection, not an independent digital publisher signature.

## Upload and run

1. Prepare a NEW empty installation directory, for example `/home/example.com/web`,
   containing only an empty `public` directory. Never use an existing CMS directory.
2. Configure the canonical HTTPS domain with document root
   `/home/example.com/web/public`. Restrict access to the operator until setup ends.
   Redirect aliases to this domain at the server level before starting.
3. Upload **only** the two files from `web/` into that `public` directory.
   The PHP runtime identity temporarily needs write access to the installation
   directory AND `public`. PHP 8.5+ and ZIP are required for extraction.
4. Visit `https://your-domain/`. Extraction starts automatically, checks the ZIP,
   shows real batch progress and redirects to `/install`.
5. The FIRST Core screen requests the licence key. Database and owner creation
   remain inaccessible until the licence has been verified. Later checks require
   curl, pdo_mysql, sodium, zip, mbstring, session, Argon2id and 128 MB+ RAM.
   This full Workspace development candidate requires a NEW empty MariaDB 10.11+
   database; MySQL full-workspace compatibility is not yet certified.
6. Complete database/owner setup. `/install` then closes. The bootstrap has already
   been replaced by the real Core entry point and the public ZIP removed.
7. Remove write access to application source; retain the runtime access required
   for `storage`. The private `.sense-bootstrap` directory retains only extraction
   bookkeeping and empty staging directories; it can be removed by the operator
   after checking installation. Do not delete CMS `storage` or its encryption keys.

## Web server

Nginx DOES NOT process `.htaccess`. Configure HTTPS/FPM normally, set `root` to
the `public` directory and use `try_files $uri $uri/ /index.php?$query_string` in
`location /`. Only `/index.php` may execute PHP; deny other PHP paths and dotfiles.
Pass the real HTTPS state to PHP-FPM. Do not use a project-root Nginx document root.

Apache also uses `public` as DocumentRoot. Enable mod_rewrite and AllowOverride for
the packaged `public/.htaccess`; the bootstrap itself works before extraction.
The root-level compatibility `.htaccess` is included but is not needed or relied on
by this bootstrap. Server config/certificates are deliberately not changed by PHP.

## Recovery and boundaries

Reload in the SAME browser session to resume a paused extraction. Another session
or domain cannot take over. A failed batch never enables a partially extracted Core.
For a lost session or disk-write failure, inspect the private state and files first;
start again only in another empty directory or after operator-reviewed cleanup of
the failed NEW installation. Never erase an installed CMS to retry this bootstrap.

The archive is Core + Workspace, not a clone of sensecms.com. It contains no customer
data, licence keys, tokens, website-only distribution/broker services, installed
themes or optional extension packages. Install themes/extensions separately.

This is a DEVELOPMENT candidate, not a Stable release. HTTP tests verify extraction,
integrity, resumption and the real licence-first screen. A complete newly licensed
database installation through this exact artifact and real Nginx/Apache acceptance
remain release gates before Stable promotion.

Build: `php scripts/build-installer.php --web` (output directory must be empty).
Tests: `php tests/installer-package.php` and `python tests/web-installer.py`.
