<?php
declare(strict_types=1);
$uiText ??= static fn(string $source): string => $source;

$provider = is_array($facebookProvider ?? null) ? $facebookProvider : [];
$connections = array_values(array_filter((array)($provider['connections'] ?? []), 'is_array'));
$connected = $connections !== [];
$oauthRevision = (int)($facebookOAuthRevision ?? 0);
$oauthOutcome = (string)($facebookOAuthOutcome ?? '');
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$steps = [
    ['file-pen-line', $uiText('Prepare'), $uiText('Create and review the post in Sense CMS.')],
    ['list-checks', $uiText('Choose'), $uiText('Select Facebook Page and customize the message if needed.')],
    ['calendar-clock', $uiText('Publish'), $uiText('Send now or schedule one idempotent delivery.')],
    ['activity', $uiText('Monitor'), $uiText('Inspect the result and retry a failed delivery safely.')],
];
?>
<div class="sensecms-unified-workspace social-publishing-page social-facebook-page" data-facebook-page data-facebook-connected="<?= $connected ? '1' : '0' ?>" data-facebook-oauth-revision="<?= $oauthRevision ?>" data-facebook-oauth-outcome="<?= $h($oauthOutcome) ?>">
    <header class="sensecms-section-hero social-publishing-hero is-facebook">
        <div class="sensecms-section-hero-main">
            <span class="sensecms-section-hero-icon"><i data-lucide="facebook"></i></span>
            <div>
                <span class="sensecms-section-overline"><?= $h($uiText('Social Publishing provider')) ?></span>
                <h2><?= $h($uiText('Facebook Page')) ?></h2>
                <p><?= $h($uiText('Connect a Page through the official Sense CMS Meta application. Personal profiles are not publishing destinations.')) ?></p>
            </div>
        </div>
        <div class="social-hero-actions">
            <?php if ($connected): ?><span class="social-hero-summary"><i data-lucide="circle-check"></i><span><strong><?= $h($uiText('{count} connected', ['{count}' => (string) count($connections)])) ?></strong><small><?= $h($uiText(count($connections) === 1 ? 'Facebook Page' : 'Facebook Pages')) ?></small></span></span><?php endif; ?>
            <a class="btn border-default-200" href="/social-publishing"><i data-lucide="arrow-left"></i><?= $h($uiText('Social Publishing')) ?></a>
        </div>
    </header>

    <section class="card social-panel">
        <header class="card-header social-panel-header">
            <div class="social-panel-title">
                <span><i data-lucide="<?= $connected ? 'badge-check' : 'link' ?>"></i></span>
                <div><h2 class="card-title"><?= $connected ? $uiText('Connected Pages') : 'Connect Facebook' ?></h2><p><?= $connected ? $uiText('Connect Pages from one or more Meta accounts, then choose destinations for each post.') : $uiText('Sign in at Meta, choose a Page and approve the requested publishing permission.') ?></p></div>
            </div>
            <form method="post" action="/social-publishing/facebook/connect" data-facebook-connect><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="btn bg-primary text-white" type="submit" data-sensecms-native><i data-lucide="plus"></i><?= $connected ? $uiText('Add Facebook Page') : $uiText('Connect with Facebook') ?></button></form>
        </header>
        <div class="card-body">
            <?php if ($connected): ?>
                <div class="social-account-list">
                <?php foreach ($connections as $connection): ?><article class="social-account-card">
                    <div class="social-account-primary">
                        <span class="social-provider-icon"><i data-lucide="facebook"></i></span>
                        <div><span class="social-account-kicker"><?= $h($uiText('Facebook Page')) ?></span><strong><?= $h($connection['display_name'] ?: $uiText('Facebook Page')) ?></strong><small><?= $h($uiText('An editor can select this destination independently.')) ?></small></div>
                    </div>
                    <dl class="social-account-meta">
                        <div><dt><?= $h($uiText('Connection')) ?></dt><dd><span class="social-status is-connected"><i data-lucide="circle-check"></i><?= $h($uiText('Connected')) ?></span></dd></div>
                        <div><dt><?= $h($uiText('Page ID')) ?></dt><dd><?= $h($connection['external_account_id']) ?></dd></div>
                        <div><dt><?= $h($uiText('Last verified')) ?></dt><dd><?= $h($connection['last_verified_at'] ?: $uiText('Not available')) ?></dd></div>
                    </dl>
                    <form class="social-account-actions" method="post" action="/social-publishing/facebook/connections/<?= (int)$connection['id'] ?>/disconnect"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="btn social-disconnect" type="submit" data-sensecms-native><i data-lucide="unlink"></i><?= $h($uiText('Disconnect')) ?></button></form>
                </article><?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="social-empty social-connect-empty">
                    <span><i data-lucide="facebook"></i></span><strong><?= $h($uiText('No Facebook Page connected')) ?></strong>
                    <p><?= $h($uiText('The Meta login exchanges authorization through the central Sense CMS service. The Meta App Secret is never copied into this CMS installation.')) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card social-panel">
        <header class="card-header social-panel-header">
            <div class="social-panel-title"><span><i data-lucide="route"></i></span><div><h2 class="card-title"><?= $h($uiText('How publishing works')) ?></h2><p><?= $h($uiText('A controlled four-step flow from review to delivery.')) ?></p></div></div>
        </header>
        <div class="card-body"><ol class="social-steps">
            <?php foreach ($steps as $index => [$icon, $title, $description]): ?><li><span class="social-step-number"><?= $index + 1 ?></span><span class="social-step-icon"><i data-lucide="<?= $h($icon) ?>"></i></span><div><strong><?= $h($title) ?></strong><p><?= $h($description) ?></p></div></li><?php endforeach; ?>
        </ol></div>
    </section>

    <div class="facebook-oauth-modal" data-facebook-oauth-modal hidden><section role="dialog" aria-modal="true" aria-labelledby="facebook-oauth-title"><div class="facebook-oauth-icon"><i data-lucide="facebook"></i></div><h2 id="facebook-oauth-title"><?= $h($uiText('Connect Facebook')) ?></h2><p data-facebook-oauth-status aria-live="polite"><?= $h($uiText('Continue securely in the Facebook window. This page will update automatically when you finish.')) ?></p><button class="btn border-default-200" type="button" data-facebook-oauth-cancel><?= $h($uiText('Cancel')) ?></button></section></div>
</div>
