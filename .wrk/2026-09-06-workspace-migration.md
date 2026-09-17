# Eduvixo Workspace adaptation — in progress, not production

User requires the actual Eduvixo console, multiple facilities (formerly campuses),
general-purpose calendar with editable categories, and no education-only workflows.
This supersedes the earlier minimal-console milestone; Cambo Jumbo remains deferred.

Source: read-only `F:/Git/ChivaleGroup/eduvixo-cms/.cms/source`; provenance is in
`workspace-source.json`. Imported console, services and assets are being adapted
under `.cms/source`; calendar source is isolated under `.addons/calendar`.
Existing Sense licensing, owner credentials, sessions and public theme are preserved.
No production code or production database has been migrated in this work.

## Current verification environment

- Private host directory: `/root/sense-workspace-test.SC495Gg0`.
- Disposable, uniquely named database/user; credentials only in its root-only
  `preview-private.json`. Never copy these to production or documentation.
- SSH-forwarded loopback 8873: real authentication HTTP tests.
- SSH-forwarded loopback 8874: `tests/workspace-visual.php`, restricted QA-only
  visual router. This authenticating test helper must never enter a distribution.
- Migration rehearsal passed 13 assertions, including repeatability, existing
  users/settings/password preservation, owner-only privileges and no fake campus seed.
- HTTP suite is being expanded; passing some screens is not release acceptance.

## Verified checkpoint

- 159 real HTTP assertions passed on PHP 8.5.10/MariaDB: 31 console screens,
  assets/nonces, CSRF rejection, two facility saves/reloads, content categories,
  JSON calendar categories and event lifecycle, real restricted-user login, and
  cross-facility GET/POST rejection with unchanged target data.
- 33 disposable-database migration/functional assertions passed with PHP warnings
  promoted to exceptions. Includes Argon2id password writes, session revocation,
  single-use recovery links, last-owner protection, category deactivation preserving
  existing editable/searchable events and category colors.
- Existing Windows suites remained passing: licensing 41, packages 55, theme/site
  175; PHP lint 140 files and `git diff --check` passed at this checkpoint.
- Installed the real product theme in QA through the signed ThemeManager, with an
  ephemeral QA-only signing key. Verified `/`, `/extensions`, `/docs`, `/contact`
  still render public pages without an administration session cookie.
- Computer Use checked desktop 1440px dashboard and 390px calendar; saved a Polish
  category through the actual AJAX UI and saw selectors update. Mobile calendar
  document width equals viewport (390px); no browser error logs were returned.
  No logged-in side-by-side comparison against the Eduvixo demo yet.

Corrections: `/system/extensions` avoids stealing public `/extensions`; page creation
does not require an installed legacy theme; facility update scope covers posted `id`;
password/reset paths use existing Sense session versions; removed education footer
and remote CSS font imports (local sans-serif fallback); preserved inactive event
categories in editor and applied saved category colors. New facilities default to UTC.

The checkpoints below supersede the original registry/package-contract blockers.
Do not enable this candidate on production until the remaining integration gates
and production-clone rehearsal pass. A console screen returning 200 is not acceptance.

## Remaining release gates

### Canonical package lifecycle checkpoint

The console PackageManager now verifies the existing canonical `sense-package.json`
format through Packages/Archive, not a second `sensecms-package.json` verifier.
Runtime permissions/migrations come only from the hashed runtime descriptor inside
that verified payload. Identity, publisher name, PHP/Core ranges, dependencies and
installed dependants are checked. Added `.addons/calendar/sense-package.json`;
calendar remains a development release, not a published production artifact.

Install/update/rollback/uninstall are serialized with a schema-scoped advisory lock.
Signed source archives are retained privately by checksum. Rollback rechecks trust,
signature, file inventory, identity, dependencies and checksum. Removed unsigned ZIP
backup/restore helpers. Extracted payloads are rehashed. Uninstall retains extension
data and migration history; reinstall reuses them. Destructive down SQL is no longer
run automatically on install failure or uninstall. A failed migration restores prior
code while retaining database changes for inspected recovery; this is deliberately
not reported as a complete database rollback.

Migration `028_package_migration_integrity.sql` adds nullable up/down checksums.
Applied migration IDs are immutable; existing entries without verified checksums
require controlled reconciliation (never silently assumed trustworthy). Downgrades
which would remove applied migrations are blocked pending a reviewed data-preserving
procedure. Unsigned bundled/development installs cannot be upgraded as if they had
verified recovery archives. In particular, do not silently promote the manually
installed calendar in the long-lived preview; lifecycle tests use a separate fixture.

`scripts/migrate-workspace.php` inside the Core is a licensed CLI schema-only migration
command. It does not enable the console. Applied 028 only to the disposable preview;
production remains unchanged. No new dependencies or publishing keys were added.

Verification: 52 migration/functional/package assertions passed, including canonical
calendar installation, update, revoked-key rollback rejection, tampered archive
rejection, successful rollback, data-preserving uninstall/reinstall, changed SQL ID
rejection, and deliberately failed SQL migration with previous code/data preserved.
The disposable database and filesystem package fixture are removed by the harness.
The 159 HTTP checks and existing 41 licensing / 55 archive / 175 theme checks passed
again. Public theme contract/registry integration, Page Builder and public content
publishing, module lifecycle and release publication remain unfinished. No production
promotion or Git push has been performed.

### Managed public content checkpoint

Product theme candidate is now 0.1.3 (development). Versions 0.1.1/0.1.2 were private
QA iterations; no public release was created. It includes the Workspace theme
descriptor and a public page view using the existing product layout. ThemeManager
validates the optional Workspace contract and matching signed identity before
activation. ManifestRegistry can include the active private payload; duplicate slugs
fail closed. Static-only themes remain compatible.

Localized public page routes now reach PublicController when Workspace and the rich
active theme are available. Static marketing routes retain the session-free renderer.
Page Builder exposes only the theme's implemented text/custom-HTML sections, plus
active extension components. Text defaults no longer describe a school. Output HTML
is sanitized; text action links render safely. Preview URLs include the actual
facility; only managed public pages allow same-origin framing, administration stays
unframeable. HEAD follows GET routing without a body.

Verified on the isolated MariaDB/PHP host: 217 HTTP assertions, including real page
creation, JSON builder writes, stale-version conflicts, per-facility same-slug pages,
English/Polish rendering, anonymous rejection of draft/private/future-scheduled
content, preview URL scope and HEAD. Existing 52 migration/package assertions pass.
Windows: 41 licensing, 55 archive, 183 theme/site assertions; PHP lint 130 selected
source/theme/test files and git diff --check pass. Fresh captured QA HTTP log batch
had no PHP warnings/fatals or Core exception references (170 lines, not truncated).
Production homepage still HTTP 200; nginx/php8.5-fpm/mariadb active, unchanged.

Actual browser UI: opened builder, inspected published-page iframe at desktop 1440
and mobile 375 (document width equals viewport), edited a test heading and saved via
the button. Anonymous HTTP then confirmed that exact saved heading. Browser control
timed out during the final reload and viewport-reset attempt; do not claim final
browser cleanup succeeded. No native Windows UI automation was used. Current QA
record 1 contains that UI test heading. Reference Eduvixo demo is open in Chrome,
but logged-in side-by-side comparison has not yet been performed.

Still not complete: merging editable marketing routes/navigation/SEO/sitemap with
database content; theme switching through the console versus the private active
pointer; additional generic section renderers; full post and extension-renderer
coverage; authenticated draft preview and refreshing an already opened preview
after a save. The current draft preview deliberately returns 404, not leaked content.
Other imported educational defaults remain outside the two enabled theme sections.
Module lifecycle, public release packaging, installer enabling Workspace, production
clone/rehearsal and deployment remain release gates. No new dependencies, production
data/schema/config changes, production promotion, commit or Git push in this step.

### Product website navigation and discovery checkpoint

User clarification: www.sensecms.com is the product/distribution website, not a demo.
demo.sensecms.com will be a separate future installation. Recorded in .info. No demo
DNS, server, credentials or deployment was created. Private loopback QA is not a demo.

Theme candidate 0.1.4 connects primary/footer/footer-connect menus to the existing
editor. Unconfigured menus preserve product defaults; explicitly empty saved menus
stay empty. One repository query loads all three locations, preserving localized
labels, visibility, order and safe new-window attributes. Product routes remain
session-free but now read menu data from the database when Workspace is enabled;
theme assets and static-only installations remain independent of the database.

Managed pages render saved SEO title, description, robots, canonical, language
alternates, Open Graph, Twitter and escaped JSON-LD. Generic SEO defaults use
Organization/Sense CMS, with no missing school image fallback. Sitemap combines the
static product routes and published active-facility pages/posts, excluding per-locale
noindex settings. Discovery routes now reach Workspace, with no-store for sitemap;
robots excludes administration. Existing marketing page copy remains theme-owned;
this step does not claim all product sections are editable from the panel.

Final result including empty-menu save/render/restore: 248 HTTP checks and 188 theme/site
checks passed on the remote QA host. 52 migration/package checks, 41 licensing and
55 archive checks passed; PHP lint 131 files and git diff --check passed. Retrieved
completed remote logs after the interrupted tool session; controller/layout SHA-256
match local files. No browser testing in this step. Nginx/FPM/MariaDB were active and
production homepage returned 200; production code/data/infrastructure unchanged.

### Signed theme activation checkpoint

Resolved the split between the console's database active_theme flag and the private
storage/theme.json pointer used by the public renderer. Workspace CmsRepository now
reads the actual private active release; per-theme settings cannot inherit another
theme's legacy global configuration. Content and extension data are unchanged.

ThemeManager retains a validated inventory of signed releases. Panel ZIP installation
stages an inactive release; exact-release activation and rollback recheck current
publisher trust, archive hash, payload hashes, PHP/Core compatibility and the rich
Workspace contract before atomically changing the pointer. CLI installation retains
its existing activate-by-default behavior. Both activation paths reject conflicting
legacy theme identities. Inventory is capped at 80 retained releases to stay within
the runtime state size limit; no automatic deletion or retention UI was introduced.
Legacy DB-only activation returns 409. Legacy package rollback/removal cannot mutate
managed private releases. New routes require appearance.manage and valid CSRF.

Console Themes shows exact versions and the true serving release, explicit activation
and previous-release recovery using the existing Eduvixo-derived components. A staged
package is not presented as live. Supported section counts reflect the real catalog.
Private themes are not reclassified as bundled source by syncBundled.

Validation: 263 HTTP checks, 60 migration/functional/package checks, 200 theme/site
checks, 41 licensing checks and 55 canonical archive checks passed. The HTTP suite
installs an alternate signed QA theme without switching, activates it through the
real controller, verifies its unique public output and console identity, rolls back,
and verifies the original public output. Invalid CSRF, missing appearance permission,
revoked trust, identity collisions and modified payloads are covered. PHP lint passed
for 144 candidate/theme/calendar/test files; git diff --check passed. Six critical
Core/controller/view/bootstrap files have matching local and remote SHA-256 hashes.

Browser QA via Computer Use: Themes visually checked at default desktop 1280 and
mobile 375x812, including release cards/buttons. Mobile document width was exactly
375; no horizontal overflow. Temporary viewport override reset successfully. No UI
activation was performed (the real activation flow was verified by HTTP tests).

SSH upload was interrupted before the new suite began; verified the old completed log
and unchanged active pointer, then uploaded successfully and ran the final suite.
The private fixture currently serves sensecms 0.1.4; previous points to qa-switch-test
0.1.4 as a result of the round-trip test. That QA-only signed release and its public
publisher key are retained only in the isolated fixture. Never copy them, QA content,
or QA trust configuration into production. Local tunnel 8874 was re-established.
Earlier PHP server process handles are no longer attached to this tool session, so
their fresh stderr stream was not inspected in this checkpoint. HTTP/test results
are verified; do not claim an exhaustive fresh PHP error-log review.

Production homepage HTTP 200 and nginx/php8.5-fpm/mariadb active. No production code,
database/schema, infrastructure, dependencies, commit or Git push changed this step.

Remaining: reconcile private theme release inventory with the generic marketplace,
update catalog and installed-package metadata (currently separate); signed themes
with dependencies/parents are not supported by this private lifecycle and fail
compatibility checks. Product-page sections still need full panel editing. Theme
configuration fields/draft preview, module lifecycle, full distribution/installer,
production-clone rehearsal and production promotion remain release gates. Do not
deploy a demo or copy QA content to production.

### Marketplace and private theme registry checkpoint

PackageManager now projects private signed theme releases into packages()/package()
without duplicating the authoritative pointer in SQL. Existing legacy database rows
are preserved but cannot override the serving version, activation flag or update
count. The selected archive is signature/checksum/identity checked against current
publisher trust; metadata is cached only within this PackageManager request instance.
Trust changes invalidate that cache. Revoked trust or a damaged archive produces an
unverified entry, not a false distribution/verified badge or silent theme switch.

Version means the actual serving release for the active theme; installed_version is
the highest retained release; pending_version distinguishes an installed upgrade
awaiting explicit activation. Inactive themes also appear in Marketplace. Reinstalling
an already staged version is rejected. Old per-package theme update endpoints are
excluded: private themes use the official/governed signed catalog routes. No schema
change, network trust expansion or production data migration was introduced.

Marketplace merges installed packages before remote entries, preventing remote
entries from hiding inactive installations. Updates compare against the highest
installed version, respect the installed channel, and bind the selected release's
exact official/catalog ID without replacing it with an older or different-channel
entry. Canonical bounded Core version ranges are supported. Cards/details separate
serving, pending and downloadable versions; managed themes link to retained releases
and do not offer unsupported legacy uninstall actions. Unverified signatures are
labelled explicitly. Controller, package manager, catalog and existing marketplace JS
were edited; tests extended in themes.php, workspace-migration.php and workspace-http.py.

Final validation: 269 HTTP, 75 migration/functional/package, 213 theme/site, 41
licensing and 55 archive checks; PHP lint 144 files, node --check and git diff --check.
Tests cover real activation/rollback reflected by Marketplace API, staged upgrades,
stale SQL metadata, skipped obsolete update URL, preserved legacy rows, revoked keys,
damaged retained archive, channel/version filtering and exact download ID selection.
An initial added GET API test omitted Accept: application/json; corrected the test
client to request the existing JSON contract, then the suite passed.

Restarted only the verified private QA PHP server (old PID 2839114, exact cwd and
command checked first) to persist stderr in /root/sense-workspace-test.SC495Gg0/
http-server.log. Current PID 3734459, still bound only to 127.0.0.1:8873. The first
captured suite found a pre-existing NotificationController warning: its imported
Telegram factory attempted to use the Eduvixo license constructor/config. Factories
now check for the central-service license capability before constructing clients,
and use Runtime's Sense license service when that capability exists. Currently
central Telegram/WhatsApp brokerage remains explicitly unavailable; no credential
headers or external service authorization were added. GET notifications degrades
without warning instead of invoking the incompatible constructor.

After this guard, 269 HTTP checks passed again; 467 fresh PHP log lines contained no
warnings/fatals/notices, uncaught errors, Core error references or failed activation
audit notices. Five changed backend/frontend files match local/remote SHA-256.
NotificationController PHP lint also passed. Production nginx/php8.5-fpm/mariadb
remained active. The local temporary upload archive was removed after verification.

Computer Use browser QA passed at desktop 1280 and mobile 375x812: cards and detail
drawer, readable metadata, close button, Manage releases navigation to the actual
release inventory. Mobile document width 375, no horizontal overflow. Temporary
viewport override reset. No package was installed or activated through UI testing.
The two similarly named QA cards are two distinct signed fixture slugs, not duplicate
entries: sensecms and qa-switch-test. Neither fixture becomes a public demo.

Remaining: real signed distribution download/install/update verification against the
published Sense catalog (only deterministic catalog selection tested here), full
system update-screen/count integration with that catalog, editable product-page
sections, module lifecycle and Workspace installer. Earlier production clone,
deployment and non-theme preview gates remain. Production homepage was checked at
HTTP 200; no production deployment, dependencies, commit or push in this checkpoint.

### Editable product homepage checkpoint

Candidate theme 0.1.5 adds ten plain-text homepage configuration fields: hero heading,
intro and description, features intro, closing heading/text, and homepage SEO title
and description. Existing layout and default copy are preserved. This is a bounded
homepage slice, not an editor for every product page or multilingual marketing copy.
Uses the existing theme contract and Eduvixo-derived configuration workspace, no new
frameworks/dependencies. PublicTheme sanitizes configuration and escapes output;
session-free public routing reads only published, per-theme settings from Workspace.

Replaced the nonfunctional legacy preview-token redirect with direct authenticated
rendering of the active signed theme's homepage, using draft configuration and the
same public navigation. Preview sends private/no-store and noindex/nofollow headers.
Inactive-theme preview is explicitly unavailable (409); this does not implement
the separate managed-page/Page Builder draft-preview workflow.

Draft/publish/discard now explicitly require appearance.manage. Their setting changes
and activity audits run in a transaction serialized by the theme_drafts setting row.
Publication and preview reject a draft whose base release version has changed until
it is reviewed and saved again. Structured field values are rejected; text limits
are Unicode-safe. Discard API returns current published values; the browser updates
its published baseline after publication, handles blocked popups and does not treat
an already-running save as a successful save. Long text fields reuse the existing
controls with four-row textareas, maxlength and consistent focus styling.

Validation: 296 HTTP, 79 migration/functional/package, 227 theme/site, 41 licensing,
55 archive checks. Local PHP lint: 127 files; private QA PHP lint: 125 available files.
Node syntax check and git diff --check passed. HTTP tests cover draft/public isolation,
private preview/SEO/XSS, anonymous and scoped access, CSRF, malformed input, publication,
discard, stale release protection and restoration of original homepage configuration.
Fault injection verifies rollback of settings, lock-row creation and audit together.
The initial rollback test assumed an audit user FK that this schema does not enforce;
changed the test to explicit pre-commit fault injection rather than altering schema.

Computer Use verified desktop 1280 and mobile 375x812, four multiline controls,
375px document width with no horizontal overflow, keyboard focus and all four action
buttons. Clicking Preview draft saved via AJAX and opened the real private homepage
preview. Temporary viewport reset and test tabs closed. The final HTTP rerun restored
original published homepage fields and removed the browser-created draft.

Synchronized only candidate source into /root/sense-workspace-test.SC495Gg0. The HTTP
fixture installed 0.1.5 through its isolated signing key; never production trust.
SHA-256 matched for fourteen changed source/theme/test files. Fresh captured HTTP
logs had no PHP warning/fatal/notice/uncaught errors or failed activity audit notices.
Production homepage remained HTTP 200 and nginx/php8.5-fpm/mariadb active. No production
deployment, database schema change, DNS/SSL change, dependency, commit or push here.
User-reported demo DNS addition is recorded in .info; public demo remains deferred.

Next: remaining marketing sections/pages, general module lifecycle and installer,
signed distribution and system update integration, then production-clone rehearsal
and storage-preserving deployment. Existing broad release gates below still apply.

### Installer distribution builder checkpoint

Found that scripts/build-installer.php still built the initial milestone: lang was
omitted, its extension allowlist rejected Workspace JSON and image/audio assets, and
the output filename collided with the already retained initial installer. Reworked
that existing script into a testable development builder, reusing package path and
case-collision validation. Required files cover the installer, Workspace entrypoint,
migrator, configuration, translations and web rules. Per-directory file types limit
web PHP to public/index.php; exact root/public .htaccess exceptions do not permit
hidden parent directories. Source links, private/system paths, malformed JSON and
recognizable private-key material are rejected. Top-level runtime and optional
package directories are excluded rather than copied from the developer installation.

ZIP creation has size/count bounds, fixed timestamps and normal 0644 file attributes.
installer-manifest.json inventories exact SHA-256 bytes; ZIP contents are checked
before atomic, non-overwriting publication, using the same hard-link mechanism as
signed package builds. This inventory is NOT a publisher signature or trusted stable
release. Core/license engine versions are unchanged; Workspace remains disabled by
default and fresh installation still creates only the initial console. Full fresh
Workspace installation/recovery, MySQL migration compatibility and release signing
remain explicit gates, not simulated completion. README now records those limits.

Created ignored local candidate .cms/releases/sensecms-install-0.1.0-workspace-dev.1.zip,
382 source files plus inventory, 8787161 bytes, SHA-256
db617ea0264f1178aac06f53a1a6486448b83998fb13a05892c49787bce36f32.
Earlier development artifacts were not overwritten. No public download/catalog entry
was published, no production source/database/runtime or demo directories changed.

New tests/installer-package.php builds twice and compares exact ZIP hashes, verifies
every source hash, checks immutable output, excluded runtime sentinels, malicious
paths/links, invalid JSON, missing translations, payload tampering and false stable
metadata. Extracted PHP files are linted, operator prepare is executed and the actual
license-first page is requested from a transient loopback-only PHP server. This test
does not activate a license, create a database or pretend to test the full installation.
Every test-owned server/temp directory is cleaned; no dependency was added.

Windows: 43 installer distribution checks passed; Linux private QA: 44 (one additional
source-directory symlink test). Existing local suites: 227 theme, 41 licensing and 55
archive checks passed. PHP lint for the two changed scripts and git diff --check passed.
Both platforms linted every extracted PHP file and booted the license-first installer
without PHP warnings/fatals. Final QA/local SHA-256 matched for builder, test and README.
nginx/php8.5-fpm/mariadb were active and the production homepage returned HTTP 200.
Only three candidate files were synchronized to the existing private QA fixture.
Local transport archive removed after verification; no commit or push in this checkpoint.

### Fresh Workspace installation and interrupted recovery checkpoint

New licensed installations now seed the initial owner, apply all Workspace migrations,
provision an installation-local encryption identity and only then write installed.json.
The router requires both installed.json and workspace.enabled, so partial schema or
an early workspace.json cannot expose the administration panel. Migration file hashes
are pinned in installing.json. Resume preserves the original owner/password hash and
valid existing Workspace identity, rejects changed migration source and unexpected
tables, and never recreates the database. Migration planning validates directories,
rejects source links and checks file bytes again before executing unjournaled SQL.

Earlier unfinished Core installs without workspace_schema retain their original Core
mode; that backward-compatibility branch remains untested by the new full-Workspace
HTTP scenario. Existing installed sites are not automatically migrated or enabled.
Full Workspace currently requires MariaDB 10.11+: new MySQL installs are rejected
before schema mutation because imported migration SQL is not yet verified there.
Installer screen and README explicitly state that limitation. Optional packages,
sample content and facilities remain absent; CAPTCHA is enabled on a fresh login.

Fresh empty Themes screen now renders the standard panel, signed-ZIP installation
entry and an empty-state message instead of returning 404 when no theme is installed.
No new UI framework or optional theme was bundled. Tests install the test-signed
product theme only after verifying that the plain installation works without it.

Verification: 69 fresh-install HTTP checks with real Chivale license validation in a
unique loopback-only fixture and disposable schema/user. Fault tests interrupt owner
seeding, then migration DDL after real journal progress; reject changed owner/password,
changed migration files, unexpected tables and invalid Workspace identity; then resume
and prove all 26 migrations complete, owner hash unchanged, no sample/extension seeds,
private state and installer closure. The suite checks nine empty-panel screens, login,
settings, password change/revocation, logout, rate limiting and signed theme activation.
CAPTCHA was disabled only in the test's disposable database after verifying its default
presence; production CAPTCHA was not changed or solved. Failed test iterations were
test-contract corrections (MariaDB ALTER privilege also permitted INDEX operations;
full-panel auth uses 422, and password confirmation field is confirm_password).

Existing private QA regression: 296 HTTP and 79 migration/functional/package checks.
Installer archive suites: Windows 43, Linux 44; extracted PHP lint/first-license-page
boot passed. Local theme 227, licensing 41 and archive 55 checks also passed. Changed
PHP lint and git diff --check passed; eight changed files match QA/local SHA-256.
Fresh Workspace HTTP logs were clean. Fresh installer logs contain expected handled
errors from fault injection, but no PHP warnings/notices/fatals or uncaught exceptions.

Built ignored local development candidate
.cms/releases/sensecms-install-0.1.0-workspace-dev.2.zip (382 source files plus inventory),
SHA-256 e36021c62520a7203386fbe95cd66c2144d58c0231b78581dd51b045eabfff82.
The development inventory now records fresh Workspace activation. This is unsigned
development output, not a published stable release or production promotion.

Four temporary fresh-install fixture directories, including their temporary license
input and local encryption files, were removed after verifying their disposable DBs
and users were gone. Final 69-check log retained in the existing private QA directory
as fresh-install-checks.log. Main QA fixture remains available; production services
nginx/php8.5-fpm/mariadb are active and www homepage HTTP 200. No production deployment,
DNS/demo changes, dependency, commit or push. Existing-site upgrade rehearsal, MySQL
compatibility, signed release distribution and broader package lifecycle remain gates.

## Existing-Core migration preflight and explicit activation

Added `scripts/migrate-workspace.php --status` (read-only database status; license
cache may refresh) and `--enable`. Default command remains schema-only. Unknown
flags fail before runtime access. Activation requires an installed licensed Core,
complete journal/tables and the same DB advisory lock as migration. MariaDB 10.11+
is enforced for this candidate; MySQL is deliberately not advertised as supported.
Preflight validates the initial Core identity on every run and all journal checksums,
rejecting unknown rows and history gaps before DDL. Status reports pending files and
missing tables; it is not an exhaustive column/index drift audit.

Explicit activation preserves a valid Workspace key and additional private state.
An absent key is generated only when no Core ciphertext exists. Empty, malformed or
mismatched identities fail closed. Existing SMTP settings, AI provider keys,
notification settings and Web Push subscriptions are checked without logging secrets.
Ciphertext scanning streams rows and restores PDO buffering even after failure.
Independent extension secret stores still require package-specific upgrade checks.
The operator sequence and matching source/DB/private-storage rollback are in the
installer README. No new dependency or framework was added.

Validation: 109 MariaDB migration/functional/package checks, including read-only
initial status, corruption before DDL, missing tables, concurrent locks, missing/wrong
keys and all four ciphertext stores. Fresh isolated installation: 77 HTTP/CLI checks,
including no-license rejection, disabled-state preservation, explicit activation,
repeat safety and existing owner authentication. Existing private QA: 296 HTTP checks.
Local theme 227, licensing 41, package 55 and installer archive 43 checks passed;
Linux archive suite 44 passed. Extracted PHP lint/boot, changed PHP lint and
git diff --check passed. Five changed source/test/README SHA-256 values match local/QA.
Fresh HTTP logs contained no PHP warnings/notices/fatals or uncaught exceptions.

Built ignored unsigned development installer
`.cms/releases/sensecms-install-0.1.0-workspace-dev.3.zip`, 382 source files plus inventory,
SHA-256 b36729054231bafa0e41d81296dbea09e8ea1798dddd3cbdb7da2ef83340b373.
Prior development archives remain untouched. This is not a stable/public release.
QA evidence in `/root/sense-workspace-test.SC495Gg0/activation-*-checks.log`;
pre-change QA source backup `pre-activation-source.tgz`. Two new temporary fresh-install
fixtures and their temporary licensing/encryption files were removed after verifying
their disposable DBs/users were absent. Local/remote transport archive removed.
Main QA fixture remains intact. Production HTTPS 200 and nginx/php8.5-fpm/mariadb
active; production application/database were not changed. No demo/DNS change or Git
commit/push. Next bounded step: protected existing-production upgrade rehearsal before
storage-preserving production promotion; do not mistake the fresh-install suite for
that rehearsal.

