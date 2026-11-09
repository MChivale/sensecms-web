<?php declare(strict_types=1); ?>
<title><?= $e($seo['title']) ?></title>
<meta name="description" content="<?= $e($seo['description']) ?>">
<?php foreach (['keywords','author','publisher'] as $name): if (($seo[$name] ?? '') !== ''): ?><meta name="<?= $name ?>" content="<?= $e($seo[$name]) ?>"><?php endif; endforeach; ?>
<meta name="robots" content="<?= $e($seo['robots']) ?>">
<?php foreach (['google'=>'google-site-verification','bing'=>'msvalidate.01','yandex'=>'yandex-verification','pinterest'=>'p:domain_verify'] as $provider=>$name): if (($seo['verification'][$provider] ?? '') !== ''): ?><meta name="<?= $name ?>" content="<?= $e($seo['verification'][$provider]) ?>"><?php endif; endforeach; ?>
<link rel="canonical" href="<?= $e($seo['canonical']) ?>">
<?php foreach ($seo['alternates'] as $code => $url): ?><link rel="alternate" hreflang="<?= $e($code) ?>" href="<?= $e($url) ?>"><?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= $e($seo['x_default']) ?>">
<?php foreach (['title'=>'og_title','description'=>'og_description','type'=>'og_type','url'=>'canonical','site_name'=>'site_name','locale'=>'og_locale'] as $property => $key): ?><meta property="og:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endforeach; ?>
<?php foreach ($seo['og_locale_alternates'] ?? [] as $alternate): ?><meta property="og:locale:alternate" content="<?= $e($alternate) ?>"><?php endforeach; ?>
<?php if (($seo['facebook_app_id'] ?? '') !== ''): ?><meta property="fb:app_id" content="<?= $e($seo['facebook_app_id']) ?>"><?php endif; ?>
<?php if ($seo['og_type'] === 'article'): foreach (['published_time'=>'date_published','modified_time'=>'date_modified'] as $property=>$key): if (($seo[$key] ?? '') !== ''): ?><meta property="article:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endif; endforeach; endif; ?>
<?php if ($seo['image'] !== ''): ?>
<meta property="og:image" content="<?= $e($seo['image']) ?>"><meta property="og:image:secure_url" content="<?= $e($seo['image']) ?>"><meta property="og:image:alt" content="<?= $e($seo['image_alt']) ?>"><?php if ($seo['image_width'] > 0): ?><meta property="og:image:width" content="<?= (int) $seo['image_width'] ?>"><?php endif; ?><?php if ($seo['image_height'] > 0): ?><meta property="og:image:height" content="<?= (int) $seo['image_height'] ?>"><?php endif; ?><?php if ($seo['image_type'] !== ''): ?><meta property="og:image:type" content="<?= $e($seo['image_type']) ?>"><?php endif; ?>
<?php endif; ?>
<?php foreach (['card'=>'twitter_card','title'=>'twitter_title','description'=>'twitter_description'] as $property => $key): ?><meta name="twitter:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endforeach; ?>
<?php foreach (['site'=>'twitter_site','creator'=>'twitter_creator'] as $property=>$key): if (($seo[$key] ?? '') !== ''): ?><meta name="twitter:<?= $property ?>" content="<?= $e($seo[$key]) ?>"><?php endif; endforeach; ?>
<?php if ($seo['twitter_image'] !== ''): ?><meta name="twitter:image" content="<?= $e($seo['twitter_image']) ?>"><meta name="twitter:image:alt" content="<?= $e($seo['twitter_image_alt']) ?>"><?php endif; ?>
<script type="application/ld+json" nonce="<?= $e($_SERVER['SENSE_CSP_NONCE'] ?? '') ?>"><?= json_encode($seo['json_ld'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
