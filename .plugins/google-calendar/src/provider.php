<?php
declare(strict_types=1);

namespace SenseCMS\GoogleCalendar;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

return new class {
    public function verify(array $settings): void
    {
        $calendar = $this->request('GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList/' . $this->calendar($settings), $settings);
        if (!in_array($calendar['accessRole'] ?? '', ['owner','writer'], true)) throw new RuntimeException('Google Calendar requires a writable calendar.', 403);
    }

    public function upsert(array $settings, array $event, ?string $externalId): string
    {
        if (!is_string($event['uid'] ?? null) || $event['uid'] === '') throw new RuntimeException('Missing event identity.');
        $id = $externalId ?: 'sc' . hash('sha256', $event['uid']);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . $this->calendar($settings) . '/events';
        $payload = $this->payload($event);
        if (!$externalId) $payload['id'] = $id;
        try {
            $result = $this->request($externalId ? 'PUT' : 'POST', $url . ($externalId ? '/' . rawurlencode($id) : ''), $settings, $payload);
        } catch (RuntimeException $error) {
            // A retried creation must update the same deterministic ID, never duplicate it.
            if ($externalId || $error->getCode() !== 409) throw $error;
            $result = $this->request('PUT', $url . '/' . rawurlencode($id), $settings, $payload);
        }
        if (($result['id'] ?? null) !== $id) throw new RuntimeException('Google Calendar returned an unexpected event identity.');
        return $id;
    }

    public function delete(array $settings, string $externalId): void
    {
        if ($externalId === '') throw new RuntimeException('Missing external event identity.');
        $this->request('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . $this->calendar($settings) . '/events/' . rawurlencode($externalId), $settings, null, [204,404,410]);
    }

    public function payload(array $event): array
    {
        $utc = new DateTimeZone('UTC');
        $start = $this->date((string)($event['start_at'] ?? ''), $utc);
        $end = $this->date((string)($event['end_at'] ?? ''), $utc);
        if ($end <= $start) throw new RuntimeException('Event end must follow its start.');
        $title = trim((string)($event['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Missing event title.');
        $payload = ['summary'=>$title, 'description'=>(string)($event['description'] ?? ''), 'location'=>(string)($event['location'] ?? '')];
        if (!empty($event['all_day'])) {
            $zone = new DateTimeZone((string)($event['timezone'] ?? 'UTC'));
            $from = $start->setTimezone($zone)->format('Y-m-d');
            $to = $end->setTimezone($zone)->format('Y-m-d');
            if ($to <= $from) throw new RuntimeException('All-day event requires an exclusive end date.');
            $payload['start'] = ['date'=>$from]; $payload['end'] = ['date'=>$to];
        } else {
            $payload['start'] = ['dateTime'=>$start->format(DATE_RFC3339),'timeZone'=>'UTC'];
            $payload['end'] = ['dateTime'=>$end->format(DATE_RFC3339),'timeZone'=>'UTC'];
        }
        return $payload;
    }

    private function date(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('Invalid event date.');
        return $date;
    }

    private function calendar(array $settings): string
    {
        $id = $settings['calendar_id'] ?? null;
        if (!is_string($id) || trim($id) === '' || strlen($id) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $id)) throw new RuntimeException('Invalid Google calendar ID.');
        return rawurlencode($id);
    }

    private function request(string $method, string $url, array $settings, ?array $payload = null, array $codes = [200,201]): array
    {
        foreach (['client_id','client_secret','refresh_token'] as $name) {
            if (!is_string($settings[$name] ?? null) || $settings[$name] === '' || strlen($settings[$name]) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $settings[$name])) throw new RuntimeException('Missing or invalid Google OAuth credentials.');
        }
        $token = $this->http('https://oauth2.googleapis.com/token', [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query([
            'client_id'=>$settings['client_id'], 'client_secret'=>$settings['client_secret'], 'refresh_token'=>$settings['refresh_token'], 'grant_type'=>'refresh_token'
        ])], [200]);
        $access = $token['access_token'] ?? null;
        if (!is_string($access) || $access === '' || strlen($access) > 8192 || preg_match('/[\x00-\x20\x7f]/', $access)) throw new RuntimeException('Google OAuth returned an invalid token.');
        $options = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access,'Accept: application/json','Content-Type: application/json']];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->http($url, $options, $codes);
    }

    private function http(string $url, array $options, array $codes): array
    {
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('Google Calendar connection could not be initialized.');
        $body = '';
        curl_setopt_array($curl, $options + [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>20,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
        finally { curl_close($curl); }
        // Never include upstream bodies, URLs, tokens or transport diagnostics in logs.
        if ($ok === false || !in_array($status, $codes, true)) throw new RuntimeException('Google Calendar request failed.', $status);
        if (in_array($status, [204,404,410], true)) return [];
        $data = json_decode($body, true);
        if (!is_array($data) || !str_starts_with(ltrim($body), '{')) throw new RuntimeException('Google Calendar returned an invalid response.');
        return $data;
    }
};
