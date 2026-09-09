<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AiChatService
{
    public function __construct(private readonly AiRepository $repository, private readonly Secrets $secrets, private readonly ?CmsRepository $cms = null) {}

    public function reply(string $message, string $locale, string $conversation, string $visitorName = '', bool $forceHuman = false): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 2000) throw new RuntimeException('Please enter a message up to 2,000 characters.');
        $this->repository->conversation($conversation, $locale);
        $state = $this->repository->conversationStatus($conversation);
        if (($state['status'] ?? '') === 'closed') throw new RuntimeException('This conversation has ended. Start a new chat to continue.');
        $visitorName = trim($visitorName);
        if ($visitorName !== '') $this->repository->setVisitorName($conversation, mb_substr($visitorName, 0, 150));
        $this->repository->message($conversation, 'visitor', $message);
        $state = $this->repository->conversationStatus($conversation);
        if (($state['channel'] ?? '') === 'human' || in_array($state['status'] ?? '', ['queued', 'assigned'], true)) return ['message' => null, 'handoff' => true, 'conversation' => $conversation];
        if ($forceHuman || $this->requiresHuman($message)) return $this->handoff($conversation, $locale);
        $provider = $this->repository->provider('openai');
        if (!$provider || empty($provider['api_key_encrypted'])) return $this->handoff($conversation, $locale);
        try {
            $context = $this->repository->context($message, $locale);
            $answer = $this->openAi($provider, $this->secrets->decrypt((string) $provider['api_key_encrypted']), $message, $context);
            if ($answer === '') return $this->handoff($conversation, $locale);
            $this->repository->message($conversation, 'assistant', $answer, (string) $provider['slug']);
            return ['message' => $answer, 'handoff' => false, 'conversation' => $conversation];
        } catch (\Throwable) { return $this->handoff($conversation, $locale); }
    }

    public function state(string $conversation): ?array
    {
        $state = $this->repository->conversationWithMessages($conversation);
        if (!$state || !$this->cms || empty($state['assigned_user_id'])) return $state;
        $userSettings = LiveChatSettings::userFrom($this->cms->setting('live_chat_user_' . (int) $state['assigned_user_id'], []));
        $state['agent_avatar'] = LiveChatSettings::avatar(['avatar_url' => $state['agent_profile_avatar'] ?? ''], $userSettings);
        unset($state['agent_profile_avatar']);
        return $state;
    }

    private function requiresHuman(string $message): bool { return (bool) preg_match('/\b(human|person|agent|real person|talk to)\b/i', $message); }
    private function handoff(string $conversation, string $locale): array
    {
        $message = match ($locale) {
            'km' => 'សូមអរគុណ។ យើងកំពុងភ្ជាប់អ្នកទៅកាន់ក្រុមការងាររបស់យើង។',
            'zh' => '谢谢。我们正在为您接通支持团队。',
            default => 'Thank you. We are connecting you with the support team.',
        };
        if ($this->repository->queueForHuman($conversation)) $this->repository->message($conversation, 'system', $message);
        return ['message' => $message, 'handoff' => true, 'conversation' => $conversation];
    }
    private function openAi(array $provider, string $key, string $message, array $context): string
    {
        $knowledge = $context ? "\n\nUse only this school knowledge when it helps. If it does not answer the question, say so briefly and offer a human handoff:\n" . implode("\n---\n", $context) : '';
        $payload = json_encode(['model' => $provider['default_model'], 'messages' => [['role' => 'system', 'content' => 'You are a helpful, concise school assistant. Never invent facts, fees, policies, dates, or admissions availability.' . $knowledge], ['role' => 'user', 'content' => $message]], 'temperature' => 0.2, 'max_tokens' => 400], JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true]]);
        $raw = @file_get_contents(rtrim((string) $provider['base_url'], '/') . '/chat/completions', false, $context);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    }
}
