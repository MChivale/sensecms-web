# SenseCMS.com first production application deployment

## Scope and status

User explicitly requested continued Sense CMS development and production publishing.
Published the verified development product website and initial licensed Core at
`https://www.sensecms.com`, with administration at `/login`. This is not a stable CMS
distribution. The site clearly states development status and offers no fake ZIP.
Content editing, general package UI, module migrations and release distribution are
still outstanding. Cambo Jumbo was not changed.

## Installation and infrastructure

- Before deployment, `/home/sensecms.com/web` contained only Apache rules and public
  favicons; no Core or Sense CMS database existed. Existing unrelated databases
  and the default PHP pool were preserved.
- Created system account `sensecms` (no interactive shell), a dedicated PHP 8.5-FPM
  pool/socket `/run/php/php8.5-sensecms.sock`, and database `sensecms_site` with a
  dedicated database user restricted to that schema. Public root remains
  `/home/sensecms.com/web/public`. Nginx now uses the dedicated socket.
- Core source is root-owned, read-only to PHP. Private storage is owned by sensecms,
  directories 0700 and files 0600. No deployment credential entered public storage.
- Initial setup ran offline through the real licensing/Installer services. Chivale
  validated the supplied domain/license before database tables and owner creation.
  The front controller was published only after installation and theme activation.
- Explicit deployment provisioning initialized the Sense CMS publisher key under
  `/root/sensecms-private`, mode 0600; the private key is never shipped in Core or
  theme archives. The theme was signed, verified and activated through ThemeManager.
- Owner login: `info@sensecms.com`. Generated password is in the ignored, ACL-restricted
  local `.cfg/Production-owner.json`, with root-only server copy `owner.json` under
  the private operator directory. Do not print or commit its contents. The local
  file records the initial credential and becomes stale when the owner changes it.

## Account management

Added `/account` with current-password verification, matching 14–200-character new
passwords, CSRF, per-session attempt spacing and Argon2id hashing. A transaction
updates the password and increments session_version; previous sessions then fail
validation. The current session ID is regenerated and retained. Password changes
are audited without storing submitted passwords. No production password was changed
during smoke testing.

## Verification

- Local regression suites: 175 website/theme checks, 55 packages, 41 licensing.
- Expanded isolated Linux/MariaDB HTTP suite: 45 checks, including wrong-current-
  password rejection, CSRF, version revocation and old/new password login behavior.
- Live HTTPS: all ten public pages, documentation, public/admin assets, sitemap,
  robots and login returned 200; installer, private files, theme PHP and unknown
  public route returned 404. HTTP/HTTPS apex redirect 301 preserves path and query.
- Verified owner login, real dashboard audit records, settings/account pages,
  account CSRF rejection and logout on production. Secure/HttpOnly session cookie;
  public homepage does not issue an administration cookie. HSTS/CSP present.
- Production homepage, installation documentation, download page, theme CSS and JS
  matched the tested local preview response exactly. Updated account PHP file SHA-256
  hashes matched local source; PHP lint passed remotely and locally.
- Nginx and FPM configuration checks succeeded; Nginx, PHP-FPM and MariaDB remained
  active. Production PHP error log had no entries at the completed checks.

## Deployment interruption and recovery

FPM reload returned before its new socket appeared. The initial script stopped at
the socket check while the application remained unpublished; installation itself
had succeeded. Read-only checks confirmed the socket and services were healthy.
Extracted a guarded `publish-site.sh` continuation with a bounded readiness wait,
then completed publication without recreating the database or owner.

## Backups and rollback

- Pre-application files/Nginx: `/root/sensecms-backups/20260906T100929Z-pre-application`.
- Before the account update: `/root/sensecms-backups/20260906-account-update`, containing
  a full web archive, consistent database dump and root-only operator-private backup.
- To roll back the account UI/code, restore only Auth.php, workspace.php and
  public/index.php from the account-update web archive; preserve live database,
  license storage, owner password/session_version and theme state. Do not restore
  a stale database merely to undo a code change.
- To withdraw this initial publication, remove/move only the published front
  controller from public access and restore the saved Sense CMS Nginx configuration
  after syntax validation. Preserve the new database, private storage, signing keys
  and any later data. Never overwrite other vhosts or the complete Nginx directory.
- Deployment scripts are explicitly first-install only and refuse existing Core.
  Do not rerun provisioning as an update mechanism. Later releases need a guarded
  update/deployment flow with storage preservation and post-deploy checks.

Final cleanup removed only the unique deployment/test staging directories and local
transport archives, including the temporary plaintext license copy. Test databases
and users were removed by the test harness. Production runtime, owner credentials,
publisher keys and both backups were preserved. Final Nginx error-log entries
predate publication (the earlier empty document root); no new application errors
were found after publication. The database contains exactly one owner assignment.
