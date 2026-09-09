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
