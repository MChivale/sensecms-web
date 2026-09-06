# Sense CMS

A general-purpose PHP CMS, web installer and independently installable extensions.
This repository also owns the Sense CMS product website and package distribution.

## Current implementation

The first development milestone implements the signed package format, immutable
archive builder, verifier and dependency planner. It is **not yet a runnable CMS
or an installation release**. No download advertised as a finished CMS is published.
The existing production HTTPS infrastructure is separate from this source work.

## Repository boundaries

| Path | Responsibility |
| --- | --- |
| `.cms/source` | Portable Core and web installer source |
| `.modules` | Independently installed application features |
| `.plugins` | Event-driven integrations and extensions |
| `.addons` | Optional administrative/operational tools |
| `.themes` | Public presentation packages, including `cambo-jumbo` |
| `web` | Sense CMS product website and distribution application |
| `deploy` | Server configuration and deployment tooling |
| `scripts`, `tests` | Repository build and verification tools |

The administrative Workspace belongs to Core, not to any public theme. Eduvixo
is the reference for its design and existing implementation. Educational data,
campus functions and Cambo Jumbo's previous custom administration are not Core.
Package source folders are not public web directories.

## Development

PHP 8.5+ with Sodium and ZIP is required for package tooling. No framework or
third-party dependency has been introduced.

```text
php tests/packages.php
php scripts/package.php build .modules/<slug> <release.zip> module
php scripts/package.php verify <release.zip> <trust-store.json> 0.1.0
```

The builder requires `SENSE_PACKAGE_SIGNING_KEY_FILE` pointing to a protected
Base64 Ed25519 secret key supplied by the release operator. It never creates or
trusts a signing key automatically. The verifier reads an independently provisioned
JSON map of key IDs to Base64 public keys. The development Core compatibility
version `0.1.0` is not a licensed product version.

See [the package contract](docs/packages.md) and [implementation decisions](.wrk/2026-09-06-core-foundation.md).