## Protected production-clone upgrade and rollback rehearsal

Added `tests/workspace-upgrade.py`. The Linux-only harness requires a new root-private
`/root/sensecms-upgrade-test.XXXXXXXX` directory with pristine candidate source and
private license input. It verifies the exact production identity and seven-table
initial Core before taking a bounded read-only data snapshot and transactional dump.
All writes target a cryptographically named disposable schema with separate credentials.
It copies only public theme release state/payloads; no production sessions, license
encryption key, Workspace key or database credentials are installed into the clone.
The clone validates the supplied license with its own local encryption identity.

The final run passed 120 existing-site upgrade checks. Actual production rows survive
migration (including password hashes, roles, settings and audit). Default CLI migration
does not activate the panel; explicit activation does. All 14 tested public document,
asset and discovery responses are byte-identical before/after activation. The retained
production theme is not silently replaced. Thirteen owner screens, CSP nonces, private
file denial, installer closure and CSRF rejection pass. The existing `/account` alias
still opens password settings. A random password and disabled CAPTCHA are applied only
to the clone after verifying original password preservation; no production account
credentials are used for login and no production CAPTCHA setting is changed.

Rollback restores the original seven-table database dump exactly, then boots the actual
previous production source with the clone's pre-migration private state. All 14 public
responses remain identical; original login and closed installer work. The harness then
verifies production Core rows plus installed/theme/license file hashes stayed unchanged.
Independent existing QA regression passed 297 HTTP checks, including the account alias.
No application fix was needed: the first draft used a non-existent `/features` test
route; correcting it to the verified `/platform` route resolved that test-contract error.

Final harness SHA-256 b40b2a40f5cd9d095cc1774e341a21c51deb38a59de0c99b61b5b74eef8c0046;
HTTP suite SHA-256 39bb6336f8475758c4ba7575cc598d095aeff150cc7d378a1b92239385664d09.
Local/QA hashes match. Main QA retains `production-clone-upgrade-checks.log` and
`upgrade-account-checks.log`; it also has the new harness for future isolated runs.
Four temporary rehearsal fixtures (one draft, three successful iterations) were removed
after disposable DB/user absence checks, including copied production rows, SQL dumps,
temporary license input, keys and baseline source. HTTP logs had no PHP warning, notice,
fatal or uncaught exception. No public demo, DNS or production application/DB change.

Nginx and PHP-FPM configuration checks passed; nginx/php8.5-fpm/mariadb active and
production HTTPS 200. Existing pool runs as sensecms:sensecms at
`/run/php/php8.5-sensecms.sock`; Nginx forwards application CSP without a competing
server CSP. This rehearsal used private loopback PHP, not FPM or visual browser QA.
Next deployment work: storage-preserving production rollout with a fresh backup,
short controlled cutover, runtime ownership, actual FPM/browser verification and log
checks. Keep the existing public theme until a separately signed compatible release
is promoted; calendar/other optional packages remain separate deployment work. No
dependency, Core package rebuild, Git commit or push in this turn.

## Production cutover completed; post-interruption verification

Production is NOW the enabled Workspace, not the initial seven-table Core. Do not
rerun first-install provisioning, `deploy-workspace.py`, or the initial-Core clone
rehearsal against the upgraded production schema. The one-time cutover completed
before interruption, confirmed again on the subsequent resumed turn (user-local
2026-09-07). All 26 journal entries are applied, pending/missing lists empty.

Cutover backup: `/root/sensecms-backups/20260906T164750Z-workspace/`, containing
`web-before.tgz`, `database-before.sql`, original front controller, candidate source,
candidate digest and retained cutover log/script. Candidate was the previously tested
dev.3 ZIP, SHA-256 b36729054231bafa0e41d81296dbea09e8ea1798dddd3cbdb7da2ef83340b373;
all 382 source files matched QA before deployment and live after the cutover.
`scripts/deploy-workspace.py` performed the guarded one-time overlay, kept storage,
public theme releases and user data, and applied migration/activation as sensecms.
The measured migration-and-verification interval was 4.6 seconds after an additional
maintenance readiness/drain wait; this is NOT a measurement of total user downtime.
No DNS, Nginx/FPM configuration, demo, package publisher trust or owner password change.

The actual owner authenticated BEFORE cutover. That authenticated session survived
the migration. Thirteen owner screens were checked directly through the real FPM pool
and then HTTPS, with the Workspace shell and nonce CSP. Fourteen public page/asset/
discovery responses matched their pre-cutover bytes. Accounts, password hashes,
role assignments, installed config, license encryption keys and theme state were
compared unchanged. Installer/private paths returned 404; hidden temporary controller
was removed. Full post-cutover browser sign-in has NOT been completed: CAPTCHA remains
enabled and must be completed by the user, not bypassed for QA.

Core code remains root:root 0644/0755. `storage/workspace.json` is sensecms:sensecms
0600. Runtime package roots themes/plugins/addons are sensecms:sensecms 0750.
Public media and custom sound roots are sensecms:www-data 02750, allowing runtime
writes and Nginx group reads without writable Core source. Upload and package mutation
permissions have been inspected, but no real production upload/package install was
performed as a smoke test. Calendar and other optional packages remain separate work.

Browser verification found a leftover `Education CMS` line in the login template.
Changed it to `Sense CMS` only, added a regression assertion, ran 298 private QA HTTP
checks successfully, then atomically published the linted template. Prior view is saved
as `login-before-branding.php` in the cutover backup. Final login.php SHA-256:
5bd350485dce30e908bfc77fd42daabd2db8ba6ad5081b1585886a02301e5aaa (local/QA/live match).
Only this reviewed view differs from the tested dev.3 inventory; the dev.3 ZIP itself
was not overwritten and does not include the branding correction.

Fresh checks after resumption: live Workspace enabled, schema ready, HTTPS 200;
all 14 login asset/CAPTCHA GETs and four page/CSS HEAD checks pass. Browser desktop
login and loaded CAPTCHA render correctly (initial screenshot preceded image load).
No CAPTCHA was solved, no credentials were typed through browser automation. Nginx
and PHP-FPM configuration tests pass and nginx/php8.5-fpm/mariadb remain active;
no production php-error.log exists. git diff --check and changed PHP lint pass.
QA evidence: `post-cutover-http-checks.log` in the persistent private QA directory.
Cutover transport archive retained in backup; staging directory removed after checks.
No Git commit/push or public stable release publication. Next: user-authenticated
visual comparison against Eduvixo, optional calendar deployment and public-theme
management/distribution completion; do not describe these as already finished.

Finish the remaining public-theme/console integration and preserve marketing routes. Verify all
console screens and mutations, facility scoping, CSRF, password/session revocation,
calendar categories/events, responsive layout and branding. Reconcile legacy
extension/update contracts with signed Sense packages; never enable a foreign trust
root or publish development-source packages as verified releases. Rehearse on a
protected production clone, back up production, then use a storage-preserving
deployment with explicit migration and rollback. Inspect Nginx CSP/header behavior.

The user files `.src/` and `web/favicon.*` are unrelated and must be preserved.

## 2026-09-07 — Product theme and Core-owned public paths (deployed)

Latest production state supersedes the earlier public-theme integration TODO:
Sense product theme **0.1.6** is signed with the existing production publisher and active.
15 product pages and 3 navigation locations were imported into the production CMS;
an active primary Sense CMS facility was created because production had no facilities/pages.
No existing page/menu was overwritten; importer preserves existing paths and refuses slug conflicts.
`scripts/publish-product-pages.php` is product-site tooling, excluded from clean Core.

Core migration `029_public_page_paths.sql` adds nullable unique `pages.public_path`.
An owner can assign `/`, `/platform` or nested addresses in the page editor. Content,
publication, visibility, URL ownership and canonical/sitemap handling remain independent
of the theme. Draft/private/archived paths reserve their address and return 404, never
silently falling back to theme starter text. Other languages retain localized URLs.
Page listing/editor/builder previews use the saved public address. Core reserved paths
and traversal/query/fragment input are rejected. Theme asset serving supports safe
nested CSS/JS/image/font/video paths rather than three branded filenames.

Theme presentation follows Eduvixo's light blue gradient, heavy navy/blue type,
framed composition, navy information band and cards, with general-purpose copy.
Hero artwork is a native architecture illustration, not a screenshot of the real panel.
Common block rendering is shared between starter and database-managed pages.
Marketing menus have real routes; only the accessibility skip link uses a fragment.
Developer contract is published at `/docs/themes`.

Acceptance evidence: 351 local theme/URL/package-lifecycle checks, 41 licensing,
55 package, 43 installer distribution checks; private MariaDB 109 migration checks,
312 Workspace HTTP and 92 managed-product HTTP checks (including switching to a
different signed theme, preserving content/URLs, withdrawing/republishing and rollback).
The first resumed failure was an outdated sitemap assertion expecting the localized
instead of canonical path; corrected the test. Product editor restoration test must
send datetime-local values with minute precision, matching the actual editor contract.

Development Core artifact `.cms/releases/sensecms-install-0.1.0-workspace-dev.4.zip`:
384 source files, SHA-256 `e33dcd105bb5b62f2370809d690a4e4cc86efee5f7a1d05ceaa25a810b726900`.
Unsigned development distribution, not a stable public release. Licensed engine ID unchanged.
Production source changed only 11 reviewed files, checksums verified after deployment.
Database now reports 27 applied migrations, no pending or missing tables.
Backup `/root/sensecms-backups/20260907T023801Z-product-pages/` contains full pre-cutover
web/private storage, maintenance-window database dump, signed theme 0.1.6, import log,
candidate digest and deployed-file inventory. `scripts/deploy-product-site.py` is a
one-time guarded cutover, not a repeatable generic deployment command. Its failure path
restores prior source/theme pointer, preserving additive schema and content for recovery.

Verified 20 production GET+HEAD routes, no fragment menu links or QA markers, nginx and
PHP-FPM config checks, all services active, no production php-error.log. Browser confirmed
15 published pages in the actual logged-in panel and desktop layout; mobile menu opens
and closes with Escape. DOM width checks at 360/390/768/1280 showed no horizontal overflow;
no browser errors. Computer Use was used for visual comparison and acceptance.

Known follow-up: complete product-site localization and final editorial/design polish;
align legacy static-home SEO fields in theme settings with the managed page SEO editor
(managed SEO currently correctly comes from the page/SEO workspace, not those legacy fields).
Full generic package/module lifecycle and public stable distribution still need release gates.
Demo remains deferred; Cambo Jumbo unchanged. No Git commit/push was requested or performed.
Private QA now includes imported product pages: the older Workspace HTTP test assumes a
static, session-free homepage; use `tests/product-pages-http.py` for the current fixture,
or a separate pre-import fixture for the full Workspace test. Do not delete QA records blindly.

Do not rerun first-install production provisioning. Do not rerun preview provisioning
over the existing fixture; synchronize candidate source only. Stop both loopback
servers and remove only validated QA database/user and private staging when finished.

## 2026-09-07 — Version-independent licensing policy (deployed)

User clarified free packages require the CMS key, paid packages require a separate
product key, and updates must not require a new key merely because a version changes.
`LicenseClient` now verifies exact name/model and validity dates without comparing
provider or encrypted-cache version. Required Chivale request field ProductVersion
remains the fixed licensing identity **1.0**, unrelated to Core/package releases.
Provider rejection, canonical-domain binding, key format, expiry, encrypted-cache
integrity and refresh TTL are unchanged. This does not remove package compatibility
or upgrade/downgrade checks.

Added shared `Packages/Entitlement` policy and integrated official download requests.
Explicit `pricing=free` selects the configured Sense CMS identity; `pricing=paid`
requires a distinct package name/model. Missing/unknown pricing fails closed. Fixed
the old client sending engine version in X-SenseCMS-Version. Keys are sensitive
parameters and remain in headers, not URLs. The distribution server must select its
own trusted inventory entry: caller headers are never authorization evidence.

Verification: 70 licensing checks locally and on Linux QA; 55 package, 351 theme/URL
and 43 installer distribution checks locally; 92 managed-product HTTP checks on QA.
Real Chivale validation accepted the existing Sense CMS key for www.sensecms.com;
no private response fields/keys were logged. Paid product tests use isolated fixtures,
not registered commercial products or real paid keys.

Three production files deployed with guarded `scripts/deploy-license-policy.py`:
LicenseClient.php, PackageManager.php, Packages/Entitlement.php. Local/remote SHA-256
match, PHP lint and Nginx/PHP-FPM configuration checks passed, graceful FPM reload.
Backup: `/root/sensecms-backups/20260907T033934Z-license-policy`; contains prior two
files plus before/after hashes. No database, runtime storage, theme or infrastructure
configuration changes. Automatic failure rollback restores old files; the unused new
class may remain for inspection. Script refuses re-running against a changed baseline.
Post-deploy: production license cache valid under the sensecms Unix user; GET/HEAD
200 for home, licensing docs, download and login; all three services active; no
production storage/php-error.log. Five routes also passed deployment health checks.

Remaining: actual server-side license-gated package distribution, browser download
UI, trusted inventory/catalog publishing and full package lifecycle/release gates.
This deploy does not make public package downloads or paid licensing live. No stable
release or new installer artifact published. Existing dev.4 installer predates this
change. Do not ask for speculative license products: when paid packages are selected,
provide exact distinct name/model pairs with fixed 1.0 to register and test. General
CMS key is already available; no replacement key is currently needed.

## 2026-09-07 — Product homepage redesign 0.2.0 (deployed)

User rejected the earlier presentation and explicitly requested the redesigned theme
on production for review. Theme-only work: new `views/home.php`, `assets/product.css`,
real QA workspace capture `assets/workspace.png`, layout integration and descriptor
0.2.0. New editable theme settings cover features/ecosystem/ownership headings and
descriptions; existing homepage settings retained. Changed default hero copy to
“Bring it all together. Make it your own.” Existing saved overrides are not overwritten.
Production had no saved overrides for those headline fields; QA does have old values.
Managed page blocks, URLs, menus, user data and Core source are untouched.

Design: light blue editorial hero, full-width real workspace image, concise principles,
managed feature cards, dark ecosystem diagram, ownership section, contact/release CTA.
Shared typography/navigation/article styling also applies to existing subpages; their
content and structure were not comprehensively rewritten. Static design labels remain
in the theme; the main section copy is configurable and existing feature blocks editable.
Do not claim complete website localization or release distribution from this redesign.

Image is an actual capture from the isolated private QA dashboard with synthetic
records, not production customers or a generated interface. Caption and alt explicitly
identify development/example data. Capture script `scripts/capture-workspace.cjs` uses
existing Playwright and installed Chrome; no new dependency. It initially failed due
to missing bundled Chromium and, after interruption, a stopped SSH tunnel; both resolved.
The image has no contact details or credentials. Original `.src` logos are untouched.

372 theme/contract/URL checks passed locally and on Linux; updated lifecycle assertions
to derive source and next versions instead of hard-coding 0.1.6/0.2.0. 92 managed page
HTTP checks passed on QA including editing/publishing and switching/restoring themes.
Computer Use inspected the redesign and actual production. Widths 360/390/768/1280/1440
had no horizontal overflow on QA; production mobile image/menu/Escape checks passed.
All public images loaded and browser warnings/errors were empty in the checked view.

Theme-only deployment script `scripts/deploy-product-theme.py` requires --qa then
--production, exact accepted source hashes, previous active 0.1.6, and retains backups.
Candidate transfer SHA256: e5f73ce6c0dc0201058fae0f1459498e17704fdbcf2426373806078d48359d60.
QA package uses an independent ephemeral publisher (`sensecms-qa-design-020`), not
production signing credentials. Production package signed with the existing publisher.
Production active directory: `sensecms-0.2.0-c074b2a6841cb6e8`.
Signed ZIP SHA256: c074b2a6841cb6e8f0b8864cd97290340243699f2aa024605fddcdcc8d0ccc4c.
Backup: `/root/sensecms-backups/20260907T085001Z-product-design/` with prior pointer,
complete retained theme storage, candidate hashes and signed ZIP. Previous active 0.1.6
is retained for ThemeManager rollback using existing publisher trust.
New release was staged inactive, ownership repaired, then activated as sensecms user.
Signature and installed payload hashes reverified. No production SQL, Core, service or
infrastructure configuration changes. Nginx/FPM config checks pass; all services active.
Production 18 GET/HEAD checks on pages and new assets returned 200; matching local/remote
asset hashes; no production storage/php-error.log. New headline and CMS menus verified.

No Git commit/push, no public Stable installer or downloadable package published.
Demo and Cambo Jumbo unchanged. Product is now live for the user's visual feedback.

## 2026-09-07 — Platform subpage and return-to-top 0.2.1 (deployed)

User accepted the homepage and requested a stronger Eduvixo-style subpage hero plus
the same TOP control placement. Inspected the live `/en/product/` reference and its
actual CSS/JS. Implemented public-theme-only `views/product.php`, declared selectable
`product` template (Platform presentation), and connected managed/starter rendering.
Dark full-width hero, CSS orbit/logo, real existing QA workspace screenshot, first
editable block as overview, remaining editable blocks as capability cards. All blocks
retain the shared sanitizer; no Core/panel changes or new dependencies. Homepage
presentation unchanged apart from the global return control and focus destination.

TOP: fixed 42px circle, desktop right 24px/bottom 78px; <=760px right 16px/bottom 18px.
Appears after 480px scroll, hidden from keyboard/accessibility tree before that; returns
focus to main, smooth scroll unless reduced motion requested. No fragment menu links.

386 local and Linux theme/contract/security checks passed; 92 private QA HTTP checks
passed including managed page withdrawal/restoration and signed theme switching.
PHP lint, JS syntax, Python deployment syntax and git diff --check passed. Browser QA
at widths 360/390/768/1024/1440 found no horizontal overflow; mobile menu/Escape and
TOP click tested. Live desktop and 390px mobile TOP positions match reference; click
and Enter return to scrollY=0/main focus. Production images loaded. Twenty GET/HEAD
checks (public pages/login/assets) returned 200. Deployed CSS/JS SHA256 match source;
active signed theme verified; nginx/php8.5-fpm/mariadb active, PHP error log absent/empty.
An initial extra lint check used release root instead of its `payload/` subdirectory;
corrected after checking ThemeManager, then all three deployed PHP views passed lint.

Guarded deployment now targets 0.2.1 from 0.2.0 and requires exact accepted QA inventory.
QA uses independent publisher sensecms-qa-design-021; production signer stays private.
Production active: `sensecms-0.2.1-a8f064c5aac9853f`.
Signed ZIP SHA256: a8f064c5aac9853f1a7c7e4705d76051a0925d19d3e1f4b0fdfece6fcc35d1a5.
Production backup: `/root/sensecms-backups/20260907T104407Z-product-design/`.
QA backup: `/root/sense-workspace-test.SC495Gg0/20260907T104306Z-product-design/`.

Only production database change: existing facility 1 page id 2 `/platform` template
`default` -> `product`, guarded against concurrent template/path changes. No text,
blocks, SEO, menus, publication timestamps or schema altered. Exact prior assignment
saved to backup/platform-before.json; full prior theme storage and pointer backed up.
Rollback: activate retained signed `sensecms-0.2.0-c074b2a6841cb6e8` with existing trust
as runtime user and restore only that page's template from the backup if still product.
Never restore a stale full database. Deployment failure handler restores its own
template assignment and prior pointer, retaining candidate for diagnosis.

Remaining scope: this redesign covers `/platform`, not every subpage. Other pages gain
TOP only. Demo/Cambo/installer/package-download availability unchanged. No Git push.

## 2026-09-07 — Subpage design and native contact form 0.3.0 (deployed)

User requested stronger design across subpages, then a real Contact form with OUR
native CAPTCHA, branded sender copy and an explicit test to mario@ittsp.com.
Delivered redesigned `/contact`, `/extensions` and four category pages, `/docs` and
five guides, `/download`. Homepage and Platform retained. New shared subpage view:
light or navy hero, breadcrumbs, extension category navigation, resource cards,
sticky guide sidebar and previous/next real URLs, contact aside. No anchor menu links.
Managed content still uses the shared sanitizer; static starter code examples preserved.

Theme supports Core `contact-form` blocks and renders accessible fields, consent,
native PNG CAPTCHA with refresh, AJAX submission, disabled/busy state and honest
uncertain/failure messages. Existing contact page blocks preserved after the new form.
Form UID `03000000-0000-4000-a000-000000000001`, production page 10 / facility 1.
Setup used PageBuilder validation/versioned save, preserving original block identities,
translations and content. Panel inbox receives submissions; copy checkbox configurable
in the block. No database schema migration, framework or external CAPTCHA provider.

Core changes (eight files): FormController, CmsRepository, PageBuilder, EmailSystem,
MailService, new FormMail, app/workspace.php, config/page-builder.php. Reused existing
CAPTCHA, inbox storage and SMTP service. Fixed empty-CSRF comparison, malformed-input
handling, unknown-form CAPTCHA issuance; sender copies force native CAPTCHA independent
of login toggle. Session attempts include failed CAPTCHA; durable IP/submission limit
remains; recipient copies limited to four stored submissions per rolling day.
Sender copy requires storage + CAPTCHA in builder validation. Copy result is separate
from inbox success: SMTP outage leaves the enquiry stored and reports copy failure.
No automated mail queue/retry is claimed. FormMail has HTML + plain text, escaped fields,
reference, installation-configured sender name/address, no theme/customer hardcoding.
SMTP now handles partial writes, CRLF/dot stuffing, quoted-printable line lengths and
does not falsely fail accepted DATA solely because the peer drops QUIT.

SMTP had not previously been configured in this installation. Loaded .cfg/Email.txt
through root-only staging, configured existing EmailSystem with SSL/465 and verified
actual provider delivery handoff. Password encrypted using THIS installation's key in
settings; private staging file removed. No credentials in repository or logs. One
explicit test message sent to user-requested recipient, accepted by SMTP. Receipt was
rendered by the exact production FormMail and transport, with a [TEST] subject. We did
not submit a fake visitor enquiry to production or claim inbox/spam-folder delivery.
Idempotent operator markers `test-mail-started` / `test-mail-accepted` prevent reruns.

Verification: 422 local theme/URL/sanitizer/branding checks; all modified PHP linted,
JS syntax, Python syntax, git diff --check. 92 QA managed HTTP tests plus 24 contact
checks against a private loopback SMTP sink: CSRF, honeypot, invalid CAPTCHA/email,
single-use/replay, exact recipients/count, Reply-To, HTML injection escaping, plain
text, dropped QUIT and SMTP outage/inbox preservation. Repeated contact acceptance
after installation-neutral branding refinement. QA sink never sends external mail.
QA synthetic submissions remain private; future contact-suite runs need a fresh
fixture or reviewed cleanup of this test form's visitor@example.test records to avoid
the intended rolling rate limits. Expected SMTP failure entries are QA-only.

Browser checked contact/guide/extensions/release at 360/768/1024/1440: no horizontal
overflow and one H1. Contact desktop/mobile full-page and email browser rendering
inspected; native CAPTCHA refresh works live. E-mail client-specific Outlook/Gmail
rendering and receipt in the recipient inbox remain unverified. Production 36 GET/HEAD
checks passed; missing CSRF returns 419 without mail, CAPTCHA returns PNG/no-store.
Every installed Core/theme payload hash matches accepted QA inventory. Signed active
theme independently verified, nginx/php8.5-fpm/mariadb active, production PHP log empty.

Release: `sensecms-0.3.0-a9adbb15f7b3707e`.
ZIP SHA256: a9adbb15f7b3707e48b4b62911d2d929edc5ef380e879499f08e0ce2ca8fc938.
Production backup: `/root/sensecms-backups/20260907T112210Z-product-design/`.
QA backup: `/root/sense-workspace-test.SC495Gg0/20260907T111544Z-product-design/`.
QA refined three Core files after first acceptance; original versions retained in
`brand-refinement/`; contact acceptance rerun before accepted inventory updated.
Deployment source is private `candidate-030/`; script requires exact prior Core hashes,
active 0.2.1, accepted source inventory and independent QA publisher. Existing publisher
key never leaves production private storage. Archives and backup metadata retained.

Rollback: run setup-product-contact.php --rollback against the saved backup to withdraw
ONLY the newly added form block and restore prior mail settings; it retains submitted
messages and original/concurrent page content. Restore eight Core files from `core/`
(FormMail is new), activate retained signed 0.2.1 as runtime user. No full database
restore. Script automatically restores its changed Core files/pointer and withdraws
the new form on deployment failure. Initial extra verification used a source manifest
as if it were a payload file; corrected to follow signed archive layout, then all checks
passed. No infrastructure/service reload, demo/Cambo change, Git commit or public
stable package publication. Developer-only /_email-preview added to existing loopback
preview script, never to production routing.

## 2026-09-07 — Editorial theme refinement 0.3.1

Continued the accepted public design without changing Core, forms/mail behaviour,
database content/schema, infrastructure, demo or Cambo Jumbo. Contact renders its
managed form in the main column and all remaining CMS blocks exactly once in a
details column, replacing redundant hardcoded advice. Shared safe block rendering
and native CAPTCHA are preserved. Documentation now has a featured installation
entry, consistent category icons, a quieter guide hero and bordered reading area.
Extension categories reuse the same static SVG icon system; responsive/focus states
are included. No dependencies added. Changed theme payload: theme.json,
views/subpage.php, views/blocks.php, assets/product.css; package manifest 0.3.1.

Added 12 regression assertions (434 total passed), JavaScript syntax and diff checks
passed, all candidate PHP linted remotely. Signed private QA installation passed
92 managed HTTP checks before production accepted the exact source inventory.
Computer Use verified documentation, guides, extensions and contact at desktop,
390px mobile and 768px tablet widths: no horizontal overflow. Production CAPTCHA
refresh/image loaded, CSRF present in the actual HTTP response, no browser errors.
No additional test mail sent; the prior SMTP acceptance is not a new delivery claim.

Production release: sensecms-0.3.1-844bef2bd1aa1e5d.
Archive SHA256: 844bef2bd1aa1e5d385a54a2d7b9cf4a16d33ff7986767e89044cd0c75713c32.
Backup: /root/sensecms-backups/20260907T123809Z-product-design/.
QA backup: /root/sense-workspace-test.SC495Gg0/20260907T123506Z-product-design/.
Source: private candidate-031; accepted inventory product-design-031-accepted.json.
Deployment uses scripts/deploy-product-theme.py --qa/--production --theme-only,
requires previous 0.3.0, stages the signed theme, repairs ownership then activates
as runtime user. No Core copy or contact setup operation in this mode. All 15
installed payload hashes match accepted source; signed activation reverified.
36 production GET/HEAD checks passed; nginx/php8.5-fpm/mariadb active. No new
service error events. Nginx log timestamps are CEST (UTC+02), not UTC; earlier
FastCGI disk-buffering warnings predate this deployment. Configured PHP error log
is absent, not a populated log containing failures.

