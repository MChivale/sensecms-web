<?php
declare(strict_types=1);
$chatSounds = array_map(static fn(int $number): string => sprintf('notification-%02d.mp3', $number), range(1, 5));
$weekdays = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
$chatTimezones = ['Asia/Phnom_Penh' => 'Phnom Penh (UTC+7)', 'Asia/Bangkok' => 'Bangkok (UTC+7)', 'Asia/Shanghai' => 'Shanghai (UTC+8)', 'UTC' => 'UTC'];
?>
<section class="sensecms-unified-workspace">
<?php
$sectionHero = $screen === 'conversations' ? [
    'overline' => 'ENGAGEMENT · REAL-TIME SUPPORT',
    'title' => 'Live chat',
    'description' => 'Respond to visitors, coordinate operators and keep every active conversation visible in one focused support workspace.',
    'icon' => 'messages-square',
    'status' => count($conversations) . ' conversations',
    'status_detail' => ($liveChatUnread ?? 0) > 0 ? (int) $liveChatUnread . ' unread messages' : 'Inbox is up to date',
    'status_icon' => ($liveChatUnread ?? 0) > 0 ? 'message-circle-more' : 'circle-check',
] : [
    'overline' => 'ENGAGEMENT · LIVE CHAT CONFIGURATION',
    'title' => 'AI & live support settings',
    'description' => 'Manage multilingual chat content, operator teams, availability, sounds, appearance and conversation lifecycle from one place.',
    'icon' => 'sliders-horizontal',
    'status' => 'Configuration ready',
    'status_detail' => count($languages) . ' active languages',
];
require __DIR__ . '/console-section-hero.php';
?>
<nav class="sensecms-chat-nav" aria-label="Live chat sections">
    <a href="/conversations" class="<?= $screen === 'conversations' ? 'is-active' : '' ?>"><i data-lucide="inbox"></i>Inbox<?php if (($liveChatUnread ?? 0) > 0): ?><b data-chat-nav-unread><?= min(99, (int) $liveChatUnread) ?></b><?php endif; ?></a>
    <a href="/conversations/configuration" class="<?= $screen === 'live-chat-config' ? 'is-active' : '' ?>"><i data-lucide="sliders-horizontal"></i>Configuration</a>
</nav>

