<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use InvalidArgumentException;

final class SystemNotifications
{
    public static function record(PDO $db, string $key, int $userId, string $source, string $subject, string $title, string $message, string $url, array $channels = [], array $context = []): void
    {
        if ($userId < 0 || !preg_match('/^[a-z][a-z0-9_-]{0,29}$/D', $source) || !preg_match('#^/(?!/)[A-Za-z0-9_/?=&%.~-]*$#D', $url) || strlen($subject) > 100) throw new InvalidArgumentException('Invalid system notification.');
        foreach ($channels as $channel) if (!in_array($channel, ['telegram-notifications','whatsapp-notifications','web-push'], true)) throw new InvalidArgumentException('Invalid notification channel.');
        $db->prepare('INSERT INTO notification_events (event_key,user_id,source,subject,title,message,url,channels,context,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE channels=IF(channels="" OR VALUES(channels)="","",CONCAT_WS(",",channels,VALUES(channels))),processed_at=NULL')->execute([hash('sha256', $source.':'.$key), $userId, $source, $subject, mb_substr($title,0,180), mb_substr($message,0,1000), $url, implode(',',array_unique($channels)),json_encode(array_intersect_key($context,array_flip(['start_at','expires_at'])),JSON_THROW_ON_ERROR)]);
    }
}
