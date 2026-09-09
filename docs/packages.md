# Package contract v1

## Prepared development packages

The current complete source inventory contains `addon:calendar` 0.1.0,
`theme:sensecms` 0.3.8, `plugin:google-analytics` 0.1.1 and
`plugin:google-calendar` 0.1.0, `plugin:microsoft-365-calendar` 0.1.0 and
`plugin:apple-calendar` 0.1.0.
No standalone module implementation is ready to release yet.
Signed ZIPs are prepared privately in `.cms/releases/packages-20260907/`, including
an independently signed `release-set.json`, `release-set.sig` and `SHA256SUMS`.
These are development artifacts, not a public Stable release or anonymous downloads.
Free/paid assignments follow the approved Eduvixo catalogue mapping described below.
The original private release set contains theme 0.3.2; it is retained unchanged.
The current distribution archive is separately signed from theme 0.3.8 source.

To prepare all implemented packages, set `SENSE_PACKAGE_SIGNING_KEY_FILE` to the
protected publisher key and run `php scripts/build-packages.php <new-directory>`.
The parent must exist; an existing release is never overwritten. All four source
roots are inspected and incomplete package directories fail the build. Private
staging is renamed only after the complete signed set has been built.
The supplied public-key copy is informational: establish publisher trust separately.
This operator tool does not enable distribution or modify an installed website.

`tests/package-release.php <release-directory> <trusted-public-keys.json>` runs on
private Linux/MariaDB QA, verifies the actual signed artifacts and installs both
into a disposable database/application directory. It checks custom calendar
categories, data-preserving uninstall/reinstall, theme activation and public rendering.
The generated QA database and application directory are removed afterward.
`tests/workspace-migration.php` separately covers update, rollback and migration
failure recovery; its theme test versions follow the actual source version.

The source of each extension is `.<type>s/<slug>/sense-package.json` plus its
payload files. `module`, `plugin`, `addon` and `theme` are the supported types.
Core distribution and updates are deliberately not accepted as extension packages.

```json
{
  "schema": 1,
  "type": "module",
  "slug": "example",
  "name": "Example",
  "version": "0.1.0",
  "requires": {
    "php": {"min": "8.5.0", "max_exclusive": "9.0.0"},
    "core": {"min": "0.1.0", "max_exclusive": "1.0.0"}
  },
  "publisher": {"key_id": "publisher-key-id", "name": "Publisher"},
  "dependencies": {
    "module:another-module": {"min": "1.0.0", "max_exclusive": "2.0.0"}
  }
}
```

This is documentation, not a shipped example product. Versions have three numeric
components. Bounds are inclusive `min` and exclusive `max_exclusive`; unsupported
range syntax, unknown manifest fields and self dependencies fail validation.

## Archive

- `sense-package.json`: source metadata plus `files`, a sorted map of payload
  relative paths to SHA-256 hashes.
- `signature.ed25519`: Base64 Ed25519 signature of the exact manifest bytes.
- `payload/<path>`: only listed regular files, with no extra ZIP entries.

Archives use stable ordering, timestamps and file attributes; repeated builds
with identical inputs are byte-identical in the tested environment. An existing
release is never overwritten. Same-filesystem hard-link publication provides an
atomic no-clobber operation; unsupported filesystems fail explicitly.

The verifier requires a trusted key supplied outside the archive. A key embedded
in a package cannot establish trust. PHP in signed extensions is executable code:
publisher trust is therefore a code-execution trust decision, not a sandbox.

Limits: 25 MiB archive, 100 MiB expanded, 10 MiB per payload file, 2,000 payload
files and 512 KiB manifest. Encryption, symlinks, path traversal, absolute paths,
Windows reserved names, case collisions, file/directory collisions, hidden files,
runtime storage and private/system files are rejected. Verification does not
extract files, execute PHP, apply SQL or change installation state.

## License entitlement

The official website now publishes all 13 product tiers at `/extensions/catalog`,
with CMS-managed detail/category pages. `.src/package-catalog.php` is website-only
content; it is not trusted download-entitlement inventory and is not bundled into
portable Core. Free/paid classification and USD annual prices follow the live
Eduvixo marketplace, as explicitly requested. Five entries are free, eight paid
(including Core). Publication does not make unadapted packages downloadable.
Calendar and the official theme have signed development archives; other products
remain explicitly marked as awaiting Sense CMS adaptation. The official theme is
enabled for CMS-license-gated download. Calendar 0.1.0 is enabled for download with
its separately validated product key: ProductName `Sense CMS Calendar`,
ProductModel `Sense CMS Calendar Addon`, protocol ProductVersion `1.0`.
Purchasing and Stable releases are not open yet.

