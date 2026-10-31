<?php

declare(strict_types=1);

return (static function (): array {
$pages = [
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
        ['Package availability and lifecycle', 'The public catalogue lists each product and its current availability. Supported package types use signed archives, compatibility and dependency checks, installation and controlled lifecycle operations. Selected development packages can be downloaded after the required licence is verified. Availability is specific to each product; a catalogue entry does not mean a package is ready to download or approved as Stable. Review its detail page and prepare a backup before installation or upgrade.'],
    ]],
    '/docs/server' => ['title' => 'Server configuration', 'description' => 'Public document roots, HTTPS and private storage for Nginx and Apache.', 'kind' => 'article', 'sections' => [
        ['Public means public only', 'Use public as the document root. Keep source code, license storage, database credentials and backups outside it. Grant the PHP runtime write access only to storage.'],
        ['Nginx', 'Nginx does not read .htaccess files. Configure routing in the server block: serve existing public assets and route other requests to index.php. Permit PHP execution only for that front controller, pass the HTTPS status to PHP-FPM and reject hidden or private file paths.'],
        ['Apache', 'The distribution includes Apache rewrite rules. Enable mod_rewrite and the required AllowOverride permissions, and use public as the document root. The current development installer has passed isolated licensed installation tests on Apache over HTTPS. Validate your own server configuration before deployment.'],
        ['Canonical HTTPS', 'Redirect HTTP and hostname aliases to the licensed HTTPS hostname, preserving the path and query. Sense CMS rejects unexpected installation hosts. Do not trust arbitrary forwarded headers from public clients.'],
        ['Before production', 'Verify private-file denial, certificate renewal, database recovery and fresh installation in a separate environment. Current development builds are not stable production releases.'],
    ]],
    '/download' => ['title' => 'Start with a verified release.', 'description' => 'Sense CMS release availability and the checks required before a public stable distribution.', 'kind' => 'download'],
    '/update' => ['title'=>'Stay current. Stay in control.', 'description'=>'Explore the latest Stable Sense CMS releases, release notes and requirements.', 'kind'=>'managed', 'sections'=>[]],
    '/platform/ai' => ['title'=>'AI that supports your team.', 'description'=>'Editorial assistance, managed knowledge and a visitor assistant, with your choice of provider and human control over publishing.', 'kind'=>'platform', 'sections'=>[
        ['Editorial assistance', 'Draft and improve page sections in Page Builder and post content. Review and edit suggestions before choosing what to publish.'],
        ['Your provider and limits', 'Configure supported AI providers and their usage limits. Provider credentials remain encrypted within your installation.'],
        ['Knowledge and visitor support', 'Use approved sources to support answers and source links. Keep knowledge up to date and hand the conversation to your team when more help is needed.'],
    ]],
    '/contact' => ['title' => 'Let’s talk about your project.', 'description' => 'Contact Sense CMS about the platform, licensing or a technical question.', 'kind' => 'contact'],
];

