<?php
declare(strict_types=1);
namespace App\Core;

final class PublicChat
{
    public static function inject(string $html, array $liveChatSettings, string $locale): string
    {
        ob_start();
        try { require dirname(__DIR__) . '/Views/public-chat.php'; $widget = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        $end = strripos($html, '</body>');
        return $end === false ? $html . $widget : substr_replace($html, $widget, $end, 0);
    }
}