Distribution inventory must explicitly declare `pricing: "free"` or `pricing: "paid"`.
This is distribution metadata, not an additional field in the v1 archive manifest.
Missing or unknown terms fail closed. Free packages require the Sense CMS license
identity from `config/product.php`; they do not allow anonymous downloads. Paid
packages require their own `license.product_name` and `license.product_model`.
A paid entry cannot reuse the CMS identity. Each separately licensed product needs
a distinct registered name/model pair; do not invent provider registrations.

The Chivale protocol field `ProductVersion` stays at **1.0**, independently of the
Core version and package version. Responses and encrypted caches authorize by exact
product name/model and validity dates, not by version. Provider rejection, revocation,
domain binding and expiration checks still apply. Updates do not require a new key
while the same product license remains valid. Package compatibility/version checks
remain separate and still apply to installation and rollback.

`Packages/Entitlement` resolves this policy for trusted inventory or verified signed
catalog entries. Download requests send the canonical installation domain and key in
headers, never the URL. The distribution server must resolve the expected identity
from its own package inventory, never from caller-supplied name/model/pricing headers.
Client-side header construction is **not** server-side download authorization.
The official website also exposes `GET /packages/download` for public offer metadata
and a session CSRF token, and `POST /packages/download` for a browser form containing
`product`, `domain`, `license_key` and `csrf`. This browser endpoint accepts no query
parameters. It is separate from the package-manager HTTP client header contract.

`Packages/Distribution` is disabled unless private `storage/distribution.json`
explicitly enables it. Portable installations do not inherit the official host's
inventory, publisher trust or archives. Archives stay outside `public`, under
`storage/distribution/releases`; each download checks its signature, exact signed
identity/version and pinned byte length/SHA-256 before live license validation.
The server determines free/paid entitlement and never persists submitted keys.
An isolated HttpOnly/SameSite session, CSRF/origin checks and a fixed-size rate
limiter protect the POST endpoint. The browser clears the key after submission
and independently verifies the returned bytes against offer metadata.

`tests/distribution.php` covers inventory, signatures, tampering and rate limits.
`tests/distribution-http.py` exercises the real HTTP endpoint and a real existing
CMS license without printing or saving the key. The actual downloaded ZIP is
retained privately for installation acceptance. With `--calendar`, the test reads
the Calendar key from private stdin (never argv or a server credential file),
checks live paid download and both cross-product refusals, compares actual ZIP
bytes to signed acceptance artifacts and repeats clean installation on QA.
`scripts/deploy-distribution.py --qa|--production --calendar` publishes only the
preverified Calendar archive, preserves the existing theme offer and journals the
previous configuration. Production requires the matching QA acceptance receipt.

## Installation planning

### Google Analytics plugin

The free `plugin:google-analytics` package requires the existing CMS licence to
download. Configure its GA4 Measurement ID at `/system/extensions/google-analytics`
inside the existing panel; `extensions.manage` and CSRF protect updates. An empty
identifier disables collection. No database migrations or additional libraries.

Only anonymous published CMS pages are eligible. Authenticated users, previews,
system routes and standalone theme fallback pages are excluded. Google resources
are not loaded until explicit consent; refusal, 180-day expiry and withdrawal are
supported. Advertising consent stays denied. Review the GA property's Enhanced
Measurement options and the site's privacy notice before enabling collection.
Publication of this package does not enable tracking on the official website.
Actual event delivery into a GA property has not been verified without a real ID.

The signed 0.1.0 archive remains immutable; 0.1.1 fixes a conflict between the
plugin form and the panel's bubbling submit handler. A capturing handler prevents
duplicate requests and controls remaining disabled. No global panel handler changed.
`tests/analytics.php` covers validation and CSP; `tests/analytics-consent.cjs` covers
consent and submit-handler isolation. `tests/analytics-http.py` verifies QA runtime
and real licence-gated downloads (production mode tests distribution only).
The operator's `--analytics` publication mode requires the exact 0.1.0 baseline,
the signed 0.1.1 archive and matching QA receipt; it preserves other product offers.

### Google Calendar plugin

`plugin:google-calendar` 0.1.0 is a development outbound integration, not a
bidirectional sync service. It requires `addon:calendar` >=0.1.0 <1.0.0 and an
active Calendar runtime. Configure it from Calendar's existing Integrations dialog.
The package uses that addon's encrypted settings, permissions and delivery queue;
it introduces no Core changes, migration or dependency library.

