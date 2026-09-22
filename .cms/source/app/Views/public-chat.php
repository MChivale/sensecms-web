<?php
declare(strict_types=1);
$chatSettings = \App\Core\LiveChatSettings::from($liveChatSettings ?? []);
$chatLocale = (string) ($locale ?? 'en');
$chatCopy = array_replace(\App\Core\LiveChatSettings::defaults()['general']['localized']['en'], (array) ($chatSettings['general']['localized'][$chatLocale] ?? []));
$chatOnline = \App\Core\LiveChatSettings::isAvailable($chatSettings);
$chatAssistant = !empty($chatSettings['assistant']['enabled']);
$chatAssistantStatus = match ($chatLocale) {
    'pl' => 'Asystent AI · możliwość połączenia z człowiekiem',
    'zh' => 'AI 助手 · 可转接人工支持',
    'km' => 'ជំនួយការ AI · អាចភ្ជាប់ទៅកាន់បុគ្គលិក',
    default => 'AI assistant · human handoff available',
};
$chatEscape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$chatLabels = match ($chatLocale) {
    'pl' => ['name'=>'Twoje imię (opcjonalnie)','email'=>'E-mail do kontaktu zwrotnego','message'=>'Napisz wiadomość…','send'=>'Wyślij','source'=>'Źródło','close'=>'Zamknij czat','new'=>'Nowa rozmowa','ended'=>'Rozmowa została zakończona.','error'=>'Nie udało się potwierdzić wysłania. Sprawdź rozmowę przed ponowną próbą.','refresh'=>'Nie można odświeżyć rozmowy. Spróbujemy ponownie.','sending'=>'Wysyłanie…','waiting'=>'Zespół został powiadomiony · AI dołączy za {seconds} s','waitingNoAi'=>'Zespół został powiadomiony · oczekiwanie na konsultanta','assistant'=>'Asystent AI dołączył do rozmowy','emailHint'=>'Zostaw adres e-mail, a zespół wróci z odpowiedzią.','sendHint'=>'Enter wysyła · Shift+Enter dodaje wiersz'],
    'zh' => ['name'=>'姓名（可选）','message'=>'消息','send'=>'发送','source'=>'来源','close'=>'关闭聊天','new'=>'新对话','ended'=>'对话已结束。','error'=>'无法确认发送。重试前请检查对话。','refresh'=>'无法刷新对话，将重试。','sending'=>'发送中…'],
    'km' => ['name'=>'ឈ្មោះ (មិនចាំបាច់)','message'=>'សារ','send'=>'ផ្ញើ','source'=>'ប្រភព','close'=>'បិទការជជែក','new'=>'ការសន្ទនាថ្មី','ended'=>'ការសន្ទនាបានបញ្ចប់។','error'=>'មិនអាចបញ្ជាក់ការផ្ញើបានទេ។ សូមពិនិត្យការសន្ទនាមុនពេលផ្ញើម្ដងទៀត។','refresh'=>'មិនអាចផ្ទុកការសន្ទនាបានទេ។ យើងនឹងព្យាយាមម្ដងទៀត។','sending'=>'កំពុងផ្ញើ…'],
    default => ['name'=>'Your name (optional)','email'=>'Callback e-mail address','message'=>'Write a message…','send'=>'Send','source'=>'Source','close'=>'Close chat','new'=>'New conversation','ended'=>'This conversation has ended.','error'=>'Sending could not be confirmed. Check the conversation before trying again.','refresh'=>'Conversation could not be refreshed. We will try again.','sending'=>'Sending…','waiting'=>'Team notified · AI joins in {seconds}s','waitingNoAi'=>'Team notified · waiting for an operator','assistant'=>'AI assistant joined the conversation','emailHint'=>'Leave your e-mail address and our team will get back to you.','sendHint'=>'Enter to send · Shift+Enter for a new line'],
};
?>
<link rel="stylesheet" href="/assets/public-chat.css?v=20260922-live-3">
<div class="sense-public-chat" data-public-chat data-endpoint="<?= $chatAssistant ? '/api/ai/chat' : '/api/chat/message' ?>" data-locale="<?= $chatEscape($chatLocale) ?>" data-csrf="<?= $chatEscape(\App\Core\Auth::csrf()) ?>" data-labels="<?= $chatEscape(json_encode($chatLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <button class="sense-chat-launcher" type="button" aria-controls="sense-chat-panel" aria-expanded="false" aria-label="<?= $chatEscape($chatCopy['title']) ?>" data-chat-open><span></span><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg></button>
    <section class="sense-chat-panel" id="sense-chat-panel" role="dialog" aria-labelledby="sense-chat-title" hidden>
        <header class="sense-chat-header"><div class="sense-chat-brand"><span><img src="/assets/logo.svg" alt=""></span><div><strong id="sense-chat-title"><?= $chatEscape($chatCopy['title']) ?></strong><p><i></i><?= $chatEscape($chatAssistant ? $chatAssistantStatus : $chatCopy[$chatOnline ? 'online' : 'offline']) ?></p></div></div><button type="button" data-chat-close aria-label="<?= $chatEscape($chatLabels['close']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
        <div class="sense-chat-body">
            <div class="sense-chat-intro" data-chat-intro><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9M12 3a9 9 0 0 1 9 9M12 7v5l3 2"/></svg></span><div><strong><?= $chatEscape($chatCopy['welcome']) ?></strong><p><?= $chatEscape($chatCopy['intro']) ?></p></div></div>
            <div class="sense-chat-messages" role="log" aria-live="polite" aria-relevant="additions" data-chat-messages></div>
        </div>
        <p class="sense-chat-status" role="status" data-chat-status></p>
        <button class="sense-chat-new" type="button" data-chat-new hidden><?= $chatEscape($chatLabels['new']) ?></button>
        <form class="sense-chat-form" data-chat-form>
            <label class="sense-chat-name"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg><input name="name" autocomplete="given-name" maxlength="150" placeholder="<?= $chatEscape($chatLabels['name']) ?>" aria-label="<?= $chatEscape($chatLabels['name']) ?>"></label>
            <label class="sense-chat-email" data-chat-email hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM4 7l8 6 8-6"/></svg><input name="email" type="email" autocomplete="email" maxlength="190" placeholder="<?= $chatEscape($chatLabels['email'] ?? 'Callback e-mail') ?>" aria-label="<?= $chatEscape($chatLabels['email'] ?? 'Callback e-mail') ?>"><small><?= $chatEscape($chatLabels['emailHint'] ?? '') ?></small></label>
            <div class="sense-chat-composer"><textarea name="message" rows="1" maxlength="2000" required placeholder="<?= $chatEscape($chatLabels['message']) ?>" aria-label="<?= $chatEscape($chatLabels['message']) ?>"></textarea><button type="submit" aria-label="<?= $chatEscape($chatLabels['send']) ?>"><span><?= $chatEscape($chatLabels['send']) ?></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/></svg></button></div>
            <small><?= $chatEscape($chatLabels['sendHint'] ?? '') ?></small>
        </form>
    </section>
</div>
<script src="/assets/public-chat.js?v=20260922-live-3" defer></script>
