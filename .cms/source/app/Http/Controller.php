<?php

declare(strict_types=1);

namespace App\Http;

abstract class Controller
{
    protected function view(string $file, array $data = []): never
    {
        extract($data, EXTR_SKIP);
        require $file;
        exit;
    }

    protected function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    protected function wantsJson(): bool { return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || (($_SERVER['HTTP_X_SENSECMS_REQUEST'] ?? '') === '1'); }
    protected function result(bool $ok, string $message, ?string $redirect = null, int $status = 200, mixed $data = null): never
    {
        if ($this->wantsJson()) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => $ok, 'message' => $message, 'redirect' => $redirect, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        $_SESSION['flash'] = $message;
        $this->redirect($redirect ?? ($_SERVER['HTTP_REFERER'] ?? '/dashboard'));
    }
}
