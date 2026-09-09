<?php declare(strict_types=1); ?>
<title><?= $e($seo['title']) ?></title>
<meta name="description" content="<?= $e($seo['description']) ?>">
<meta name="robots" content="<?= $e($seo['robots']) ?>">
<link rel="canonical" href="<?= $e($seo['canonical']) ?>">
<?php foreach ($seo['alternates'] as $code => $url): ?><link rel="alternate" hreflang="<?= $e($code) ?>" href="<?= $e($url) ?>"><?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= $e($seo['x_default']) ?>">
<?php foreach (['title'=>'og_title','description'=>'og_description','type'=>'og_type','url'=>'canonical','site_name'=>'site_name','locale'=>'og_locale'] as $property => $key): ?><meta property="og:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endforeach; ?>
<?php if ($seo['image'] !== ''): ?>
<meta property="og:image" content="<?= $e($seo['image']) ?>"><meta property="og:image:alt" content="<?= $e($seo['image_alt']) ?>">
<?php endif; ?>
<?php foreach (['card'=>'twitter_card','title'=>'twitter_title','description'=>'twitter_description'] as $property => $key): ?><meta name="twitter:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endforeach; ?>
<?php if ($seo['twitter_image'] !== ''): ?><meta name="twitter:image" content="<?= $e($seo['twitter_image']) ?>"><meta name="twitter:image:alt" content="<?= $e($seo['twitter_image_alt']) ?>"><?php endif; ?>
<script type="application/ld+json" nonce="<?= $e($_SERVER['SENSE_CSP_NONCE'] ?? '') ?>"><?= json_encode($seo['json_ld'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
