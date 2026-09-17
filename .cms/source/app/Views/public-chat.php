<?php
declare(strict_types=1);
$chatSettings = \App\Core\LiveChatSettings::from($liveChatSettings ?? []);
$chatLocale = (string) ($locale ?? 'en');
$chatCopy = array_replace(\App\Core\LiveChatSettings::defaults()['general']['localized']['en'], (array) ($chatSettings['general']['localized'][$chatLocale] ?? []));
$chatOnline = \App\Core\LiveChatSettings::isAvailable($chatSettings);
$chatEscape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$chatLabels = match ($chatLocale) {
    'pl' => ['name'=>'Twoje imię (opcjonalnie)','message'=>'Wiadomość','send'=>'Wyślij','close'=>'Zamknij czat','new'=>'Nowa rozmowa','ended'=>'Rozmowa została zakończona.','error'=>'Nie udało się potwierdzić wysłania. Sprawdź rozmowę przed ponowną próbą.','refresh'=>'Nie można odświeżyć rozmowy. Spróbujemy ponownie.','sending'=>'Wysyłanie…'],
    'zh' => ['name'=>'姓名（可选）','message'=>'消息','send'=>'发送','close'=>'关闭聊天','new'=>'新对话','ended'=>'对话已结束。','error'=>'无法确认发送。重试前请检查对话。','refresh'=>'无法刷新对话，将重试。','sending'=>'发送中…'],
    'km' => ['name'=>'ឈ្មោះ (មិនចាំបាច់)','message'=>'សារ','send'=>'ផ្ញើ','close'=>'បិទការជជែក','new'=>'ការសន្ទនាថ្មី','ended'=>'ការសន្ទនាបានបញ្ចប់។','error'=>'មិនអាចបញ្ជាក់ការផ្ញើបានទេ។ សូមពិនិត្យការសន្ទនាមុនពេលផ្ញើម្ដងទៀត។','refresh'=>'មិនអាចផ្ទុកការសន្ទនាបានទេ។ យើងនឹងព្យាយាមម្ដងទៀត។','sending'=>'កំពុងផ្ញើ…'],
    default => ['name'=>'Your name (optional)','message'=>'Message','send'=>'Send','close'=>'Close chat','new'=>'New conversation','ended'=>'This conversation has ended.','error'=>'Sending could not be confirmed. Check the conversation before trying again.','refresh'=>'Conversation could not be refreshed. We will try again.','sending'=>'Sending…'],
};
?>
<link rel="stylesheet" href="/assets/public-chat.css?v=20260913-2">
<div class="sense-public-chat" data-public-chat data-locale="<?= $chatEscape($chatLocale) ?>" data-csrf="<?= $chatEscape(\App\Core\Auth::csrf()) ?>" data-labels="<?= $chatEscape(json_encode($chatLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <button class="sense-chat-launcher" type="button" aria-controls="sense-chat-panel" aria-expanded="false" aria-label="<?= $chatEscape($chatCopy['title']) ?>" data-chat-open><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg></button>
    <section class="sense-chat-panel" id="sense-chat-panel" role="dialog" aria-labelledby="sense-chat-title" hidden>
        <header class="sense-chat-header"><div><strong id="sense-chat-title"><?= $chatEscape($chatCopy['title']) ?></strong><p><?= $chatEscape($chatCopy[$chatOnline ? 'online' : 'offline']) ?></p></div><button type="button" data-chat-close aria-label="<?= $chatEscape($chatLabels['close']) ?>">×</button></header>
        <div class="sense-chat-intro"><strong><?= $chatEscape($chatCopy['welcome']) ?></strong><p><?= $chatEscape($chatCopy['intro']) ?></p></div>
        <div class="sense-chat-messages" role="log" aria-live="polite" aria-relevant="additions" data-chat-messages></div>
        <p class="sense-chat-status" role="status" data-chat-status></p>
        <button class="sense-chat-new" type="button" data-chat-new hidden><?= $chatEscape($chatLabels['new']) ?></button>
        <form class="sense-chat-form" data-chat-form>
            <label><?= $chatEscape($chatLabels['name']) ?><input name="name" autocomplete="given-name" maxlength="150"></label>
            <label><?= $chatEscape($chatLabels['message']) ?><textarea name="message" rows="3" maxlength="2000" required></textarea></label>
            <button type="submit"><?= $chatEscape($chatLabels['send']) ?></button>
        </form>
    </section>
</div>
<script src="/assets/public-chat.js?v=20260913-1" defer></script>
