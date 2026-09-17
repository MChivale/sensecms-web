<?php
declare(strict_types=1);
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($isHome ? $siteName : 'Page not found') ?></title><link rel="icon" href="/assets/logo.svg"><link rel="stylesheet" href="/assets/workspace.css"></head>
<body><a class="skip" href="#main">Skip to content</a><main id="main" class="content"><section class="card settings-card" data-public-home>
<a class="brand" href="/"><img src="/assets/logo.svg" alt=""><span>Sense CMS</span></a>
<hr>
<h1><?= $e($isHome ? $siteName : 'Page not found') ?></h1>
<?php if ($isHome): ?>
<p>This website is being prepared.</p><p class="muted">Please check back soon.</p>
<a class="primary" href="/login">Sign in</a>
<?php else: ?>
<p>The requested page is not available.</p><a class="primary" href="/">Go to homepage</a>
<?php endif; ?>
</section></main></body></html>
