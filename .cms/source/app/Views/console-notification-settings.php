<?php declare(strict_types=1);
$channelSlugs=array_column($notificationChannels,'slug');
$enabledCount=count(array_filter($notificationChannels,static fn(array $channel):bool=>$channel['enabled']&&!empty($channel['verified_at'])&&!$channel['last_error']));
?>
<section class="sensecms-unified-workspace sensecms-channel-workspace">
<?php $sectionHero=['overline'=>'SYSTEM · COMMUNICATION','title'=>'Notification channels','description'=>'Connect your team to the updates that matter. Manage delivery here; each person controls their own connected account.','icon'=>'bell-ring','action_url'=>'/settings','action_label'=>'My notification settings','action_icon'=>'settings-2'];require __DIR__.'/console-section-hero.php'; ?>
<div class="sensecms-channel-summary" aria-label="Delivery overview">
    <article><i data-lucide="radio"></i><div><strong><?= $enabledCount ?></strong><span>Verified plugin channels</span></div></article>
    <article><i data-lucide="monitor-smartphone"></i><div><strong><?= (int)($webPushStats['devices']??0) ?></strong><span>Subscribed browser devices</span></div></article>
    <article><i data-lucide="send"></i><div><strong><?= in_array('telegram-notifications',$channelSlugs,true)?($telegramRecipients===null?'Unavailable':(int)$telegramRecipients):'—' ?></strong><span>Connected Telegram accounts</span></div></article>
</div>
<div class="sensecms-channel-grid">
    <article class="card sensecms-channel-card">
        <header class="card-header"><div class="sensecms-channel-heading"><span class="sensecms-channel-icon"><i data-lucide="monitor-smartphone"></i></span><div><span class="sensecms-section-overline">BUILT INTO CORE</span><h3>Browser Web Push</h3></div></div><span class="sensecms-channel-badge">Included</span></header>
        <div class="card-body"><p>Deliver encrypted notifications directly to browsers that users have subscribed from My settings.</p>
            <dl class="sensecms-channel-metrics"><div><dt>Devices</dt><dd><?= (int)($webPushStats['devices']??0) ?></dd></div><div><dt>Users</dt><dd><?= (int)($webPushStats['users']??0) ?></dd></div><div><dt>Last accepted delivery</dt><dd><?= $escape($webPushStats['last_success_at']??'Not sent yet') ?></dd></div></dl>
            <div class="sensecms-channel-note"><i data-lucide="shield-check"></i><p>Browser permission is required. Expired subscriptions are deactivated automatically; encryption keys stay protected.</p></div>
            <div class="sensecms-channel-results"><?php foreach($webPushDeliveries as $delivery): ?><span><?= $escape(['sent'=>'Accepted by push service','expired'=>'Rejected: expired subscription','pending'=>'Queued','failed'=>'Rejected','unknown'=>'Unconfirmed','skipped'=>'Skipped'][$delivery['status']]??ucfirst($delivery['status'])) ?> <b><?= (int)$delivery['total'] ?></b></span><?php endforeach; ?><?php if(!$webPushDeliveries): ?><span>No deliveries recorded yet</span><?php endif; ?></div>
            <?php if(array_filter($webPushDeliveries,static fn(array $row):bool=>$row['status']==='expired')): ?><p class="text-warning" role="status">Expired counts are unsuccessful delivery attempts, not delivered notifications or a count of devices. Open My settings and enable Web Push again to renew an expired browser subscription. Previous failures remain in the delivery history.</p><?php endif; ?>
        </div><footer class="sensecms-channel-footer"><a href="/settings" class="btn bg-primary text-white"><i data-lucide="settings-2"></i>Manage my devices</a></footer>
    </article>
    <?php foreach($notificationChannels as $channel): $telegram=$channel['slug']==='telegram-notifications';$ready=$channel['enabled']&&!empty($channel['verified_at'])&&!$channel['last_error']; ?>
    <form method="post" action="/system/notifications" class="card sensecms-channel-card" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="slug" value="<?= $escape($channel['slug']) ?>">
        <header class="card-header"><div class="sensecms-channel-heading"><span class="sensecms-channel-icon"><i data-lucide="<?= $escape($channel['icon']) ?>"></i></span><div><span class="sensecms-section-overline">INSTALLED PLUGIN</span><h3><?= $escape($channel['name']) ?></h3></div></div><span class="sensecms-channel-badge <?= $ready?'is-ready':'' ?>"><?= $ready?'Enabled':'Disabled' ?></span></header>
        <div class="card-body">
            <?php if($telegram): ?>
            <p>Private notifications through the Sense CMS bot. No bot tokens to copy and no Calendar dependency.</p>
            <dl class="sensecms-channel-metrics"><div><dt>Connected accounts</dt><dd><?= $telegramRecipients===null?'Unavailable':(int)$telegramRecipients ?></dd></div><div><dt>Connection</dt><dd>Personal opt-in</dd></div><div><dt>Last verified</dt><dd><?= $escape($channel['verified_at']??'Not verified yet') ?></dd></div></dl>
            <div class="sensecms-channel-note"><i data-lucide="qr-code"></i><p>Open <a href="/settings">My settings</a>, choose Connect Telegram and press Start in the bot. The one-time link connects only your account in this installation.</p></div>
            <?php else: ?><p><?= $escape($channel['description']) ?></p><?php endif; ?>
            <?php foreach($channel['fields'] as $field): ?><label class="sensecms-channel-field"><span><?= $escape($field['label']) ?></span><?php if($field['name']==='recipient_map'): ?><textarea name="recipient_map" class="form-input" rows="4" maxlength="4096" spellcheck="false"><?= $escape($field['value']) ?></textarea><small>Map user IDs to private destinations for recipients who have opted in. Maximum 100 recipients.</small><?php else: ?><input class="form-input" name="<?= $escape($field['name']) ?>" type="<?= $escape($field['type']) ?>" value="<?= $escape($field['value']) ?>" maxlength="4096" autocomplete="new-password" <?= $field['type']==='password'?'placeholder="'.($field['configured']?'Stored securely; leave blank to keep':'Not configured').'"':'' ?>><?php endif; ?></label><?php endforeach; ?>
            <label class="sensecms-channel-check"><input class="form-checkbox" type="checkbox" name="consent_confirmed" value="1" <?= !empty($channel['consent_confirmed'])?'checked':'' ?>><span><b>Confirm recipient consent</b><small><?= $telegram?'Only users who connect the bot themselves can receive notifications authorized for them.':'Every listed recipient has agreed to receive notifications through this channel.' ?></small></span></label>
            <label class="sensecms-channel-check"><input class="form-checkbox" type="checkbox" name="enabled" value="1" <?= $channel['enabled']?'checked':'' ?>><span><b>Enable this channel</b><small>Saving an enabled channel verifies its connection before activation.</small></span></label>
            <?php if($channel['last_error']): ?><p role="alert" class="text-danger"><?= $escape($channel['last_error']) ?></p><?php endif; ?>
            <div class="sensecms-channel-results"><?php foreach($notificationDeliveries as $delivery): if($delivery['plugin_slug']===$channel['slug']): ?><span><?= $escape(ucfirst($delivery['status'])) ?> <b><?= (int)$delivery['total'] ?></b></span><?php endif; endforeach; ?></div>
        </div><footer class="sensecms-channel-footer"><button type="submit" class="btn bg-primary text-white"><i data-lucide="save"></i>Save channel</button><?php if($telegram): ?><a href="/settings" class="btn bg-default-100 text-default-700"><i data-lucide="qr-code"></i>Connect my Telegram</a><?php endif; ?></footer>
    </form>
    <?php endforeach; ?>
    <?php if(!in_array('telegram-notifications',$channelSlugs,true)): ?>
    <article class="card sensecms-channel-card"><header class="card-header"><div class="sensecms-channel-heading"><span class="sensecms-channel-icon"><i data-lucide="send"></i></span><div><span class="sensecms-section-overline">OPTIONAL PLUGIN</span><h3>Telegram Notifications</h3></div></div><span class="sensecms-channel-badge">Not active</span></header><div class="card-body"><p>Connect personal Telegram accounts with a one-time link. Install and activate the licensed plugin to make this channel available.</p></div><footer class="sensecms-channel-footer"><a href="/system/extensions" class="btn bg-primary text-white"><i data-lucide="package"></i>Manage extensions</a></footer></article>
    <?php endif; ?>
