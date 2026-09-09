<?php declare(strict_types=1); $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>
<!doctype html>
<html lang="en" data-theme="light" data-sidenav-color="light" data-sidenav-size="default">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="stylesheet" href="/theme/sensecms-admin.css">
    <link rel="stylesheet" href="/theme/sensecms-custom.css?v=20260901-toast-layer-1">
    <title>License · SenseCMS</title>
    <script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">(function(){const h=document.documentElement,k='__SENSECMS_CONFIG__',d={dir:'ltr',theme:'light',sidenav:{color:'light',size:'default'}},c=JSON.parse(sessionStorage.getItem(k)||'null')||d;window.defaultConfig=structuredClone(d);window.config=c;h.setAttribute('dir',c.dir);h.setAttribute('data-theme',c.theme);h.setAttribute('data-sidenav-color',c.sidenav.color);h.setAttribute('data-sidenav-size',innerWidth<=1140?'offcanvas':c.sidenav.size)})();</script>
</head>
<body data-sensecms-sounds="<?= $escape(json_encode($sounds, JSON_UNESCAPED_SLASHES)) ?>"<?= $flash ? ' data-sensecms-flash="' . $escape($flash) . '"' : '' ?><?= $licenseNotice ? ' data-sensecms-license="' . $escape($licenseNotice) . '"' : '' ?>>
<div class="wrapper">
    <aside id="app-menu" class="app-menu">
        <a href="/dashboard" class="logo-box sticky top-0 flex min-h-topbar-height items-center justify-start px-6 backdrop-blur-xs"><div class="logo-light"><img src="/assets/logo.svg" class="logo-lg h-6 max-w-36 object-contain" alt="SenseCMS"><img src="/assets/logo.svg" class="logo-sm h-6" alt="SenseCMS"></div><div class="logo-dark"><img src="/assets/logo.svg" class="logo-lg h-6 max-w-36 object-contain" alt="SenseCMS"><img src="/assets/logo.svg" class="logo-sm h-6" alt="SenseCMS"></div></a>
        <div class="absolute top-0 end-5 flex h-topbar items-center"><button id="button-hover-toggle" type="button"><i class="iconify tabler--circle size-5"></i></button></div>
        <div class="relative min-h-0 flex-grow"><div class="size-full" data-simplebar><ul class="side-nav p-3">
            <li class="menu-title"><span>Workspace</span></li>
            <li class="menu-item"><a href="/dashboard" class="menu-link"><span class="menu-icon"><i data-lucide="layout-dashboard"></i></span><span class="menu-text">Overview</span></a></li>
            <li class="menu-title"><span>Content</span></li>
            <li class="menu-item"><a href="/content/pages" class="menu-link"><span class="menu-icon"><i data-lucide="files"></i></span><span class="menu-text">Pages</span></a></li>
            <li class="menu-title"><span>Engagement</span></li>
            <li class="menu-item"><a href="/conversations" class="menu-link"><span class="menu-icon"><i data-lucide="messages-square"></i></span><span class="menu-text">Live chat</span></a></li>
            <li class="menu-item"><a href="/ai" class="menu-link"><span class="menu-icon"><i data-lucide="bot-message-square"></i></span><span class="menu-text">AI assistant</span></a></li>
            <li class="menu-title"><span>Experience</span></li>
            <li class="menu-item"><a href="/appearance" class="menu-link"><span class="menu-icon"><i data-lucide="palette"></i></span><span class="menu-text">Appearance</span></a></li>
            <li class="menu-item"><a href="/appearance/themes" class="menu-link"><span class="menu-icon"><i data-lucide="panels-top-left"></i></span><span class="menu-text">Themes</span></a></li>
            <li class="menu-title"><span>System</span></li>
            <li class="menu-item"><a href="/system/extensions" class="menu-link"><span class="menu-icon"><i data-lucide="blocks"></i></span><span class="menu-text">Extensions</span></a></li>
            <li class="menu-item"><a href="/license" class="menu-link active"><span class="menu-icon"><i data-lucide="badge-check"></i></span><span class="menu-text">License</span></a></li>
        </ul></div></div>
    </aside>
    <div class="page-content">
        <div class="app-header min-h-topbar-height flex items-center sticky top-0 z-30 bg-(--topbar-background) border-b border-default-200"><div class="w-full flex items-center justify-between px-6"><div class="flex items-center gap-5"><button id="button-toggle-menu" class="btn btn-icon size-8 hover:bg-default-150 rounded" type="button"><i class="iconify lucide--align-left text-xl"></i></button><div class="lg:flex hidden items-center relative"><div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none"><i class="iconify tabler--search text-base"></i></div><input type="search" class="form-input px-12 text-sm rounded border-transparent focus:border-transparent w-60" placeholder="Search something..."><button type="button" class="absolute inset-y-0 end-0 flex items-center pe-4"><span class="ms-auto font-medium">⌘ K</span></button></div></div><div class="flex items-center gap-3"><button class="btn btn-icon size-8 hover:bg-default-150 rounded-full" id="light-dark-mode" type="button"><i class="iconify tabler--moon text-xl"></i></button><a href="/" target="_blank" class="btn btn-sm bg-default-150">View site <i data-lucide="external-link" class="size-4"></i></a><form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn btn-icon size-8 hover:bg-default-150 rounded-full" type="submit" aria-label="Sign out"><i data-lucide="log-out" class="size-4"></i></button></form></div></div></div>
        <main>
            <div class="page-title"><div><h4 class="mb-1 text-xl font-semibold text-default-800">Installation license</h4><p class="text-default-500 text-sm">Manage the encrypted Chivale license for this SenseCMS installation.</p></div><div class="flex size-11 items-center justify-center rounded-lg bg-success/10 text-success"><i data-lucide="badge-check" class="size-5"></i></div></div>
            <?php if ($flash): ?><div class="mb-5 flex items-center gap-3 rounded-lg bg-success/10 px-4 py-3 text-sm text-success"><i data-lucide="circle-check" class="size-5"></i><?= $escape($flash) ?></div><?php endif; ?>
            <div class="grid gap-5 xl:grid-cols-3">
                <section class="card xl:col-span-2"><div class="card-header"><div><h6 class="card-title">Install encrypted license file</h6><p class="mt-1 text-sm font-normal text-default-500">Validate and install a new license without exposing its key.</p></div></div><div class="card-body"><form method="post" action="/license" enctype="multipart/form-data" class="max-w-xl"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label class="block"><span class="mb-2 block text-sm font-medium text-default-700">License file</span><input class="form-input" type="file" name="license" accept=".lic,application/octet-stream" required><span class="mt-2 block text-xs text-default-500">Accepted format: encrypted <code>.lic</code>, up to 16 KB.</span></label><button class="btn mt-5 bg-primary text-white" type="submit"><i data-lucide="shield-check" class="size-4"></i> Validate and install</button></form></div></section>
                <aside class="card h-fit"><div class="card-header"><h6 class="card-title">License issuer</h6></div><div class="card-body"><p class="text-sm text-default-500">Provide this public installation key to your authorized license issuer. It cannot decrypt your license.</p><label class="mt-4 block"><span class="mb-2 block text-sm font-medium text-default-700">Public key</span><textarea readonly rows="7" class="form-input resize-none text-xs leading-5"><?= $escape($publicKey) ?></textarea></label></div></aside>
            </div>
        </main>
        <footer class="mt-auto footer flex items-center py-5 border-t border-default-200"><div class="lg:px-8 px-6 w-full flex flex-col items-center justify-center gap-2 text-center md:flex-row md:justify-between md:gap-4 md:text-left"><div class="text-sm text-default-600">© Copyright by Sense CMS. All rights reserved.</div><em class="text-xs font-normal text-default-500">Content &amp; Communication Platform</em></div></footer>
    </div>
</div>
<script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" src="/theme/sensecms-icons.js"></script><script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" src="/theme/sensecms-ui.js"></script><script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">document.addEventListener('DOMContentLoaded',()=>window.lucide?.createIcons());</script><script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" type="module" src="/theme/sensecms-admin.js"></script>
</body>
</html>
