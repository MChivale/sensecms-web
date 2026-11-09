<?php
declare(strict_types=1);
$databaseSettings = is_array($databaseSettings ?? null) ? $databaseSettings : [];
?>
<section class="sensecms-unified-workspace">
    <?php $sectionHero=['overline'=>'SYSTEM · INSTALLATION','title'=>'Settings','description'=>'Manage the installation name and the private database connection used by this Sense CMS system.','icon'=>'settings-2','status'=>'Owner-only settings','status_detail'=>'Database credentials stay private'];require __DIR__.'/console-section-hero.php'; ?>
    <form method="post" action="/system/settings" class="grid gap-5 xl:grid-cols-3">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <section class="card xl:col-span-2">
            <div class="card-header"><div><h6 class="card-title"><?= $escape($uiText('System name')) ?></h6><p class="mt-1 text-sm font-normal text-default-500"><?= $escape($uiText('This name is set during installation and is used across the public site, e-mail and workspace.')) ?></p></div></div>
            <div class="card-body"><label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('System name')) ?></span><input class="form-input" name="system_name" maxlength="120" required value="<?= $escape($systemName) ?>"></label></div>
        </section>
        <aside class="card h-fit"><div class="card-header"><h6 class="card-title"><?= $escape($uiText('Access')) ?></h6></div><div class="card-body"><p class="text-sm text-default-500"><?= $escape($uiText('Only the system owner can view or change these installation settings.')) ?></p></div></aside>
        <section class="card xl:col-span-3">
            <div class="card-header"><div><h6 class="card-title"><?= $escape($uiText('Database connection')) ?></h6><p class="mt-1 text-sm font-normal text-default-500"><?= $escape($uiText('The connection is tested and required Sense CMS tables are checked before any private configuration is replaced.')) ?></p></div><span class="rounded-full bg-default-150 px-3 py-1 text-xs font-medium text-default-600"><?= $escape(($databaseSettings['driver'] ?? '') === 'pgsql' ? 'PostgreSQL' : 'MariaDB') ?></span></div>
            <div class="card-body grid gap-5 md:grid-cols-2">
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('Database engine')) ?></span><select class="form-input" name="db_driver"><option value="pgsql" <?= ($databaseSettings['driver'] ?? '') === 'pgsql' ? 'selected' : '' ?>>PostgreSQL</option><option value="mysql" <?= ($databaseSettings['driver'] ?? '') === 'mysql' ? 'selected' : '' ?>>MariaDB / MySQL</option></select></label>
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('Database host')) ?></span><input class="form-input" name="db_host" maxlength="253" required value="<?= $escape($databaseSettings['host'] ?? '') ?>"></label>
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('Port')) ?></span><input class="form-input" type="number" name="db_port" min="1" max="65535" required value="<?= (int)($databaseSettings['port'] ?? 0) ?>"></label>
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('Database name')) ?></span><input class="form-input" name="db_name" maxlength="64" required value="<?= $escape($databaseSettings['name'] ?? '') ?>"></label>
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('Database user')) ?></span><input class="form-input" name="db_user" maxlength="128" required autocomplete="username" value="<?= $escape($databaseSettings['user'] ?? '') ?>"></label>
                <label class="block"><span class="mb-2 block text-sm font-medium text-default-700"><?= $escape($uiText('New database password')) ?></span><input class="form-input" type="password" name="db_password" maxlength="512" autocomplete="new-password" placeholder="<?= $escape($uiText('Leave blank to keep the current password.')) ?>"><small class="mt-2 block text-xs text-default-500"><?= $escape(!empty($databaseSettings['password_configured']) ? 'A password is configured and remains hidden.' : 'No password is configured.') ?></small></label>
            </div>
            <div class="card-body border-t border-default-200"><p class="mb-4 text-xs text-default-500"><?= $escape($uiText('Changing the database connection does not copy data. Save it only after the target database contains this installation.')) ?></p><div class="flex justify-end"><button class="btn bg-primary text-white" type="submit"><i data-lucide="save" class="size-4"></i><?= $escape($uiText('Verify and save system settings')) ?></button></div></div>
        </section>
    </form>
</section>