Rollback: activate retained signed sensecms-0.3.0-a9adbb15f7b3707e using ThemeManager
and existing trusted publisher as sensecms runtime user; no database restore or Core
rollback needed. The deployment also preserves theme-before.json and theme storage
archive and automatically restores its pointer if acceptance fails. Do not rerun
the deploy command blindly after successful activation; its baseline guard prevents it.

## 2026-09-07 — Unified approved hero style 0.3.2

User explicitly rejected mixed light/dark subpage headers and selected Contact's
navy gradient as the common style. Replaced separate palettes with one rule for
home/platform/subpage heroes. All subpage title typography, spacing, breadcrumbs
and emblem geometry now use the same selectors, including individual docs guides.
Removed guide-specific size/spacing overrides and obsolete Platform orbit styling;
Platform uses the shared subpage layout/emblem, retaining its useful CTA and facts.
Homepage retains its composition with the common navy palette and readable accents.
Category tabs have light labels/active indicators against the dark background.
No Core, database, form, email, infrastructure or dependency change.

437 local checks, JS syntax/diff checks, remote PHP lint and 92 private QA HTTP
checks passed. Computer Use checked desktop/mobile (390px), then production computed
styles on six subpage types: identical background, white titles, identical font size,
no horizontal overflow. Home shares the palette while retaining its landing layout.
36 production GET/HEAD checks and all 15 deployed payload hashes passed; signed
activation verified; nginx/php8.5-fpm/mariadb active, no PHP error log generated.
Existing FastCGI temporary-buffer warnings are operational, not rendering failures.

Release: sensecms-0.3.2-8de41709f6a01c20.
SHA256: 8de41709f6a01c202fae9dc792abfc85dda29e97911af7619d5fa205b0416146.
Backup: /root/sensecms-backups/20260907T125948Z-product-design/.
QA backup: /root/sense-workspace-test.SC495Gg0/20260907T125859Z-product-design/.
Candidate/accepted inventory: candidate-032 / product-design-032-accepted.json.
Payload delta: theme.json, views/product.php, assets/product.css; package manifest
version also bumped. Theme-only deploy expects 0.3.1; rollback is signed activation
of retained sensecms-0.3.1-844bef2bd1aa1e5d, without restoring any application data.

## 2026-09-07 — All existing package sources built and accepted privately

Prepared addon:calendar 0.1.0 and theme:sensecms 0.3.2, the complete implemented
package inventory. Plugin/module directories have no products; no placeholders were
invented. Added operator-only scripts/build-packages.php: inspect all source roots,
reuse canonical Archive builder, protected external signing key, complete private
staging, serialized/no-overwrite release directories, signed inventory and SHA256SUMS.
Existing production publisher key stayed on the server. Public-key metadata is not
an independently trusted root. No Core, public route, active theme, pricing or database
change was deployed. These artifacts remain development/unpublished.

Private server release: /root/sensecms-private/packages-20260907/.
Local copy: .cms/releases/packages-20260907/ (gitignored).
addon-calendar-0.1.0.zip: 69475 bytes,
SHA256 329c9fc0f98594e35537735865a3df1d8994406c20418d648a02815a8cdacbdd.
theme-sensecms-0.3.2.zip: 335444 bytes,
SHA256 8de41709f6a01c202fae9dc792abfc85dda29e97911af7619d5fa205b0416146.
The theme ZIP is byte-identical to the active signed production release.

Acceptance source: /root/sense-workspace-test.SC495Gg0/packages-candidate-20260907/;
uses existing private QA Core source via .cms/source symlink, never production storage.
tests/package-release.php verified exact final ZIPs using independently retained
/root/sensecms-private/trust.json, installed into new disposable MariaDB/application
state, created calendar category, uninstalled/reinstalled with unchanged category
data, activated theme and rendered five routes: 20 assertions passed. Disposable
DB/directory removed automatically. No SMTP, real recipients or production data used.
109 separate migration/functional/package lifecycle checks passed including signed
update, rollback, revocation, tampering and preservation after failed migration.
Fixed stale 0.1.6/0.2.0 theme assumptions in this test; versions now derive from source.
First artifact test incorrectly indexed associative theme releases by zero; corrected
to array_values and reran clean acceptance successfully.
Local 55 archive and 70 license tests, PHP lint, calendar JS syntax, diff checks passed.
Downloaded ZIP signatures/hashes independently reverified locally. Existing release
overwrite was rejected with byte-identical inventory preserved. Production four-route
HTTP smoke passed, active theme remains sensecms-0.3.2-8de41709f6a01c20.

Open: free/paid classification. User replied 'zrob wszystkie' to classification
question; this authorizes preparing all packages but does not select pricing.
Public catalog, license-gated download endpoint/UI and actual paid-license acceptance
remain unimplemented; do not claim that preparing ZIPs completes distribution.
No public download URLs were created. Ask for the exact classification before public
release; paid products need distinct registered name/model pairs (protocol version 1.0).

## 2026-09-07 — Public package catalogue and theme 0.3.3

Supersedes the previous pricing question: user explicitly instructed using the
live Eduvixo marketplace. Verified all 13 entries by HTTPS. Free: official theme,
Shoudu theme, Google Analytics, AI Translation Assistant, Windows client. Annual
USD: Core 360, Calendar/iFirewall 120 each, Google/Apple/Microsoft calendar 12 each,
Telegram/WhatsApp 48 each. Do not ask for classification again.

Published `/extensions/catalog`, 13 detail pages and four category pages as normal
CMS-managed content, with entry cards on the six existing extension/download pages.
`.src/package-catalog.php` is official-site content, not portable Core or trusted
download authorization metadata. No unadapted Eduvixo executable was published.
All availability notices explicitly distinguish prepared development ZIPs from
pending adaptations. Public downloads/purchasing remain unavailable.

Theme 0.3.3 fixes catalogue breadcrumb/category navigation using the existing navy
design. No Core/schema/dependency/infrastructure changes. Signed production release
sensecms-0.3.3-238c1bbc0734baad; SHA256
238c1bbc0734baadb95d9edafb8f95eaa96747ba39c04efef80daf484bf76ec2.
Theme backup: /root/sensecms-backups/20260907T150653Z-product-design/.
Content journal and database dump:
/root/sensecms-backups/20260907T150654Z-package-catalog/.
Operator source: /root/sense-workspace-test.SC495Gg0/catalog-candidate-20260907/.
Theme QA candidate/acceptance: candidate-033 / product-design-033-accepted.json.

Validation: 437 local theme checks; 92 existing QA page checks; new read-only
tests/package-catalog-http.py passes QA and production (18 GET/HEAD routes,
13 prices/licence policies, six entry links). Original content on all six updated
pages independently compared against the publication journal, in QA and production.
Initial comparison incorrectly included expected sort_order/updated_at changes;
inspection showed only insertion offset and timestamps changed. Corrected the
verification, with all substantive fields unchanged. Desktop production and 390px
QA detail checked through Computer Use; no horizontal overflow on mobile.
Nginx, PHP-FPM, MariaDB active; no new priority 0..3 service journal entries.

Rollback: publish-package-catalog.php <production-root> --rollback <content-backup>
withdraws only new pages and hides only injected entry cards; preserves content.
Theme rollback reactivates the retained signed 0.3.2 release. Do not restore the
entire database over newer customer edits; the private SQL dump is a recovery fallback.
Remaining: adapt remaining products and implement live license-gated downloads;
paid products need actual Sense-specific provider registrations/acceptance.

## 2026-09-07 — Marketplace presentation matched to Eduvixo

Inspected live Eduvixo marketplace visually. Replaced numbered editorial cards on
catalogue collection routes with icon/category product cards, price/licence badges,
aligned detail actions, search, category and pricing filters, result counts, reset
and empty state. Existing navy hero remains unchanged. Responsive 3/2/1-column
layout and a wide desktop application card; consistent theme tokens and SVG strokes.
Details remain separate real URLs. Downloads are still explicitly unavailable.

Theme-only change: new views/marketplace.php reads the existing CMS text blocks,
sanitises/parses their established paragraphs, and preserves unknown/custom content
using the shared block renderer. No inventory or credentials copied into the theme;
no database writes, Core changes or dependencies. Cards remain visible without JS;
filter controls appear only after enhancement. Filters use textContent, not HTML.

443 local theme checks, PHP/JS syntax and diff checks passed. Existing 92 QA HTTP
checks and catalogue 18 GET/HEAD routes with 13 price/licence checks passed.
Computer Use checked desktop and 390px mobile, category+price combinations, search,
empty state and reset. Initial reset relied on a microtask before browser default
reset completed: replaced with explicit reset values and synchronous rendering.
Verified corrected QA sequence 0 -> 13 results and production 5 -> 13 results,
with matching visible card counts and no horizontal overflow.

Final production release: sensecms-0.3.5-3a98b919e93df067; SHA256
3a98b919e93df06786da8188ef997a9eb7fd5e2caaa53834a2e790fab2d8228d.
Candidate/acceptance: candidate-035 / product-design-035-accepted.json.
Backup: /root/sensecms-backups/20260907T151929Z-product-design/.
Pre-marketplace backup: /root/sensecms-backups/20260907T151722Z-product-design/.
QA final backup: /root/sense-workspace-test.SC495Gg0/20260907T151856Z-product-design/.
Signed activation reverified all payload hashes; nginx/php8.5-fpm/mariadb active,
no priority 0..3 nginx/FPM journal events since initial marketplace deployment.
Full rollback should activate retained signed 0.3.3, not intermediate 0.3.4 with
the reset race. Do not restore CMS database for a theme-only rollback.

## 2026-09-07 — Primary marketplace route and in-place product UX (0.3.6–0.3.7)

User correctly identified that /extensions still led to an editorial intermediary.
Promoted the complete catalogue to /extensions via scripts/promote-marketplace.php.
All 13 cards now use shared CMS sections across the primary page, old catalogue and
category pages, so editing an existing linked card updates those views. Detail-page
copy remains separate CMS content. Existing six entry blocks archived, not deleted;
confirmed individually in production. All previous public detail URLs remain valid.
Updated primary hero copy and added category chips, a decorative circular hero motif,
and two-column desktop grid matching Eduvixo. No Core/schema/dependency changes.

Promotion rollback rehearsed on QA, then reapplied in a new journal directory.
Production content journal + database dump:
/root/sensecms-backups/20260907T152937Z-marketplace-entry/.
Theme 0.3.6 backup: /root/sensecms-backups/20260907T152938Z-product-design/.
Promotion operator: /root/sense-workspace-test.SC495Gg0/candidate-036/scripts/promote-marketplace.php.
Rollback operator restores the six exact pre-promotion documents, detaches and archives
the created shared sections. Review newer edits before invoking; do not restore SQL
over newer customer data. Promotion itself was validated by 19 GET/HEAD routes,
all card policies and exact shared IDs between /extensions and /extensions/catalog.

User further clarified the interaction must match Eduvixo, not navigate to dead ends.
Inspected live Eduvixo details modal and licence-download modal (no key submitted).
0.3.7 adds one native dialog populated from the selected CMS card using textContent;
card/title/details open in place, separate Download status opens the availability
section. No network request, licence collection or simulated download. Native modal
focus containment, Escape, backdrop close, Back to results, focus restoration and
unchanged filters/URL. No-JS users retain real detail links; status buttons stay hidden.
Actual license-gated downloads are still unimplemented and explicitly unavailable.

448 local theme checks, PHP/JS syntax, diff checks, 92 QA page checks and 19 production
marketplace GET/HEAD routes passed. Computer Use verified category+pricing+search,
reset, paid/free dialog copy, Escape/focus return, keyboard Space and 390px modal
without horizontal overflow. Production: free theme opens on /extensions, closes
back to five filtered results with focus on original action; reset restores 13.

Final signed theme: sensecms-0.3.7-c071c87fdbbf0976, SHA256
c071c87fdbbf09764387b798d7bfd93659db807e86312599bcd13abef428a324.
Production theme backup: /root/sensecms-backups/20260907T153903Z-product-design/.
QA theme backup: /root/sense-workspace-test.SC495Gg0/20260907T153751Z-product-design/.
Source/acceptance: candidate-037 / product-design-037-accepted.json.
Nginx, PHP-FPM and MariaDB active; no priority 0..3 nginx/FPM journal entries during
the checked post-deployment interval. Signed activation verified installed payloads.
Theme rollback to retained 0.3.6 restores preceding interaction without CMS changes.

## 2026-09-08 — Real license-gated theme distribution (0.3.8)

Implemented opt-in Packages/Distribution and DistributionController, dispatched
before public theme rendering at /packages/download. Private operator inventory
is authoritative; CMS card text cannot grant download entitlement. Free packages
use the configured CMS product identity. Paid products require a separate registered
name/model; Calendar remains disabled pending those provider details and a test key.
License protocol version remains 1.0; package version is not the license entitlement.

GET returns public offers plus session CSRF; POST receives product/domain/key in
the body, rejects query parameters and cross-origin/expired-CSRF submissions,
rate limits attempts, verifies exact trusted signed bytes and validates the license
live. Keys are neither persisted nor logged. Private archives are outside public.
The theme adds a masked-key form inside the existing marketplace dialog, explicit
development confirmation, loading/error states, key clearing and client SHA-256
verification before browser download. Unavailable products cannot collect a key.
Portable Core does not inherit enabled distribution or operator configuration.

Validation: 13 distribution unit checks, 70 licensing checks, 448 theme checks,
PHP/JS syntax and git diff --check passed. Actual HTTP acceptance passed 12 checks
on QA and 12 on production, including real installed CMS-key validation and ZIP
retrieval. No real key was exposed in tool output or persisted by the tests.
The actual QA-downloaded theme ZIP and existing signed Calendar ZIP passed 20
clean-install/activation/data-preservation/rendering checks in disposable MariaDB.
The production-downloaded ZIP is byte-identical to that accepted QA artifact.

Browser verified desktop/mobile 390px form, masked key cleared on submission,
synthetic-key refusal, Escape and filtering; production dialog exposes version
0.3.8 and the licence form. The visual-only 8874 router starts an admin session
before the public controller and causes CSRF interference: use normal QA 8873 for
download tests, not tests/workspace-visual.php. No production workaround was added.

Production theme: sensecms-0.3.8-6ae1fb675754bc01.
Download ZIP: theme-sensecms-0.3.8.zip, 343511 bytes; SHA-256:
6ae1fb675754bc0113ec1cbff9e80aaabdcf55086fcaf03fb3c1d536a29852a8.
Candidate: /root/sense-workspace-test.SC495Gg0/candidate-038/.
Core backup: /root/sensecms-backups/20260908T072108Z-distribution/.
Theme backup: /root/sensecms-backups/20260908T072110Z-product-design/.
Deployment scripts require unchanged baseline and exact accepted source inventories.
Only three Core files changed remotely; no schema/dependency/infrastructure changes.
The --release-status mode in publish-package-catalog.php updates only the existing
theme-detail release block through CMS optimistic-version saving; QA and production
verified 0.3.8. Its before-document journal is release-detail-before.json in each
distribution backup. Other content and shared catalogue cards remain preserved.
Nginx, PHP 8.5-FPM and MariaDB active; no priority 0..3 Nginx/FPM journal entries in
the checked post-deployment five-minute interval.

Rollback: disable distribution in private runtime configuration, activate retained
signed theme 0.3.7, then restore index-before.php from the Core backup if removing
the endpoint. New classes can remain inert. Preserve archives and newer CMS edits;
restore only the release-detail block from its journal via current builder version
if needed. No whole-database restore is necessary. Demo and Cambo Jumbo untouched.
Remaining: registered Calendar product identity/test key and live paid acceptance;
adapt the remaining unimplemented packages, purchase flow, Stable/Core distribution
and broader automatic update/rollback acceptance. Do not label these complete.

### Calendar licensing follow-up

User supplied CMS and Calendar keys in F:/Git/MChivale/cambojumbo-web/.cfg/License.txt
(not sensecms-web/.cfg/License.txt, which still contains only the original CMS record).
Do not duplicate these secrets. Live Chivale validation with ProductName
"Sense CMS Calendar", the previously proposed ProductModel "Sense CMS Calendar Addon",
ProductVersion "1.0" and canonical https://www.sensecms.com returned HTTP 200 with
error=true and "Product model not licensed". No key or raw response was logged.
Asked for the exact registered ProductModel; no provider registration, entitlement
or production configuration was changed. Do not try alternative guessed models or
publish Calendar until exact-product live validation succeeds.

## 2026-09-08 — Paid Calendar download enabled after live licensing acceptance

User corrected provider ProductModel to "Sense CMS Calendar Addon". Live validation
now succeeds with exact ProductName "Sense CMS Calendar", protocol version 1.0 and
canonical https://www.sensecms.com. No provider response version was made an
entitlement condition. Keys were consumed from the authorized local file through
SSH stdin for remote tests; no key file was uploaded or persisted on the server.

Extended existing deploy-distribution.py with guarded --calendar mode: verifies
the existing publisher-signed archive, journals previous configuration, preserves
theme offer and uses Runtime's atomic private config write. Shared deployment lock;
production requires matching QA receipt (entry plus relevant Core hashes). Archive
and configuration are sensecms-owned mode 0600. No production Core/theme source,
schema, dependencies, services, DNS or demo/Cambo application changes.

Extended tests/distribution-http.py --calendar: 20 HTTP assertions passed on QA
and again on production. Real CMS key refuses paid Calendar; Calendar key refuses
the free theme; proper keys return only the appropriate exact ZIPs. CSRF/origin,
invalid/unknown product, private path and byte integrity checks passed. Actual
downloaded bytes match signed acceptance artifacts. The existing 20-check clean
install/activate/calendar-category/uninstall/reinstall suite passed again on QA,
preserving category data and identities. Local 13 distribution and 70 licensing
checks, PHP/Python syntax and git diff --check passed.

Calendar archive: addon-calendar-0.1.0.zip, 69475 bytes, development (not Stable).
SHA256: 329c9fc0f98594e35537735865a3df1d8994406c20418d648a02815a8cdacbdd.
QA backup: /root/sense-workspace-test.SC495Gg0/20260908T085928Z-calendar-distribution/.
Production backup: /root/sensecms-backups/20260908T085958Z-calendar-distribution/.
Receipt: /root/sense-workspace-test.SC495Gg0/candidate-038/calendar-accepted.json.
Actual downloads: candidate-038/download-calendar-{qa,production}.zip (byte-identical).
Both backups contain distribution-before.json and release-detail-before.json.
The latter journals only the existing Calendar detail block changed through CMS
optimistic-version saving by publish-package-catalog.php --calendar-status.
Both detail routes verified; official theme remains signed 0.3.8 unchanged.
Browser confirmed Download package: Sense CMS Calendar opens in place on /extensions,
separate paid licence terms, version 0.1.0 and development acknowledgement.
Nginx, PHP 8.5-FPM and MariaDB active; no priority 0..3 Nginx/FPM journal entries
since 08:59 UTC in the checked post-deployment interval.

Rollback: remove/disable only addon:calendar via Runtime::write, preserving current
theme/other inventory. distribution-before.json is the exact pre-publication fallback
only if no newer inventory changes exist. Restore only Calendar's release detail
from the journal using the current builder version; retain private archives.
Remaining: other catalogue products still need adaptation/acceptance. Purchasing,
Stable/Core public distribution and automatic updates remain separate work.

## 2026-09-08 — Google Analytics 0.1.1 distribution and settings fix

Added free plugin source under .plugins/google-analytics, using the existing
ExtensionRuntime, PackageManager and full console. Published CMS pages only;
authenticated users, previews, system routes and standalone theme fallback pages
excluded. Empty Measurement ID disables tracking. Explicit visitor consent precedes
all Google requests, refusal/expiry/withdrawal supported, advertising denied.
No real GA property supplied: external event ingestion unverified. Official www
distribution only; plugin not installed or tracking enabled on production.

0.1.0 was published before browser settings acceptance. Browser found a real defect:
plugin and generic panel handlers both submitted, and global processing restored
the already-disabled save button. 0.1.1 uses a capturing local submit handler with
stopImmediatePropagation, matching other dedicated workspace form handlers.
No global/Core changes. Old signed bytes retained; no same-version replacement.

0.1.0: 8226 bytes, SHA256
d181bfb752d30b08305e5fa047dea69ebc43ac4e9b039ea9eafdc02ed582eb36.
Production initial backup: /root/sensecms-backups/20260908T092043Z-analytics-distribution/.
Current 0.1.1: plugin-google-analytics-0.1.1.zip, 8316 bytes, development (not Stable).
SHA256: 9a5684adca21f9b1f0de562bd16e07ca40200fe0aa32203c2f96074121657770.
QA backup: /root/sense-workspace-test.SC495Gg0/20260908T101853Z-analytics-distribution/.
Production backup: /root/sensecms-backups/20260908T101956Z-analytics-distribution/.
Both contain distribution-before.json and release-detail-before.json. Candidate
and receipts: /root/sense-workspace-test.SC495Gg0/candidate-038/; 0.1.1 receipt
analytics-011-accepted.json, actual downloads download-analytics-011-{qa,production}.zip.

Acceptance: 14 PHP, 14 consent and 4 submit-regression checks; 31 QA HTTP and
7 production HTTP checks passed. Invalid licence refused; real CMS licence returns
exact signed ZIP; private archive path returns 404. QA 0.1.0 uninstall and actual
download reinstall preserved unrelated extensions; 0.1.0 to 0.1.1 signed upgrade
preserved settings. Browser on installed 0.1.1 confirmed two consecutive saves,
successful response, reenabled button, no console errors before promotion.
Desktop/390px settings layout checked during initial acceptance; final marketplace
modal shows free CMS-licence-gated v0.1.1 download in place. Current theme 0.3.8 and
Calendar 0.1.0 offers unchanged. PHP/JS syntax and diff whitespace checks passed.
Nginx/PHP8.5-FPM/MariaDB active, no priority 0..3 Nginx/FPM journal entries in the
post-deployment check since 10:19 UTC. Archive/config 0600 sensecms-owned.

Rollback: disable only plugin:google-analytics via Runtime::write, preserving newer
offers. Prefer disabling to reoffering known-defective 0.1.0. Restore only Analytics
detail using journal and current optimistic builder version. Keep private archives.
No production schema, services, DNS, new dependencies, demo or Cambo changes.
Remaining: other marketplace packages, Stable/Core distribution and automatic updates.

## 2026-09-08 — Google Calendar development package and paid distribution

Implemented .plugins/google-calendar as a provider for the existing Calendar
IntegrationManager/dispatcher and its panel modal, using Eduvixo's provider contract.
No new Core, Calendar addon, database schema or framework changes. Settings use
the existing installation-local secret. Plugin config_url is /calendar (the
integrations UI is a modal there, not a separate /calendar/integrations route).
Calendar signed dependency >=0.1.0 <1.0.0; matching runtime identity required.

Provider: fixed HTTPS Google endpoints, OAuth refresh, owner/writer check via
CalendarList, no redirects, verified TLS, bounded response body, sanitized errors.
Deterministic sc+SHA256 event IDs and 409 update retry; UTC timed events and local
all-day exclusive ends; idempotent deletes. Existing dispatcher handles visibility,
recurring occurrences and backoff. External Google delivery is NOT verified:
no real OAuth credentials/calendar supplied, no external event created or deleted.
Product detail explicitly discloses this and development (not Stable) status.

User added key under Sense CMS Google Calendar in
F:/Git/MChivale/cambojumbo-web/.cfg/License.txt. Consumed only in memory over SSH
stdin; never copied to server/config/docs. Exact live licensing passed with
ProductName Sense CMS Google Calendar, ProductModel Sense CMS Google Calendar Plugin,
protocol version 1.0, domain https://www.sensecms.com, including validity dates.

Acceptance: 27 isolated provider protocol checks, 14 signed-package QA checks,
10 real download checks each on QA and production. Invalid key and CSRF refused;
CMS key cannot download plugin, plugin key cannot download free theme/paid addon.
HTTP bytes match signed installation archive. Authenticated Calendar API exposes
four empty fields, disabled/unverified; two disabled saves passed with rotated
post-login CSRF. Initial old login CSRF correctly produced 419, not an app defect.
Browser confirmed Google Calendar entry/fields in existing Calendar integrations
modal. No separate plugin frontend, no enabled connection. QA fabricated credentials
were removed after verification; unrelated extensions preserved.

Initial private build was rejected for mismatched display names between manifests;
fixed before publication. Initial uninstall assertion assumed QA Calendar was a
package, but it is bundled: existing protection correctly refused uninstall. Test
now records bundled protection without claiming a live dependent-uninstall check.
Dependency absence is independently rejected by signed Manifest compatibility.

Accepted ZIP: plugin-google-calendar-0.1.0.zip, 3674 bytes.
SHA256: 1e207473613b24c85ca05be034ba3cefaf5bb475bf91b8df358ad83533703302.
Only use candidate-038/google-calendar-accepted-build/ archive; initial candidate
at candidate-038/plugin-google-calendar-0.1.0.zip is rejected, never published.
QA backup: /root/sense-workspace-test.SC495Gg0/20260908T103427Z-google-calendar-distribution/.
Production backup: /root/sensecms-backups/20260908T114320Z-google-calendar-distribution/.
Both contain distribution-before.json and release-detail-before.json.
Receipt: candidate-038/google-calendar-accepted.json; actual downloads:
candidate-038/download-google-calendar-{qa,production}.zip.

Production has four offers: theme:sensecms 0.3.8, addon:calendar 0.1.0,
plugin:google-analytics 0.1.1, plugin:google-calendar 0.1.0. Plugin not installed on
www; only its private distribution archive and existing CMS release detail changed.
Home, marketplace, detail HTTP 200; detail shows version and live-test limitation.
Nginx/PHP8.5-FPM/MariaDB active, no priority 0..3 Nginx/FPM journal entries during
post-deploy check since 11:43 UTC. Distribution/archive mode 0600, owner sensecms.

Rollback: disable/remove only plugin:google-calendar inventory entry through
Runtime::write, preserving other/newer offers, and restore its detail block using
the journal plus current builder version. Retain immutable archives. No production
DNS, service, database-schema, demo or Cambo changes. Remaining: live authorised
Google-account delivery acceptance and the other marketplace package adaptations.

## 2026-09-08 — Microsoft 365 Calendar development publication

Implemented .plugins/microsoft-365-calendar 0.1.0 using the existing Calendar
provider contract, integrations modal, encrypted settings and delivery dispatcher.
Outbound Microsoft Graph only, not two-way sync. Five settings: tenant/client GUIDs,
application secret, mailbox, optional calendar ID. Requires active Calendar addon
>=0.1.0 <1.0.0. No Core, schema, framework or production runtime installation changes.

Provider verifies canEdit; uses application OAuth, fixed HTTPS endpoints, immutable
Graph event IDs and deterministic creation transaction IDs. Strict dates, UTC timed
events, local all-day exclusive ends, idempotent delete. TLS verified, no redirects,
bounded responses and sanitized failures. No attendees/invitations. Calendar excludes
private/participant-only events. No real Microsoft tenant credentials supplied; no
external events created, changed or deleted. Live Graph delivery remains unverified.