// Starter copy belongs to this theme, never to the clean Core distribution.
$content = [
    '/' => [
        ['An independent workspace', 'Manage pages, posts, media, navigation and SEO from one administrative environment. Your public theme can change without replacing your workspace.', null, 'Explore the platform →', '/platform'],
        ['Many facilities. One foundation.', 'Organise locations, teams and their content with scoped access. Keep local responsibilities clear while managing your organisation centrally.', null, 'Discover the platform →', '/platform'],
        ['A theme of your own', 'Choose a compatible theme or create one for your project. Page content and its public address belong to Core; the theme decides how they look.', null, 'Build a theme →', '/docs/themes'],
        ['Good foundations start before the homepage.', 'Installation begins with a verified license, followed by server checks, database setup and your owner account. Keep private application files outside the public document root.', null, 'Read the installation guide →', '/docs/installation'],
    ],
    '/platform' => [
        ['Your organisation, connected', 'Sense CMS is a general-purpose system for websites, organisations and multi-facility projects. The administrative workspace is independent of the public theme.'],
        ['Content and publishing', 'Create multilingual pages and posts, organise categories, build pages from supported sections and manage media, navigation and SEO. Draft, publication and review controls keep editorial work structured.'],
        ['Teams and facilities', 'Assign responsibility through roles and facility-scoped permissions. Manage each location’s profile and content in a shared workspace.'],
        ['Presentation without lock-in', 'Pages, public paths and editorial records remain in Core when you change the public theme. Compatible themes declare their settings, page templates and supported blocks.', null, 'Read the theme contract →', '/docs/themes'],
        ['Explore the working demo', 'Sense CMS runs this website, and a separate public demo is available to explore. The current installer has passed isolated licensed installation tests on Nginx and Apache over HTTPS. It remains a development candidate; no Stable Core release has been published.', null, 'Try the demo →', 'https://demo.sensecms.com/'],
    ],
    '/extensions' => [
        ['Modules', 'Application capabilities with their own routes, data and lifecycle.', null, 'About modules →', '/extensions/modules'],
        ['Plugins', 'Connections to external services and application events.', null, 'About plugins →', '/extensions/plugins'],
        ['Addons', 'Optional tools that extend administrative and operational workflows.', null, 'About addons →', '/extensions/addons'],
        ['Themes', 'The public appearance of your website, separate from the administration panel.', null, 'About themes →', '/extensions/themes'],
        ['Release availability', 'Package architecture is not an open marketplace. There are no public stable extension downloads yet. Signing and verification must be followed by installation, update and recovery checks.'],
    ],
    '/docs' => [
        ['Installation', 'Start with the server requirements, a licensed domain and a new, empty database. Follow the setup sequence from licence verification to your first owner login. The guide also explains how to resume an interrupted installation and which private files to preserve. Use the development installer in an isolated environment; check release availability before planning deployment.', null, 'Installation guide →', '/docs/installation'],
        ['Licensing', 'Understand how the system licence is tied to your installation domain and how verification works. Learn which extensions need a separate product licence and what to prepare before changing a domain or key.', null, 'Licensing guide →', '/docs/licensing'],
        ['Package contract', 'Compare themes, modules, plugins and add-ons. Review package identity, compatible versions, dependencies and signature verification before installing code from a trusted publisher.', null, 'Package documentation →', '/docs/packages'],
        ['Theme development', 'Create a public website theme while keeping content and administration in Core. Follow the rendering contract for page templates, editable sections, assets and multilingual links.', null, 'Theme contract →', '/docs/themes'],
        ['Server configuration', 'Configure HTTPS, PHP and the public document root for Nginx or Apache. Keep private application files outside the web root and verify routing, access restrictions and recovery before deployment.', null, 'Server guide →', '/docs/server'],
        ['Content and AI', 'Explore editorial assistance, approved knowledge sources and the visitor assistant. Understand where human review fits and how provider settings and usage limits support your team.', null, 'Explore content and AI →', '/platform/ai'],
        ['Updates and release status', 'Review published Stable release notes when available and check the current development status. Plan updates with a backup and a recovery procedure; automatic Core installation is not available.', null, 'Check updates →', '/update'],
    ],
    '/download' => [
        ['Development, not a stable release', 'The current installer has passed isolated licensed installation tests, including Nginx and Apache over HTTPS. It remains a development candidate. No Stable Core release has been published, and automatic Core installation is not available.'],
        ['What is implemented', 'License-first installation; owner authentication; multilingual pages, posts and media; navigation and SEO; facility-scoped access; theme settings with draft and publication; signed theme installation, activation and rollback.'],
        ['Demo and package availability', 'The public demo is open. Selected signed development packages are available through the catalogue with the required licence; each product lists its own status. Stable Core publication remains separate from the completed installation tests.', null, 'Try the demo →', 'https://demo.sensecms.com/'],
        ['Prepare your environment', 'Read the requirements and installation flow before choosing a production release. No payment or release date is being offered on this page.', null, 'Read the installation guide →', '/docs/installation'],
    ],
    '/contact' => [
        ['Talk to Sense CMS', 'For platform questions, licensing enquiries or your next project, contact info@SenseCMS.com.', null, 'Open your email application →', 'mailto:info@SenseCMS.com'],
        ['Technical questions', 'Include your PHP version, Core version and a short description of the problem. Never send passwords, license keys, private encryption keys or database credentials.'],
    ],
];
$legalPages = require __DIR__ . '/legal-pages.php';
foreach ($legalPages as $legalPath => $legalPage) {
    $pages[$legalPath] = ['title'=>$legalPage['title'], 'description'=>$legalPage['description'], 'kind'=>'managed'];
    $content[$legalPath] = $legalPage['sections'];
}
unset($legalPages, $legalPath, $legalPage);
foreach (['modules'=>'Application capabilities', 'plugins'=>'Connections and integrations', 'addons'=>'Optional workspace tools', 'themes'=>'Your public identity'] as $slug => $title) {
    $pages['/extensions/' . $slug] = ['title'=>ucfirst($slug) . ': ' . $title, 'description'=>'Understand the role of ' . $slug . ' in Sense CMS.', 'kind'=>'managed'];
    $content['/extensions/' . $slug] = [
        [$title, $content['/extensions'][array_search($slug, ['modules','plugins','addons','themes'], true)][1]],
        ['Compatible, independently packaged', 'Each package declares its identity, PHP and Core requirements and publisher. Only trusted packages should execute on your server. A signature establishes origin and integrity, not a sandbox.', null, 'Read the package contract →', '/docs/packages'],
        ['Current availability', 'Public stable distribution is not open yet. Check release status before planning a production installation.', null, 'Release status →', '/download'],
    ];
}
$pages['/docs/themes'] = ['title'=>'Build an independent theme', 'description'=>'A public theme contract for Sense CMS: presentation without ownership of your content.', 'kind'=>'article', 'sections'=>[
    ['Package identity', 'Declare type theme, a unique slug, version, publisher and compatibility in sense-package.json. Keep the same slug and version in theme.json. Use a trusted publisher signature before installation.'],
    ['Rendering contract', 'Include pages.php for optional starter routes, layout.php for their presentation and views/page.php for CMS-managed pages. A content-only theme may return an empty array from pages.php. Declare contract_version 1, page_templates and configuration in theme.json. Every compatible theme renders the complete portable Core section standard; supported_blocks remains descriptive metadata for additional package tooling.'],
    ['Content stays in Core', 'Managed pages, translations, publication rules and public paths remain in the database when a theme changes. The managed view receives page with blocks, locale, navigation, themeSettings, appearance, baseUrl and SEO metadata. Do not import another installation’s database or credentials.'],
    ['Templates and blocks', 'Implement every Core section and declare only additional theme-specific blocks beyond that standard. Always support a default page template as a fallback. Escape plain text and use App\\Core\\HtmlSanitizer for editor HTML. Render only trusted extension components.'],
    ['Theme assets', 'Place CSS, JavaScript, images, fonts and video beneath assets. The active theme serves them at /theme-assets/ followed by their relative path. Nested asset directories are supported. Executable source, hidden paths and traversal are not served.'],
    ['URLs belong to pages', 'Set a Public path in the page editor for the default language, such as /about or /docs/installation. Core resolves it before starter theme routes. Unpublished, private and archived pages remain unavailable, instead of falling back to starter copy. Other languages retain their localized URLs.'],
    ['Independent administration', 'Do not style or replace the administration panel from a public theme. Keep configuration declarative so Core can provide draft, preview and publication controls. Test a second theme, preserved content, routing, keyboard access, mobile layout and rollback before release.'],
]];
foreach ($content as $path => $sections) $pages[$path]['sections'] = $sections;
return $pages;
})();
