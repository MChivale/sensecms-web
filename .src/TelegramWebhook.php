<?php
declare(strict_types=1);

namespace SenseCMS\Website;

/** Website-only ingress. No sessions, CMS cookies or browser-supplied identity. */
final class TelegramWebhook
{
    public static function response(array $server, string $body, TelegramBrokerService $broker): array
    {
        try {
            if (($server['HTTPS']??'off')==='off' || empty($server['HTTPS']) || ($server['HTTP_HOST']??'')!=='www.sensecms.com') return [421,['ok'=>false]];
            $action=match($server['REQUEST_URI']??'') {
                '/api/telegram/v1/webhook'=>'webhook',
                '/api/telegram/v1/connect/start'=>'start',
                '/api/telegram/v1/connect/status'=>'status',
                '/api/telegram/v1/connect/disconnect'=>'disconnect',
                '/api/telegram/v1/recipients'=>'recipients',
                '/api/telegram/v1/deliver'=>'deliver',
                default=>null,
            };
            if ($action===null) return [404,['ok'=>false]];
            if (($server['REQUEST_METHOD']??'')!=='POST') return [405,['ok'=>false]];
            if (strtolower(trim(explode(';',(string)($server['CONTENT_TYPE']??''),2)[0]))!=='application/json') return [415,['ok'=>false]];
            if ((int)($server['CONTENT_LENGTH']??0)>65536 || strlen($body)>65536) return [413,['ok'=>false]];
            $input=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($input) || !str_starts_with(ltrim($body),'{')) return [400,['ok'=>false]];
            return [200,$broker->handle($action,$server,$input)];
        } catch (\JsonException) { return [400,['ok'=>false]]; }
        catch (\Throwable $error) {
            $status=$error instanceof \RuntimeException && in_array($error->getCode(),[401,403,404,409,413,422,429,502,503],true)?$error->getCode():503;
            return [$status,['ok'=>false]];
        }
    }
}
