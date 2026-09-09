<?php
declare(strict_types=1);

$blocks = $page['blocks'] ?? [];
$page = array_replace($page, ['kind' => match ($page['template'] ?? '') { 'home' => 'home', 'product' => 'product', default => 'managed' }, 'title' => (string) $page['title'], 'description' => (string) ($page['excerpt'] ?? '')]);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$status = 200;
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require dirname(__DIR__) . '/layout.php';
