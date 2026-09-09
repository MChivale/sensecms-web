<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AiChatService;

final class AiChatController extends Controller
{
    public function __construct(private readonly AiChatService $chat) {}
    public function reply(bool $forceHuman = false): never
    {
        $this->headers();
        unset($_SESSION['ai_chat_ended']);
        $window = $_SESSION['ai_rate_window'] ?? 0;
        if ($window < time() - 600) { $_SESSION['ai_rate_window'] = time(); $_SESSION['ai_rate_count'] = 0; }
        if (($_SESSION['ai_rate_count'] ?? 0) >= 12) { http_response_code(429); echo json_encode(['error' => 'Please wait a few minutes before sending another message.']); exit; }
        $_SESSION['ai_rate_count'] = ($_SESSION['ai_rate_count'] ?? 0) + 1;
        try { echo json_encode($this->chat->reply((string) ($_POST['message'] ?? ''), preg_replace('/[^a-z-]/', '', (string) ($_POST['locale'] ?? 'en')) ?: 'en', $_SESSION['ai_conversation'] ??= $this->uuid(), (string) ($_POST['name'] ?? ''), $forceHuman), JSON_UNESCAPED_UNICODE); }
        catch (\Throwable $exception) { http_response_code(422); echo json_encode(['error' => $exception->getMessage()]); }
        exit;
    }

    public function state(): never
    {
        $this->headers();
        $conversation = (string) ($_SESSION['ai_conversation'] ?? '');
        if ($conversation === '') { echo json_encode(['ok' => true, 'data' => !empty($_SESSION['ai_chat_ended']) ? ['status' => 'closed', 'ended' => true] : null]); exit; }
        $state = $this->chat->state($conversation);
        if (($state['status'] ?? '') === 'closed') {
            unset($_SESSION['ai_conversation']);
            $_SESSION['ai_chat_ended'] = true;
            echo json_encode(['ok' => true, 'data' => ['status' => 'closed', 'ended' => true]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode(['ok' => true, 'data' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    private function uuid(): string { return bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-a' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6)); }
}
