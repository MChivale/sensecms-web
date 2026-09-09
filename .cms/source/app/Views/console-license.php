<?php
declare(strict_types=1);
$valid = !empty($licenseStatus);
$sectionHero = ['overline'=>'SYSTEM · INSTALLATION','title'=>'License','description'=>'Validate the license for this Sense CMS installation.','icon'=>'badge-check','status'=>$valid?'License active':'Validation required','status_detail'=>$valid?'Chivale · Sense CMS System':'The public website requires a valid license.'];
require __DIR__ . '/console-section-hero.php';
?>
<div class="grid gap-5 xl:grid-cols-3">
    <section class="card xl:col-span-2"><div class="card-header"><h6 class="card-title">Installation license</h6></div>
        <form class="card-body grid gap-5" method="post" action="/license">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <label><span class="mb-2 block">License key</span><input class="form-input" type="password" name="license_key" required autocomplete="off" maxlength="200"></label>
            <p class="text-sm text-default-500">The current license is retained until the replacement is validated. The key is encrypted in private server storage.</p>
            <div><button class="btn bg-primary text-white" type="submit"><i data-lucide="shield-check"></i>Validate and install</button></div>
        </form>
    </section>
    <aside class="card h-fit"><div class="card-header"><h6 class="card-title">Sense CMS System</h6></div><div class="card-body space-y-4">
        <p>Issuer: Chivale</p><p>Status: <?= $valid ? 'Validated' : 'Validation required' ?></p>
        <?php if ($valid): ?><p>Expiry: <?= isset($licenseStatus['valid_until']) ? $escape(gmdate('Y-m-d', $licenseStatus['valid_until'])) : 'No expiry date' ?></p><?php endif; ?>
    </div></aside>
</div>
