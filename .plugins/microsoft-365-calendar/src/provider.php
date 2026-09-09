<?php
declare(strict_types=1);

namespace SenseCMS\MicrosoftCalendar;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

return new class {
    public function verify(array $settings): void
    {
        $calendar = $this->request('GET', $this->base($settings), $settings);
        if (($calendar['canEdit'] ?? null) !== true) throw new RuntimeException('Microsoft 365 requires a writable calendar.', 403);
    }

    public function upsert(array $settings, array $event, ?string $externalId): string
    {
        $payload = $this->payload($event);
        if (!$externalId) {
            if (!is_string($event['uid'] ?? null) || $event['uid'] === '') throw new RuntimeException('Missing event identity.');
            $payload['transactionId'] = 'sensecms-' . hash('sha256', $event['uid']);
        } else $this->identifier($externalId);
        $url = $this->base($settings) . '/events' . ($externalId ? '/' . rawurlencode($externalId) : '');
        $result = $this->request($externalId ? 'PATCH' : 'POST', $url, $settings, $payload, $externalId ? [200] : [201]);
        $id = $result['id'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $id)
            || ($externalId && $id !== $externalId)) throw new RuntimeException('Microsoft 365 returned an unexpected event identity.');
        return $id;
    }

    public function delete(array $settings, string $externalId): void
    {
        $this->identifier($externalId);
        $this->request('DELETE', $this->base($settings) . '/events/' . rawurlencode($externalId), $settings, null, [204,404]);
    }

    public function payload(array $event): array
    {
        $utc = new DateTimeZone('UTC');
        $start = $this->date((string)($event['start_at'] ?? ''), $utc);
        $end = $this->date((string)($event['end_at'] ?? ''), $utc);
        if ($end <= $start) throw new RuntimeException('Event end must follow its start.');
        $title = trim((string)($event['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Missing event title.');
        $allDay = !empty($event['all_day']);
        $payload = ['subject'=>$title, 'body'=>['contentType'=>'text','content'=>(string)($event['description'] ?? '')],
            'location'=>['displayName'=>(string)($event['location'] ?? '')], 'isAllDay'=>$allDay];
        $zone = $allDay ? new DateTimeZone((string)($event['timezone'] ?? 'UTC')) : $utc;
        $from = $start->setTimezone($zone)->format($allDay ? 'Y-m-d\T00:00:00' : 'Y-m-d\TH:i:s');
        $to = $end->setTimezone($zone)->format($allDay ? 'Y-m-d\T00:00:00' : 'Y-m-d\TH:i:s');
        if ($to <= $from) throw new RuntimeException('All-day event requires an exclusive end date.');
        $payload['start'] = ['dateTime'=>$from,'timeZone'=>$zone->getName()];
        $payload['end'] = ['dateTime'=>$to,'timeZone'=>$zone->getName()];
        return $payload;
    }

    private function date(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('Invalid event date.');
        return $date;
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException('Invalid Microsoft 365 identifier.');
        return rawurlencode($value);
    }

    private function base(array $settings): string
    {
        $user = $this->identifier($settings['user_id'] ?? null);
        $calendar = $settings['calendar_id'] ?? '';
        return 'https://graph.microsoft.com/v1.0/users/' . $user . ($calendar === '' ? '/calendar' : '/calendars/' . $this->identifier($calendar));
    }

    private function request(string $method, string $url, array $settings, ?array $payload = null, array $codes = [200]): array
    {
        foreach (['tenant_id','client_id'] as $name) {
            if (!is_string($settings[$name] ?? null) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $settings[$name])) throw new RuntimeException('Microsoft tenant and client IDs must be GUIDs.');
        }
        $secret = $settings['client_secret'] ?? null;
        if (!is_string($secret) || $secret === '' || strlen($secret) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $secret)) throw new RuntimeException('Missing or invalid Microsoft application secret.');
        $token = $this->http('https://login.microsoftonline.com/' . $settings['tenant_id'] . '/oauth2/v2.0/token', [CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$settings['client_id'],'client_secret'=>$secret,
                'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials'])], [200]);
        $access = $token['access_token'] ?? null;
        if (!is_string($access) || $access === '' || strlen($access) > 16384 || preg_match('/[\x00-\x20\x7f]/', $access)) throw new RuntimeException('Microsoft identity returned an invalid token.');
        $options = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access,
            'Accept: application/json','Content-Type: application/json','Prefer: IdType="ImmutableId"']];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->http($url, $options, $codes);
    }

    private function http(string $url, array $options, array $codes): array
    {
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('Microsoft 365 connection could not be initialized.');
        $body = '';
        curl_setopt_array($curl, $options + [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>20,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
        finally { curl_close($curl); }
        // Keep upstream bodies, tokens and transport diagnostics out of error messages.
        if ($ok === false || !in_array($status, $codes, true)) throw new RuntimeException('Microsoft 365 request failed.', $status);
        if (in_array($status, [204,404], true)) return [];
        $data = json_decode($body, true);
        if (!is_array($data) || !str_starts_with(ltrim($body), '{')) throw new RuntimeException('Microsoft 365 returned an invalid response.');
        return $data;
    }
};