User supplied licence in CamboJumbo .cfg/License.txt: ProductName
Sense CMS Microsoft 365 Calendar; ProductModel Sense CMS Microsoft 365 Calendar Plugin;
protocol 1.0. Exact real licensing/validity check passed; key consumed in memory over
SSH stdin only, not copied to server or documentation. CMS/addon/other keys refused.

Acceptance: 32 isolated provider protocol checks, 14 signed package QA checks,
10 real download checks each on QA and production. Authenticated QA API confirmed
five fields, disabled/unverified, and two consecutive disabled saves. Browser
confirmed existing Calendar modal fields and production marketplace in-place paid
download form with v0.1.0 development acknowledgement. No live Graph test claimed.
QA synthetic credentials cleared; unrelated packages preserved. Bundled QA Calendar
uninstall protection tested; absent dependency separately rejected by Manifest.

ZIP: plugin-microsoft-365-calendar-0.1.0.zip, 3850 bytes.
SHA256: 04b05f105c2b8b9e8b33b68f0542675d9a9daeb7898d816fefeb598e99be92fa.
Candidate: /root/sense-workspace-test.SC495Gg0/candidate-038/.
Receipt: microsoft-calendar-accepted.json; actual downloads:
download-microsoft-calendar-{qa,production}.zip.
QA backup: /root/sense-workspace-test.SC495Gg0/20260908T115516Z-microsoft-calendar-distribution/.
Production backup: /root/sensecms-backups/20260908T120141Z-microsoft-calendar-distribution/.
Both contain distribution-before.json and release-detail-before.json.

Production now has five offers; prior four unchanged. Only private distribution
archive/config and existing CMS product detail were published. Detail explicitly
discloses unverified live delivery and not-Stable status. Home, marketplace, detail
HTTP 200; downloaded bytes match accepted signed archive. Archive/config 0600 and
sensecms-owned. Nginx/PHP8.5-FPM/MariaDB active; no priority 0..3 Nginx/FPM journal
entries since 12:01 UTC during post-deployment checks.

Rollback: disable/remove only plugin:microsoft-365-calendar through Runtime::write,
preserving other/newer offers. Restore only this detail block from its journal using
current optimistic builder version. Retain immutable archives and backups. No DNS,
service, database-schema, demo or Cambo changes. Remaining: real authorised Microsoft
delivery acceptance, other marketplace adaptations, Stable/Core distribution and updates.

## 2026-09-08 — Apple Calendar development publication

Adapted EduvixoAppleCalendar provider into .plugins/apple-calendar 0.1.0. Reused
Calendar's existing provider interface, panel modal, encryption and delivery queue.
No Core, schema, service, framework, demo or Cambo changes. Outbound iCloud CalDAV
only; dedicated writable calendar recommended, no automatic discovery/two-way sync.

Hardened iCloud destination validation: HTTPS-only allowlisted hosts/collection path,
globally routable IPv4 DNS pinned in cURL, no proxy/redirects, verified TLS, bounded
body and sanitized failures. XML response must be UTF-8 without NUL/DTD/entities;
LIBXML_NONET and no expansion flags. Initial local test showed LIBXML_NO_XXE is
unavailable with the local libxml build; removed reliance on that optional constant,
retaining explicit parser protections including a UTF-16 bypass regression fixture.

Verify reads collection type and read/write/create/delete privileges on the exact
requested href. Events have deterministic namespaced UIDs, UTC timed values, local
all-day exclusive ends, escaped text and UTF-8 line folding. GET validates existing
UID/strong ETag; conditional PUT/DELETE rejects changes occurring during delivery.
Not a durable external-edit conflict detector: Sense CMS is source of truth and an
earlier manual iCloud change can be superseded by later outbound delivery. No attendees.

User supplied Sense CMS Apple Calendar key in CamboJumbo .cfg/License.txt; consumed
in memory over SSH stdin. ProductName Sense CMS Apple Calendar; ProductModel
Sense CMS Apple Calendar Plugin; protocol 1.0. Exact real licensing and validity checks
passed; key not copied to the server. CMS and other product keys correctly refused.

Acceptance: 64 protocol checks on Windows and Linux PHP, 14 signed-package QA
checks, 10 licensed-download checks each on QA and production. QA authenticated API
exposes three empty fields, disabled/unverified; two consecutive disabled saves pass.
Browser verified Apple fields in existing integrations modal and in-place production
paid download dialog v0.1.0. Synthetic settings cleared; unrelated packages preserved.
Bundled QA Calendar uninstall protection verified; absent dependency separately rejected.
No live iCloud requests or credentials; real account event delivery remains unverified.

ZIP plugin-apple-calendar-0.1.0.zip, 4907 bytes.
SHA256 f260b021bc3dd86cc94dcfdb9ec772363457db52f32fd20f28638691b2b5e69c.
Candidate /root/sense-workspace-test.SC495Gg0/candidate-038/.
Receipt apple-calendar-accepted.json; downloads download-apple-calendar-{qa,production}.zip.
QA backup /root/sense-workspace-test.SC495Gg0/20260908T122001Z-apple-calendar-distribution/.
Production backup /root/sensecms-backups/20260908T122143Z-apple-calendar-distribution/.
Both preserve distribution-before.json and release-detail-before.json.

Production now has six offers; five earlier entries verified unchanged. Only private
distribution and this CMS detail updated. Plugin NOT installed/enabled on www.
Home/marketplace/detail HTTP 200, exact signed download hash, config/archive 0600
sensecms-owned. Nginx/PHP8.5-FPM/MariaDB active; no priority 0..3 Nginx/FPM entries
since 12:21 UTC at post-deploy check. Detail discloses live delivery limitation.

Rollback: disable/remove only plugin:apple-calendar using Runtime::write, preserving
other/newer offers; restore only its detail block from journal with current optimistic
builder version. Keep immutable archives and backups. Temporary local transport
archives and task QA tunnel removed after checks. Remaining: authorised live calendar
acceptance, remaining marketplace packages, Stable/Core distribution and updates.

## 2026-09-08 — Telegram discovery and client prerequisites (not published)

Next package: Telegram Notifications. EduvixoTelegram 1.2.0-beta.1 uses central
delivery, not per-installation bot tokens; notifications are independent of Calendar.
Website-only broker reference: Eduvixo app/TelegramBrokerService.php. Do not reuse
Eduvixo production bot/token, webhook or bindings for Sense CMS. Sense workspace's
telegram_broker_url is empty. No Telegram.txt or bot-token-shaped value found in
the two project .cfg text-file sets at inspection. User added the Telegram package
licence under Sense CMS Telegram Notifications in CamboJumbo .cfg/License.txt;
presence checked only, live entitlement not yet checked or published.

Found incompatible migrated constructors in TelegramConnectionController and
NotificationDispatcher: old array/string LicenseService arguments versus Sense's
directory/LicenseClient constructor. Locally changed those calls to Runtime::license().
TelegramConnectionClient also refers to absent marketplaceHeaders(); now explicitly
fails closed with 503 instead of a method error, pending a real Sense-specific broker
authentication contract. This is an unavailable service, not simulated verification.

Client hardening: exact t.me start token/query, no URL credentials/port/fragment,
explicit TLS verification, Sense CMS user agent, no upstream error-message exposure,
bounded object-only JSON responses and transport failures never treated as success.
26 isolated transport checks plus 3 checks with real Sense LicenseService pass via
tests/telegram-client.php [--real-license]. No requests to Telegram/broker, no messages,
no credentials copied, no production changes. PHP lint and diff whitespace checks pass.

Remaining before package publication: provision/identify separate Sense CMS bot and
central broker, define and test CMS/package entitlement and tenant isolation, connect
client authentication, build/test signed package and QA lifecycle, then production
publication/health. Do not claim Telegram works based on the client tests above.

## 2026-09-08 — Central Telegram broker service prepared, private QA only

Added .src/TelegramBrokerService.php as an official-site-only service, outside
portable Core and public themes. No public route, webhook or production deployment.
Rechecked both projects' .cfg text files: no Telegram bot token found. Licence exists
as recorded above; no real licensing or Telegram calls performed in this milestone.

Broker actions: start/status/recipients/disconnect/deliver/webhook. Fixed Sense CMS
System licence identity and protocol 1.0 through existing LicenseClient response
validation; canonical tenant domain comes from X-SenseCMS-Domain. Caller-supplied
product/model/endpoint overrides are not accepted. Paid package download remains a
separate entitlement; the shared transport authenticates the CMS installation.

All state encrypted with an independent broker secret and bound to its HMAC-derived
filename. Atomic Runtime writes, private storage and files; no clear licence keys or
connection tokens stored. Nonblocking process lock serializes binding changes with
delivery; busy requests fail 503. Domain/user rate limits and 100 connected-user limit
per installation bound individual state documents. Single global lock is conservative
for the initial single-host deployment; throughput and pre-auth HTTP rate limits need
operational acceptance before public exposure.

Connection links expire in 600 seconds; newest request supersedes prior ones.
Disconnect invalidates pending tokens. Webhook requires its secret and matching
private sender/chat identity, rejects group/bot messages and consumes tokens before
binding. Binding does not send unsolicited confirmation; the CMS polls status.
Delivery requires confirmed private chat/message response, persists processing before
network, records ambiguous outcomes as unknown and refuses automatic retry. Duplicate
event IDs with different payloads fail; successful same-payload repeats do not send.

tests/telegram-broker.php: 34 isolated checks passed locally and on server PHP8.5.
Includes licence/product/expiry refusal, domain/user isolation, replay, malformed and
oversized webhook input, private encryption, duplicate/uncertain delivery, disconnect,
exclusive lock and rate limiting. Synthetic transport only, no live licence or bot.
Earlier 26 client protocol plus 3 actual-LicenseService fail-closed checks still pass.
QA source/tests uploaded only below /root/sense-workspace-test.SC495Gg0/; temporary
test state removed by tests, local transport archive removed after upload/checks.

Remaining: bot credentials, public HTTP routing and pre-auth rate limiting, tested
client credential export restricted to Sense broker, state-retention policy/cleanup
(delivery tombstones must not be deleted so early that replay duplicates messages),
package assembly/lifecycle, real-account consented acceptance and production release.
Do not expose this service publicly or label Telegram downloadable before these gates.

## 2026-09-08 — New @SenseCMSBot provisioned; webhook-only production ingress

User created bot and confirmed credentials ready. Actual source is ignored
F:/Git/MChivale/cambojumbo-web/.cfg/Telegram.txt, not Sense's .cfg. getMe verified
username SenseCMSBot / name Sense CMS Notifications; original webhook empty.
No source credential file copied into Sense repo or command arguments. Only selected
bot credentials transferred over SSH stdin to operator scripts/setup-telegram.php.

Production private files: storage/private/telegram/{config.json,key.bin}, independent
random encryption key and webhook secret, dirs 0700/files 0600, sensecms-owned.
No replacement of an existing bot/webhook/binding. Provisioning refuses existing
private directory and foreign webhook. No messages or pending updates deleted.

Website-only .src/TelegramWebhook.php + telegram-webhook.php deployed under
/home/sensecms.com/web/website alongside TelegramBrokerService.php. Only public
endpoint /api/telegram/v1/webhook; other broker actions are NOT publicly routed.
Nginx exact location invokes this private script with canonical HTTPS. Per-IP
10 requests/sec, burst 20, status 429; body max 64 KiB. PHP validates host, path,
method, content type, bounded JSON object and webhook secret; generic errors only,
no browser sessions. Direct private script/storage access remains 404. No portable
Core changes, theme changes, package publication, database or dependencies in this step.

Telegram setWebhook confirmed fixed canonical URL, allowed_updates=[message],
max_connections=1, drop_pending_updates=false. Authenticated empty POST confirms
full Nginx/FPM ingress without binding users or sending messages. getWebhookInfo
subsequently reports 0 pending updates and no error. No real user notification yet.

Validation: 48 broker/ingress checks pass Windows and server PHP8.5; 26 client plus
3 actual-LicenseService fail-closed checks pass locally. PHP lint/diff --check pass.
10 external HTTP checks: home, marketplace, login 200; webhook GET 405, no-secret
POST 403, malformed object 400, wrong MIME 415, oversized body 413, private paths
404. Application JSON errors no Set-Cookie or diagnostic text. Deployed source and
Nginx SHA256 match local. nginx -t successful, Nginx/FPM/MariaDB active, no priority
0..3 service journal entries since 15:16:43 UTC; no PHP error log present at check.

Backup /root/sensecms-backups/20260908T151643Z-telegram-webhook/nginx-before.conf.
Rollback: disable Telegram webhook without dropping pending updates, restore ONLY
the saved Sense vhost if no later changes (otherwise remove this exact location and
zone), nginx -t then reload. Preserve private config/key/state for recovery. No old
website files were overwritten; only new website service files and vhost updated.
QA sources/operator script at /root/sense-workspace-test.SC495Gg0; source archive
sensecms-telegram-webhook-setup.tgz SHA256
c79895b2fa6ed7ae83cd736d478161c96779b212eac8872a1f82a9205aa01ec2.
Local temporary transport archive removed after checks.

Remaining: authenticated CMS client export + service routes, verify central channel
on enable (not a fake verified timestamp), state cleanup/delivery retention policy,
package lifecycle/licensing and distribution, real consented account connection and
test delivery through panel. Do not advertise Telegram package as ready or ask users
to connect through plain /start: no panel connection flow is live yet.

## 2026-09-09 — Installed packages, notification UI and live Telegram acceptance

Supersedes the webhook-only status above. Production now supports authenticated
Telegram start/status/disconnect/recipients/deliver routes as well as the webhook.
LicenseService exports installation identity only to the exact official HTTPS
broker; no redirects, arbitrary credential destinations or version-based keys.
Channel enable verifies the real broker, not a synthetic timestamp. Fixed two live
integration defects: empty requests encoded as [] instead of {}, and obsolete
marketplaceHeaders guard making personal settings falsely report unavailable.

Installed and activated signed packages after all six relevant real licences passed:
Calendar 0.1.0, Google Analytics 0.1.1, Google Calendar 0.1.0, Microsoft 365 Calendar
0.1.0, Apple Calendar 0.1.0, Telegram Notifications 0.1.0. Existing add-ons and
active public theme sensecms 0.3.8 preserved. Publisher key matched independent
/root/sensecms-private/trust.json before trust registration. Only newly installed
runtime directories and private package metadata had ownership/modes repaired.
External calendar providers remain inactive/unconfigured; no analytics ID or
unrequested outbound synchronization was introduced.

Telegram is enabled and visible in System > Notification channels and My settings.
User personally connected using the panel link and confirmed this in conversation.
Exactly one active non-demo connected account was found. A single consented test
was sent through the production TelegramConnectionClient; Telegram API confirmed
acceptance. Stable test event key prevents replay from sending twice. Human receipt
has not separately been confirmed. Bot @SenseCMSBot: verified webhook, zero pending
updates, no last error. No raw keys, chat IDs or connection tokens recorded here.

Notification channels now reuses the console hero, tokens, cards, controls and icons;
desktop and 390px mobile checked in the authenticated production browser. Checkbox
help text uses a grid specificity fix against legacy global styles. Stylesheet
version 20260909-channels-2. Missing public/service-worker.js caused Web Push 404;
added notification-only worker with own-origin destination allowlist, bounded text,
no page interception or cache. GET and HEAD now return 200 application/javascript;
My settings shows Available. Browser permission and real Web Push delivery remain
untested; no permission was accepted on behalf of the user.

PackageManager discarded signed addon navigation metadata. Preserve navigation on
future installation; existing Calendar navigation recovered only from verified ZIP
in operator install-ready-packages.php with optimistic metadata guard. Owner menu
and actual Calendar settings page checked, all three provider cards inactive.
DashboardController hardcoded every new plugin Configure link to forms/submissions;
now uses each installed manifest's configuration URL, icon and group. Production
DOM confirms all five plugin URLs, and Calendar integrations open successfully.

Worker /etc/cron.d/sensecms-notifications runs as sensecms every minute; successful
scheduled JSON summaries observed, no errors or unexpected sends. Nginx adds an
authenticated-client rate zone (2r/s burst 10) separate from webhook rate limiting.
No dependency additions, destructive migrations or public-theme changes.

Validation: 73 licensing, 28 isolated client, 4 real-LicenseService fail-closed,
51 isolated broker/HTTP edge, 13 service-worker checks pass locally. Broker/client
and 8 signed Telegram lifecycle checks passed Linux QA during this implementation.
Signed Calendar navigation regression passed on actual production ZIP in private QA;
added permanent installation assertion to tests/package-release.php (full release
suite not rerun this step). PHP lint, diff --check and deployed source comparisons
pass. Production home/catalog 200, unauthenticated broker 401, private storage 404.
Nginx/FPM/MariaDB/cron active; fresh priority 0..3 service journal empty, Nginx error
log has no new entries since this deployment. Web Push subscription and actual
Google/Apple/Microsoft sync are not claimed verified.

Backup: /root/sensecms-backups/20260908T221949Z-notification-channels contains
web-before.tgz, database-before.sql, nginx-before.conf, channels-final-before.tgz
and DashboardController.before.php. Restore only affected files/config as needed,
nginx -t before reload; preserve later user data, bot key/config/bindings and delivery
tombstones. Do not wholesale restore the DB after the user's new Telegram binding.
New service-worker and cron files may be removed individually for rollback after
disabling their consumers; package uninstall preserves business data. QA source and
signed candidate remain under /root/sense-workspace-test.SC495Gg0.

Telegram signed ZIP: candidate-038/plugin-telegram-notifications-0.1.0.zip, 1106 B,
SHA256 a18f095596fab55f5f4c5b2d40aa0f69048ac44d063a5dd439cd5d2b9000f26f.
Installed on this site but NOT yet added to the public distribution/catalog.
Remaining before broad release: ephemeral broker-state cleanup/retention policy
(preserve delivery tombstones), public catalog publication and download verification.
Unrelated legacy educational descriptions in system modules observed, not changed.

## 2026-09-09 — Settings reinitialization, expired Web Push, saved channel consent

User supplied four screenshots: Checking after profile Save, test appearing to
disable push, re-entry re-enabling it, and unchecked consent after channel Save.
Root causes confirmed: SenseCMSUI.navigate replaces main/body but initialized neither
notification controller; Web Push load silently POSTed the same rejected endpoint;
test API called all non-sent results queued/success; consent was validated but never
stored or rendered. Six production delivery rows had HTTP 410, none had sent_at.

Shared UI boot now dispatches sensecms:content-ready. Web Push/Telegram initialize
once per DOM root, including AJAX replacement; detached Telegram polling stops.
Read-only push status no longer subscribes. Explicit Enable renews an endpoint absent
from the server's active list. Backend also rejects reactivation of a provider-expired
endpoint by stale clients. Test failures return HTTP 422 and current device state;
frontend retains that state and shows an error, not success. Accepted means provider
acceptance, not proof that the operating system displayed a notification.
Consent is persisted in existing encrypted settings, rendered checked on later reads,
and cleared when explicitly unchecked; no inferred consent or schema migration.
System push card labels expired counts as failed attempts, explains renewal and
retains history. Seven earlier failed attempts remain after one diagnostic test.

Production backup /root/sensecms-backups/20260908T230936Z-settings-push:
source-before.tgz plus WebPushSubscriptions.before.php. Eight source files deployed,
ownership sensecms, exact QA/source comparisons and PHP lint passed; JS cache tags
20260909-settings-2. Rollback only source; preserve new browser subscription, history
and saved consent. No credentials, browser permission changes or new dependencies.

15 tests/notification-settings.cjs VM regressions pass (initialization, replaced DOM,
duplicate handler prevention, explicit renewal, failure rendering, no auto-subscribe).
13 service-worker tests pass; syntax/diff checks pass. Private QA verified consent
true/false persistence and rejected expired re-subscription; fixtures restored via
existing-row restore / transaction rollback, no external message or real endpoint.

User prompted to refresh and renew using Enable Web Push personally. Production
browser subsequently showed Enabled plus provider-accepted success toast. Database
confirms a new test accepted with HTTP 201 at 2026-09-08 23:11:03 UTC; new subscription
active with last_success_at and zero failures, old subscription inactive. No fresh
priority 0..3 Nginx/FPM journal entries. Human confirmation of actual visible browser
notification and profile-save behavior remains pending at this note.

## 2026-09-09 — Browser receipt diagnostics and shared bot profile management

User confirms Telegram delivery, but no visible Web Push banner despite HTTP 201.
Added tests/web-push-crypto.php with public RFC 8291 Appendix A vector: complete
encrypted wire payload matches (ECDH/HKDF/AES-GCM/header). No crypto change needed.
Test push tags now unique, renotify enabled for tests. Worker reports actual push
receipt plus showNotification resolution to same-origin windows using postMessage;
settings displays this diagnostic separately from provider acceptance. No third-party
telemetry and no message contents in receipt. Tests/web-push-worker.cjs now 15 checks.

Production test at 2026-09-08 23:33:48 UTC accepted by provider, then authenticated
browser DOM displayed: Browser received the push and accepted its display at 6:33:48
AM. Thus provider-to-browser/decryption/worker/showNotification stages succeeded.
This does NOT prove the OS showed a banner; user asked to inspect Windows notification
centre, Chrome notifications and Do not disturb. No OS permission/settings changed.
No claim that earlier user's test reached their browser; receipt added only now.

New website-only .src/TelegramBotProfile.php reads local private bot config, verifies
getMe == SenseCMSBot, uploads bounded JPEG via setMyProfilePhoto multipart, retains
previous preview, locks writes, saves current private preview only on API confirmation.
No credentials exported into portable plugins or browser. Existing owner+system.manage
and CSRF routes reused; NotificationChannels resolves this provider only when official
canonical installation has operator website class/private config. Other installations
cannot manage the shared bot. Correct AccessControl::allows method used.

System > Notification channels now shows owner-only bot appearance, file upload and
Restore Sense CMS logo. Existing image validation/conversion reused; PNG/JPG/WebP up
to 2 MB and 128..4096 px, reencoded 640px JPEG. Logo from .src/images/logo.png deployed
privately as website/telegram-default.png, converted and set on real @SenseCMSBot;
Telegram confirmed success. Authenticated preview loaded natural 640x640, multipart
form correct, no horizontal overflow. No custom user image submitted yet. Background
cannot be set via the available Telegram Bot API; UI explains it instead of fake control.
Official docs: https://core.telegram.org/bots/api#setmyprofilephoto and InputProfilePhoto.

Validation: isolated telegram-profile.php verifies default conversion, identity check,
multipart contract and preservation after rejected update; no live calls in test.
RFC crypto test, 15 worker and 15 settings regressions pass. PHP/JS lint, diff check,
deployed source comparisons pass. Production home/worker 200, worker JavaScript MIME,
unauthenticated photo 302 to login, private website PHP 404; fresh Nginx/FPM critical
journal empty. Real default-logo operation run as sensecms; bot bindings unchanged.

Backup /root/sensecms-backups/20260908T233302Z-bot-profile-push-receipt contains
source-before.tgz and full private telegram-before snapshot. Rollback only affected
source and preserve current bindings/delivery tombstones. Bot photo is external state;
restoring files alone does not restore Telegram image. Previous local preview, if any,
is retained as profile.previous.jpg; original bot image was not separately fetched.
No database schema, dependencies, Nginx changes or public package publication here.
New runtime ownership sensecms, private previews/lock 0600. JS cache receipt-1.

## 2026-09-09 — Telegram 0.1.1 published with licensed download and recovery QA

User confirmed notifications work and requested closure/publication of Telegram.
Signed development release 0.1.1 (not Stable) now appears in the live marketplace:
/extensions and /extensions/catalog/plugin/telegram-notifications. USD 48/year,
separate Sense CMS Telegram Notifications key, model Sense CMS Telegram Notifications
Plugin, protocol 1.0 independent of release version. No price or licence policy change.

Actual release ZIP plugin-telegram-notifications-0.1.1.zip is 1178 bytes, SHA256
35c123f419038fbb8e432e11271d067085d4ba669529b0753b7c96189fab01b7.
Built using existing private signer, verified against independently pinned trust.
Only runtime plugin manifest in payload; no bot token, private config or website service.
Source manifest/changelog bumped; original 0.1.0 immutable archive preserved.

Broker cleanup reads authenticated encrypted identities; removes only starts/pending/
rate records older than 24-hour grace. Live records, malformed records, bindings and
delivery tombstones retained. Consumed empty pending records age by mtime. Shares
exclusive nonblocking broker lock; no links or recursive deletion. 58 broker tests
pass locally and Linux, including cleanup/idempotence and unchanged binding/tombstones.
New website/telegram-maintenance.php and root-owned cron run hourly at minute 17 as
sensecms. Initial production run removed 0, retained 4, invalid 0. First automatic
cron invocation not waited for; manual invocation and cron service/config verified.

Private QA tests/telegram-release.php ran actual signed ZIP through 0.1.0→0.1.1,
rollback→0.1.0, re-upgrade, disable/uninstall/clean reinstall. Settings/other package
rows unchanged. tests/telegram-download.py passed against QA and production HTTP:
published paid offer, bad CSRF419, invalid key403, real Core key403, real Telegram
product key200 with exact ZIP checksum/length, direct private ZIP404. Keys only via
SSH stdin/memory, never logs or files. QA acceptance receipt pins inventory and five
Core distribution/licensing file hashes before guarded production promotion.

Extended existing deploy-distribution.py --telegram and publish-package-catalog.php
--telegram-status. Six prior offers unchanged; Telegram is seventh. Detail block saved
through CMS builder with optimistic revision and private backup. Browser confirms live
card v0.1.1, USD48/year, separate-licence text and actual Verify and download modal with
development acknowledgement. Initial static fallback still shows adaptation statuses
until live inventory loads, as for other offers; this was not refactored.

Production plugin upgraded to 0.1.1 active, channel settings unchanged, 1 connected
account and one available recovery release. Runtime/package storage ownership repaired
to sensecms after operator install. No DB schema changes, provider token changes,
extra test messages, theme changes, new dependencies, or Git push/release performed.
Worker succeeds with no unsolicited sends; bot webhook verified, 0 pending/no errors.
73 licensing and 28 client checks pass; lint/diff checks pass; source comparisons match.
HTTP home/marketplace/detail200, private maintenance404. Nginx/FPM/MariaDB/cron active,
fresh critical service journal empty. Distribution config/ZIP mode0600 sensecms-owned.

Backups: /root/sensecms-backups/20260908T235455Z-telegram-release contains full web and
single-transaction sensecms_site DB dump. Distribution/detail backup:
/root/sensecms-backups/20260908T235456Z-telegram-distribution. QA counterpart:
/root/sense-workspace-test.SC495Gg0/20260908T234919Z-telegram-distribution.
Withdraw by restoring only prior distribution configuration and reviewed detail block;
preserve immutable ZIPs and subsequent user data. Plugin rollback uses PackageManager
verified 0.1.0 archive. Broker code can be restored independently and hourly cron
disabled; never restore old binding/delivery state merely to roll back source.

Telegram publication item completed. Remaining broader work: clean Core installer/
update release, real external calendar account acceptance and other marketplace
adaptations. Core transport requirement documented; demo and Cambo remain deferred.

