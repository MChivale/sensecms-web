<?php declare(strict_types=1);
$uiText ??= static fn(string $source): string => $source; $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); ?>
<div class="card">
    <div class="card-header"><div><h2 class="card-title">Google Analytics</h2><p class="text-default-500"><?= $h($uiText('GA4 for published CMS pages. No Google request is made before the visitor accepts analytics.')) ?></p></div></div>
    <div class="card-body">
        <form action="/system/extensions/google-analytics" method="post" data-ga-settings>
            <input type="hidden" name="csrf" value="<?= $h($analyticsCsrf) ?>">
            <label class="block mb-2" for="ga-measurement"><?= $h($uiText('Measurement ID')) ?></label>
            <input class="form-input mb-4" id="ga-measurement" name="measurement_id" value="<?= $h($analyticsId) ?>" maxlength="22" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false" aria-describedby="ga-help">
            <p id="ga-help" class="text-default-500 mb-4"><?= $h($uiText('Accepts the complete G- identifier or its suffix. Leave empty to disable analytics. Signed-in administrators, previews and system routes are excluded. Standalone theme fallback pages are not tracked.')) ?></p>
            <p class="text-default-500 mb-4"><?= $h($uiText('Analytics consent expires after 180 days and can be withdrawn using the visitor preference button. Advertising consent remains denied. Review the property\'s Enhanced Measurement settings and your privacy notice before enabling analytics.')) ?></p>
            <button class="btn bg-primary text-white" type="submit"><?= $h($uiText('Save configuration')) ?></button>
            <a class="btn" href="/system/extensions"><?= $h($uiText('Installed extensions')) ?></a>
            <p role="status" aria-live="polite" data-ga-result></p>
        </form>
    </div>
</div>
<script src="/extension-assets/plugin/google-analytics/settings.js?v=0.1.1" defer></script>
