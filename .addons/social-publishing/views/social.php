<?php
declare(strict_types=1);
$uiText ??= static fn(string $source,array $values=[]): string => strtr($source,$values);

$socialPublishing = is_array($socialPublishing ?? null) ? $socialPublishing : [];
$providers = (array)($socialPublishing['providers'] ?? []);
$counts = (array)($socialPublishing['counts'] ?? []);
$deliveries = (array)($socialPublishing['deliveries'] ?? []);
$connectedDestinations = array_sum(array_map(static fn(array $provider): int => (int)($provider['connected_count'] ?? (!empty($provider['connected']) ? 1 : 0)), $providers));
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$stats = [
    'pending' => [$uiText('Queued'), 'clock-3'],
    'processing' => [$uiText('Publishing'), 'loader-circle'],
    'published' => [$uiText('Published'), 'circle-check-big'],
    'failed' => [$uiText('Needs attention'), 'triangle-alert'],
];
?>
<div class="sensecms-unified-workspace social-publishing-page">
    <header class="sensecms-section-hero social-publishing-hero">
        <div class="sensecms-section-hero-main">
            <span class="sensecms-section-hero-icon"><i data-lucide="send"></i></span>
            <div>
                <span class="sensecms-section-overline"><?= $h($uiText('Content distribution')) ?></span>
                <h2><?= $h($uiText('Social Publishing')) ?></h2>
                <p><?= $h($uiText('Connect each network through its own provider and explicitly choose destinations while reviewing a post.')) ?></p>
            </div>
        </div>
        <div class="social-hero-actions">
            <span class="social-hero-summary"><i data-lucide="shield-check"></i><span><strong><?= $h($uiText('Connected destinations: {count}', ['{count}'=>(string)$connectedDestinations])) ?></strong><small><?= $h($uiText('Publishing destinations')) ?></small></span></span>
            <a class="btn bg-primary text-white" href="/content/posts"><i data-lucide="square-pen"></i><?= $h($uiText('Open posts')) ?></a>
        </div>
    </header>

    <section class="social-publishing-stats" aria-label="<?= $h($uiText('Delivery status')) ?>">
        <?php foreach ($stats as $key => [$label, $icon]): ?>
            <article class="social-stat is-<?= $h($key) ?>">
                <span class="social-stat-icon"><i data-lucide="<?= $h($icon) ?>"></i></span>
                <span><small><?= $h($label) ?></small><strong><?= (int)($counts[$key] ?? 0) ?></strong></span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="card social-panel">
        <header class="card-header social-panel-header">
            <div class="social-panel-title">
                <span><i data-lucide="share-2"></i></span>
                <div><h2 class="card-title"><?= $h($uiText('Networks')) ?></h2><p><?= $h($uiText('Each network is managed by its own provider plugin.')) ?></p></div>
            </div>
            <span class="social-security-note"><i data-lucide="lock-keyhole"></i><?= $h($uiText('Tokens encrypted locally')) ?></span>
        </header>
        <div class="card-body social-provider-list">
            <?php if (!$providers): ?>
                <div class="social-empty"><span><i data-lucide="plug"></i></span><strong><?= $h($uiText('No social provider installed')) ?></strong><p><?= $h($uiText('Install a Facebook, Instagram, X or another provider plugin to add a destination.')) ?></p></div>
            <?php endif; ?>
            <?php foreach ($providers as $provider): ?>
                <?php $connected = !empty($provider['connected']) && !empty($provider['enabled']); ?>
                <article class="social-provider">
                    <div class="social-provider-identity">
                        <span class="social-provider-icon"><i data-lucide="<?= $h($provider['icon'] ?? 'send') ?>"></i></span>
                        <div><strong><?= $h($provider['label'] ?? $provider['name'] ?? $provider['slug']) ?></strong><span><?= $h($connected ? ((int)($provider['connected_count'] ?? 1).' '.((int)($provider['connected_count'] ?? 1) === 1 ? 'destination connected' : 'destinations connected')) : $uiText('Connect an account to start publishing')) ?></span><?php if (!empty($provider['last_error'])): ?><small class="social-provider-error"><?= $h($provider['last_error']) ?></small><?php endif; ?></div>
                    </div>
                    <span class="social-status <?= $connected ? 'is-connected' : 'is-disconnected' ?>"><i data-lucide="<?= $connected ? 'circle-check' : 'circle-dashed' ?>"></i><?= $connected ? $uiText('Connected') : $uiText('Setup required') ?></span>
                    <?php if ($socialCanManage && !empty($provider['config_url'])): ?><a class="btn border-default-200" href="<?= $h($provider['config_url']) ?>"><?= $connected ? $uiText('Manage') : $uiText('Connect') ?><i data-lucide="arrow-right"></i></a><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <footer class="social-panel-footer"><i data-lucide="info"></i><?= $h($uiText('Account tokens stay encrypted in this installation. Provider application secrets remain on the official Sense CMS service.')) ?></footer>
    </section>

    <section class="card social-panel social-history">
        <header class="card-header social-panel-header">
            <div class="social-panel-title">
                <span><i data-lucide="history"></i></span>
                <div><h2 class="card-title"><?= $h($uiText('Delivery history')) ?></h2><p><?= $h($uiText('One tracked delivery per selected provider and post revision.')) ?></p></div>
            </div>
            <span class="social-security-note"><i data-lucide="refresh-cw"></i><?= $h($uiText('Bounded retries')) ?></span>
        </header>
        <div class="social-table-wrap"><table class="social-table"><thead><tr><th><?= $h($uiText('Post')) ?></th><th><?= $h($uiText('Provider')) ?></th><th><?= $h($uiText('Status')) ?></th><th><?= $h($uiText('Attempts')) ?></th><th><?= $h($uiText('Created')) ?></th><th><span class="sr-only"><?= $h($uiText('Actions')) ?></span></th></tr></thead><tbody>
        <?php if (!$deliveries): ?><tr><td colspan="6"><div class="social-table-empty"><span><i data-lucide="send-horizontal"></i></span><strong><?= $h($uiText('No deliveries yet')) ?></strong><small><?= $h($uiText('Published social posts and their delivery status will appear here.')) ?></small></div></td></tr><?php endif; ?>
        <?php foreach ($deliveries as $delivery): ?><tr><td><a href="/content/posts/<?= (int)$delivery['post_id'] ?>/edit"><?= $h($delivery['post_title']) ?></a></td><td><strong><?= $h($delivery['destination_display_name'] ?: $delivery['plugin_slug']) ?></strong><?php if (!empty($delivery['destination_external_id'])): ?><small><?= $h($delivery['destination_external_id']) ?></small><?php endif; ?></td><td><span class="social-delivery-status is-<?= $h($delivery['status']) ?>"><?= $h($uiText(ucfirst((string)$delivery['status']))) ?></span><?php if (!empty($delivery['last_error'])): ?><small><?= $h($delivery['last_error']) ?></small><?php endif; ?></td><td><?= (int)$delivery['attempts'] ?></td><td><?= $h($delivery['created_at']) ?></td><td><?php if ($socialCanPublish && $delivery['status'] === 'failed' && !empty($delivery['connection_available'])): ?><form method="post" action="/api/social-publishing/deliveries/<?= (int)$delivery['id'] ?>/retry"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="btn border-default-200" type="submit"><?= $h($uiText('Retry')) ?></button></form><?php elseif (!empty($delivery['external_url'])): ?><a href="<?= $h($delivery['external_url']) ?>" target="_blank" rel="noopener"><?= $h($uiText('View')) ?></a><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