</div>
<aside class="sensecms-channel-note"><i data-lucide="info"></i><p><strong>One system, independent channels.</strong> Notifications cover permitted forms, live chat, surveys, workflow and system updates. Calendar alerts become available when Calendar is installed. Uncertain delivery results require operator review and are not automatically sent again.</p></aside>
<?php if(!empty($telegramProfileManage)): ?>
<article class="card sensecms-channel-card">
    <header class="card-header"><div class="sensecms-channel-heading"><span class="sensecms-channel-icon"><i data-lucide="image"></i></span><div><span class="sensecms-section-overline">OFFICIAL BOT · OWNER ONLY</span><h3>Sense CMS bot appearance</h3></div></div><span class="sensecms-channel-badge">@SenseCMSBot</span></header>
    <div class="card-body">
        <p>This is the shared bot identity seen by every connected Sense CMS installation. Personal Telegram accounts are not changed.</p>
        <div class="sensecms-channel-note"><img src="/system/notifications/telegram/profile-photo" alt="Current Sense CMS bot profile photo" width="96" height="96"><p>Default: Sense CMS logo. Upload a square image, or restore the default logo below. PNG, JPG or WebP, up to 2 MB, 128–4096 pixels.</p></div>
        <form action="/system/notifications/telegram/profile-photo" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label class="sensecms-channel-field"><span>New bot profile photo</span><input class="form-input" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required></label><button class="btn bg-primary text-white" type="submit"><i data-lucide="upload"></i>Update bot photo</button></form>
        <p>Chat wallpaper is controlled in Telegram. The Bot API does not offer a method to set a private conversation background.</p>
    </div>
    <footer class="sensecms-channel-footer"><form action="/system/notifications/telegram/profile-photo/remove" method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn bg-default-100 text-default-700" type="submit"><i data-lucide="rotate-ccw"></i>Restore Sense CMS logo</button></form></footer>
</article>
<?php endif; ?>
<?php if(array_filter($notificationChannels,static fn(array $channel):bool=>$channel['delivery']!=='central')): ?><details class="card sensecms-channel-card"><summary class="card-header">Recipient directory</summary><div class="card-body sensecms-channel-results"><?php foreach($notificationUsers as $recipient): ?><span><code><?= (int)$recipient['id'] ?></code> · <?= $escape($recipient['name']) ?></span><?php endforeach; ?></div></details><?php endif; ?>
</section>
