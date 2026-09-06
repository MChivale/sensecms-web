<?php

declare(strict_types=1);

return [
    '/' => ['title' => 'A clear foundation for your next website', 'description' => 'Sense CMS is a modular PHP content management system. Explore its independent themes, licensing-first setup and development documentation.', 'kind' => 'home'],
    '/platform' => ['title' => 'One foundation. Room to grow.', 'description' => 'Understand the Sense CMS Core, independent administration and separately packaged extensions.', 'kind' => 'platform'],
    '/extensions' => ['title' => 'Add what your project needs.', 'description' => 'Modules, plugins, addons and themes: four clear roles in the Sense CMS package architecture.', 'kind' => 'extensions'],
    '/docs' => ['title' => 'A practical place to start.', 'description' => 'Sense CMS documentation for installation, licensing, server configuration and extension development.', 'kind' => 'docs'],
    '/docs/installation' => ['title' => 'Install Sense CMS', 'description' => 'Prepare your server and follow the license-first Sense CMS installation flow.', 'kind' => 'article', 'sections' => [
        ['Before you start', 'Use a separate test environment for the current development build. Prepare PHP 8.5 or later with curl, pdo_mysql, sodium, zip, mbstring, sessions and Argon2id support. Allow at least 128 MB PHP memory. Use MySQL 8.0.29+ or MariaDB 10.11+.'],
        ['Prepare an empty database', 'Create a new database and a dedicated database user. Scope permissions to that database only. The installer refuses existing tables and will not erase another application’s data.'],
        ['Set the document root', 'Extract the distribution outside the publicly served directory. Point the web server at its public directory. Application source, storage and credentials must not be web-accessible. Configure HTTPS and your canonical hostname before opening the installer.'],
        ['Bind your domain', 'Run the preparation command as the PHP runtime user. Only storage should be writable by PHP; use private directory permissions and protect backups.', 'php scripts/prepare.php https://www.example.com'],
        ['1. Verify your license', 'Open /install and enter the key assigned to the canonical domain. The server validates the key with Chivale over HTTPS. Database and account setup are unavailable until validation succeeds.'],
        ['2. Check the environment', 'Review the server checks and resolve any missing extensions, memory or write permissions before continuing.'],
        ['3. Create your workspace', 'Enter the new database details, site name and owner account. Use a unique owner password of 14–200 characters. On completion, the installer closes and redirects to /login.'],
        ['If setup is interrupted', 'Retry with the same database, owner email and original password. Keep the private installing.json file; do not delete tables or recovery state. Back up private storage together with the database.'],
    ]],
    '/docs/licensing' => ['title' => 'Licensing and domain binding', 'description' => 'How Sense CMS verifies, stores and renews license validation.', 'kind' => 'article', 'sections' => [
        ['A license comes first', 'The installer checks the key against the canonical domain and the registered product identity: Sense CMS / Sense CMS System / 1.0. Development Core versions are separate from this licensed product version.'],
        ['Private by design', 'License checks travel from your server to Chivale over verified HTTPS. Keys are not included in URLs or echoed back in pages. Local license data is encrypted with an installation-specific key outside the public directory.'],
        ['Validation and availability', 'Successful validation is cached for up to seven days, bounded by any license expiry. Expired or stale validation requires a fresh remote check. There is no unlimited offline grace period; allow outbound HTTPS to the licensing endpoint.'],
        ['Changing a key or domain', 'An authenticated installation owner can replace the key from the License page. A domain move also requires corresponding license and server configuration changes. Copying encrypted license files between unrelated installations is not supported.'],
        ['Protect your backups', 'Preserve the installation’s encryption key with the private storage backup. Store the database and private files securely; never include either in an extension package or public download.'],
    ]],
    '/docs/packages' => ['title' => 'The extension package contract', 'description' => 'Sense CMS package types, manifests, signatures and compatibility rules.', 'kind' => 'article', 'sections' => [
        ['Four package types', 'Modules provide application features. Plugins integrate with services or events. Addons provide optional administrative tools. Themes own public presentation. The administration workspace belongs to Core, not to a public theme.'],
        ['Explicit compatibility', 'Each package declares its type, slug, version, publisher, PHP and Core version bounds, and dependencies in sense-package.json. Version ranges have an inclusive minimum and an exclusive maximum.'],
        ['Signed inventories', 'An archive contains a manifest, an Ed25519 signature and a payload with SHA-256 file hashes. Verification requires a publisher key trusted independently of the downloaded archive. PHP extensions execute trusted code; signatures do not create a sandbox.'],
        ['Controlled paths and size', 'The verifier rejects traversal, symlinks, hidden runtime files, unlisted payloads and case-colliding paths. Archives are limited to 25 MiB, with at most 100 MiB expanded data and 2,000 payload files.'],
        ['Current implementation', 'Archive creation, signature verification and dependency planning are implemented. Signed themes also support operator-CLI installation and rollback. The general module lifecycle, package-owned migrations and package-management interface are still being developed. No public extension catalog is available yet.'],
    ]],
    '/docs/server' => ['title' => 'Server configuration', 'description' => 'Public document roots, HTTPS and private storage for Nginx and Apache.', 'kind' => 'article', 'sections' => [
        ['Public means public only', 'Use public as the document root. Keep source code, license storage, database credentials and backups outside it. Grant the PHP runtime write access only to storage.'],
        ['Nginx', 'Nginx does not read .htaccess files. Configure routing in the server block: serve existing public assets and route other requests to index.php. Permit PHP execution only for that front controller, pass the HTTPS status to PHP-FPM and reject hidden or private file paths.'],
        ['Apache', 'The distribution contains rewrite rules for Apache. Enable mod_rewrite and the appropriate AllowOverride permissions. A public document root is preferred over relying on parent-directory rewrites. Apache runtime verification is still pending for the development distribution.'],
        ['Canonical HTTPS', 'Redirect HTTP and hostname aliases to the licensed HTTPS hostname, preserving the path and query. Sense CMS rejects unexpected installation hosts. Do not trust arbitrary forwarded headers from public clients.'],
        ['Before production', 'Verify private-file denial, certificate renewal, database recovery and fresh installation in a separate environment. Current development builds are not stable production releases.'],
    ]],
    '/download' => ['title' => 'Start with a verified release.', 'description' => 'Sense CMS release availability and the checks required before a public stable distribution.', 'kind' => 'download'],
    '/contact' => ['title' => 'Let’s talk about your project.', 'description' => 'Contact Sense CMS about the platform, licensing or a technical question.', 'kind' => 'contact'],
];