## 2026-09-09 — local two-file web installer candidate

User requested .install/web/index.php + install.zip, automatic progress and licence
as the first actual installation screen. Extended scripts/build-installer.php with
--web and added scripts/web-installer.php as the build template. Reused existing
allowlisted Core builder, file inventory and real licence-first /install flow.
No production, database, runtime credentials or optional packages were changed.

Bootstrap requires an empty installation parent containing only public, canonical
HTTPS and public DocumentRoot. Upload both generated files into public. Same-origin
CSRF-protected POSTs automatically extract 40 files per request into private staging;
ZIP and individual file SHA-256 are pinned in index.php. Existing directories cannot
be overwritten. Session/domain ownership and flock serialize/resume extraction.
Core entry point replaces bootstrap only after final verification and canonical
setup; public ZIP is removed. No arbitrary download/extract path or CLI is needed.
Private extraction bookkeeping remains outside public for operator-reviewed cleanup.
Source permissions must be restricted again after installation; see .install/README.md.

Generated artifacts are ignored by Git; reproducible source builder remains tracked.
ZIP: 8804898 bytes, SHA-256
0cbefac42f01c4649f7d33e6ecef078050fe97c1f6bb47302833bb45aa1597ad.
43 installer-package checks and 28 new web-installer HTTP checks pass. Lint and
git diff --check pass. Fresh browser demonstrated automatic real batch progress,
redirect to licence screen; existing licence screen checked at desktop and 390px.
Bootstrap uses the same light blue/white onboarding vocabulary as that Core screen.
Tests cover tamper rejection, no overwrite, session takeover rejection, resumed
extraction, complete deployed file hashes and no database step before licence.

This is explicitly 0.1.0 DEVELOPMENT, not Stable. Full newly licensed database setup
using this exact artifact and live Nginx/Apache acceptance remain release gates.
No production promotion, Git commit/push or website distribution publication in this
turn. Production public root was not touched. No dependencies added.

Browser preview process on 127.0.0.1:8896 was stopped and its tab closed. Automatic
cleanup command was blocked by tool policy; the disposable extracted preview remains
at E:/Tmp/sense-bootstrap-preview-bc1e86e2fa9d44e1ab40699754372323 (no real licence,
database or owner credentials). It is outside the repository and distribution.

## 2026-09-09 — standalone Sense CMS project handoff

Canonical development root is F:/Git/MChivale/sensecms-web. Core, themes, packages,
website services, tests/deployment tooling and work history were already here.
Read-only source inventory found the remaining operator dependencies in the previous
project's private .cfg: the six Sense CMS product/key map and SenseCMSBot configuration.
Copied them byte-for-byte to local .cfg/Package-licenses.txt and .cfg/Telegram.txt,
without overwriting existing files or exposing values. Confirmed source Core key
matches existing local .cfg/License.txt; retained its strict CLI-compatible format.
SSH/DNS/email/catalogue/owner configuration already local and unchanged. Source
projects/files retained. Future work must use local .cfg only, not sibling fallbacks.

Updated .info and stale README; added AGENTS.md for a new project/thread to resume
without this conversation. One-time importer moved from active scripts into
.wrk/import-workspace.php.txt with unconditional execution stop. Historical import
inventory/provenance remains; do not rerun it. No runtime/build dependency on the
reference checkout remains. Third-party catalogue identities are not blanket-renamed.
Two Core source comments now identify Sense CMS; no runtime logic changes.

Rebuilt matching .install/web/index.php and install.zip after comment cleanup.
New ZIP: 8804886 bytes, SHA-256
25ae85f513958f8a206012f0d76dd6112117fe47bc427232bdf9e7bd58eb0c94.
Prior pair preserved in ignored .local/installer-before-project-handoff. Still
DEVELOPMENT with the same previously documented licensed/database release gates.

Validation: 11 new project-boundary checks inspect 362 active source/tooling files,
reject symlinks and hardcoded sibling-repository paths, preserve licence identity,
check ignore rules and scan generated ZIP for old product branding/available local
licence/bot secrets without printing them. Also passed 73 licensing, 55 package,
43 installer-distribution and 28 web-bootstrap checks; PHP lint/diff check clean.
git ls-files confirms .cfg/.local/generated installer contents are not tracked.
Existing dirty/untracked work preserved; repository already has sensecms-web origin.
No Git commit/push, project/task creation, production deployment, DB or server changes.

## 2026-09-09 — system audit: architecture, security and release readiness

Scope: analysis requested by the user, not implementation or production promotion.
Baseline: commit 67501f3, clean main checkout, 547 tracked files. Inspected project
instructions/history, source boundaries, entry points, authentication/access control,
content/builder/workflow/media, package/runtime/update contracts, licensing/secrets,
notification/integration code and tests, installer and deployment definitions.
Private .cfg file inventory was checked without exposing credentials. No other
project configuration was used. This is a cross-system audit, not a claim that every
branch has been executed or that production passed a penetration test.

Architecture: PHP 8.5+ custom modular monolith. Portable Core and administration in
.cms/source; runtime configuration/keys in private storage; MariaDB 10.11+ Workspace
with 27 migration files. Public theme in .themes/sensecms; independent plugins/addons;
.modules currently has no source files. Website-only catalogue and Telegram broker
remain in .src, outside customer Core. Nginx public DocumentRoot and dedicated FPM
pool are defined in deploy. Core build 0.1.0 remains separate from licensing protocol
1.0 (Sense CMS / Sense CMS System). No new framework is warranted by this audit.

### Confirmed priority findings

1. P1 — Non-owner privilege escalation through access management.
   AccessControl::saveUser checks users.manage but does not restrict assignment of
   the Owner role to an existing Owner actor. saveRole checks roles.manage but lets
   that actor assign system.owner to a new role. The Administrator seed explicitly
   excludes system.owner, so the distinction is not enforced by these mutation paths.
   Evidence: AccessControl.php lines 158-229; database/workspace/
   015_access_editorial_workflow.sql lines 107-120; DashboardController.php 558-568.
   Reproduced using the actual AccessControl implementation and an isolated SQLite
   :memory: fixture with NOW() registered, a real permission graph and two users:
   before saveUser: system.owner=0; after self-assignment of owner: system.owner=1.
   Independently, a non-owner with roles.manage successfully created a role carrying
   system.owner. No mocked authorization decision and no production account changes.
   Fix boundary: protect privileged role/permission grants and management of owners,
   including indirect grants and password changes; add negative non-owner tests.

2. P1 — HTML sanitization can be bypassed by nested unrecognized elements.
   HtmlSanitizer::cleanChildren unwraps an unknown element and immediately continues;
   its promoted children are not recursively sanitized. A single wrapper preserves
   script, onerror or javascript: href payloads. Two wrappers preserve an onerror
   attribute even after both save-time and render-time sanitization:
   input: <unknown><unknown><img src="/missing.png" onerror="alert(1)"></unknown></unknown>
   save:  <unknown><img src="/missing.png" onerror="alert(1)"></unknown>
   render:<img src="/missing.png" onerror="alert(1)">
   Evidence: Core/HtmlSanitizer.php 28-46, Core/PageBuilder.php 118-136,
   .themes/sensecms/views/blocks.php 8-9. Reproduced locally with actual sanitizer.
   Public CSP was present and can prevent script execution; browser execution or
   production compromise was NOT demonstrated. CSP does not repair unsafe output.
   Fix boundary: sanitize descendants before promotion; test nesting, forbidden
   elements/attributes/URLs, repeated sanitization and actual builder/theme rendering.

3. P1 release gate — Core updater does not implement the current portable contract.
   Core/SystemUpdate.php:18 reads absent app/release.json and falls back to 1.0.0,
   while config/product.php declares 0.1.0. Line 56 calls marketplaceHeaders(), absent
   from LicenseService. Lines 109-123 require the old migration baseline and paths,
   unlike Installer/WorkspaceMigration and database/workspace. The update allowlist
   does not cover the current full Core distribution. maintenance.json is written
   but no request entry point consumes it; worker.lock is not a request-traffic lock.
   No runnable SystemUpdate worker entry point/cron or dedicated updater tests were
   found in active tracked source. OfficialCatalog.php:12 expects distribution.public_key,
   while the current website distribution contract uses independently pinned publishers.
   Live unauthenticated GET /api/marketplace/v1/official/ and /core-package/ returned
   404. This does not alone prove all authenticated routes absent, but corroborates
   the missing integration found in source. Do not advertise safe Core updates or
   enable this legacy execution path merely by adding a cron. A replacement needs
   coherent version/trust/transport contracts, maintenance coordination, migration
   checks and tested recovery using exact release artifacts.

4. P2 — Module archive support is not module lifecycle/runtime support.
   Packages/Manifest.php:11 accepts module; PackageManager.php:14 only accepts
   theme/plugin/addon. ExtensionRuntime.php only loads addon/plugin, as do extension
   asset and lifecycle routes. The Extensions view calls built-in components
   "15 portable SenseCMS modules"; these are not independently installable modules.
   Decide/implement the advertised module lifecycle consistently through manifest,
   installation, dependencies, runtime, assets, permissions, UI and rollback tests.

5. P2 — Package activation can leave inconsistent state.
   PackageManager.php:383-390 invokes syncRuntimeState, then updates extension_packages,
   then audits, without the lock/transaction used by install/uninstall/rollback.
   Failure between writes can leave installed_plugins/settings and extension_packages
   disagreeing; concurrent addon toggles can overwrite the shared extension_states
   read/modify/write. This is source-confirmed risk; no production failure injected.
   Verify transactional activation and concurrency against isolated MariaDB.

6. P2 — Upload limits contradict the advertised video capacity.
   MediaLibrary.php:15,25 allows 80 MiB video; public/index.php:34 rejects a Workspace
   request larger than 64 MiB, including multipart overhead. Tracked Nginx/FPM configs
   do not establish matching general upload limits (Telegram has its own 64k limit).
   An 80 MiB upload cannot pass the application guard regardless of upstream settings.
   Align UI/application/PHP/Nginx limits and test boundaries; effective production
   global Nginx/PHP limits were not inspected and must not be assumed from templates.

7. P2 — Editorial counters are truncated and unnecessarily load content rows.
   WorkflowRepository.php:26 caps queue() at 250; counts():30-32 counts four such
   result sets. A state with 251 items reports 250. Separate aggregate counts from
   paginated queue retrieval and test totals above the display limit.

8. P2 product consistency — General-purpose Core still has educational/old branding.
   AiChatService.php:60-61 hardcodes a school/admissions assistant. SeoMeta.php:49
   defaults to EducationalOrganization when sanitizing unspecified organization type.
   Page-builder defaults and SiteChrome retain educational copy. EmailSystem.php:226
   hardcodes Base CMS into the site_name token; recovery/email/update views also use
   Base CMS. Live /forgot-password returned that label. Replace owned defaults with
   generic/site-configured values, preserving saved content, legacy tokens and valid
   third-party identities; do not blanket-rebrand dependency or provenance files.

### Verification performed in this audit

944 existing checks passed locally: project-boundaries 11; packages 55; licensing 73;
themes/site 448; installer-package 43; web-installer 28; analytics PHP 14; analytics
consent/UI 18; Google 27; Microsoft 32; Apple 64; Telegram broker 58/client 28/profile 1;
Web Push crypto 1/worker 15; notification settings 15; distribution 13.
These are test-program checks, not a coverage percentage or 944 end-to-end scenarios.
The security reproductions above were additional diagnostics; existing passing tests
do not cover those adverse cases. PHP lint: all 196 tracked PHP files passed.
git diff --check passed before report and was checked again afterward.
Installer ZIP remains unchanged: SHA-256
25ae85f513958f8a206012f0d76dd6112117fe47bc427232bdf9e7bd58eb0c94.

Read-only live GET: /, /extensions, /packages/download, /forgot-password = 200;
all six checked responses (including the two updater 404s) had HSTS and CSP.
Public download metadata currently exposed seven offers. No licensed download,
provider API operation, mail, Telegram message or subscription was submitted.

### Remaining acceptance and recommended order

No live database migration/package lifecycle was executed in this Windows audit.
The existing MariaDB tests require a separate Linux fixture; do not run them against
production. Exact generated installer + real licence + new database + Nginx/Apache
acceptance remains open, as documented by the installer contract. Current production
server hashes, effective permissions/config, service logs and authenticated desktop/
mobile UI were not re-audited. Google/Microsoft/Apple live delivery and GA property
delivery were not validated; mocked protocol success is not live-account acceptance.

No tracked GitHub Actions workflow exists. This does not prove there is no external CI.
docs/packages.md still describes general plugin lifecycle as future although plugins
have implementation/tests, and tests/package-release.php:36 insists on exactly two
archives despite the expanded product inventory. Update both with actual scope.

Recommended work sequence: (1) access-control and sanitizer fixes with regression
tests; (2) safe Core update contract and isolated recovery acceptance; (3) consistent
module lifecycle and atomic package activation; (4) exact-artifact installation and
provider acceptance; (5) upload/counting/default-copy/documentation/CI consistency.
Keep the release explicitly DEVELOPMENT until its gates actually pass. Do not rebuild
or publish Stable, deploy changes, commit or push on the strength of this audit alone.

Only this work-log report was changed. No application code, credentials, generated
artifacts, production data or infrastructure were modified; no commit/push performed.

## 2026-09-09 — security fixes and verified production deployment

User explicitly authorized fixes followed by production deployment. This delivery
addresses the two reproduced security defects, not every remaining release gate.
Preserved the preceding uncommitted audit report and all existing data/configuration.

Changes:
- HtmlSanitizer now recursively cleans descendants before unwrapping unsupported
  elements. Safe semantic content survives; nested scripts, dangerous attributes and
  javascript URLs do not survive save-time or theme-render sanitization.
- AccessControl checks both requested and existing target roles against the actor's
  permissions, including inactive roles and custom roles carrying system.owner.
  Non-owners cannot assign Owner, modify privileged accounts (including password/
  e-mail changes or downgrades), edit privileged roles, or grant missing permissions.
  The owner slug remains reserved. Permissions are refreshed before writes and Demo
  User writes are explicitly refused. Legitimate lower-privilege management and
  intentional owner handover remain supported.
- User/role writes share a schema-specific MariaDB advisory lock acquired before
  authorization checks, preventing concurrent grants from racing account edits and
  preserving the existing final-writable-owner rule. Failures release the lock.
  No new schema, dependencies, permission IDs or role assignments were introduced.
- Added tests/security-regressions.php and its README commands. SQLite :memory:
  provides local checks; --mysql uses only a new random senseqa_security_<hex> DB
  and drops that exact fixture in finally. No production database tests/mutations.
- Added a one-time checksum-guarded scripts/deploy-security.py operator procedure,
  following existing deployment conventions, with separate --check/--deploy modes,
  backup, exact candidate checks, automatic file rollback and post-deploy validation.

Validation:
- 80 new local security checks passed. 84 passed on isolated MariaDB, including
  blocked concurrent user/role writes and preserved state after each rejection.
- Verified test sensitivity against original code: old sanitizer fails nested HTML;
  after changing only sanitizer, original AccessControl fails "Manager cannot become
  Owner" under MariaDB. An earlier server SQLite attempt was unavailable because
  pdo_sqlite is not installed; successful MariaDB runs replace that unavailable mode.
- Server QA: 109 full Workspace migration/functional/package lifecycle checks and
  448 theme/site checks passed. Existing package/licensing/installer/boundary checks
  also passed locally. No live external-provider delivery was triggered.
- New exact local installer pair built after preserving the former pair in
  .local/installer-before-security-20260909T180755. 28 bootstrap HTTP checks pass,
  including exact extracted hashes, session isolation and licence-first flow.
  ZIP SHA-256: 6899e06a08ab09ab6b5048eba016ee37e0997a620115ebc7262b7b49341fdad1.
  Bootstrap SHA-256: 40ce0efd7f0f7f84e30abb87c1cfe271e46ee782a4414406c6dc8ee7e5445a4e.
  Still DEVELOPMENT; not a production updater and not uploaded over the live site.

Production:
- Verified configured host ind, canonical www.sensecms.com, root
  /home/sensecms.com/web, database sensecms_site, PHP 8.5.10 and healthy services.
  Both original production file hashes matched the audited Git baseline exactly.
  Independent cached SSH host identity checked; password passed through a transient
  named pipe, not command arguments, files, output or work notes.
- Recovery: /root/sensecms-backups/20260909T110757Z-security contains verified full
  web-before.tgz (16,938,339 bytes), consistent database-before.sql (408,234 bytes),
  original two source files and deployment.json with before/after hashes. Archives
  are private mode0600. No DB restore or migration was performed.
- Atomically replaced only app/Core/AccessControl.php and app/Core/HtmlSanitizer.php,
  retaining root:root/0644 source permissions. Verified deployed SHA-256:
  AccessControl: 6be51208efd68c72c899445cc1d5fb583f5695be59fd50bff3a41203e20c4ce9.
  HtmlSanitizer: 9f607aa85ca59da9d7f75500d82b05d085bc7104cc6fad0b3dc3cf3accc4ac8c.
- A private one-shot FastCGI probe invalidated only these opcode-cache entries and
  verified the new sanitizer and access guard in the real FPM runtime. Probe removed
  immediately; no public endpoint added and no shared FPM restart/reload required.
- HTTP200/CSP verified for /, /extensions, /docs, /contact, /login, /forgot-password,
  /packages/download. Real owner HTTP authentication passed using its own existing
  session challenge without disabling CAPTCHA. Authenticated HTML checks passed for
  dashboard, access users/roles, pages and themes. Invalid-CSRF access mutation419;
  test session logged out. This was HTTP/UI-render verification, not screenshot QA.
- Account identities, password hashes, active/demo/session-version fields and all
  user-role/role-permission assignments unchanged. Private installation/workspace/
  theme configuration and license encryption files retain original hashes. Normal
  login/session/audit activity is expected; no content, packages or settings changed.
- nginx, php8.5-fpm and mariadb active after deployment; zero fresh critical service
  journal lines since deployment and no fresh Nginx application errors. Configured
  storage/php-error.log was absent; do not interpret its absence alone as log proof.
  Final filesystem check found no retained security-probe PHP file.

Acceptance source and logs retained privately at /root/sense-security-HPaVs4RF.
Source rollback: restore ONLY the two backed-up PHP files with original permissions,
invalidate those FPM opcode entries and recheck HTTP/owner UI. Restoring old source
reintroduces the vulnerabilities; prefer a forward fix. Do not restore the database
or private runtime merely to roll back this code-only patch; retain subsequent data.

Remaining audit items (Core updater, full module lifecycle, activation atomicity,
upload/count/default-copy consistency and broader Stable/provider acceptance) are
unchanged by this patch. No Stable promotion, external publication, Git commit/push,
theme package revision, demo deployment or new dependencies were performed.

Final pass: all 944 pre-existing local checks and the 80 new SQLite checks passed;
all 197 PHP files passed lint, deployment-script syntax and git diff --check passed.
Fresh Nginx/FPM journal scan at all priorities found zero PHP warning/fatal/parse/
notice or uncaught-error signatures. Effective FPM error-log path was confirmed.
Private deployment receipt retained locally in .local/security-deployment.json.
Temporary local transfer archive/helper removed; QA source/logs and recovery archives
remain deliberately retained. No live rollback was necessary.

## 2026-09-09 — maintenance hardening, transactional activation and accurate totals

Continued the authorized audit fixes and production deployment after interruption.
The earlier AccessControl/HtmlSanitizer fixes remain intact. This batch closes the
activation/count defects and contains the unsafe legacy updater; it does NOT deliver
a working automatic Core update channel or promote a Stable release.

Implementation:
- SystemUpdate keeps its public constructor/method signatures but removes the
  incompatible legacy replacement/DDL worker. Check/install/run/archive operations
  fail closed with 503, including old queued jobs. Status uses config/product.php
  core_version (0.1.0), never a caller override or legacy 1.0.0 fallback. It never
  advertises a verified/latest release from old timestamps or unsigned metadata.
  Reading status creates no runtime files. Existing signed extension catalogue
  verification is retained separately; publisher provisioning is not solved here.
- SystemUpdateController preserves authentication, permissions, demo and CSRF
  gates and exposes unavailable operations as HTTP503. The panel uses existing
  components/styles, explicit operator-deployment guidance and disabled controls,
  without claiming the installation is up to date. The replacement JS never polls
  or sends update requests; console.php cache-busts it for existing browsers.
- PackageManager::setActive shares the install/rollback/uninstall advisory lock.
  Registry, installed-plugin state, Forms/addon extension_states and audit commit
  together, rolling back on failure. Removed DashboardController's duplicate
  settings write outside the transaction. Unrelated settings/config are preserved.
- WorkflowRepository::counts aggregates pages/posts directly instead of counting
  four queues capped at 250. Existing state/status and facility visibility rules
  remain unchanged; bounded queue rendering is not expanded.
- Added maintenance-regressions.php (SQLite or guarded random MariaDB fixture)
  and system-update.cjs. README documents commands and the automatic-update boundary.
  Extended the existing exact-hash deployment procedure with --maintenance mode,
  preserving its earlier security mode rather than duplicating operator tooling.

Validation:
- 16 local SQLite maintenance checks and 29 isolated MariaDB checks passed: forged
  catalogue/stale jobs, unavailable methods without writes, disabled server HTML,
  totals above 600, facility scopes, audit-failure rollback for addon/Forms/ordinary
  plugin, preserved plugin configuration and lock contention/release.
- 5 JS lifecycle checks pass for initial/replaced panel content without network or
  polling. 80 SQLite / 84 MariaDB security checks still pass. Server QA also passes
  all 109 Workspace migration/functional/package and 448 theme/site checks.
- Local boundary, package, licence, theme, installer, analytics/consent, Google,
  Microsoft, Apple, Telegram broker/client/profile, Web Push, notification-settings
  and distribution suites pass. All 198 PHP files lint; Python syntax and
  git diff --check pass. Historical tests/telegram-package.php was inadvertently
  included in the local list: its Windows guard exits immediately without mutation;
  this Linux-only historical lifecycle script was NOT accepted or bypassed. Relevant
  current lifecycle verification is the isolated Workspace/maintenance suite.

Deployment and recovery evidence:
- Private QA: /root/sense-maintenance-HvrDfbrm; source, test/deployment logs and
  read-only post-deployment checks retained. Production remains exactly
  https://www.sensecms.com, /home/sensecms.com/web, database sensecms_site.
- Initial attempt backup /root/sensecms-backups/20260909T122915Z-maintenance.
  All HTTP/FPM checks passed, but the strict whole-row package comparison triggered
  automatic source rollback. Read-only comparison against the SQL backup proved
  ONLY extension_packages.updated_at changed on four existing bundled records:
  normal syncBundled behaviour when rendering /appearance/themes. All ten package
  identities/other fields, five installed-plugin rows and five settings rows matched.
  No database restoration or data deletion occurred. Previous security fixes remained.
- Corrected the verification to compare EVERY package field except that documented
  metadata timestamp; all plugin/settings fields remain compared. Retry completed
  and verified: /root/sensecms-backups/20260909T123210Z-maintenance. Full source backup
  web-before.tgz 16,942,494 bytes and consistent database-before.sql 408,494 bytes,
  both mode0600, plus exact original files and before/after SHA-256 deployment.json.
  Local receipt: .local/maintenance-deployment.json. The failed attempt is retained.
- Eight exact files deployed: Core/PackageManager.php, Core/SystemUpdate.php,
  Core/WorkflowRepository.php, Http/DashboardController.php,
  Http/SystemUpdateController.php, Views/console-system-update.php, Views/console.php
  (all under app/), and public/theme/sensecms-system-update.js. Per-file atomic rename
  preserves ownership/modes; all candidate/deployed hashes checked. One-shot private
  FPM probe invalidated affected PHP opcodes and verified real Core/security code;
  removed immediately. No shared service restart, schema/infrastructure change,
  dependency addition or production package toggling.
- Public HTTP200/CSP: /, /extensions, /docs, /contact, /login, /forgot-password,
  /packages/download. Real owner login, dashboard/access/users/roles/pages/themes
  and update HTML pass. Live status shows 0.1.0, supported/available/verified false;
  authenticated valid-CSRF check/install return503. Invalid access CSRF still419.
  Served JS hash/cache-busted reference confirmed. This is HTTP-render verification,
  not screenshot/mobile-browser acceptance. The operator session was logged out.
- User/password/permission assignments, private configuration/encryption/license
  files, package activity/configuration and all settings preserved. Rejected update
  requests change no saved jobs/catalogues. Independent read-only production row
  counting matches WorkflowRepository aggregates; empty facility scope stays empty.
- nginx/php8.5-fpm/mariadb active. No fresh Nginx application error signatures;
  service journal since 12:32:10 UTC has zero PHP warning/fatal/parse/notice, uncaught
  or critical/error signatures. Effective FPM error_log remains storage/php-error.log
  (absent); absence is not treated as independent proof. No probe file remained.
- Source rollback: restore only the eight original files from the successful backup
  with original modes, invalidate their FPM opcode entries and recheck HTTP/owner UI.
  Do not restore the database/private runtime for this source-only batch: preserve
  subsequent business writes. Reverting SystemUpdate re-enables unsafe legacy logic,
  so prefer a forward correction. Automatic rollback was exercised on the first run.

Installer and remaining boundaries:
- Preserved the prior matching pair at .local/installer-before-maintenance-20260909;
  generated fresh DEVELOPMENT .install/web artifacts. All 28 exact bootstrap HTTP
  checks pass, including extracted source hashes and licence-first flow.
  ZIP SHA-256 c6a1b49fdd550ae9e479367584b3adf5d907e7a1ec1d40716d48c242a7f1ed9a;
  index.php SHA-256 763362be8c819444dc512d8f5504260fd18b717f31f2b633a8f721a0df356358.
  Neither artifact was uploaded over production. Full exact-artifact licensed DB
  setup and real Nginx/Apache acceptance remain Stable gates.
- Remaining audit work: implemented signed Core updater/recovery contract, full
  module lifecycle, upload-limit consistency, generic default-copy cleanup and
  external-provider acceptance. No Stable promotion, Git commit/push, demo work,
  external messages or package release was performed.

Final cleanup: removed only the temporary local SSH/diagnostic helpers; their
non-secret diagnostic copies remain in private server QA. Kept deployment receipts,
previous installer pair and both recovery archives. Source/private-key pattern scan
found no exposed key material; final deployment-script syntax/diff checks pass.

## 2026-09-09 — official Update page, signed Stable checks and notifications

User added an Update navigation item and requested /update, an online own-CMS check
directly below the hero, manual checks from each CMS and automatic notifications.
Preserved the existing navigation and all preceding dirty work. No Stable release
was fabricated, no automatic installation enabled, no Git commit/push performed.

Implementation and contracts:
- Product theme 0.3.9 adds /update using existing hero/components/tokens, followed
  immediately by the installation-check form and latest Stable release list. It
  distinguishes an authenticated empty Stable catalogue from service failure.
  The source-only route does not replace an existing managed CMS page; preflight
  confirmed no managed /update page. The user's navigation was untouched.
