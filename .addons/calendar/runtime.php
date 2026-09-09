<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use SenseCMS\Calendar\CalendarController;

require_once __DIR__ . '/src/CalendarConflictException.php';
require_once __DIR__ . '/src/CalendarI18n.php';
require_once __DIR__ . '/src/CalendarRepository.php';
require_once __DIR__ . '/src/CalendarIntegrationManager.php';
require_once __DIR__ . '/src/CalendarController.php';

return static function (string $method, string $path, ExtensionContext $context): bool {
    if ($path !== '/calendar' && !str_starts_with($path, '/api/calendar/')) return false;
    if (!$context->auth->check()) {
        if ($path === '/calendar') { header('Location: /login', true, 302); exit; }
        $copy = new \SenseCMS\Calendar\CalendarI18n(__DIR__, (string) ($_GET['lang'] ?? ''), (string) ($context->config['default_locale'] ?? 'en'));
        http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>$copy->t('errors.Sign in to access Calendar.')], JSON_UNESCAPED_UNICODE); exit;
    }
    try {
    $controller = new CalendarController($context, __DIR__);
    if ($method === 'GET' && $path === '/calendar') $controller->page();
    if ($method === 'GET' && $path === '/api/calendar/events') $controller->events();
    if ($method === 'GET' && preg_match('#^/api/calendar/events/(\d+)$#D', $path, $match)) $controller->event((int) $match[1]);
    if ($method === 'POST' && $path === '/api/calendar/events') $controller->save();
    if ($method === 'POST' && $path === '/api/calendar/conflicts') $controller->conflicts();
    if ($method === 'POST' && preg_match('#^/api/calendar/events/(\d+)/cancel$#D', $path, $match)) $controller->cancel((int) $match[1]);
    if ($method === 'POST' && $path === '/api/calendar/resources') $controller->resource();
    if ($method === 'POST' && $path === '/api/calendar/categories') $controller->saveCategory();
    if ($method === 'GET' && $path === '/api/calendar/notifications') $controller->notifications();
    if ($method === 'POST' && $path === '/api/calendar/notifications/read') $controller->readNotifications();
    if ($method === 'GET' && $path === '/api/calendar/integrations') $controller->integrations();
    if ($method === 'POST' && preg_match('#^/api/calendar/integrations/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $path, $matches)) $controller->saveIntegration($matches[1]);
    if ($method === 'GET' && $path === '/api/calendar/deliveries') $controller->deliveries();
    if ($method === 'POST' && $path === '/api/calendar/deliveries/retry-safe') $controller->retrySafeDeliveries();
    http_response_code(404); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Calendar endpoint not found.']); exit;
    } catch (Throwable $error) {
        $forbidden = $error instanceof RuntimeException && $error->getCode() === 403;
        http_response_code($forbidden ? 403 : 500); header('Cache-Control: no-store'); header('Content-Type: application/json');
        if (!$forbidden) error_log('Calendar request failed: ' . get_class($error));
        echo json_encode(['ok'=>false,'message'=>$forbidden?'You do not have permission to access Calendar.':'Calendar could not complete the request. Please try again.']); exit;
    }
};
