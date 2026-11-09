<?php declare(strict_types=1); $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); $uiText ??= static fn(string $message, array $args=[]): string => strtr($message,$args); $rules=is_array($redirectRules??null)?$redirectRules:[]; ?>
<div class="card">
    <div class="card-header"><div><h2 class="card-title">Legacy Redirects</h2><p class="text-default-500"><?= $h($uiText('Send old paths and aliases to a current page or an HTTPS address.')) ?></p></div></div>
    <div class="card-body">
        <form action="/system/extensions/legacy-redirects" method="post" data-redirect-settings>
            <input type="hidden" name="csrf" value="<?= $h($redirectCsrf??'') ?>">
            <div data-redirect-rules>
                <?php foreach($rules as $index=>$rule): ?>
                    <fieldset class="card mb-4" data-redirect-rule>
                        <legend class="card-title"><?= $h($uiText('Redirect group {number}', ['{number}'=>(string)((int)$index+1)])) ?></legend>
                        <label class="block mb-2" for="redirect-aliases-<?= (int)$index ?>"><?= $h($uiText('Alias paths, one per line')) ?></label>
                        <textarea class="form-input mb-4" id="redirect-aliases-<?= (int)$index ?>" name="rules[<?= (int)$index ?>][aliases]" rows="4" maxlength="51200" required><?= $h(implode("\n",(array)($rule['aliases']??[]))) ?></textarea>
                        <label class="block mb-2" for="redirect-destination-<?= (int)$index ?>"><?= $h($uiText('Destination path or HTTPS URL')) ?></label>
                        <input class="form-input mb-4" id="redirect-destination-<?= (int)$index ?>" name="rules[<?= (int)$index ?>][destination]" value="<?= $h($rule['destination']??'') ?>" maxlength="2048" placeholder="<?= $h($uiText('/new-page/ or https://example.com/page')) ?>" required>
                        <label class="block mb-2" for="redirect-status-<?= (int)$index ?>"><?= $h($uiText('Redirect type')) ?></label>
                        <select class="form-input mb-4" id="redirect-status-<?= (int)$index ?>" name="rules[<?= (int)$index ?>][status]"><option value="301"<?= (int)($rule['status']??301)===301?' selected':'' ?>><?= $h($uiText('301 — permanent')) ?></option><option value="302"<?= (int)($rule['status']??301)===302?' selected':'' ?>><?= $h($uiText('302 — temporary')) ?></option></select>
                        <button class="btn" type="button" data-redirect-remove><?= $h($uiText('Remove group')) ?></button>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <p class="text-default-500 mb-4" data-redirect-empty<?= $rules?' hidden':'' ?>><?= $h($uiText('No redirect groups yet. Add a group to begin.')) ?></p>
            <div class="flex gap-2 mb-4"><button class="btn" type="button" data-redirect-add><?= $h($uiText('Add redirect group')) ?></button><button class="btn bg-primary text-white" type="submit"><?= $h($uiText('Save redirects')) ?></button><a class="btn" href="/system/extensions"><?= $h($uiText('Installed extensions')) ?></a></div>
            <p class="text-default-500 mb-4"><?= $h($uiText('Each group can have up to 25 alias paths that share one destination. Use up to 100 groups and 500 aliases total. Local paths start with one slash; external destinations must use HTTPS. Alias matching is exact and ignores query parameters.')) ?></p>
            <p role="status" aria-live="polite" data-redirect-result></p>
        </form>
    </div>
</div>
<template data-redirect-template>
    <fieldset class="card mb-4" data-redirect-rule>
        <legend class="card-title"><?= $h($uiText('Redirect group')) ?></legend>
        <label class="block mb-2" for="redirect-aliases-__INDEX__"><?= $h($uiText('Alias paths, one per line')) ?></label>
        <textarea class="form-input mb-4" id="redirect-aliases-__INDEX__" name="rules[__INDEX__][aliases]" rows="4" maxlength="51200" required></textarea>
        <label class="block mb-2" for="redirect-destination-__INDEX__"><?= $h($uiText('Destination path or HTTPS URL')) ?></label>
        <input class="form-input mb-4" id="redirect-destination-__INDEX__" name="rules[__INDEX__][destination]" maxlength="2048" placeholder="<?= $h($uiText('/new-page/ or https://example.com/page')) ?>" required>
        <label class="block mb-2" for="redirect-status-__INDEX__"><?= $h($uiText('Redirect type')) ?></label>
        <select class="form-input mb-4" id="redirect-status-__INDEX__" name="rules[__INDEX__][status]"><option value="301"><?= $h($uiText('301 — permanent')) ?></option><option value="302"><?= $h($uiText('302 — temporary')) ?></option></select>
        <button class="btn" type="button" data-redirect-remove><?= $h($uiText('Remove group')) ?></button>
    </fieldset>
</template>
<script src="/extension-assets/plugin/legacy-redirects/settings.js?v=0.2.1" defer></script>