<?php if ($screen === 'conversations'): ?>
<section class="card sensecms-chat-shell" data-operator-chat data-conversation-id="<?= $escape($conversation['id'] ?? '') ?>">
    <aside class="sensecms-chat-sidebar">
        <header class="sensecms-chat-sidebar-head">
            <div><h6>Chats</h6><span><?= count($conversations) ?></span></div>
            <label><i data-lucide="search"></i><input id="chat-filter" type="search" placeholder="Search conversations" autocomplete="off"></label>
        </header>
        <div class="sensecms-chat-list">
            <?php foreach ($conversations as $item): $itemName = $item['visitor_name'] ?: ($item['visitor_email'] ?: 'Website visitor'); $unread = (int) ($item['unread_count'] ?? 0); ?>
            <a data-chat-item data-conversation-id="<?= $escape($item['id']) ?>" href="/conversations?conversation=<?= rawurlencode($item['id']) ?>" class="sensecms-chat-item <?= ($conversation['id'] ?? '') === $item['id'] ? 'is-active' : '' ?> <?= $unread ? 'has-unread' : '' ?>">
                <span><?= $escape(mb_strtoupper(mb_substr($itemName, 0, 1))) ?></span>
                <div><header><strong><?= $escape($itemName) ?></strong><small class="<?= $statusClass($item['status']) ?>"><?= $escape($item['status']) ?></small></header><p><?= $escape($item['last_message'] ?? 'No messages yet') ?></p><footer><?php if (!empty($item['team_name'])): ?><em style="--team-color:<?= $escape($item['team_color']) ?>"><i data-lucide="users-round"></i><?= $escape($item['team_name']) ?></em><?php endif; ?><?php if ($unread): ?><b data-chat-item-unread><?= min(99, $unread) ?></b><?php endif; ?></footer></div>
            </a>
            <?php endforeach; ?>
            <?php if (!$conversations): ?><div class="sensecms-chat-empty"><i data-lucide="messages-square"></i><strong>No conversations yet</strong><span>New visitor chats will appear here.</span></div><?php endif; ?>
        </div>
    </aside>
    <div class="sensecms-chat-workspace">
        <?php if ($conversation): $name = $conversation['visitor_name'] ?: ($conversation['visitor_email'] ?: 'Website visitor'); ?>
        <header class="sensecms-chat-conversation-head">
            <div class="sensecms-chat-person"><span><?= $escape(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><div><h6><?= $escape($name) ?></h6><p><?= $escape($conversation['visitor_email'] ?: 'Website visitor') ?> · <?= $escape($conversation['channel']) ?> support<?= !empty($conversation['team_name']) ? ' · ' . $escape($conversation['team_name']) : '' ?><?= !empty($conversation['agent_name']) ? ' · ' . $escape($conversation['agent_name']) : '' ?></p></div></div>
            <div class="sensecms-chat-head-actions">
                <small class="<?= $statusClass($conversation['status']) ?>"><?= $escape($conversation['status']) ?></small>
                <div class="sensecms-chat-export" data-chat-export><button type="button" data-chat-export-toggle aria-label="Export transcript"><i data-lucide="download"></i></button><div hidden><button type="button" data-chat-export-format="txt"><i data-lucide="file-text"></i><span>Text transcript<small>Readable .txt file</small></span></button><button type="button" data-chat-export-format="json"><i data-lucide="braces"></i><span>Structured data<small>Complete .json export</small></span></button></div></div>
                <button type="button" class="sensecms-chat-transfer-button" data-chat-transfer-open aria-label="Transfer conversation"><i data-lucide="forward"></i></button>
                <button type="button" class="sensecms-chat-delete" data-chat-delete aria-label="Delete conversation"><i data-lucide="trash-2"></i></button>
            </div>
        </header>
        <div class="sensecms-chat-messages" data-operator-messages aria-live="polite">
            <?php foreach ($conversation['messages'] as $message): $operator = $message['role'] === 'agent'; ?>
            <div class="sensecms-operator-message <?= $operator ? 'is-agent' : 'is-visitor' ?>"><article><p><?= $escape($message['content']) ?></p><small><?= $escape($operator ? ($message['agent_name'] ?: $conversation['agent_name'] ?: 'Support team') : $message['role']) ?> · <?= $escape($message['created_at']) ?></small></article></div>
            <?php endforeach; ?>
        </div>
        <form method="post" action="/conversations/<?= rawurlencode($conversation['id']) ?>/messages" class="sensecms-chat-composer" data-operator-message-form>
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <textarea required maxlength="4000" name="content" rows="2" placeholder="Write a reply…" aria-label="Reply"></textarea>
            <button type="submit" aria-label="Send reply"><i data-lucide="send"></i></button>
            <label class="sensecms-enter-send"><input type="checkbox" data-enter-send><span><strong>Send with Enter</strong><small>Use Shift + Enter for a new line</small></span></label>
        </form>
        <div class="sensecms-transfer-layer" data-chat-transfer-panel hidden>
            <form action="/conversations/<?= rawurlencode($conversation['id']) ?>/transfer" method="post" class="sensecms-transfer-card" data-chat-transfer-form>
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <header><span><i data-lucide="forward"></i></span><div><small>Conversation routing</small><h3>Transfer conversation</h3><p>Move this chat to a specific operator or team without losing its context.</p></div><button type="button" data-chat-transfer-close aria-label="Close"><i data-lucide="x"></i></button></header>
                <label><span>Destination</span><select required name="target" class="form-input"><option value="">Choose an operator or team</option><?php if ($chatTeams): ?><optgroup label="Teams"><?php foreach ($chatTeams as $team): ?><option value="team:<?= (int) $team['id'] ?>"><?= $escape($team['name']) ?> · <?= (int) $team['member_count'] ?> members</option><?php endforeach; ?></optgroup><?php endif; ?><optgroup label="Operators"><?php foreach ($chatUsers as $chatUser): if ((int) $chatUser['id'] === (int) ($user['id'] ?? 0)) continue; ?><option value="user:<?= (int) $chatUser['id'] ?>"><?= $escape($chatUser['name']) ?> · <?= $escape($chatUser['email']) ?></option><?php endforeach; ?></optgroup></select></label>
                <label><span>Internal note <small>optional</small></span><textarea maxlength="500" rows="3" name="note" class="form-input" placeholder="Add context for the receiving operator…"></textarea></label>
                <footer><button type="button" class="btn bg-default-150" data-chat-transfer-close>Cancel</button><button type="submit" class="btn bg-primary text-white"><i data-lucide="send"></i>Transfer now</button></footer>
            </form>
        </div>
        <?php else: ?><div class="sensecms-chat-empty is-workspace"><i data-lucide="message-circle"></i><strong>Choose a conversation</strong><span>Select a chat from the inbox to begin.</span></div><?php endif; ?>
    </div>
</section>
<?php else: ?>
<form method="post" action="/conversations/configuration" enctype="multipart/form-data" class="sensecms-chat-config" data-chat-config-form>
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="active_tab" value="general" data-chat-active-tab>
    <input type="hidden" name="ui_locale" value="<?= $escape($_GET['lang']??($languages[0]['locale']??'en')) ?>" data-ui-locale>
    <div class="sensecms-config-tabs" role="tablist" aria-label="Live chat configuration">
        <button type="button" class="is-active" data-chat-config-tab="general"><i data-lucide="messages-square"></i><span>General<small>Localized chat copy</small></span></button>
        <button type="button" data-chat-config-tab="teams"><i data-lucide="users-round"></i><span>Teams<small>Routing and availability</small></span></button>
        <button type="button" data-chat-config-tab="sounds"><i data-lucide="volume-2"></i><span>Sounds<small>Connection and messages</small></span></button>
        <button type="button" data-chat-config-tab="appearance"><i data-lucide="user-round-cog"></i><span>Appearance<small>Your operator avatar</small></span></button>
        <button type="button" data-chat-config-tab="operations"><i data-lucide="database-backup"></i><span>Operations<small>Retention and exports</small></span></button>
    </div>
    <section class="card sensecms-config-panel" data-chat-config-panel="general">
        <div class="card-header"><div><h6 class="card-title">Public chat content</h6><p>Configure the visitor-facing header, availability and introduction for every active language.</p></div></div>
        <?php $localeTabsId='chat-locales';$localeTabsActive=$_GET['lang']??null;require __DIR__.'/console-language-tabs.php'; ?>
        <div class="card-body sensecms-localized-chat-fields"><?php foreach ($languages as $language): $languageLocale = (string) $language['locale']; $localized = $liveChatSettings['general']['localized'][$languageLocale] ?? $liveChatSettings['general']['localized']['en']; ?><section class="sensecms-language-panel" data-language-panel="<?= $escape($languageLocale) ?>" <?= $languageLocale!==$localeTabsActive?'hidden':'' ?>><div><label><span>Chat title</span><input required maxlength="80" name="localized[<?= $escape($languageLocale) ?>][title]" value="<?= $escape($localized['title'] ?? '') ?>" class="form-input"></label><label><span>Welcome heading</span><input required maxlength="120" name="localized[<?= $escape($languageLocale) ?>][welcome]" value="<?= $escape($localized['welcome'] ?? '') ?>" class="form-input"></label><label class="is-wide"><span>Introduction</span><input required maxlength="220" name="localized[<?= $escape($languageLocale) ?>][intro]" value="<?= $escape($localized['intro'] ?? '') ?>" class="form-input"></label><label><span>Online status</span><input required maxlength="100" name="localized[<?= $escape($languageLocale) ?>][online]" value="<?= $escape($localized['online'] ?? '') ?>" class="form-input"></label><label><span>Outside-hours status</span><input required maxlength="120" name="localized[<?= $escape($languageLocale) ?>][offline]" value="<?= $escape($localized['offline'] ?? '') ?>" class="form-input"></label></div></section><?php endforeach; ?></div>
    </section>
    <section class="sensecms-config-panel" data-chat-config-panel="teams" hidden>
        <div class="sensecms-team-layout">
            <section class="card"><div class="card-header"><div><h6 class="card-title">Service availability</h6><p>Set the public support status in the school's local timezone. Visitors can still leave a message outside these hours.</p></div><label class="sensecms-switch"><input type="checkbox" name="availability_enabled" <?= $liveChatSettings['availability']['enabled'] ? 'checked' : '' ?>><span></span><b>Use schedule</b></label></div><div class="card-body"><label class="sensecms-timezone"><span>Operational timezone</span><select name="availability_timezone" class="form-input"><?php foreach ($chatTimezones as $zone => $label): ?><option value="<?= $escape($zone) ?>" <?= $liveChatSettings['availability']['timezone'] === $zone ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><div class="sensecms-hours-grid"><?php foreach ($weekdays as $day => $label): $hours = $liveChatSettings['availability']['schedule'][$day]; ?><div><label class="sensecms-day-toggle"><input type="checkbox" name="availability[<?= $day ?>][enabled]" <?= $hours['enabled'] ? 'checked' : '' ?>><span><?= $label ?></span></label><label><small>From</small><input type="time" required name="availability[<?= $day ?>][start]" value="<?= $escape($hours['start']) ?>" class="form-input"></label><i>-</i><label><small>To</small><input type="time" required name="availability[<?= $day ?>][end]" value="<?= $escape($hours['end']) ?>" class="form-input"></label></div><?php endforeach; ?></div></div></section>
            <section class="card"><div class="card-header"><div><h6 class="card-title">Operator teams</h6><p>Create routing groups and choose which active users receive team conversations.</p></div><button type="button" class="btn bg-primary/10 text-primary" data-chat-team-add><i data-lucide="plus"></i>Add team</button></div><div class="card-body sensecms-team-list" data-chat-team-list><?php foreach ($chatTeams as $index => $team): ?><article class="sensecms-team-card" data-chat-team-card style="--team-color:<?= $escape($team['color']) ?>"><input type="hidden" name="teams[<?= $index ?>][slug]" value="<?= $escape($team['slug']) ?>"><header><span></span><div><input required maxlength="120" name="teams[<?= $index ?>][name]" value="<?= $escape($team['name']) ?>" class="form-input" aria-label="Team name"><small><?= (int) $team['member_count'] ?> assigned operators</small></div><label class="sensecms-switch"><input type="checkbox" name="teams[<?= $index ?>][active]" <?= $team['active'] ? 'checked' : '' ?>><span></span><b>Active</b></label><button type="button" data-chat-team-remove aria-label="Archive team"><i data-lucide="archive"></i></button></header><div class="sensecms-team-fields"><label><span>Description</span><input maxlength="500" name="teams[<?= $index ?>][description]" value="<?= $escape($team['description']) ?>" class="form-input"></label><label><span>Colour</span><input type="color" name="teams[<?= $index ?>][color]" value="<?= $escape($team['color']) ?>"></label></div><fieldset><legend>Team members</legend><div><?php foreach ($chatUsers as $chatUser): ?><label><input type="checkbox" name="teams[<?= $index ?>][members][]" value="<?= (int) $chatUser['id'] ?>" <?= in_array((int) $chatUser['id'], $team['members'], true) ? 'checked' : '' ?>><span><?= $escape(mb_strtoupper(mb_substr($chatUser['name'], 0, 1))) ?></span><b><?= $escape($chatUser['name']) ?><small><?= $escape($chatUser['email']) ?></small></b></label><?php endforeach; ?></div></fieldset></article><?php endforeach; ?></div><div class="sensecms-team-empty" data-chat-team-empty <?= $chatTeams ? 'hidden' : '' ?>><i data-lucide="users-round"></i><strong>No teams configured</strong><span>Add a team to route conversations to a group of operators.</span></div></section>
        </div>
        <template data-chat-team-template><article class="sensecms-team-card" data-chat-team-card style="--team-color:#2563eb"><input type="hidden" name="teams[__INDEX__][slug]" value="team-__TOKEN__"><header><span></span><div><input required maxlength="120" name="teams[__INDEX__][name]" value="New support team" class="form-input" aria-label="Team name"><small>Choose operators below</small></div><label class="sensecms-switch"><input type="checkbox" name="teams[__INDEX__][active]" checked><span></span><b>Active</b></label><button type="button" data-chat-team-remove aria-label="Remove team"><i data-lucide="archive"></i></button></header><div class="sensecms-team-fields"><label><span>Description</span><input maxlength="500" name="teams[__INDEX__][description]" placeholder="What this team handles" class="form-input"></label><label><span>Colour</span><input type="color" name="teams[__INDEX__][color]" value="#2563eb"></label></div><fieldset><legend>Team members</legend><div><?php foreach ($chatUsers as $chatUser): ?><label><input type="checkbox" name="teams[__INDEX__][members][]" value="<?= (int) $chatUser['id'] ?>" <?= (int) $chatUser['id'] === (int) $user['id'] ? 'checked' : '' ?>><span><?= $escape(mb_strtoupper(mb_substr($chatUser['name'], 0, 1))) ?></span><b><?= $escape($chatUser['name']) ?><small><?= $escape($chatUser['email']) ?></small></b></label><?php endforeach; ?></div></fieldset></article></template>
    </section>
    <section class="card sensecms-config-panel" data-chat-config-panel="sounds" hidden>
        <div class="card-header"><div><h6 class="card-title">Live chat sounds</h6><p>Choose separate cues for a new connection, an administrator message and a visitor message.</p></div></div>
        <div class="card-body sensecms-sound-grid">
            <article><i data-lucide="phone-call"></i><div><strong>Incoming chat</strong><span>Loops while a visitor waits for an operator.</span></div><select name="incoming_sound" class="form-input"><option value="ring.mp3" selected>Incoming call · Ring</option></select><button type="button" data-sound-preview="/sensecms/audio/ring.mp3"><i data-lucide="play"></i>Preview</button></article>
            <article><i data-lucide="headphones"></i><div><strong>Administrator notification</strong><span>Plays when the assigned chat receives a visitor message.</span></div><select name="admin_message_sound" class="form-input"><?php foreach ($chatSounds as $index => $file): ?><option value="<?= $escape($file) ?>" <?= $liveChatSettings['sounds']['admin_message'] === $file ? 'selected' : '' ?>>Notification <?= $index + 1 ?></option><?php endforeach; ?></select><button type="button" data-sound-preview-select="admin_message_sound"><i data-lucide="play"></i>Preview</button></article>
            <article><i data-lucide="user-round"></i><div><strong>Visitor notification</strong><span>Plays when an operator or assistant sends a message.</span></div><select name="visitor_message_sound" class="form-input"><?php foreach ($chatSounds as $index => $file): ?><option value="<?= $escape($file) ?>" <?= $liveChatSettings['sounds']['visitor_message'] === $file ? 'selected' : '' ?>>Notification <?= $index + 1 ?></option><?php endforeach; ?></select><button type="button" data-sound-preview-select="visitor_message_sound"><i data-lucide="play"></i>Preview</button></article>
        </div>
    </section>
    <section class="card sensecms-config-panel" data-chat-config-panel="appearance" hidden>
        <div class="card-header"><div><h6 class="card-title">Operator appearance</h6><p>Select the avatar visitors see after you connect to their conversation.</p></div></div>
        <div class="card-body sensecms-chat-appearance"><div class="sensecms-chat-avatar-preview" data-chat-avatar-preview data-default-avatar="/assets/logo.svg" data-profile-avatar="<?= $escape($user['avatar_url'] ?: '/assets/logo.svg') ?>" data-custom-avatar="<?= $escape($liveChatUserSettings['custom_avatar_url'] ?: '/assets/logo.svg') ?>"><img src="<?= $escape($liveChatUserSettings['effective_avatar']) ?>" alt="Current chat avatar"><span>Current visitor view</span></div><div class="sensecms-avatar-options"><label><input type="radio" name="avatar_mode" value="default" <?= $liveChatUserSettings['avatar_mode'] === 'default' ? 'checked' : '' ?>><span><i data-lucide="school"></i><strong>SenseCMS default</strong><small>Use the official live chat logo.</small></span></label><label><input type="radio" name="avatar_mode" value="profile" <?= $liveChatUserSettings['avatar_mode'] === 'profile' ? 'checked' : '' ?>><span><i data-lucide="contact-round"></i><strong>My profile photo</strong><small>Use the photo from My Profile.</small></span></label><label><input type="radio" name="avatar_mode" value="custom" <?= $liveChatUserSettings['avatar_mode'] === 'custom' ? 'checked' : '' ?>><span><i data-lucide="image-plus"></i><strong>Custom chat avatar</strong><small>Upload and crop an image used only in live chat.</small></span></label><div class="sensecms-chat-avatar-upload"><input data-chat-avatar-input name="chat_avatar" accept=".png,.jpg,.jpeg,.webp" type="file"><p>PNG, JPG or WebP · max. 2 MB · saved at 320 × 320 px</p><div class="sensecms-chat-avatar-crop" data-chat-avatar-crop hidden><canvas></canvas><label>Zoom<input data-chat-crop="zoom" type="range" min="1" max="3" value="1" step=".01"></label><label>Horizontal<input data-chat-crop="x" type="range" min="-100" max="100" value="0"></label><label>Vertical<input data-chat-crop="y" type="range" min="-100" max="100" value="0"></label></div></div></div></div>
    </section>
    <section class="sensecms-config-panel" data-chat-config-panel="operations" hidden>
        <div class="sensecms-operations-grid"><section class="card"><div class="card-header"><div><h6 class="card-title">Conversation retention</h6><p>Automatically remove anonymized closed conversation markers after the selected period.</p></div><label class="sensecms-switch"><input type="checkbox" name="retention_enabled" <?= $liveChatSettings['retention']['enabled'] ? 'checked' : '' ?>><span></span><b>Enabled</b></label></div><div class="card-body"><div class="sensecms-retention-control"><i data-lucide="calendar-clock"></i><label><span>Keep closed conversations for</span><div><input type="number" min="1" max="365" name="retention_days" value="<?= (int) $liveChatSettings['retention']['days'] ?>" class="form-input"><b>days</b></div></label></div><p class="sensecms-operation-note"><i data-lucide="shield-check"></i>Active conversations are never removed. Deleted conversation messages and visitor details are erased immediately.</p></div></section><section class="card"><div class="card-header"><div><h6 class="card-title">Transcript exports</h6><p>Operators can download a readable TXT transcript or complete JSON record from the conversation toolbar.</p></div></div><div class="card-body sensecms-export-info"><span><i data-lucide="file-text"></i><b>TXT</b><small>Human-readable conversation</small></span><span><i data-lucide="braces"></i><b>JSON</b><small>Messages, metadata and transfers</small></span><p>Exports are generated on demand, protected by the current operator session and never stored publicly.</p></div></section></div>
    </section>
    <footer class="sensecms-config-save"><span><i data-lucide="shield-check"></i>Changes are applied immediately without leaving the selected section.</span><button type="submit" class="btn bg-primary text-white"><i data-lucide="save"></i>Save configuration</button></footer>
</form>
<?php endif; ?>
</section>
