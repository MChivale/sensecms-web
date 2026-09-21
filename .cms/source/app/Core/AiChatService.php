<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AiChatService
{
    private readonly AiProviderClient $client;
    public function __construct(private readonly AiRepository $repository, private readonly Secrets $secrets, private readonly ?CmsRepository $cms = null,?AiProviderClient$client=null){$this->client=$client??new AiProviderClient();}

    public function reply(string $message, string $locale, string $conversation, string $visitorName = '', bool $forceHuman = false, ?string $visitorIp = null): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 2000) throw new RuntimeException('Please enter a message up to 2,000 characters.');
        $this->repository->conversation($conversation, $locale, $visitorIp);
        $state = $this->repository->conversationStatus($conversation);
        if (($state['status'] ?? '') === 'closed') throw new RuntimeException('This conversation has ended. Start a new chat to continue.');
        $visitorName = trim($visitorName);
        if ($visitorName !== '') $this->repository->setVisitorName($conversation, mb_substr($visitorName, 0, 150));
        $this->repository->message($conversation, 'visitor', $message);
        $state = $this->repository->conversationStatus($conversation);
        if (($state['channel'] ?? '') === 'human' || in_array($state['status'] ?? '', ['queued', 'assigned'], true)) return ['message' => null, 'handoff' => true, 'conversation' => $conversation];
        if ($forceHuman || $this->requiresHuman($message)) return $this->handoff($conversation, $locale);
        $provider = $this->repository->preferredProvider('chat');
        if (!$provider || empty($provider['api_key_encrypted'])) return $this->handoff($conversation, $locale);
        $usage=0;
        try {
            $context = $this->repository->context($message, $locale);
            $knowledge = $context ? "\n\nUse only this website knowledge when it helps. If it does not answer the question, say so briefly and offer a human handoff:\n" . implode("\n---\n", $context) : '';
            $system='You are a helpful, concise website assistant. Never invent facts, fees, policies, dates, or service availability.'.$knowledge;
            $usage=$this->repository->beginUsage($provider,'chat',null,mb_strlen($system)+mb_strlen($message),400);
            $result=$this->client->generate($provider,$this->secrets->decrypt((string)$provider['api_key_encrypted']),$system,$message,false,400);$answer=$result['text'];
            if ($answer === '') return $this->handoff($conversation, $locale);
            $this->repository->finishUsage($usage,true,$result['usage']);
            $this->repository->message($conversation, 'assistant', $answer, (string) $provider['slug']);
            return ['message' => $answer, 'handoff' => false, 'conversation' => $conversation];
        } catch (\Throwable$error) { if($usage)$this->repository->finishUsage($usage,false,null,$error->getCode()===429?'usage_limit':'provider_error');return $this->handoff($conversation, $locale); }
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
}
