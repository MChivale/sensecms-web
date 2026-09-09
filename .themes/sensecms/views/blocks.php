<?php
declare(strict_types=1);
foreach ($blocks as $block): $data = (array) ($block['payload'] ?? []);
    if ($block['type'] === 'text'): ?>
<section<?= !empty($featuredResource) && ($data['cta_url'] ?? '') === '/docs/installation' ? ' class="resource-start"' : '' ?>><?php if (isset($blockIcons[$data['cta_url'] ?? ''])): ?><span class="section-symbol" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $blockIcons[$data['cta_url']] ?>"/></svg></span><?php endif; ?><?php if (!empty($data['eyebrow'])): ?><span class="eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?><?php if (!empty($data['title'])): ?><h2><?= $e($data['title']) ?></h2><?php endif; ?><?= \App\Core\HtmlSanitizer::sanitize((string) ($data['text'] ?? '')) ?><?php if (!empty($data['cta_label']) && !empty($data['cta_url'])): ?><?= \App\Core\HtmlSanitizer::sanitize('<a class="text-link" href="' . $e($data['cta_url']) . '">' . $e($data['cta_label']) . '</a>') ?><?php endif; ?></section>
<?php elseif ($block['type'] === 'contact-form' && !empty($formCsrf)): ?>
<?php require __DIR__ . '/contact-form.php'; ?>
<?php elseif ($block['type'] === 'custom-html'): ?>
<section><?= \App\Core\HtmlSanitizer::sanitize((string) ($data['html'] ?? '')) ?></section>
<?php elseif (!empty($block['component_renderer'])): ?>
<?= \App\Core\PluginComponentRenderer::render($projectRoot, $block['component_source'], $block['component_renderer'], ['data' => $data, 'shared' => $block['shared'] ?? [], 'locale' => $locale, 'page' => $page]) ?>
<?php endif; endforeach; ?>