Paid download uses ProductName `Sense CMS Google Calendar` and ProductModel
`Sense CMS Google Calendar Plugin`, protocol version 1.0. Release versions are not
licence entitlement conditions. A CMS or Calendar addon licence is not sufficient.

OAuth client ID, client secret, refresh token and destination calendar ID are
installation-specific. The account must have owner/writer access to a calendar
listed for that account, with CalendarList read access and event-write scopes.
Google's OAuth consent and credentials are not provisioned by this plugin. No
credential from Eduvixo is copied. Connections remain disabled until successful
verification. Real Google event delivery has not yet been accepted on a live account.

The Calendar dispatcher excludes private/participant-only events and sends concrete
occurrences from recurring series. The provider preserves UTC instants, local all-day
dates and exclusive ends, uses deterministic event IDs for creation retries, and
accepts deletion of already-absent events. Google errors remain failures; redirect
following is disabled, TLS is verified, response bodies are bounded and never logged.

`tests/google-calendar.php`: isolated protocol tests, no external requests.
`tests/google-calendar-package.php`: signed installation, dependency checks,
encrypted settings, disable/uninstall/reinstall in private QA.
`tests/google-calendar-http.py`: actual licence-gated downloads, cross-product
refusals and exact archive bytes, on QA and production. The `--google-calendar`
operator deployment preserves the other offers and requires a matching QA receipt.

### Microsoft 365 Calendar plugin

`plugin:microsoft-365-calendar` 0.1.0 is a development outbound Microsoft Graph
integration. It requires the installed and active `addon:calendar` >=0.1.0 <1.0.0.
It reuses Calendar's Integrations dialog, encrypted settings and delivery queue;
no Core changes, migration or additional library are needed.

Paid download uses ProductName `Sense CMS Microsoft 365 Calendar` and ProductModel
`Sense CMS Microsoft 365 Calendar Plugin`, protocol version 1.0. A CMS, Calendar
addon or other integration licence does not authorise this package. Release versions
are independent of licence validity.

Configure tenant ID, application ID and secret, mailbox user ID/email, and optional
calendar ID (empty selects the default calendar). Application authentication requires
administrator-authorised Calendars.ReadWrite access; restrict the application's
mailbox access in Microsoft 365. Tenant setup and admin consent are not provisioned
by the plugin. Connections remain disabled until successful writable-calendar
verification. No Microsoft account is connected on the product website.

Timed events preserve UTC instants; all-day events retain local dates and exclusive
ends. Creation uses a stable transaction ID, Graph requests use immutable event IDs,
and deleting an absent event is idempotent. Private/participant-only events are
excluded by Calendar. No attendees or invitations are created. Fixed HTTPS endpoints,
TLS verification, disabled redirects and bounded responses protect credentials;
upstream response bodies are not exposed in errors. This is not two-way sync.

`tests/microsoft-calendar.php` covers 32 isolated protocol checks, not live Graph
delivery. `tests/google-calendar-package.php <archive> --microsoft` covers signed
installation, dependencies, encryption and data-preserving uninstall/reinstall.
`tests/google-calendar-http.py --microsoft [--production]` verifies real licensed
downloads and exact accepted archive bytes. Deployment uses `--microsoft-calendar`
and a matching QA receipt, preserving the other offers. Live event delivery to an
authorised Microsoft 365 account remains unverified and is disclosed on its detail page.

### Apple Calendar plugin

`plugin:apple-calendar` 0.1.0 is a development outbound iCloud CalDAV provider,
requiring the active `addon:calendar` >=0.1.0 <1.0.0. Configure the private calendar
collection URL, Apple Account email and an app-specific password from Calendar's
existing Integrations dialog. Settings use installation-local encryption. A dedicated
writable calendar is recommended. No Core, schema or dependency-library changes.

Paid download uses ProductName `Sense CMS Apple Calendar`, ProductModel
`Sense CMS Apple Calendar Plugin`, protocol 1.0; release versions do not gate licence
entitlement. Real key/validity verification and cross-product refusals were tested.

Only HTTPS iCloud CalDAV hosts and private collection paths are accepted. Public
IPv4 DNS addresses are validated and pinned; proxy use and redirects are disabled.
TLS verification remains enabled. Responses are bounded; UTF-8 XML rejects DTDs,
entities and NULs before parsing with LIBXML_NONET, without external entity expansion.
Verification checks the exact collection resource and read/write/create/delete rights.

