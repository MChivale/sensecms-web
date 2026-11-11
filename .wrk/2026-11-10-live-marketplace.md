# Live Marketplace publication — 2026-11-10

The official Sense CMS Marketplace now publishes the signed Stable catalogue from
the current package sources. It contains 21 free packages: three external themes,
two add-ons and sixteen plugins. Each is gated by the Sense CMS Core licence;
there are no separately licensed packages in this release set.

The public catalogue is signed and is served at `/api/marketplace/v1/catalog`.
Customer installations fetch it through the Core Marketplace updater and resolve
package archives through the protected official package endpoint. The product
website's catalogue and package-detail content was reconciled to the same release
set, with Stable-only descriptions and metadata.

The Sense CMS product-site theme was activated internally as signed version
`1.0.31` to remove stale preview copy from the Marketplace interface. It remains
an internal website theme and is not listed in the public package catalogue.

Production validation after publication confirmed 21 signed catalogue entries,
all Stable and free, plus HTTP 200 for the Marketplace landing/category pages and
all 21 package detail pages. No `development`, `not available`, or stale public
download wording remained in those rendered Marketplace pages.

## 2026-11-10 — Elingo PostgreSQL package-operation lock

The Marketplace install confirmation on `elingo.school` reached the package
lifecycle lock but attempted to call MySQL's `GET_LOCK()`, which PostgreSQL does
not provide. `PackageManager` now keeps MySQL's named-lock path and uses a
database-scoped PostgreSQL advisory lock with the same ten-second acquisition
window and explicit release. The production file was replaced only after a
private backup and syntax check. A runtime check through Elingo's real installed
PostgreSQL connection acquired and released the package lock successfully. No
package, database row, installed theme or private configuration was changed.

## 2026-11-10 — School Theme 1.1.0 Marketplace release

Published the signed Stable `theme:school-blue-red` release as **School Theme
1.1.0**. The package preserves the established update identity while supplying
the School Theme starter layout and admissions popup, supplied SVG header/footer
logos, touch icon and favicon. It contains neutral package images only; no
customer Shoudu content or data is distributed.

The official signed catalogue was regenerated with 21 Stable products and the
new School Theme entry was verified from both the signed stored envelope and
the public Marketplace API. During validation, the API handler was found to
have been accidentally replaced by the catalogue-copy source. It was restored
atomically from the matching, hash-verified Marketplace handler after a private
backup; the endpoint then returned the signed envelope successfully. The
Elingo Marketplace cache was explicitly refreshed and verified to contain
School Theme 1.1.0. No package was installed, updated or activated on Elingo.

### School Theme 1.1.1 follow-up

The starter page no longer renders the unwanted “Skip to main content” link.
The circular School Theme mark now uses a non-rotated, centred and no-wrap title
with a centred supporting line at desktop and mobile sizes. A signed Stable
1.1.1 package was published to replace 1.1.0 in the official distribution; the
public signed API and the refreshed Elingo catalogue both verified the new
version. The update was not installed or activated on Elingo.

### School Theme 1.1.2 package correction

The 1.1.1 archive was incomplete: its archive build did not contain
`views/page.php`, so the package lifecycle correctly rejected the update before
it could change the active theme. It was replaced by signed Stable 1.1.2. The
full source transfer was checked before signing, the signed ZIP was checked for
all runtime files including `views/page.php`, and the exact published archive
completed an isolated `ThemeManager` installation rehearsal as the website PHP
user. The public Marketplace API and Elingo catalogue both verify 1.1.2; Elingo
remains on its unchanged active 1.1.0 release until the owner installs it.

### School Theme 1.1.2 activation on Elingo

The active 1.1.0 signed release still contained the former starter-layout skip
link. Although 1.1.2 had already been installed, it had not been activated.
The verified 1.1.2 release was activated through `ThemeManager`, retaining
1.1.0 as the private previous release. A direct public HTTPS response check
confirmed the active page carries the 1.1.2 popup key and no longer contains
“Skip to main content”.

### Elingo language default form repair

The global administration form handler read `form.action`. The language forms
also contain a hidden field named `action`, which clobbered that DOM property
in browsers; no `POST /system/languages` reached the application. The handler
now reads the form's `action` and `method` attributes explicitly. The updated
script and cache-busting console reference were backed up, deployed to Elingo,
and the publicly served JavaScript hash was verified. Polish was then set as
the enabled default/fallback language through `CmsRepository`; the database
verified `pl=default` and `en=not default`.

### School Theme 1.1.3 — public language selector

The static School Theme starter route now receives the installation locale,
enabled languages and safe language URLs from the public renderer. The Polish
default renders at `/`; `/en` renders the English starter page and both pages
expose functional EN/PL links. The package also has a query-string fallback for
older compatible Cores. The Polish starter copy is rendered server-side, while
the established layout, popup, assets and accessibility behaviour are retained.

The exact `theme:school-blue-red` 1.1.3 archive was signed with the official
publisher key, signature-verified, published in the Stable Marketplace
distribution and included in the renewed signed Marketplace catalogue. Elingo
refreshed that catalogue, installed the verified archive through the package
lifecycle and activated its signed release, retaining the prior theme release
for rollback. The public HTTPS checks passed for Polish root content, EN/PL
switching, English route content and absence of the removed skip link.
