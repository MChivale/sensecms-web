# Package contract v1

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

## Installation planning

`Plan::resolve()` accepts verified selected and installed manifests. It checks
the prospective complete dependency graph, including existing dependants, detects
cycles and returns dependency-first identities. Reinstalling the same version and
downgrading are rejected; explicit rollback is a separate future lifecycle action.

The archive verification and planning layers are implemented. Installation locks,
staging/activation, database migration ownership, rollback, uninstall and the admin
UI are subsequent layers and are not claimed complete by this contract.
