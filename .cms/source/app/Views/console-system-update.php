<?php $state=$updateState; ?>
<section class="sensecms-update" data-system-update data-csrf="<?= $escape($csrf) ?>">
<?php $sectionHero=['overline'=>'SYSTEM · STABLE RELEASES','title'=>'Update','description'=>'Signed release checks, automatic notifications and safe operator deployment.','icon'=>'refresh-cw','status'=>'Stable release channel','status_detail'=>'Checked automatically every six hours','status_icon'=>'shield-check'];require __DIR__.'/console-section-hero.php'; ?>
<div class="sensecms-update-grid">
    <article class="sensecms-update-main">
        <span class="sensecms-update-symbol"><i data-lucide="package-check"></i></span>
        <span class="sensecms-update-kicker">SENSE CMS</span>
        <h2 data-update-heading><?= $escape(!$state['verified']?$uiText('Release status needs verification'):($state['available']?$uiText('A Stable Core update is available'):(!$state['latest']?$uiText('No Stable Core release has been published'):$uiText('No newer Stable Core release is listed')))) ?></h2>
        <p><?= $escape($uiText('Installed build')) ?> <strong data-update-version><?= $escape($state['version']) ?></strong></p>
        <p data-update-release><?= $escape($state['latest']?$uiText('Latest Stable: ').$state['latest']['version']:$uiText('Development builds are not presented as Stable releases.')) ?></p>
        <div class="sensecms-update-actions">
            <button type="button" class="btn bg-default-150" data-update-check<?= !$state['supported']?' disabled':'' ?> aria-describedby="sensecms-update-reason"><i data-lucide="refresh-cw"></i><?= $escape($uiText('Check for updates')) ?></button>
            <button type="button" class="btn bg-primary text-white" data-update-install disabled aria-describedby="sensecms-update-reason"><i data-lucide="download"></i><?= $escape($uiText('Install update')) ?></button>
        </div>
        <p id="sensecms-update-reason" role="status" aria-live="polite" data-update-message><?= $escape($state['error'] ?? $uiText('Checks download signed public metadata only. Core installation remains operator-managed.')) ?></p>
        <?php if(!$state['compatible']): ?><p role="alert">The latest Stable release requires PHP <?= $escape($state['latest']['php_min']) ?> or newer. Review compatibility before deployment.</p><?php endif; ?>
    </article>
    <aside class="sensecms-update-aside">
        <h3><?= $escape($uiText('Operator deployment requirements')) ?></h3>
        <ol>
            <li><i data-lucide="badge-check"></i><div><strong><?= $escape($uiText('Verify in isolation')) ?></strong><p><?= $escape($uiText('Test the exact candidate and its compatibility with the installed Core and packages.')) ?></p></div></li>
            <li><i data-lucide="database-backup"></i><div><strong><?= $escape($uiText('Protect recovery')) ?></strong><p><?= $escape($uiText('Back up source, database and private encryption keys before deployment.')) ?></p></div></li>
            <li><i data-lucide="shield-check"></i><div><strong><?= $escape($uiText('Verify the deployment')) ?></strong><p><?= $escape($uiText('Check file hashes, application behaviour, service health and fresh logs.')) ?></p></div></li>
        </ol>
        <small data-update-checked><?= $state['checked_at']?$uiText('Last successful check: ').$escape(gmdate('Y-m-d H:i \U\T\C',(int)$state['checked_at'])):$uiText('No successful release check yet.') ?></small>
        <a href="/marketplace"><?= $escape($uiText('Browse the official Marketplace')) ?> <span aria-hidden="true">→</span></a>
    </aside>
</div>
<script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" type="application/json" data-update-bootstrap><?= json_encode($state,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
</section>