- CoreReleases verifies a bounded Ed25519 envelope, exact Sense CMS/Stable identity,
  lifetime and release-row contract. Requests use fixed HTTPS origin/path, public
  DNS pinning, no proxy/redirects, TLS verification, bounded size and timeouts.
  SystemUpdate saves only successfully verified metadata, rejects issuance rollback,
  separates installed/latest versions, reports PHP compatibility, and never certifies
  failed/expired checks. Archive installation and old legacy worker remain blocked.
- Independent public trust is installation-local storage/update-trust.json, provisioned
  by the operator. On the official site it matches the existing private publisher and
  independently provisioned trust.json. No private key, trust store, customer runtime
  or website-only service is bundled in the Core installer. Customer installs need
  their public verification key provisioned before checking; documentation explains it.
- SystemUpdateController retains authenticated system.manage, Demo and CSRF guards;
  check is functional, unsupported install remains503. Check UI has one submit handler
  across AJAX lifecycle, explicit loading/failure states and disabled install action.
- Successful checks are due every six hours, errors back off one hour, manual checks
  have a 60-second cooldown and nonblocking shared file lock. Existing notifications
  endpoint runs due checks for system.manage/non-Demo users and reuses its Core update
  notification item only for a verified newer Stable version. CLI check-updates.php
  and an hourly installation-specific cron work independently of an open browser.
  No email, Telegram, Web Push subscription or external user message is added.
- The website-only endpoint /api/updates/v1/catalog verifies the saved signed envelope
  before serving it. The reviewed .src/core-releases.json inventory is empty, because
  no Stable Core release is approved. Root-only publication refreshes its 24-hour
  signature validity every six hours; FPM never receives signing-key access. This is
  metadata refresh, not a release promotion or package-download authorization.
- Online checking uses an explicit HTTPS origin, a random nonce and a session-free
  /update-connect landing. Same-origin Continue preserves Strict session cookies;
  unauthenticated users sign in on their own CMS, then return to a fixed update route.
  The user confirms check-and-share in that panel. postMessage sends only version,
  latest version, verification time and flags to the fixed official origin. The
  website accepts exact source/origin/nonce, bounded freshness and one response only.
  No keys, cookies, accounts or content cross domains. No implicit session discovery.
  Older CMS installs require the new Core/check handler and trust provisioning.

Verification:
- 27 isolated release checks: genuine empty feed, signature tampering/wrong key,
  schema/product/channel/time/size/row failures, prerelease and duplicate rejection,
  due checks, newer Stable comparison, failed-check suppression/backoff, metadata
  rollback refusal and permanently blocked legacy installation.
- 14 CMS JS checks: no unsolicited network/polling, CSRF POST, explicit sharing,
  fixed target/minimal payload, no sharing on failure, reenabled controls and AJAX
  reinitialization. 16 website JS checks: empty/error distinction, URL constraints,
  spoofed source/origin/nonce/fields/time rejected and single-response acceptance.
- Isolated server QA /root/sense-updates-4c0rnb7y: 29 MariaDB maintenance checks,
  84 security, 109 Workspace migration/functional/package and 466 theme/site checks
  pass. All 205 PHP files lint; deployment/test Python syntax and git diff --check pass.
  No fixture database points to production. Local installer/boundary tests pass.
- Browser inspected actual /update with retained user menu, real empty Stable state,
  hero/form ordering and desktop layout. Mobile 390px check found no horizontal
  overflow (document width375, viewport390); form width293 and single-column controls.
  Unit tests cover cross-origin message validation and HTTP acceptance covers the
  real authenticated check/bridge screen. A complete real two-installation browser
  login-and-callback rehearsal remains unverified; no external customer account was
  created or used. In-app popup tracking was not reliable, so it is not claimed as
  end-to-end callback evidence. Ordinary popup/opener restrictions have documented
  direct-panel fallback. No screenshot/UI claim substitutes for these distinctions.

Production delivery:
- Initial /root/sensecms-backups/20260909T133300Z-updates attempt automatically rolled
  source/theme/config back when the HTTP test parsed an HTML redirect as JSON. Access
  logs proved public routes, signed catalogue and manual check succeeded; the test
  omitted Accept: application/json on /api/notifications. Fixed that test contract,
  not application authorization. The inactive orphan theme archive was verified and
  moved to the next backup's retained-orphan-theme before a fresh install. No deletion
  or database restoration; all recovery state retained.
- Final backup /root/sensecms-backups/20260909T133533Z-updates: web-before.tgz
  17,627,865 bytes and database-before.sql421,407 bytes, both root-only mode0600;
  originals, exact target ownership/modes, accepted hashes and deployment.json.
  Local receipt .local/updates-deployment.json. Final status verified.
- Eleven Core files, website endpoint, narrowly added Nginx exact location, update
  worker cron and private metadata publication tooling were published. Source hashes
  match accepted QA. Shared FPM opcodes invalidated via private deleted one-shot
  probe, no FPM restart. Nginx config tested before graceful reload.
- Signed immutable theme sensecms-0.3.9-017cb4da57452a26 activated via ThemeManager,
  with verified archive SHA-256
  017cb4da57452a26a57392e3a2a89496108e5715a7c050d44ac00937972c1be5.
  Runtime ownership repaired before activation; previous 0.3.8 retained. Public
  package download offer stays at its previous version; this is not a Stable release.
- Actual HTTP checks: public pages/assets/landing200 and CSP; catalogue GET200,
  POST405, correctly empty Stable list; owner login; bridge confirmation UI; invalid
  CSRF419; valid check200/verified=true; install503; notifications JSON200/no false
  Core alert; dashboard/pages/access/themes200; smoke-test session logged out.
- User identities/passwords/permissions, all menus/menu items/translations and all
  existing page records/translations match the predeployment snapshot exactly.
  Private installed/workspace/license files retain hashes. No schema/data migration.
- Real PHP-user CLI succeeded twice, showing build0.1.0 and available=false. nginx,
  PHP-FPM, MariaDB and cron active; no fresh critical/PHP error signatures in checked
  Nginx log or service journal since 13:35:33 UTC. No retained update-probe file.
- Recovery: disable only the two new cron entries, restore the recorded originals
  and Nginx configuration/theme pointer, invalidate affected opcodes and recheck.
  Do not restore the database or overwrite later user/menu changes for code rollback.
  Retain signed theme and both backups for inspection; do not rerun deployment blindly.

Fresh local DEVELOPMENT installer built after preserving the previous matching pair
in .local/installer-before-release-checks-20260909. All 28 exact-artifact bootstrap
HTTP checks pass; archive SHA-256
903b9ed8e0fbf705d8c6369822a5dccef2841e1df12f49aa9e0a13b80070edca,
index.php 0861461a136f27a9d833be658affc03939c8b42724a0ff342d563018b82654c5.
No bootstrap overwrote production. Exact-artifact licensed DB install/Nginx/Apache,
full automatic Core installation/recovery, module lifecycle, upload limits, generic
defaults and provider-account acceptance remain separate audit/release work.

Cleanup: stopped the local loopback preview, removed the reproducible transfer tar
and temporary SSH/Nginx-inspection helpers. Server QA, exact deployment receipts,
immutable theme archives and both production recovery backups remain. The final
private-key/token pattern scan found no matches; no secrets were logged or committed.

## 2026-09-09 — Update page simplified at the user's request

Scope supersedes the previous cross-domain bridge requirement: `/update` now lists
Stable releases and notes only. Removed the domain form, popup/message protocol,
session-free landing view/route and special post-login return. Manual signed checks,
six-hour automatic checks/notifications and the disabled Core installer remain.
There is still no accepted Stable Core release; no development artifact was promoted.
Unrelated pending work, the user's menu and all business data were preserved.

Verification: CMS/website JS regression suites pass (including no sharing even with
an old bridge attribute/opener); 27 signed-feed checks, 465 theme checks, local
80 security/16 maintenance checks and isolated MariaDB 84 security/29 maintenance
checks pass. Modified PHP lint, Python syntax, git diff --check and 43 installer
distribution checks pass. Initial private QA missed `.src/package-catalog.php`;
added the exact local fixture dependency and reran successfully before deployment.

Production: signed immutable theme `sensecms-0.3.10-522fe650512043b6`, archive SHA-256
522fe650512043b64d49c2825548064c1902c3f2d5b28c8762a4b10d9f9b8b40.
Six Core files replaced with accepted hashes, obsolete `app/Views/update-connect.php`
removed and recoverable from backup. No database migration, infrastructure change,
service restart, public package promotion, commit or push. FPM opcodes invalidated
through a private one-shot probe, then probe deleted.

Recovery backup `/root/sensecms-backups/20260909T135800Z-update-simplification`:
web-before.tgz17,636,650 bytes, database-before.sql421,466 bytes, both0600; exact
originals, ownership/modes, signed theme and deployment.json retained. Local receipt
`.local/update-simplification-deployment.json`; QA `/root/sense-simplify-LVpGVHhi`.
For code rollback restore only recorded source and theme pointer, including the
removed landing, invalidate affected FPM opcodes and recheck. Do not restore the
database or discard newer business edits. Previous signed theme0.3.9 remains.

Live acceptance: website/metadata/assets200, removed landing404, authenticated
ordinary update UI even with stale bridge query, invalid CSRF419, signed check200,
install503, notifications JSON200/no false update, adjacent panel pages200; test
session logged out. Exact identity/permission/menu/page snapshots and private
installation/licence hashes unchanged. Real PHP-user CLI passes; nginx/PHP-FPM/
MariaDB/cron active, no fresh Nginx/PHP errors or service-journal priority0–3 entries
since13:58:00UTC. Chrome DOM and screenshots confirm release-only page: mobile390px
document390px/card354px, desktop1440px document1425px; no horizontal overflow. First
full-page screenshot timed out; subsequent viewport screenshots passed. Temporary
viewport emulation cleared after inspection.

Fresh local DEVELOPMENT installer preserves the preceding pair at
`.local/installer-before-update-simplification-20260909`. All28 exact-artifact HTTP
checks pass. ZIP SHA-256e7ae85656c594a9da7690beaacb06ce1c435a878c7ce631f59984d3e9e39109a,
bootstrap SHA-256c9a2a4046846ed369e66c2fe91ec0dd908fdffb7919a9af70845a71c2dda48a1.
No production bootstrap overwrite. Full new licensed DB/server acceptance and other
previously documented audit gates remain; two-installation bridge acceptance is
no longer applicable. Temporary local SSH helper and transfer archive removed.

## 2026-09-10 — Upload limits and generic Core defaults (deployed)

User authorized continuing the remaining fixes and production delivery. Scope:
upload limits across UI/Core/PHP/Nginx, plus owned Base CMS labels and the generic
SEO/chat/header/footer defaults. No saved business content, module implementation,
theme release or new Stable publication was included.

Root cause and implementation:
- MediaLibrary allowed 80 MiB MP4 but the front controller allowed only 64 MiB per
  administrative request; legacy storeVideo used decimal80,000,000 instead. Live
  private FPM probe showed inherited upload_max_filesize/post_max_size8G and20 files;
  site Nginx had no general explicit limit (only Telegram64k overrides).
- MediaLibrary centralizes80 MiB video,95 MiB aggregate files,96 MiB request and10
  files. Actual temporary file sizes replace caller-supplied sizes. Too many files
  or an oversized aggregate is rejected before persistence, not silently sliced.
  Per-file limits remain8 MiB image/20 MiB audio/PDF. PHP partial/oversize uploads
  and upstream HTTP413 produce actionable messages. A smaller operator PHP POST
  limit is detected before a misleading CSRF error when Content-Length is available.
- UI displays the aggregate and binary-unit limits, rejects excess count/aggregate,
  retains selection after failure and reenables controls. Cache key updated.
  Dedicated production pool now80M per file/96M POST instead of inherited8G; Nginx
  administration96m, public location16k; existing Telegram64k/rate limits preserved.
  Admin URLs rewrite to the only PHP front controller; they never serve PHP source
  from the newly scoped location. Templates and portable operator README documented.
- Removed Base CMS from owned application messages, recovery/email UI and mailer
  defaults. Email site_name token reads configured site name, default Sense CMS.
  AI default context is website/service-oriented, not school/admissions-specific.
  SEO sanitization defaults to Organization but still accepts explicit School etc.
  SiteChrome EN/KM/ZH defaults are generic; new site_name token and legacy school
  token both work; stored overrides remain authoritative. Media tags use generic
  examples. No actual email or AI provider request was sent by these checks.

Verification:
-18 media/default checks: exact per-type thresholds, actual-size spoof rejection,
  aggregate/count bounds, explicit education identity preservation and saved/legacy
  footer tokens. JS upload suite: count/aggregate/no request on failure,80 MiB request,
  CSRF, non-JSON413, control recovery and selection preservation.
- Real multipart PHP server on Windows accepts83,886,080 bytes and rejects one byte
  over. Isolated Linux Nginx plus separate FPM processes, bound only to loopback,
  repeat that acceptance and verify early413 for96 MiB+1 administration and16 KiB+1
  public requests. Initial QA readiness check used / and saw403 before FPM was ready;
  fixed readiness to require the fixture's actual /index.php422 response, not an
  unrelated status. No production setting was weakened to fix the test.
- Isolated MariaDB Workspace suite112 checks, including actual80 MiB file storage,
  database recorded size and rejection without extra rows. Security84/maintenance29
  on isolated MariaDB; local security80/maintenance16; theme465; installer43;
  PHP lint, Python syntax and git diff --check pass. Fixture databases/directories
  are random disposable targets and removed by the tests. No80 MiB production upload.

Production evidence:
- QA /root/sense-media-C1YgZvc9; backup
  /root/sensecms-backups/20260909T205322Z-media-defaults (UTC; local task dateSept10).
  web-before.tgz18,328,802 bytes and database-before.sql421,525 bytes, both0600;
  exact source/config originals and metadata in files/targets.json. Receipt
  deployment.json copied to .local/media-defaults-deployment.json.
- Fifteen Core source files and this site's Nginx/FPM pool deployed with exact hashes.
  Nginx/FPM syntax tests passed before graceful reload (no service restart). Runtime
  probe confirms80M/96M. Source/config hashes match accepted candidate. Existing
  theme0.3.10 and all private installation/licensing hashes unchanged.
- Authenticated HTTP acceptance includes media screen/new limit copy/cache key,
  dashboard/pages/access/themes, signed release check/CSRF/install503/notifications.
  Public recovery now Sense CMS; website/JS200; oversized request headers without
  bodies produce413 on upload and contact routes. No production files, emails or
  business records created by these checks; own test login session logged out.
- Exact users/permissions/settings/menus/pages/media snapshots unchanged. No schema
  migration or data restoration. nginx/php8.5-fpm/MariaDB/cron active; no fresh PHP
  or critical Nginx signatures. Expected oversize rejection error-log entries are
  not application failures. Service journal priority0–3 empty since20:53:22UTC.
- Recovery: restore the recorded15 source files and two scoped configs with saved
  ownership/modes, test both configs, gracefully reload PHP-FPM/Nginx and recheck.
  Do not restore database or overwrite newer user settings to roll back code.

New matching DEVELOPMENT installer retains prior pair in
.local/installer-before-media-defaults-20260910;28 exact bootstrap HTTP checks pass.
ZIP SHA-256826d10defc2f8125068a2ee4816dca5f54f1126df0c1745ebcd9b8378c6c3476;
bootstrap SHA-256bfb4f0aedce8815fb9a3f4b71c289c6c387abb6902b86bf17d2537724a6648cd.
No bootstrap was uploaded over production, no commit/push or Stable promotion.

Remaining: school-specific page-builder presets/localized defaults and some legacy
editor labels still need a compatible cleanup; this is NOT a claim that every school
reference is removed. Preserve component keys/saved content and third-party product
identities. Module lifecycle, exact licensed-new-install/server acceptance, provider
account acceptance and public Stable release gates remain. Automatic Core installation
is still disabled. Local transfer tar and temporary SSH/probe helpers removed; server
QA, deployment receipts and private recovery archives retained.

## 2026-09-10 — Generic builder presets and truthful section labels (deployed)

Continued user-authorized cleanup after interruption. Fourteen Core files deployed:
PageBuilder, three preset configuration files and ten administration views. No
database migration, theme activation, infrastructure change, service reload/restart,
package promotion, commit or push. The preceding upload/security/update fixes remain.

Changes:
- All15 built-in builder component IDs and field contracts remain; visible admissions
  and programs labels are Process steps and Services. EN/KM/ZH defaults now describe
  general teams/services/projects, with no inherited school copy or old theme image
  paths. Images/posters/alt text start empty; editors select real media. Statistics
  use explicit unfilled figures instead of fabricated school numbers. Saved data is
  not rewritten or migrated to these defaults.
- Catalogue construction previously suppressed required only at the top field level,
  so blank nested gallery images were rejected while merely reading the catalogue.
  Added an internal defaults flag propagated recursively through sanitizeData; only
  preset construction bypasses required-value validation. Save validation still
  checks nested required media, URL safety, repeater bounds and component rules.
- Generic labels/examples in posts, categories, navigation, media, facilities,
  live-chat and legacy editor labels. Navigation documents site_name and preserved
  school token. Core section tabs now describe built-in sections, not15 separately
  installable modules. Counts are computed; theme supported-block limitations are
  explicit. Legacy route/query/DOM keys remain compatible. Full module lifecycle
  remains unimplemented, not silently claimed by this wording change.

Verification:
-175 new builder checks pass: every component/locale, no school defaults or old
  theme media, all legacy keys/order, safe URL validation, blank nested preset fields
  vs enforced save requirements, preserved explicit school titles/media paths in
  round trips, and official theme's exact three-section boundary. This is validation
  evidence, not a claim of publishing new pages on production.
- Isolated server QA /root/sense-builder-I06xQGCK: builder175, media/default18,
  Workspace112 (including real file storage fixture), MariaDB security84 and
  maintenance29, theme465. PHP lint, Python syntax and git diff --check pass.
  Installer43 distribution and28 exact-artifact bootstrap HTTP checks pass.
- Live owner HTTP acceptance: extensions section labels/count text; posts generic
  copy; footer token help; actual builder data-builder-payload catalogue restricted
  to text/custom-html/contact-form; media limits, signed release check, CSRF419,
  blocked install503, notifications and adjacent/public routes. No content save,
  file upload, notification transmission or account changes; test session logged out.
  Browser inventory exposed only an unauthenticated IAB, so no authenticated visual
  screenshot or click-through acceptance is claimed this batch. Existing layout and
  styles were unchanged; UI copy/catalogue checked in authenticated HTTP responses.

Production evidence and recovery:
- Backup /root/sensecms-backups/20260909T213354Z-builder-presets (UTC; localSept10):
  web-before.tgz18,336,702 bytes; database-before.sql421,584 bytes; both0600.
  Exact file originals/ownership/modes, candidate hashes and deployment.json retained;
  local receipt .local/builder-presets-deployment.json.
- All14 deployed hashes match accepted QA. Only affected PHP opcodes invalidated
  via a private one-shot FPM probe, deleted afterwards. Nginx/PHP config checks pass;
  nginx/php8.5-fpm/MariaDB/cron active. No fresh Nginx/PHP errors or service-journal
  priority0–3 entries since21:33:54UTC. Private installed/workspace/theme/license
  hashes unchanged. Exact users/permissions/settings/menus/pages/media/blocks,
  translations/shared sections/revision snapshots unchanged.
- Roll back only recorded14 source files with preserved ownership/modes and invalidate
  opcodes; do not restore the database or overwrite later editorial changes. Previous
  theme0.3.10 and earlier backup batches remain retained.

Fresh DEVELOPMENT installer: previous pair retained at
.local/installer-before-builder-presets-20260910.
ZIP SHA-256b5ba2dbd68c352bf1e9903b6ec8b7070b5a936cb5fd7da8792f1127f000dbf00;
bootstrap SHA-25643dabdc045307afd40807f10f53bc3ab10446863e1c3c690024b3bec40be3ff1.
No production bootstrap replacement. Temporary local transfer and SSH helper removed.

Remaining: legacy DashboardController::defaultHome/defaultPopup still include old
school copy and an image fallback; investigate callers and saved/fallback behavior
before adjusting them. This batch cleans builder presets, not every product fallback.
Independent module lifecycle, exact licensed installation/server acceptance, provider
account acceptance and public Stable promotion remain open. Documentation now separates
implemented plugin/add-on lifecycle from unimplemented independent module runtime.

## 2026-09-10 — Empty, non-injecting popup defaults (deployed)

Removed the unused private DashboardController::defaultHome (no callers in active
source/tooling). New popup content, media, highlights and localized values start
empty; disabled state, dimensions, colours and frequency remain. Without nested
starter highlights/translations, recursive merging no longer resurrects omitted
campaign items or overrides root content with fabricated translated defaults.
Explicitly saved school content is valid and remains untouched. PublicController
already reads saved settings directly; public rendering was not changed.

One production Core file changed: app/Http/DashboardController.php, SHA-256
9f1f6c368755116764ae4eb1d4f5c4799e66b0c87b498c806d26b226fdc1be53.
No data migration, theme/config changes, restart, package promotion or Git operation.
QA /root/sense-popup-nQQo93Ij accepted popup27, builder175, media18, Workspace112,
MariaDB security84 and maintenance29, theme465. PHP lint and git diff --check pass.
Live owner HTTP acceptance includes the popup form, adjacent panel/public routes,
signed update check, CSRF rejection and disabled installer. Test session logged out;
no campaign save or outbound notification. Exact database snapshots of users,
permissions, settings, menus, pages, media, blocks, translations/shared sections and
revisions match before/after. Private installation/theme/licence hashes unchanged.
No authenticated browser screenshot/click-through acceptance claimed.

Backup: /root/sensecms-backups/20260909T214419Z-popup-defaults (UTC), complete web
and database recovery archives, file originals and permissions, deployment receipt.
Local receipt: .local/popup-defaults-deployment.json. Only affected PHP opcode was
invalidated using a deleted private FPM probe. Configuration checks, four active
services and fresh PHP/Nginx error checks pass. Rollback: restore only this recorded
source file with its ownership/mode and invalidate opcode; never restore the DB over
new editorial data.

Regenerated matching DEVELOPMENT installer; prior pair retained in
.local/installer-before-popup-defaults-20260910. ZIP SHA-256
a9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1;
bootstrap SHA-25644b348edc56ddbbc9eae619a7f33984afc66c0de35ba7c462ac02b658a67be3e.
Installer distribution43, exact bootstrap HTTP28 and standalone boundaries11 checks
pass. Recovery archives are0600: web18,333,033 bytes and database421,643 bytes;
service journal priority0–3 is empty since21:44:19UTC. Temporary local SSH helper
and transfer archive removed; private QA and recovery evidence retained. No production
bootstrap replacement. Independent module lifecycle, exact newly licensed complete
installation/server acceptance, provider-account acceptance and Stable promotion
remain open. The earlier legacy Dashboard fallback cleanup is now closed.

## 2026-09-10 — Exact web-installer handoff for user installation

User will perform licensed database installation and explicitly requires output at
F:/Git/MChivale/sensecms-web/.install/web. That directory was empty on current
inspection; rebuilt the exact current Core into index.php (65,500 bytes) and
install.zip (8,801,795 bytes). Hashes match the preceding popup candidate:
bootstrap44b348edc56ddbbc9eae619a7f33984afc66c0de35ba7c462ac02b658a67be3e;
ZIP a9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1.
No Core change, new package, Stable promotion or production deployment this turn.
The removed .install/README.md was left as found; root README now gives the full
two-file browser workflow and links to the existing Core requirements instead.

Extended tests/web-installer.py with an explicit --nginx fixture: private temporary
installation, dedicated loopback Nginx/FPM processes, unprivileged PHP worker,
separate configuration/logs, process teardown and temporary-directory cleanup.
Does not reload/configure system services or access any production database/key.
Remote QA /root/sense-install-5OlRCB5Y received only the exact installer and HTTP test.
All34 Nginx/FPM checks pass, including extraction, tamper rejection, existing-file
preservation, CSRF, session ownership, resumability, every extracted source hash,
real licence-first installer, blocked database bypass, and private/non-front PHP
path denial. Local PHP server28, distribution43 (including all extracted PHP lint),
boundaries11, licensing73, packages55, security80 SQLite, maintenance16 SQLite and
popup27 pass; update/upload JavaScript tests and git diff --check pass. Exact remote
artifact hashes match local files; production nginx/php8.5-fpm/MariaDB remain active.

Scope of acceptance: loopback HTTP with the existing strictly local test flag,
not target public TLS. Apache is not installed on this host; no server package was
installed or service changed. Target TLS/Apache, real licence activation, database
completion and post-install owner acceptance remain unverified and belong to the
user's forthcoming installation. Optional independent modules and external-account
acceptance remain separate; they were not represented as delivered prerequisites.
Temporary local SSH helper and transfer archive removed; private QA artifact retained.

## 2026-09-10 — Bootstrap empty-storage fix and requested demo database

Screenshot investigation confirmed the uploaded bootstrap and ZIP match the previous
accepted pair. The actual demo root contains public/index.php, public/install.zip
and an empty storage sibling. The guard incorrectly rejected that prepared storage,
not the two public installer files. Updated scripts/web-installer.php and the generated
.install/web/index.php only (no Core payload or ZIP regeneration). Initial and
pre-publication guards now permit only an empty writable non-symlink storage directory;
private mode0700 is enforced before writing setup. Other existing data remains blocked.
Errors/help explicitly distinguish the parent installation folder from public.
Updated README and regression fixtures. Local HTTP37 and isolated Nginx/FPM48 pass,
including nonempty storage, storage changed during extraction, symlink rejection,
resumption, integrity and licence-first acceptance. PHP lint/diff checks pass.
New bootstrap SHA-256430b7994516d88e82b71e910cbf9eb63be9bb9878fdbde991469fdcf188a4c17;
ZIP remains a9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1.
No bootstrap upload to the user's demo; user will transfer the corrected file.

Explicitly authorized database provisioning on verified ind.ittsp.net (82.180.147.156),
MariaDB11.8.6. Created previously absent sensecms_demo schema (utf8mb4_unicode_ci)
and same-named user restricted to localhost/127.0.0.1, with a cryptographically random
password and grants limited to the escaped exact schema name, no GRANT OPTION.
Credentials saved at user-requested .cfg/MySQL.demo.txt (Git-ignored; ACL current
Windows user and SYSTEM only); private recovery copy root0600 on server. No secret
values recorded here or emitted by tooling. Both localipv4 and localhost PDO logins,
temporary-table read/write and denied access to production users were verified.
The demo database remains empty; no existing database/user was changed.

Additional diagnosed blocker, not changed without separate scope: demo Nginx points
to php8.5-sensecms.sock (official-site pool, user sensecms), but demo web/public are
root:root0755 and storage root:root0700. PHP cannot safely prepare that installation.
Asked whether to configure a separate demo pool and scoped ownership; no permission,
pool, Nginx, installed runtime, or application database migration changes made.
Temporary provisioning script and local transfer/SSH helpers removed after verification.

## 2026-09-10 — Fresh-install homepage routing (both live installations)

User reported /en/home returning Facility not found after completing demo setup.
Verified real installed demo: no active theme, zero facilities/pages; root sent302
to /en/home and the Workspace handler returned404. A clean Core must not require
seeded facility/content merely to open its homepage. No sample data was inserted.

