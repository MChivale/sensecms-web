<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AiRepository;
use App\Core\Auth;

final class LiveChatController extends Controller
{
    public function __construct(private readonly Auth $auth, private readonly AiRepository $chat) {}

    public function events(): never
    {
        $this->guard();
        $since = max(0, (int) ($_GET['since'] ?? time()));
        $userId = $this->auth->id() ?? 0;
        $unread = $this->chat->unreadCounts($userId);
        $this->json(true, [
            'pending' => $this->chat->queuedConversations($userId),
            'assignments' => $this->chat->recentAssignments($since, $userId),
            'messages' => $this->chat->recentVisitorMessages($since, $userId),
            'unread' => $unread,
            'unread_total' => array_sum($unread),
            'server_time' => time(),
        ]);
    }

    public function conversation(string $conversation): never
    {
        $this->guard();
        if (!$this->validConversationId($conversation)) $this->json(false, null, 'Conversation not found.', 404);
        $data = $this->chat->conversationWithMessages($conversation, $this->auth->id() ?? 0);
        if (!$data || ($data['status'] ?? '') === 'closed') $this->json(false, null, 'This conversation has ended.', 410);
        $this->json(true, $data);
    }

    public function connect(string $conversation): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->json(false, null, 'Your session token is invalid. Refresh and try again.', 419);
        if (!$this->validConversationId($conversation)) $this->json(false, null, 'Conversation not found.', 404);
        $userId = $this->auth->id() ?? 0;
        if (!$this->chat->claimConversation($conversation, $userId)) {
            $current = $this->chat->conversationWithMessages($conversation, $userId);
            $agent = trim((string) ($current['agent_name'] ?? ''));
            $message = $agent !== '' ? $agent . ' has already connected to this conversation.' : 'This conversation is no longer waiting.';
            $this->json(false, ['agent_name' => $agent], $message, 409);
        }
        $user = $this->auth->user();
        $this->json(true, [
            'conversation' => $conversation,
            'agent_name' => (string) ($user['name'] ?? 'Support team'),
            'redirect' => '/conversations?conversation=' . rawurlencode($conversation),
        ], 'Connected.');
    }

    public function read(string $conversation): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->json(false, null, 'Your session token is invalid. Refresh and try again.', 419);
        if (!$this->validConversationId($conversation) || !$this->chat->markRead($conversation, $this->auth->id() ?? 0)) $this->json(false, null, 'Conversation not found.', 404);
        $this->json(true, ['unread_total' => $this->chat->unreadTotal($this->auth->id() ?? 0)], 'Conversation marked as read.');
    }

    private function guard(): void
    {
        if ($this->auth->check()) return;
        $this->json(false, null, 'Your session has expired.', 401);
    }

    private function validConversationId(string $id): bool { return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id); }

    private function json(bool $ok, ?array $data = null, ?string $message = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(['ok' => $ok, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