Namespaced deterministic UIDs make retry targets stable. Existing resources require
the matching UID and a strong ETag; PUT/DELETE use conditional headers. Concurrent
changes between read and mutation produce an error instead of blind overwrite.
Sense CMS remains the source of truth for managed events: edits made earlier in iCloud
may be replaced by subsequent outbound delivery. This is not two-way sync or calendar
discovery. Timed dates remain UTC, all-day dates retain local exclusive ends, text is
escaped, and UTF-8 lines are folded at 75 octets. No attendees/invitations are emitted.

`tests/apple-calendar.php`: 64 isolated protocol checks on local and server PHP.
`tests/google-calendar-package.php <archive> --apple`: signed lifecycle and encryption.
`tests/google-calendar-http.py --apple [--production]`: real licensed downloads.
Operator deployment uses `--apple-calendar` with an exact signed archive and QA receipt.
No Apple account is connected on the product website. Real iCloud delivery is still
unverified; the public detail explicitly discloses that limitation and development status.

`Plan::resolve()` accepts verified selected and installed manifests. It checks
the prospective complete dependency graph, including existing dependants, detects
cycles and returns dependency-first identities. Reinstalling the same version and
downgrading are rejected; explicit rollback is a separate future lifecycle action.

The archive verification and planning layers are implemented. Theme-only staging,
activation and rollback are also implemented below. General module/plugin lifecycle,
database migration ownership, uninstall and the admin package-management UI remain
subsequent layers and are not claimed complete by this contract.

## Theme lifecycle

Themes implement `pages.php` (public route metadata) and `layout.php` (trusted PHP
template). The current asset contract exposes only `assets/site.css`, `assets/site.js`
and `assets/logo.svg` through `/theme-assets/`; source PHP is never downloaded.
Themes must not claim Core administration routes. Public requests use the licensed
canonical hostname and do not start an administration session or database connection.

The operator CLI requires a completed, licensed Core and independently provisioned
publisher keys. ThemeManager copies the ZIP into unique private staging, verifies
the signature/inventory and compatibility, writes only rehashed payload files, then
publishes a versioned directory and atomically changes `storage/theme.json`.
All operations share a nonblocking installation-level lock. Existing versions remain
untouched; the previous active reference is retained for explicit rollback.

Rollback reverifies the saved archive with current publisher trust, checks Core/PHP
compatibility and every payload hash, then swaps references. No theme operation runs
SQL, changes users or claims module support. Source and storage must have appropriate
ownership: run the CLI as the PHP runtime user. Themes are trusted executable PHP,
not sandboxed code; syntax/HTTP validation belongs in staging before production.
If publication succeeds but the pointer write fails, the orphan release is retained
for operator inspection rather than deleted. Do not remove active or previous releases.

## Telegram Notifications 0.1.1 (development)

The paid plugin is available through `/extensions` and `/packages/download` on the
official site. Download requires the **Sense CMS Telegram Notifications** product key
(model **Sense CMS Telegram Notifications Plugin**) and its installation domain.
A Core licence does not authorize this paid download. Licensing protocol stays 1.0
across package upgrades; product identity, domain and validity are verified separately
from package compatibility. This is not a Stable Core release.

Requires PHP 8.5 and a Sense CMS Core with `TelegramConnectionClient`, the central
notification channel and personal `/settings` connection flow. Calendar is optional.
Enable the plugin/channel, confirm recipient consent, then let each user connect via
the one-time link and press Start in @SenseCMSBot. No bot token belongs in the plugin.
The shared bot profile is managed only by the official installation owner.

Signed 0.1.0 → 0.1.1 upgrade, rollback, re-upgrade and clean reinstallation were tested
in private QA. Settings and unrelated packages remain unchanged. Production preserves
the verified 0.1.0 recovery archive. Do not replace or delete broker keys/bindings when
updating a customer plugin; they belong to the independent official-site service.

Official broker maintenance runs hourly as `sensecms`. Only authenticated encrypted
`starts`, `pending` and `rate` records older than a 24-hour safety margin are removed.
Fresh or malformed records are retained; malformed data is counted for operator review.
Bindings and delivery deduplication tombstones are retained indefinitely to prevent
replay and ambiguous-send duplication. Cleanup shares the broker lock, skips symlinks
and never sends messages. Binding removal uses explicit disconnect; tombstone retention
must not be shortened without a reviewed replay policy. Back up private service state
before maintenance changes. Source rollback does not require restoring old user data.