Three Core files deployed to www.sensecms.com and demo.sensecms.com:
- public/index.php serves a theme-independent Core start page at / when no active
  theme exists; configured site name escaped, sign-in link, noindex, security headers,
  enforced licence, no administration session. Enabled locale home aliases redirect
  once to /. Other missing URLs stay404 and public POST stays405; HEAD is bodyless.
- app/Views/public-home.php reuses existing Core stylesheet/logo/controls for the
  unconfigured homepage and404 state. No dependency on the official site's theme.
- PublicController::show validates the locale and redirects missing-primary-facility
  requests to /, where an installed theme can render its normal homepage.
Existing public theme rendering and saved content/routes remain unchanged.

Source hashes: public/index.php29b7ec293a3490ccf49cfaf099c6aa1e747b5839745fa3c61a46c8060e13f4ae;
PublicController.php7670f45c688a4108be2cfa2bc4ea3fb9ed438e6797a6c1bdabda96247947a390;
public-home.php27e0ea0814d7197c36168c39887c1738a1a7d125baf4d424be55241cf79f1b05.

Preflight/QA /root/sense-home-qb49jnGQ: real front-controller routing17 checks with
explicit runtime/licence/repository doubles (no claim of real activation); isolated
MariaDB Workspace112, security84, maintenance29, themes465, builder175, popup27.
Local media18 and Core releases27 pass, plus PHP lint, Python syntax and diff checks.
Live acceptance on both exact hosts verifies homepage200, correct theme/default
presentation, login200 and closed installer404; demo's actual enabled en/pl aliases
redirect to /, missing page404, HEAD200 and homepage CSS/logo200. Official owner
HTTP regression suite passes signed update checks, CSRF, notifications and adjacent
panel/public routes. Exact data/settings/permissions/facilities/pages/media/block
snapshots preserved on both databases; runtime/theme/licence hashes unchanged.
Browser verified old demo URL redirect to / and rendered page at1366/390 CSS pixels;
no horizontal overflow, mobile body16px/heading32px/button45px. Temporary viewport
override cleared. No credential entry or content save through the browser.

Recovery: /root/sensecms-backups/20260910T104038Z-public-home includes separate full
web/database archives for both hosts, originals, ownership/modes and deployment.json;
local receipt .local/public-home-deployment.json. Only six PHP paths invalidated in
the currently shared FPM pool; no restart, infrastructure change or DB migration.
No fresh errors in either Nginx/PHP log and all four services active. Roll back only
recorded source originals and remove the newly added view if reverting, then invalidate
opcodes; do not replace current databases/private runtime with historical snapshots.

User explicitly changed workflow: test deployed source before building an installer,
and build only when explicitly told. Recorded in .info/README. Installer bootstrap
430b7994516d88e82b71e910cbf9eb63be9bb9878fdbde991469fdcf188a4c17 and ZIP
a9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1 remain untouched
and intentionally do NOT yet include this Core fix. No Stable publication, commit/push
or installer build. Demo PHP isolation remains a separate outstanding operation.
Recovery archives0600: demo web8,705,097 bytes/DB117,806 bytes; official web18,351,348
bytes/DB421,702 bytes. Service journal priority0–3 empty since10:40:38UTC.
Temporary local SSH helper and source transfer tar removed; private recovery/QA retained.

## 2026-09-10 — Separate demo read-only account, existing Owner preserved

User explicitly separated demo from official-site operations and requested a second
demo@sensecms.com account with32-character password and read-only access. Verified
demo has one active non-demo Owner(id1). Used demo's own installed runtime/database;
no official-site accounts, source or configuration changed. Documented target separation
in .info. Original owner identity, password hash, active status and Owner access preserved.

Created Demo User(id2), separate demo-read-only role with eight explicit permissions:
console.access, content.pages.view, content.posts.view, content.workflow.view,
facilities.view, forms.view, surveys.view, chat.view; is_demo=1 and active=1. No owner,
system/user management, export or edit grants. Current facilities are empty; future
facility visibility follows explicit account assignments, not automatic global scope.
Password generated from24 cryptographic random bytes encoded as32 URL-safe characters;
Argon2id storage. Private credentials .cfg/Demo-user.txt, ignored by Git, Windows ACL
current user and SYSTEM only; server private recovery copy0600. No password in notes.

Acceptance exposed a Core defect: AccessControl::permissions returned ALL permissions
for is_demo accounts, overriding the assigned role. Temporarily disabled only the new
account, removed that escalation branch in source and deployed only to demo's
app/Core/AccessControl.php, preserving existing POST/PUT/etc read-only enforcement.
SHA-256af55ffb8184fc87fdb97cf294c5965798a597fb117549c2408807d838aa5e112.
Official site's older AccessControl hash remains unchanged; do not mirror this deployment
without an explicit official-site task. Local security regressions now check exact
demo role grants and no implicit owner/system access:84 SQLite and88 isolated MariaDB
checks pass. Demo opcode only invalidated via removed private FPM probe; no restart.

Real Auth::attempt accepted new credentials; an operator-created test session then
verified HTTPS read access to dashboard/pages/posts/facilities/settings;403 for privileged
access/email/licence views;403 read-only for settings/password/content/facilities/media/
access/update/notification mutation routes using a valid CSRF. Exact business/settings,
password and role snapshots unchanged during probes; session logged out. This was
an authenticated backend/HTTP acceptance, not a browser CAPTCHA login test.
An initial test session was root-owned and not usable by FPM; corrected ownership of
the explicitly generated test session to the runtime user before final passing probes.
No production authentication/CAPTCHA policy was weakened.

Recovery: /root/sensecms-backups/demo-user-KaZIQcpr/database-before.sql and original
AccessControl.php, private0600. Account/role mutations use existing AccessControl APIs
and audit events. If reverting access, disable only demo account first; never restore
the entire DB over newer content. Private credentials/backup retained, temporary local
and remote operator scripts removed. ZIP/bootstrap left untouched. Full demo system
user/FPM isolation remains separate; application installation/accounts are independent.

## 2026-09-11 — Full Demo preview and Owner-only account activation (both installations)

User superseded the earlier limited-role Demo requirement: all backend sections must
be visible, with no writes. Core now supplies Demo presentation permissions from the
catalogue except system.owner and unrestricted facility visibility. The existing
server-side non-read-method guard and browser warning toasts remain in force. Demo
does not gain Owner authority. AccessControl::saveUser additionally restricts activation,
deactivation and Demo-mode administration to Owner; non-Owners cannot create active
accounts or modify existing Demo accounts. Users form disables those controls for
non-Owners, preserves active status on permitted ordinary edits, and explains session
revocation. Existing session_version invalidation and final writable Owner protection
are preserved. No schema migration or permission-role mirroring was needed.

Both hosts have Owner(id1,active,writable), and independent demo@sensecms.com(id2):
demo active, official www disabled. Demo's existing password/role were not changed.
Official site's independent32-character cryptographic password is Argon2id-hashed;
private .cfg/Demo-user.www.txt and server recovery file0600, local ACL current user /
SYSTEM only, Git ignored. No credentials or encryption keys copied between installs.

Deployed app/Core/AccessControl.php SHA3101dd948e88b8a756675222a5832042864cb7ef2c387a2591c4365466a50760
and app/Views/console-access-control.php SHA12931c144b5b4119dd04376eaca566dd8e443ee43c71c91aab71d70e4ce4b45a
to both exact installed targets, atomically preserving ownership/modes. Targeted opcode
refresh only; no restart. Recovery /root/sensecms-backups/20260911T021805Z-demo-access
contains separate DB backups, original source/metadata and verified deployment receipt;
initial pre-account DB backup is /root/sensecms-backups/20260911T021408Z-demo-access.
If reverting, restore only recorded source and invalidate opcodes; disable the dedicated
account through Owner if needed. Never restore old databases over new production data.

Verification: local92 security checks, isolated MariaDB96 security +112 Workspace +29
maintenance checks; PHP lint and git diff --check; tests/demo-mode.cjs exercises welcome,
forms/fetch writes, repeated warning toasts, read/logout exceptions and writable Owner.
tests/demo-access-http.py uses actual HTTPS login and only its own CAPTCHA session:
official Owner accepted, official Demo rejected, active Demo accepted with welcome;
12 privileged/content screens200, account checkbox disabled for Demo, nine actual
mutation routes403 with read-only JSON/header and valid CSRF. Owner's official account
switch enabled in rendered HTML. All successful authenticated QA sessions logged out.
Session revocation and re-enable-not-reviving-session are verified in isolated Auth tests.
This is not a fresh interactive browser/visual acceptance of every backend option.

Three pre-acceptance attempts restored original source automatically: test assertions
initially expected a different welcome phrase, selected hidden status input instead
of checkbox, and treated legacy /account redirect as the current password-save route.
Corrected test uses actual text/control and /settings/password; final acceptance passes.
Owner credentials/status, settings/content snapshots and private runtime/themes/licences
preserved. Both homepages200, services nginx/php8.5-fpm/mariadb/cron active, fresh logs
and priority0-3 service journal clean. Receipt .local/demo-access-deployment.json.
Bootstrap/ZIP hashes remain430b7994... and a9700aef... unchanged; no installer build,
Stable release, commit or push. Dedicated demo PHP user/pool remains a separate task.
Final resumed verification confirmed both deployed hashes, account states and service
health. Temporary local SSH/provision/deploy scripts removed; remote operator scripts
archived with the verified recovery backup, reusable HTTP/JS tests retained under tests.

## 2026-09-11 — Demo PHP identity/pool isolation completed

User requested completing the remaining technical work before their own panel review.
Read-only inspection confirmed both independent installed runtimes/databases still
used sensecms/php8.5-sensecms.sock; demo Nginx even retained a commented dedicated
socket. No demo worker/cron dependencies, symlinks, mounts or hard links in its tree.

Created locked system identity/group demo-sensecms(uid994/gid984), nologin, no home
creation, no shared group membership. Dedicated PHP8.5 pool/socket now serves only
demo.sensecms.com. Existing limits and clear_env preserved; opcache permission checks
enabled. Session path, private temp/upload temp and PHP errors point to demo storage.
Transferred only demo files formerly owned by sensecms to demo-sensecms, preserving
modes/content. Existing root-owned private historical QA sessions left untouched.
Public media and custom audio roots use demo-sensecms:www-data2750: new directories
inherit the Nginx read group while Core0640 files remain private from other runtime
users. Nginx has no write access. Private storage never shares that group.

Deployment templates: deploy/php/demo-sensecms.conf and deploy/nginx/demo.sensecms.com.conf.
Pinned operator workflow scripts/deploy-demo-isolation.py; test/demo account workflow
tests/demo-access-http.py now derives each installation's Unix identity from storage
ownership rather than assuming sensecms. No Core source, accounts/roles, DB schema,
licensing, theme, official Nginx/pool, cron or DNS changes. PHP-FPM/Nginx gracefully
reloaded; demo-only maintenance503 gate lasted5.6s in final deployment. Official
homepage remained200 during that gate.

Verified actual FPM effective UID, own DB, denial of the other DB (SQL1044/1142), own
private temp files, session/error paths and opcode permission setting. Verified both
directions of filesystem read/write and database denial. Temporary uploaded test files
created as demo-sensecms returned200 with GET/HEAD and were removed. Real HTTPS login
suite passed: official Owner accepted, official Demo rejected, active Demo accepted
with notice,12 backend screens200, nine writes403, sessions logged out. Public/static
routes200, installer/private paths404 on both hosts. Source and private runtime hashes,
both databases' account/role/settings/content snapshots unchanged. No fresh application
errors during the accepted deployment; services healthy. Human visual review remains
with the user; no claim of exhaustive visual acceptance.

Recovery /root/sensecms-backups/20260911T054127Z-demo-isolation contains full demo web
archive, DB dump, original Nginx, per-path owner/group/modes, actual FPM probe result,
HTTPS acceptance and deployment receipt. Stage /root/sense-isolation-XhAkFkdc retained
privately. Nginx SHA b9ae053c55dec9bb40013074de541f9b758b939bd5322cc370b7bbe2b16ba30d;
pool SHA b2bed5642d9354a3bea5c2db366d0b1a92739a390906f9af6d8f5b1235709a6f.
Rollback: gate only demo, restore recorded ownership/modes and original vhost, point
back to original pool, test/reload Nginx, then remove dedicated pool/test/reload FPM.
Handle new files under demo explicitly; do not restore old DB/archive over newer data.

First attempt automatically rolled back after the CLI upload fixture inherited the
operator's0077 umask instead of FPM's verified0022, causing a test-directory403.
Corrected fixture sets0022; no application change was needed. Empty migration dirs
and unused locked identity were removed after verifying no processes or owned files;
second attempt above passed. Earlier backup20260911T054006Z-demo-isolation retained.
No installer/ZIP build, Stable publication, commit or push.
Final check: both dedicated/shared-original pool processes present as their distinct
Unix users, both homepages200, exact configuration hashes, no remaining public QA
files, service journal priority0-3 clean. Local receipt .local/demo-isolation-deployment.json;
temporary local SSH helper removed. Installer/bootstrap hashes unchanged.

## 2026-09-13 — Product-theme email PNG supplied and deployed

User supplied C:/Users/MC/OneDrive/Documents/Sense CMS/sensecms-logo-email.png and
required that it belong to our theme, locally and on the server. Copied the original
unchanged to .themes/sensecms/assets/sensecms/images/sensecms-logo-email.png (38,381
bytes, SHA25674f2d7cd4832bf1316390078df0d369afd5c34d1a5baf13b1063ddf78f55b32a).
Confirmed official /theme-assets/sensecms/images/sensecms-logo-email.png was404.
Signed theme lifecycle requires a new version, not editing a retained release payload:
updated only theme.json/sense-package.json0.3.10->0.3.11 plus the new image. Verified
all other local candidate files match the active production payload byte-for-byte.

Local tests/themes.php now covers exact PNG GET, valid image type and HEAD length/
empty body:468 checks passed, PHP lint and diff check pass. Built a private signed
theme deployment archive, verified the new asset in its signed checksum inventory,
installed/activated as sensecms through ThemeManager. No root-owned runtime window,
no source override or old release modification. Active sensecms-0.3.11-e5bba71253c7fa36;
archive SHAe5bba71253c7fa36cab453667e4a5d27239cb4827551aa8497e55f57a92634cd.
Live GET200 image/png with exact original hash; HEAD200 correct length/bodyless;
homepage/update/contact/login and existing theme CSS/logo200. Account/settings/page
snapshot unchanged, private identities and demo config preserved, fresh logs clean,
nginx/php8.5-fpm/mariadb/cron active. No downtime/reload needed.

Recovery /root/sensecms-backups/20260913T013848Z-email-logo contains prior theme state,
unchanged signed0.3.10 archive, new signed0.3.11 archive and verified receipt. Stage
/root/sense-email-logo-xkupCN6E retained privately. Rollback via ThemeManager activate
previous directory sensecms-0.3.10-522fe650512043b6 using existing publisher trust; no DB
restore. A preflight initially looked for the source manifest in extracted payload;
corrected it to read signed archive metadata before any production mutation.

Outstanding identified during this task: EmailSystem defaults and restore-logo still
reference that theme URL. Theme-less demo returns404 for this URL (and currently lacks
an equivalent Core PNG). Portable email branding needs a theme-independent default/
fallback before final installer acceptance. User was informed; no implicit installation
of the official product theme on demo. Other release gates remain per README: explicit
installer build, exact-artifact licensed DB completion and target server acceptance;
external integrations/module roadmap are separate, not declared complete.
No Core installer rebuild, Stable catalogue publication, commit or push. Temporary local
transfer archive/SSH/deploy helpers removed; theme source asset/tests and server recovery
artifacts retained.

## 2026-09-13 — Work toward1.0 requested; portable mail logo fixed, theme removal needs identification

User requested finishing remaining work, version1.0 for working components and removing
an old "Senso CMS" theme visible in Marketplace. No blanket version/release promotion
performed: Core/workspace currently0.1.0, all six independent extension signed manifests
and the product theme require Core<1.0.0, runtime manifests use^0.1, calendar integrations
depend on Calendar^0.1. A coordinated compatibility/install/rollback acceptance is
required before deploying Core1.0.0. Existing built-in addons already report1.0.0;
external calendar delivery remains unverified. Installer and Stable publication pending.

Completed/deployed portable email branding to both independent installations:
EmailSystem DEFAULT_LOGO=/sensecms/images/sensecms-logo-email.png; default/reset and
exact legacy theme PNG/SVG defaults resolve to Core. Custom local/HTTPS logos and an
intentionally empty logo remain unchanged; no settings migration or outbound email.
Copied original approved PNG to .cms/source/public/sensecms/images; product-theme copy
retained. Eight new isolated regression checks,120 Workspace checks total passed.
Production GET/HEAD image/png returns exact original bytes on www and demo; separate
pool opcodes refreshed, no restart, saved settings unchanged. Owner/Demo HTTPS suite
passed, including all earlier privileged views and write403 guards; fresh logs clean.
EmailSystem SHAc9bbe43c73a27a22c423e4295459d7c82577f9e025bfbc05e96296498459461a;
PNG SHA74f2d7cd4832bf1316390078df0d369afd5c34d1a5baf13b1063ddf78f55b32a.
Backup /root/sensecms-backups/20260913T024339Z-core-email-logo contains originals,
ownership metadata, HTTP acceptance and verified receipt. Rollback only class and
the new Core asset, then invalidate each installation's pool; no DB restore.

Removal investigation: no Senso-named source, legacy installed theme rows, governed
Marketplace entries or saved official catalogue on either inspected installation.
All16 retained official-site theme archives have slug sensecms/name Sense CMS product
website; active0.3.11. Actual Owner-authenticated Marketplace bootstrap filtered to
themes contains precisely theme:sensecms / Sense CMS product website /0.3.11, installed
and active. Demo has no theme release installed. Shoudu Custom Theme appears only as
an unadapted website catalogue source entry, not this installed Marketplace result.
Asked user to identify the exact obsolete tile/domain or confirm hiding the active
product theme from Marketplace without uninstalling it. No theme/source/archive/offer
removed. Await that business choice; never uninstall the active official-site theme
as a guess. Temporary local helpers removed; private operational evidence retained.

## 2026-09-13 — Retain only the latest Sense CMS theme on the official installation

User clarified that obsolete releases of the same Sense CMS theme, not an unrelated
theme or the active theme, must disappear from the backend and official website.
Verified all16 installed archives share slug sensecms; latest/active0.3.11. Under the
existing deployment and theme lifecycle locks, retained the exact active release
sensecms-0.3.11-e5bba71253c7fa36 and moved15 inactive release directories to private
recovery storage outside the installation. Reduced theme.json releases to that one
entry and cleared previous; active signed payload and application content unchanged.
Demo remains independent and theme-less; no demo filesystem/database changes.

Distribution previously offered0.3.8. Copied the existing verified signed0.3.11 archive
byte-for-byte (no rebuild) into private releases, atomically changed only its offer's
version/file/hash/size, and moved the superseded0.3.8 download to recovery storage.
Preserved publisher trust, development channel, pricing, licence gates and all other
package offers. Local .src/package-catalog.php now also lists0.3.11. Official website
is CMS-managed: an optimistic update changed only the version phrase in
content_block_translations id89/block87/en, shown at /extensions/catalog/theme/sensecms.
The original row bytes are backed up; no schema, account, settings or other content
changes. No source Core deployment or service reload was required.

Recovery: /root/sensecms-backups/20260913T043245Z-theme-retention contains original
theme.json/distribution.json, row before/after, retired15 directories, old download
and ownership metadata/receipt. Restore retired directories to storage/themes first,
restore the old download to storage/distribution/releases, then restore original
configs with recorded sensecms ownership/mode0600 under the same locks. Restore row89
only if it still matches release-notice-after.bin; never overwrite later editor work.
The private operator script includes immediate rollback on an operational failure.
Stage/evidence: /root/sense-theme-prune-VtE19nhT; local ignored deployment receipt:
.local/theme-retention-deployment.json. Retired archives are recoverable but no longer
available through the CMS release list or public distribution.

Verified signed manifest, Core/PHP compatibility and every active payload hash before
and after cleanup. SHA256 remains
e5bba71253c7fa36cab453667e4a5d27239cb4827551aa8497e55f57a92634cd.
25 HTTPS/runtime acceptance checks passed, including actual Owner login, precisely
one release on /appearance/themes and Marketplace, current public page/offer, valid
Core-licensed ZIP download with exact hash/size/headers, CSRF/cross-origin/invalid-key
rejection and private archive404s. Homepage/extensions/update/login/logo200, demo200;
nginx/php8.5-fpm/mariadb/cron active, existing nginx/PHP error logs did not grow, no new
service errors. Configs and download remain sensecms:sensecms0600. Local catalog PHP
lint, project-boundary checks,468 theme/website checks and git diff --check passed.
Local temporary SSH/operation/test helpers removed.

This resolves the obsolete-theme identification/removal item. Coordinated1.0.0
compatibility/release acceptance remains separate and incomplete; no claim of Stable
promotion. Installer artifacts unchanged, no new installation ZIP, commit or push.

## 2026-09-13 — Core1.0.0 transition completed on both independent installations

User authorised the proposed version/compatibility, isolated QA, production deployment
and review plan; installation ZIP remains deferred until explicit build instruction.
Core product.php now1.0.0; Workspace engine version, initial console footer, installer
installed-version metadata and future build filenames/WEB label derive from canonical
product version. Licence identity Sense CMS / Sense CMS System / protocol1.0 unchanged.
No encryption identity, account, schema or environment variables were replaced.

New independently signed release set (same established publisher) and official offers:
theme:sensecms1.0.0; addon:calendar1.0.0; plugin:telegram-notifications1.0.0;
plugin:google-analytics0.1.2; Google/Microsoft365/Apple Calendar plugins0.1.1.
Signed Core bounds and runtime constraints now >=0.1.0 <2.0.0; Calendar dependant
bounds likewise span0.1.0 through1.x. Upgrade providers before Calendar, then Core.
Preserved existing migration IDs/SQL and immutable old signed archives. External
analytics/calendar live-account acceptance remains unverified, so those integrations
retain development versioning. All distribution channels remain development pending
release approval; numbering1.0.0 is not a fabricated Stable installer certification.
The signed Stable Core catalogue remains empty; no automatic installation was enabled.

Private stage /root/sense-release-1.0-eDoxsJCH contains source, exact old/new signed
archives, deployment script and QA/HTTPS logs. New immutable packages directory includes
release-set.json/signature/checksums; no private signing key was copied into artifacts.
tests/package-release.php now checks all7 packages rather than its obsolete2-package
inventory, including uninstall/reinstall with dependent plugins and retained categories.
New tests/release-transition.php validates exact old/new signatures, installation on
Core0.1, dependent-first upgrade, Core1.0 compatibility, old-package rejection on Core1.0,
coordinated Core/package/theme rollback and re-upgrade while preserving data.
Initial new-test fixture mistakes (column names and package projection) were corrected
against the real schema/API before acceptance; no production changes during that phase.
Workspace's historical migration fixture versions and update-check test now stay
independent of current product numbering. tests/release-versions.php checks paired
source versions, bounds, website inventory and canonical installer/runtime metadata.

Verification: 44 exact signed release installation checks;39 signed transition checks;
120 Workspace migration/function/package checks;96 security and29 maintenance checks
on guarded random disposable MariaDB databases (including concurrency). Local468
theme/website,55 package,73 licensing,27 Core feed,13 distribution,46 release-version
checks passed, plus builder175/popup27/media18, analytics14, Google27/Microsoft32/Apple64,
Telegram client28/broker58, SQLite security92/maintenance16 and7 JS suites. PHP lint153
application/config/package files plus modified tooling/tests; project boundaries11 and
git diff --check passed. External protocol tests are not live-account delivery proof.
No production fixture DB was used; disposable schema/files cleaned by guarded tests.

Preflight compared candidate runtime source against both installations: demo matched
apart from4 intended Core files; official differed additionally only in2 legacy comments
(Auth.php and workspace.css), deliberately left unchanged. Live official optional-package
inventory had six packages; demo had no optional packages. Both preserve built-in addons.
scripts/deploy-release-1.0.py is pinned one-shot operator tooling, not a general updater.
It used existing deployment/package/theme locks, backed up each database, source and
extension state, upgraded via the established signed PackageManager as sensecms, then
atomically installed4 Core files separately on each target and refreshed each FPM cache.
No Nginx configuration, service restart, new framework, dependency or schema change.
installed.json core_version retains installation provenance; current runtime version
comes from product.php. Demo did not inherit official packages, theme, data or secrets.

Production recovery /root/sensecms-backups/20260913T052855Z-release-1.0: per-installation
database.sql, original4 Core files and extension tar; original theme/distribution state;
public notice row bytes; checksums/receipt and acceptance logs. Recovery must first
restore Core0.1.0 on the affected installation, then old Calendar before old dependent
plugins using their retained signed source archives; restore old theme directory from
private backup before theme state, then restore offer metadata. Refresh that FPM pool.
Do not blindly restore DB dumps or overwrite later business edits. Existing migration
identities are unchanged; coordinated code rollback was exercised on QA. Public notice
rollback must be conditional on the current row still matching the deployed version.

Official distribution now offers exact accepted new bytes for the same7 identities,
preserving pricing, trust and licence checks. Updated local catalogue and precisely6
public release-notice rows89/101/105/109/113/125. Telegram detail has no standard version
notice and was not rewritten. Kept only sensecms-1.0.0-e53db69ae59f6db4 in installed
theme state/directory; previous0.3.11 and its former download moved outside runtime into
the backup. New theme SHAe53db69ae59f6db4fa1cebd159892851ef514c6355cb8d60bbf71c23bfd8c4c5.

Final production64 HTTPS checks passed: both backends show Core1.0.0, correct isolated
theme inventories, all7 official Marketplace entries unique/current/compatible,
Calendar/Analytics/notification views, exact current catalogue/public details, real
licensed theme/Analytics downloads with matching signature-accepted hashes and sizes,
CSRF/origin/invalid-key and cross-product entitlement refusals, private archive404s.
Actual Owner/Demo login/activation/read-only suite and full update-page/feed/manual-check/
notifications regression passed. Account/role/settings/page/navigation snapshots and
private installation/encryption identities were unchanged at deployment verification.
Both services healthy; nginx/php8.5-fpm/mariadb/cron active. Nginx/PHP error logs did not
grow (1556/0/2374 bytes); no fresh service errors. Receipt status verified, local copy
.local/release-1.0-deployment.json. Local temporary transfer/SSH/probe helpers removed.

Ready for user's production review. Remaining: actual external-provider acceptance,
explicit installer build followed by exact-artifact licensed empty-database install and
target server acceptance, then reviewed Stable catalogue promotion. Future WEB installer
template/build version fixed in source only; no installer artifacts regenerated.
Existing index.php SHA430b7994516d88e82b71e910cbf9eb63be9bb9878fdbde991469fdcf188a4c17;
install.zip SHAa9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1.
No Git commit/push or GitHub Release performed; unrelated existing dirty work preserved.

### 2026-09-13 — public human live chat restored and production verified

