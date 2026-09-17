<?php
declare(strict_types=1);

$socialPublishing = is_array($socialPublishing ?? null) ? $socialPublishing : [];
$providers = (array)($socialPublishing['providers'] ?? []);
$counts = (array)($socialPublishing['counts'] ?? []);
$deliveries = (array)($socialPublishing['deliveries'] ?? []);
$connectedDestinations = array_sum(array_map(static fn(array $provider): int => (int)($provider['connected_count'] ?? (!empty($provider['connected']) ? 1 : 0)), $providers));
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$stats = [
    'pending' => ['Queued', 'clock-3'],
    'processing' => ['Publishing', 'loader-circle'],
    'published' => ['Published', 'circle-check-big'],
    'failed' => ['Needs attention', 'triangle-alert'],
];
?>
<div class="sensecms-unified-workspace social-publishing-page">
    <header class="sensecms-section-hero social-publishing-hero">
        <div class="sensecms-section-hero-main">
            <span class="sensecms-section-hero-icon"><i data-lucide="send"></i></span>
            <div>
                <span class="sensecms-section-overline">Content distribution</span>
                <h2>Social Publishing</h2>
                <p>Connect each network through its own provider and explicitly choose destinations while reviewing a post.</p>
            </div>
        </div>
        <div class="social-hero-actions">
            <span class="social-hero-summary"><i data-lucide="shield-check"></i><span><strong><?= $connectedDestinations ?> connected</strong><small>Publishing destinations</small></span></span>
            <a class="btn bg-primary text-white" href="/content/posts"><i data-lucide="square-pen"></i>Open posts</a>
        </div>
    </header>

    <section class="social-publishing-stats" aria-label="Delivery status">
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
                <div><h2 class="card-title">Networks</h2><p>Each network is managed by its own provider plugin.</p></div>
            </div>
            <span class="social-security-note"><i data-lucide="lock-keyhole"></i>Tokens encrypted locally</span>
        </header>
        <div class="card-body social-provider-list">
            <?php if (!$providers): ?>
                <div class="social-empty"><span><i data-lucide="plug"></i></span><strong>No social provider installed</strong><p>Install a Facebook, Instagram, X or another provider plugin to add a destination.</p></div>
            <?php endif; ?>
            <?php foreach ($providers as $provider): ?>
                <?php $connected = !empty($provider['connected']) && !empty($provider['enabled']); ?>
                <article class="social-provider">
                    <div class="social-provider-identity">
                        <span class="social-provider-icon"><i data-lucide="<?= $h($provider['icon'] ?? 'send') ?>"></i></span>
                        <div><strong><?= $h($provider['label'] ?? $provider['name'] ?? $provider['slug']) ?></strong><span><?= $h($connected ? ((int)($provider['connected_count'] ?? 1).' '.((int)($provider['connected_count'] ?? 1) === 1 ? 'destination connected' : 'destinations connected')) : 'Connect an account to start publishing') ?></span><?php if (!empty($provider['last_error'])): ?><small class="social-provider-error"><?= $h($provider['last_error']) ?></small><?php endif; ?></div>
                    </div>
                    <span class="social-status <?= $connected ? 'is-connected' : 'is-disconnected' ?>"><i data-lucide="<?= $connected ? 'circle-check' : 'circle-dashed' ?>"></i><?= $connected ? 'Connected' : 'Setup required' ?></span>
                    <?php if ($socialCanManage && !empty($provider['config_url'])): ?><a class="btn border-default-200" href="<?= $h($provider['config_url']) ?>"><?= $connected ? 'Manage' : 'Connect' ?><i data-lucide="arrow-right"></i></a><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <footer class="social-panel-footer"><i data-lucide="info"></i>Account tokens stay encrypted in this installation. The Meta App Secret remains on the official Sense CMS service.</footer>
    </section>

    <section class="card social-panel social-history">
        <header class="card-header social-panel-header">
            <div class="social-panel-title">
                <span><i data-lucide="history"></i></span>
                <div><h2 class="card-title">Delivery history</h2><p>One tracked delivery per selected provider and post revision.</p></div>
            </div>
            <span class="social-security-note"><i data-lucide="refresh-cw"></i>Bounded retries</span>
        </header>
        <div class="social-table-wrap"><table class="social-table"><thead><tr><th>Post</th><th>Provider</th><th>Status</th><th>Attempts</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        <?php if (!$deliveries): ?><tr><td colspan="6"><div class="social-table-empty"><span><i data-lucide="send-horizontal"></i></span><strong>No deliveries yet</strong><small>Published social posts and their delivery status will appear here.</small></div></td></tr><?php endif; ?>
        <?php foreach ($deliveries as $delivery): ?><tr><td><a href="/content/posts/<?= (int)$delivery['post_id'] ?>/edit"><?= $h($delivery['post_title']) ?></a></td><td><strong><?= $h($delivery['destination_display_name'] ?: $delivery['plugin_slug']) ?></strong><?php if (!empty($delivery['destination_external_id'])): ?><small><?= $h($delivery['destination_external_id']) ?></small><?php endif; ?></td><td><span class="social-delivery-status is-<?= $h($delivery['status']) ?>"><?= $h(ucfirst((string)$delivery['status'])) ?></span><?php if (!empty($delivery['last_error'])): ?><small><?= $h($delivery['last_error']) ?></small><?php endif; ?></td><td><?= (int)$delivery['attempts'] ?></td><td><?= $h($delivery['created_at']) ?></td><td><?php if ($socialCanPublish && $delivery['status'] === 'failed' && !empty($delivery['connection_available'])): ?><form method="post" action="/api/social-publishing/deliveries/<?= (int)$delivery['id'] ?>/retry"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="btn border-default-200" type="submit">Retry</button></form><?php elseif (!empty($delivery['external_url'])): ?><a href="<?= $h($delivery['external_url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