User reported enabled backend configuration without a frontend widget. The product
theme did not render the supplied live-chat settings. Added a Core-owned partial,
namespaced CSS/JS and shared PublicChat renderer; no signed theme payload changes.
Managed page rendering isolates template scope (theme blocks reuse `$data`); the
public entry point also covers static theme pages with the same private session and
no-store response. Assets, discovery, errors and theme previews do not inject chat.

POST /api/chat/message queues the existing human-support workflow without requiring
an AI provider/licence. Existing operator claim/reply/close routes are reused. Both
public chat endpoints enforce extension activation; writes enforce session CSRF and
existing message/rate limits. Unexpected send errors return generic messages rather
than database details. UI escapes messages via textContent, polls only while open,
supports close/Escape and new conversation after closure, uses configured localized
copy/availability, and accepts offline messages. No provider configuration changed.

Acceptance:21 disposable MariaDB HTTP checks (real controllers/repositories, managed
theme with populated blocks and static theme response, CSRF, session isolation,
validation, human queue, operator reply, disabled extension, closed/new conversation).
468 existing theme/website checks;11 project-boundary checks;6 changed runtime PHP
files linted, JS syntax and git diff --check passed. No production fixture database.

Two initial deployment acceptance failures automatically restored the exact original
files; template variable scope was corrected and covered by populated-block QA before
successful activation. The homepage is a managed CMS page, despite resembling the
static theme layout; both rendering paths now have coverage.

Official installation only: /home/sensecms.com/web,8 Core files. Private original
backup /root/sensecms-backups/20260913T070824Z-public-chat; final narrow refinement
backup /root/sensecms-backups/20260913T071152Z-public-chat. Each contains original
bytes, modes and receipt with final SHA256 inventory. Local receipt copy:
.local/public-chat-deployment.json. scripts/deploy-public-chat.py is pinned one-shot
operator tooling with deployment lock, atomic replacement, FPM invalidation, health
checks and automatic rollback. No service restart, schema migration or dependency.
Account/settings snapshots unchanged; demo installation was not modified.

Live Chrome visitor sent one uniquely labelled QA message; real Owner authenticated,
claimed it and replied through the backend HTTPS controller. Reply appeared through
normal frontend polling. An independent visitor could not access the conversation.
Only this QA conversation's contents were removed using the normal backend delete
action (empty closed marker retained by existing lifecycle). Browser confirmed closed
state, new-conversation controls remaining enabled, Escape close, desktop and390x844/
320x568 responsive layouts. No browser JS warnings/errors. Temporary viewport reset.
9 final production smoke checks passed across homepage/platform/extensions/contact/
update, exact CSS/JS GET plus HEAD, CSRF rejection and no conversation side effect.
Nginx/PHP-FPM/MariaDB active; PHP log unchanged2374 bytes. Nginx log3505 bytes, no
new errors; earlier warnings only describe large workspace.png FastCGI buffering.

Rollback to pre-chat: restore4 existing Core files from070824 backup with receipt
modes/owner sensecms, remove only4 recorded new widget files, invalidate affected FPM
scripts. Do not restore unrelated databases or configuration. Final refinement can
instead be reversed from071152 backup. Private QA stage:
/root/sense-live-chat-i1jwYjZ7. Local transfer archive and SSH helper removed.
No installer regeneration: index.php SHA430b7994516d88e82b71e910cbf9eb63be9bb9878fdbde991469fdcf188a4c17;
install.zip SHAa9700aef1a922a591d0d51b0843f6c1551019903eed12b6828173e990ee682e1.
No commit/push or Stable publication. Human live chat ready for user review;
external AI-provider acceptance is separate and was not claimed.

### 2026-09-13 — operator-only visitor IP and country deployed

User requested the visitor IP and country in the Sense CMS support panel. New live
chats on the official installation now record the canonical server-observed
`REMOTE_ADDR`; client-supplied forwarding and country headers are never trusted.
Operators see the IP and approximate English country name/code in the selected
conversation and incoming-chat modal. Older conversations without stored metadata
honestly show no data. Public visitor state explicitly removes both fields.

Country resolution is local: `IpCountry` performs a bounded binary search over a
private fixed-width index compiled from the September 2026 DB-IP Country Lite CSV.
No visitor address is sent to a third-party service. The operator views retain the
required DB-IP attribution link and mark the result approximate. Invalid, private,
reserved, uncovered, missing or corrupt data fails closed to unknown. The source
archive checksum and CC BY 4.0 provenance are pinned in the private deployment receipt.
The private index has 717,170 sorted ranges and SHA-256
`d2c597a62427764982ed6ccc751d6de11b53d2c44c36d34fd789c098b42eb530`;
its public URL returns 404. Refreshing the monthly data remains an explicit operator
operation through `scripts/build-ip-country.py`, not an automatic network dependency.

Additive migration `030_chat_visitor_location.sql` adds nullable `visitor_ip` and
`visitor_country`. Conversation deletion now erases both fields in the same transaction
as messages, visitor identity, reads and transfers. No historical IP backfill was
attempted. The first production attempt safely restored code after a test assumed an
incorrect country for a real IPv6 range; the already-applied additive migration was
retained. The exact source range was inspected, the assertion corrected to its actual
country, and the final deployment then completed. No existing business data was lost.

Verification: 23 offline IP/range/format checks and 24 isolated MariaDB public-chat
HTTP checks passed. Existing security (92), theme/website (468), project-boundary (11)
and relevant PHP/JS/Python syntax and diff checks passed. A real production visitor
conversation proved the captured IP was not a spoofed forwarded value, its saved
country matched the private database, operator API/HTML/popup received both values,
normal claim/reply still worked, and public state exposed neither. The marked QA
messages and location were then removed through the normal operator delete action;
only the anonymised closed marker remains. A loopback visual fixture verified the
conversation row and incoming modal at desktop and 390x844, including a long IPv6
address, source link, working Cancel control and no horizontal overflow.

Official installation only: ten Core/runtime paths, one additive migration and private
data; demo was not modified. Backup and rollback evidence:
`/root/sensecms-backups/20260913T081530Z-chat-location`; local ignored receipt
`.local/chat-location-deployment.json`. Final deployed hashes match the receipt,
the migration checksum is registered, homepage/live chat remain available, and
nginx/php8.5-fpm/MariaDB/cron are active. PHP error log stayed unchanged; no new
critical Nginx/PHP signature appeared. Rollback restores only recorded source/private
files with their modes and invalidates the official FPM pool. The additive columns
and migration journal should remain for forward compatibility; never run destructive
down SQL or restore the database over newer conversations.

No service restart, external API, demo deployment, installer regeneration, Stable
promotion, commit or push. The existing DEVELOPMENT bootstrap/ZIP remain unchanged.

## 2026-09-16 — Meta application and modular Social Publishing foundation

Created the unpublished Meta developer application `Sense CMS Social` and a Facebook
Login for Business configuration using a system-user access token and required Pages
asset selection. Strict HTTPS OAuth redirect is
`https://www.sensecms.com/api/social/meta/v1/callback`. The Pages use case now has
`pages_show_list`, `pages_manage_posts` and `pages_read_engagement` in Ready for
testing state. Meta currently reports no Required actions. App Review, Tech Provider
access verification, public legal/data-deletion URLs and publication remain pending;
the application is not available to customer businesses yet.

Added development source packages `addon:social-publishing` and
`plugin:facebook-publisher`. The add-on provides explicit per-post destinations,
encrypted installation-local connections, idempotent revisions, a delivery queue,
bounded retries, history and an editor sidebar. The Facebook provider uses fixed
Graph HTTPS endpoints, system-user/Page identity verification and separate feed/photo
publishing. Meta App Secret is never bundled; central OAuth onboarding is fixed to the
official Sense CMS broker URL. Non-secret Meta identifiers and the empty secret slot
are stored only in ignored `.cfg/Meta.txt`.

Validation: 17 isolated Facebook protocol checks, 75 licensing checks, PHP lint,
package-manifest validation, project boundaries and `git diff --check` passed. No
live Graph publication, broker deployment, package build, production deployment,
commit, push or Stable promotion was performed.

### 2026-09-16 — Social Publishing and Meta broker deployed to production

Deployed the provider-neutral Social Publishing foundation to the official
`www.sensecms.com` installation only. The signed production packages are active:
`addon:social-publishing` 0.1.1 and `plugin:facebook-publisher` 0.1.0. The addon owns
the post-target schema, encrypted connection records, idempotent queue, review-first
editor controls, delivery history and one-minute locked worker. Facebook remains an
independent plugin and uses fixed Graph API v26.0 Page endpoints. No demo installation,
public Stable catalogue, Git remote or installer artifact was changed.

The official-site-only broker is deployed under `/api/social/meta/v1/`, outside Core
packages and themes. It validates the fixed Sense CMS licence identity, binds return
URLs to the licensed installation, uses ten-minute single-use authorization/selection/
claim records, encrypts transient state, never places access tokens in URLs, supports
multi-Page selection and verifies Meta signed data-deletion requests. After a customer
installation claims the selected Page token, the broker removes its reusable copy.
Nginx has a dedicated 64 KiB/rate-limited route. The broker currently fails closed
with HTTP 503 because the Meta App Secret has not yet been manually placed in ignored
`.cfg/Meta.txt` and provisioned into private production storage. No live OAuth or Graph
publication is claimed.

Published three CMS-managed English pages required by Meta: `/privacy-policy`,
`/terms-of-service` and `/data-deletion`. GET and HEAD return 200. The app callback is
`https://www.sensecms.com/api/social/meta/v1/callback`; the data-deletion callback is
`https://www.sensecms.com/api/social/meta/v1/data-deletion`. Meta app basic fields and
publication remain pending final browser save/confirmation and App Secret provisioning.
The app remains unpublished and customer-business access still requires Tech Provider
access verification/App Review.

Production acceptance built exact packages with the protected server publisher key
and exercised them first on an isolated MariaDB database. Local results: 31 Meta broker,
17 Facebook protocol, 75 licensing, 11 project-boundary and 46 version checks. Exact
signed lifecycle, dependency refusal, uninstall/reinstall and data-table preservation
also passed on the server. The first deployment gate stopped before mutation because
the QA assertion inspected the stored package manifest instead of the installed runtime
manifest. The second deployed then correctly rolled back when `/social-publishing` was
not yet classified as an administration route. One scoped Core route entry fixed the
root cause. The final deployment passed all 18 gates.

The first live worker check exposed a historical `config/app.php` assumption. It was
not patched in place: addon 0.1.1 was signed, tested and installed through PackageManager,
preserving the immutable 0.1.0 recovery archive. Its separate update passed 13 gates.
The production worker now returns exactly `ok=true, queued=0, published=0, failed=0`;
cron is root-owned mode 0644. Nginx, PHP-FPM, MariaDB and cron are active; Nginx syntax
passes; no fresh critical Nginx/PHP error signature was found. `/` returns 200,
`/dashboard` 303 and unauthenticated `/social-publishing` 302 to login. Both packages
are `signature_status=verified`; all three social tables are empty before first use.

Recovery: `/root/sensecms-backups/20260916T055338Z-meta-social` contains the private
database dump, exact original Core/Nginx/website state, legal-page journal and receipt.
The worker update recovery is `/root/sensecms-backups/20260916T060558Z-social-worker-011`.
Production package SHA-256: addon 0.1.1
`7c8ee4ee5bd396e57c8f256c244451c75129e47336c784ade9d58f1aa22ea358`;
Facebook plugin 0.1.0
`e0af1be20ba32f79edb57fef7a0da312797acb039beaf6aefc9601ca453780ff`.
Do not restore the database dump over later business data. Roll back packages through
PackageManager and restore only the receipt-recorded source/Nginx files and created legal
pages if still unchanged. Existing unrelated dirty work was preserved. No commit/push.

### 2026-09-16 — Meta credentials provisioned and live broker enabled

Completed the Meta developer app basic configuration and verified the persisted state
after a full page reload. `sensecms.com`, the production privacy/terms/data-deletion
URLs, the official Sense CMS icon, Tools and Productivity category and the approved
DPO contact/address are saved. The App Secret was retrieved only after interactive
owner re-authentication, never printed or logged, stored in ignored `.cfg/Meta.txt`,
and provisioned to the official-site installation through the reviewed CLI validator.
The browser-side secret buffer and one-time loopback bridge were removed immediately;
the Meta page was reloaded and the secret is masked again.

Production private Meta storage is owned by `sensecms:sensecms`; its directory is mode
0700 and `config.json`/`key.bin` are mode 0600. Recovery is
`/root/sensecms-backups/20260916T082832Z-meta-secret`. A direct Graph API v26.0
credential check resolved the expected app ID and name `Sense CMS Social`. The broker
`POST /api/social/meta/v1/start` now returns the expected HTTP 401 without a licence
payload instead of the prior fail-closed 503, proving that private configuration loads.
Nginx, PHP-FPM, MariaDB and cron remain active; Nginx syntax passes, no temporary
provisioner remains and the fresh PHP critical-error count is zero. All three public
legal-page HEAD checks remain 200 and unauthenticated `/social-publishing` remains 302.

The Meta app intentionally remains unpublished. Connecting customer businesses and
publishing to their Pages still requires Meta Tech Provider access verification/App
Review (or continued testing with authorised app roles/assets); no live social post was
created during this credential/provisioning step.

### 2026-09-16 — Meta app published

Published the Meta developer application `Sense CMS Social` (app ID
`1373530514896687`). Meta displayed the authoritative confirmation that the app was
successfully published and is available for public use; the dashboard now reports
`Published` and no required action items. The saved Facebook Pages permissions remain
`Ready for testing`: `pages_show_list`, `pages_manage_posts` and
`pages_read_engagement`. Publication alone does not grant production access to data
owned by customer business portfolios.

The next Meta gate is the irreversible Tech Provider designation. Meta requires
Business Verification, Access Verification and App Review, including data-use,
handling and protection questions, before requesting customer-business access. The
Tech Provider confirmation dialog was intentionally left open without accepting the
irreversible designation pending explicit owner confirmation. No live Page connection
or social post was created during publication.

### 2026-09-16 — Meta Tech Provider and App Review preparation

The owner accepted Meta's irreversible `Yes, I'm a Tech Provider` designation.
Meta now shows Business Verification for business `Chivale` as `In review`. Access
Verification remains disabled until Business Verification completes. The App Review
submission (`1373810831535322`) is still `Not submitted`: App settings is complete,
while Verification, Allowed usage, Data handling and Reviewer instructions are not.

A least-privilege audit found a mismatch that must be corrected before recording the
review screencast. The active Facebook Login for Business configuration currently
requires only `pages_show_list`; production code also needs `pages_manage_posts` and
`pages_read_engagement`. The broker lists the administrator's Pages through
`/me/accounts`, verifies the selected Page, and the independent provider publishes to
`/{page-id}/feed` or `/{page-id}/photos`. No Business Manager API endpoint or asset
claiming operation is used, so `business_management` should be removed from the review
request rather than justified artificially.

Meta currently reports required test calls at `0/1` for `pages_manage_posts`,
`pages_read_engagement` and the unused `business_management`; `pages_show_list` and
`public_profile` require no counted API call. A compliant end-to-end review recording
must show an authorised administrator entering Social Publishing, connecting through
Meta, selecting a Page, returning to Sense CMS, preparing/reviewing a post, explicitly
selecting Facebook, publishing it, and viewing the delivery result. The live Page
connection, test post, recording upload, data-handling attestations and final App Review
submission remain pending explicit owner confirmation and completion of Meta's
verification gate.

### 2026-09-16 — Meta least-privilege configuration and credential rotation

Updated Facebook Login for Business configuration `28576295405370380` to require the
exact three permissions used by the production implementation: `pages_show_list`,
`pages_manage_posts` and `pages_read_engagement`. Verified the persisted configuration
after saving. Removed unused `business_management` from App Review submission
`1373810831535322`; the remaining request contains the three required Page permissions
and automatically granted `public_profile`.

Rotated the Meta App Secret. An intermediate post-reset value appeared in diagnostic
browser output and was treated as compromised: it was never deployed and was
immediately invalidated by a second rotation. The final secret was transferred directly
from the authenticated Meta field to ignored `.cfg/Meta.txt` through a loopback-only,
one-use helper without entering model-visible output. The browser buffer, helper,
marker and remote verifier were removed, and the Meta page was reloaded with the secret
masked.

Before production provisioning, the existing private Meta configuration and encryption
key were copied to `/root/sensecms-backups/20260916T134734Z-meta-secret-rotation` with
mode 0600. Because that backup contains the now-invalid pre-rotation Meta secret, it is
an audit snapshot, not a usable credential rollback. The reviewed provisioner hash
matched local source and ran as `sensecms`, preserving `sensecms:sensecms` ownership and
0600 modes for `config.json` and `key.bin`.

Production verification resolved Graph API v26.0 app ID `1373530514896687` and name
`Sense CMS Social` with the final credential. The unauthenticated broker start endpoint
returns the expected HTTP 401 rather than 503. Nginx, PHP 8.5 FPM, MariaDB and cron are
active; Nginx configuration passes; the verification created zero new bytes in both the
application PHP error log and the Sense CMS Nginx error log. No live Page was connected
and no social post was published during this rotation.

### 2026-09-17 — Facebook OAuth navigation hotfix deployed

The production `Connect with Facebook` button showed the generic panel error before
leaving Sense CMS. Root cause was the global administration form handler: it intercepted
the OAuth form with `fetch()`, followed the intentional HTTP redirect and then attempted
to parse the resulting navigation as JSON. The provider endpoints correctly use native
redirects, so both connect and disconnect submitters now opt into the existing
`data-sensecms-native` contract. No global administration JavaScript was changed.

Released and installed signed `plugin:facebook-publisher` 0.1.1 through PackageManager.
The isolated MariaDB signed-package lifecycle passed before production mutation. The
upgrade preserved exact counts in `social_connections`, `social_post_targets` and
`social_deliveries`; the active package remains signature-verified. Recovery is
`/root/sensecms-backups/20260916T221707Z-facebook-publisher-011`; plugin archive SHA-256
is `a1dc53fc6e5eabdfd64b87694e2b03e58c7e39d285517617d03ead17cac0fcda`.

Validation: 19 isolated Facebook provider/UI checks, 31 broker checks, 55 package checks,
46 release-version checks, PHP lint, Python syntax and `git diff --check` passed. A real
production owner-session acceptance then verified the installed HTML, CSRF-protected
native POST, the official broker redirect and the exact Facebook v26.0 OAuth dialog with
app/config IDs `1373530514896687` / `28576295405370380`. The test deliberately stopped
before Meta consent or Page selection: no Page was connected, no permission was granted
and no post was published. Homepage returned 200; Nginx, PHP-FPM, MariaDB and cron were
active and Nginx configuration passed. No demo, installer, Stable catalogue or Git remote
was changed.

### 2026-09-17 — focused Facebook OAuth popup deployed

After the native-navigation hotfix, the owner reported that Connect appeared only to
reload the provider page. Private broker diagnostics showed seven request records, no
selection or claim records, and the newest user-triggered request remained unused with
about eight minutes of its ten-minute lifetime left. Production still had zero social
connections and deliveries. This proves the licensed CMS reached the broker start
endpoint but the browser never completed the Meta callback; no Page credential entered
the installation.

Released signed `plugin:facebook-publisher` 0.1.2. Connect now obtains the same-origin,
single-use broker URL as no-store JSON, validates its exact origin/path, and opens it in
a centred named browser popup. The administration page displays an accessible modal
progress overlay, validates same-origin completion messages and independently polls a
read-only connection-status endpoint for up to ten minutes. This preserves completion
when a provider severs `window.opener`. Success closes the popup, reloads the provider
state and presents confirmation; cancellation, blocked popups and timeout remain honest
failures. Meta is not embedded in an iframe because its security headers disallow it.

The first deployment attempt stopped during preflight without creating a backup or
mutating production because the server does not provide Node.js; local `node --check`
had already passed, so only that redundant server-side check was removed. The complete
second run passed the exact signed-package lifecycle on isolated MariaDB, backed up the
database and existing plugin, upgraded through PackageManager, retained signature
verification and preserved exact row counts. Recovery is
`/root/sensecms-backups/20260916T223443Z-facebook-publisher-012`; plugin archive SHA-256
is `776a06f7db340ea230d1585544349ea84f4b9943229a811c620bf5e2d84632aa`.

Validation: 22 provider/UI checks, 31 broker checks, 55 package checks, 46 release-version
checks, 11 project-boundary checks, PHP lint, JavaScript/Python syntax and diff checks
passed. Production owner-session acceptance verified disconnected status, CSRF-protected
JSON start, the exact official broker and Facebook v26.0 dialog addresses without
following into consent. JavaScript/CSS assets return 200 with correct media types,
homepage returns 200, Nginx/PHP-FPM/MariaDB/cron are active and Nginx configuration
passes. An isolated visual fixture verified the centred dimmed modal, readable copy,
dialog semantics, live status and Cancel control. No Page was connected, no permission
was granted and no post was published. Demo, installer, Stable catalogue and Git remote
were not changed.

### 2026-09-17 — Social Publishing workspace visual refresh deployed

Released `addon:social-publishing` 0.1.2 and `plugin:facebook-publisher` 0.1.3 as a
focused administration UI release. Both pages now use the shared Sense CMS workspace
hero, card hierarchy, icon treatment and compact typography. The overview adds
accessible delivery metrics, provider/security status and a designed delivery-history
empty state. Facebook settings present the authorised Page as structured connection
metadata and explain the review-first workflow as responsive step cards. OAuth,
connection storage, publishing and disconnect endpoint behaviour are unchanged.

Validation passed PHP lint, JSON parsing, `git diff --check`, 24 Facebook provider/UI
checks, 13 distribution checks, 46 release-version checks and 11 project-boundary
checks. Browser fixtures were visually inspected at desktop width and a 390 px mobile
viewport; controls, account metadata and provider cards reflow without clipping or
overlap. The temporary fixture and archive were removed.

The earlier SSH timeouts were local orchestration error, not a host outage: `.cfg/SSH.txt`
contains Production Server first and Local Server second, and a naive map parser retained
the second duplicate key set. The corrected deployment reads only the Production Server
block. The exact signed-package lifecycle passed on isolated MariaDB before mutation;
PackageManager then upgraded dependency-first, retained active verified signatures and
preserved the exact connection, post-target and delivery row counts. Deployed checksums
match the signed candidate. Archive SHA-256 values are
`2ca76e553f6a119e31900655d3f914aa2678fc34fedc8b1f8d83c9bf6df83cdc` for the add-on
and `dd76224506b00baab9cdcfc4e7a6908f4a1699b465e6e0e5e5bddb7e1cd4e76f` for the plugin.
Recovery is `/root/sensecms-backups/20260916T235904Z-social-ui-012`.

Authenticated production acceptance verified both redesigned pages, connected Facebook
status, the disconnect form and versioned CSS/JavaScript assets without changing the
connection or publishing a post. Nginx, PHP-FPM, MariaDB and cron are active; Nginx
configuration passes; the public homepage returns 200 and the service journal contains
no new error entries. The private deployment stage was removed after the retained receipt
was verified. Demo, installer, Stable catalogue and Git remote were not changed.

### 2026-09-17 — multiple Facebook Page connections deployed

Released signed `addon:social-publishing` 0.2.0 and
`plugin:facebook-publisher` 0.2.0. A provider can now retain multiple independent
Facebook Page connections, including Pages authorized through different Meta user
accounts. Each connection has its own internal identifier, encrypted Page credential,
editor checkbox, optional post message, idempotent delivery and per-Page disconnect
action. Personal Facebook profiles remain intentionally unsupported publication
destinations. Instagram remains outside this release.

The additive schema migration replaced the former one-row-per-plugin key with a stable
connection ID and linked post targets and delivery snapshots to that destination. The
existing connected Page, all targets and all delivery history were migrated without a
row or common-field change. Delivery history now retains the Page name and external ID
after a later disconnect. The OAuth popup uses a session completion revision, so an
already connected Page cannot falsely make a second or failed authorization appear
successful.

Before production mutation, the exact signed archives and legacy-to-multiple schema
upgrade passed an isolated MariaDB lifecycle test. Production was backed up to
`/root/sensecms-backups/20260917T003112Z-facebook-multi-020`; restore the database and
the matching package tarball together if operator recovery is required. The deployed
archive SHA-256 values are
`7be5c4af7ddb232ea3be4877e334b4ae2d1f5bd47fe8615f3db1bc3d7ffd5888` for the add-on
and `37034fd5f522b1d97a38e336b7aa135e428dddafbd010b61900db055b62d202b` for the
Facebook plugin.

Validation passed 27 Facebook protocol/UI checks, PHP and JavaScript syntax, manifest
parsing, 13 distribution checks, 46 release-version checks, 11 project-boundary checks,
signed package installation and authenticated production acceptance of the provider
page, status API, editor destination API and versioned assets. The production worker
reported healthy with no publication, Nginx/PHP-FPM/MariaDB/cron are active, Nginx
configuration passes and fresh PHP/application error counts are zero. The private
deployment stage was removed. No social post, Instagram code, demo installation,
installer, Stable catalogue or Git remote was changed.

### 2026-09-17 — free Facebook Publisher marketplace preview published

Published `Facebook Publisher` 0.2.0 as one free Plugin product across the managed
`/extensions`, `/extensions/catalog` and `/extensions/catalog/plugin` collections,
with its detail page at `/extensions/catalog/plugin/facebook-publisher`. The technical
`Sense CMS Social Publishing` addon remains an installed dependency rather than a
second marketplace product. Instagram remains outside this publication.

The detail page accurately records the current release boundary: production acceptance
has passed for an authorised Meta app account, but Meta Business Verification is still
In review, Access Verification is unavailable until that completes, and Meta App Review
is not complete. Consequently the catalogue identifies this as a Development Preview.
No `plugin:facebook-publisher` entry was added to Distribution, so public download and
the licence form remain unavailable. The page documents multiple Facebook Pages and
Meta accounts, explicit per-post destination selection, encrypted installation tokens,
the central App Secret boundary and the exclusion of personal profiles.

Recovery is `/root/sensecms-backups/20260917T014950Z-facebook-extension`, containing
the pre-change database dump, exact content journal, operator publication script,
catalogue source, HTTP acceptance test and SHA-256 inventory. The scoped rollback is
`publish-facebook-extension.php <production-root> --rollback <backup>`; it restores
the three original listing documents, archives the new detail page and detaches then
archives only the Facebook shared section. Two earlier attempts stopped before content
mutation during backup preparation; their temporary stages and incomplete backups,
including transient database option files, were removed.

Validation passed PHP lint, `git diff --check`, all 468 local theme/website checks and
20 production marketplace GET/HEAD routes. Production contains 14 marketplace products,
six free tiers and nine Plugin cards, with exactly one Facebook card in each intended
collection. Direct checks confirm the Facebook Distribution offer is absent. Desktop
and 390 px browser QA showed the published detail and marketplace card without horizontal
overflow; the mobile document width remained within the viewport. Nginx configuration
passes, Nginx/PHP-FPM/MariaDB/cron are active, and fresh application and priority 0..3
service errors are zero. No Core/schema/package, social connection, post, Instagram,
demo, installer or Git remote was changed.
