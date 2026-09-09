<?php
declare(strict_types=1);

namespace SenseCMS\AppleCalendar;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use RuntimeException;

return new class {
    public function verify(array $settings): void
    {
        $url = $this->base($settings);
        $xml = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:current-user-privilege-set/></d:prop></d:propfind>';
        $body = $this->request('PROPFIND', $url, $settings, $xml, [207])['body'];
        if (!mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0") || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) throw new RuntimeException('Unsafe CalDAV response.');
        $doc = new DOMDocument();
        $errors = libxml_use_internal_errors(true);
        try { $loaded = $doc->loadXML($body, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($errors); }
        if (!$loaded) throw new RuntimeException('Invalid CalDAV response.');
        $xp = new DOMXPath($doc); $xp->registerNamespace('d', 'DAV:'); $xp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        foreach ($xp->query('/d:multistatus/d:response') as $response) {
            $href = $xp->evaluate('string(d:href)', $response);
            if ($href !== parse_url($url, PHP_URL_PATH) && $href !== $url) continue;
            $calendar = false; $rights = [];
            foreach ($xp->query('d:propstat', $response) as $propstat) {
                if (!preg_match('#^HTTP/1\.[01] 200(?: |$)#', trim($xp->evaluate('string(d:status)', $propstat)))) continue;
                $calendar = $calendar || $xp->query('d:prop/d:resourcetype/c:calendar', $propstat)->length > 0;
                foreach ($xp->query('d:prop/d:current-user-privilege-set/d:privilege/*', $propstat) as $right) {
                    if ($right->namespaceURI === 'DAV:') $rights[] = $right->localName;
                }
            }
            $read = in_array('read', $rights, true);
            $write = in_array('write', $rights, true) || !array_diff(['write-content','bind','unbind'], $rights);
            if ($calendar && (in_array('all', $rights, true) || ($read && $write))) return;
        }
        throw new RuntimeException('iCloud requires a readable, writable calendar with create and delete access.', 403);
    }

    public function upsert(array $settings, array $event, ?string $externalId): string
    {
        $id = $this->eventId($event);
        if ($externalId !== null && $externalId !== $id) throw new RuntimeException('Unexpected iCloud event identity.');
        $body = $this->calendarData($event);
        $url = $this->base($settings) . $id . '.ics';
        $current = $this->existing($url, $settings, $id);
        $condition = $current['status'] === 404 ? 'If-None-Match: *' : 'If-Match: ' . $current['etag'];
        $this->request('PUT', $url, $settings, $body, [201,204], [$condition]);
        return $id;
    }

    public function delete(array $settings, string $externalId): void
    {
        if (!preg_match('/^sensecms-[a-f0-9]{64}$/D', $externalId)) throw new RuntimeException('Invalid iCloud event identity.');
        $url = $this->base($settings) . $externalId . '.ics';
        $current = $this->existing($url, $settings, $externalId);
        if ($current['status'] !== 404) $this->request('DELETE', $url, $settings, null, [204,404], ['If-Match: ' . $current['etag']]);
    }

    public function calendarData(array $event): string
    {
        $utc = new DateTimeZone('UTC');
        $start = $this->date((string)($event['start_at'] ?? ''), $utc);
        $end = $this->date((string)($event['end_at'] ?? ''), $utc);
        if ($end <= $start || trim((string)($event['title'] ?? '')) === '') throw new RuntimeException('Invalid event interval or title.');
        if (!empty($event['all_day'])) {
            $zone = new DateTimeZone((string)($event['timezone'] ?? 'UTC'));
            $from = $start->setTimezone($zone)->format('Ymd'); $to = $end->setTimezone($zone)->format('Ymd');
            if ($to <= $from) throw new RuntimeException('All-day event requires an exclusive end date.');
            $dates = ['DTSTART;VALUE=DATE:' . $from, 'DTEND;VALUE=DATE:' . $to];
        } else $dates = ['DTSTART:' . $start->format('Ymd\THis\Z'), 'DTEND:' . $end->format('Ymd\THis\Z')];
        $text = static function (string $value): string {
            if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new RuntimeException('Invalid calendar text.');
            return str_replace(["\\", "\r\n", "\r", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\n', '\\,', '\\;'], $value);
        };
        $lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Sense CMS//Calendar//EN','BEGIN:VEVENT',
            'UID:' . $this->eventId($event), 'DTSTAMP:' . gmdate('Ymd\THis\Z'), ...$dates,
            'SUMMARY:' . $text($event['title']), 'DESCRIPTION:' . $text((string)($event['description'] ?? '')),
            'LOCATION:' . $text((string)($event['location'] ?? '')), 'END:VEVENT','END:VCALENDAR'];
        $folded = [];
        foreach ($lines as $line) {
            while (strlen($line) > 75) { $part = mb_strcut($line, 0, 75, 'UTF-8'); $folded[] = $part; $line = ' ' . substr($line, strlen($part)); }
            $folded[] = $line;
        }
        $ics = implode("\r\n", $folded) . "\r\n";
        if (strlen($ics) > 262144) throw new RuntimeException('Calendar event is too large.');
        return $ics;
    }

    private function eventId(array $event): string
    {
        if (!is_string($event['uid'] ?? null) || $event['uid'] === '') throw new RuntimeException('Missing event identity.');
        return 'sensecms-' . hash('sha256', $event['uid']);
    }

    private function date(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('Invalid event date.');
        return $date;
    }

    private function existing(string $url, array $settings, string $id): array
    {
        $response = $this->request('GET', $url, $settings, null, [200,404]);
        if ($response['status'] === 404) return $response;
        $body = preg_replace('/\r?\n[ \t]/', '', $response['body']);
        preg_match_all('/(?:^|\r?\n)UID:([^\r\n]*)/', $body, $uids);
        if ($uids[1] !== [$id] || !preg_match('/^"[\x21\x23-\x7e]{1,200}"$/D', $response['etag'])) throw new RuntimeException('iCloud resource identity or strong ETag could not be verified.', 409);
        return $response;
    }

    private function base(array $settings): string
    {
        $url = $settings['calendar_url'] ?? '';
        if (!is_string($url) || !preg_match('#^https://((?:p[0-9]{1,3}-)?caldav\.icloud\.com)(?::443)?(/[0-9]+/calendars/[A-Za-z0-9-]+/?)$#D', $url, $match)) throw new RuntimeException('Use the private iCloud calendar collection URL on HTTPS port 443.');
        return 'https://' . $match[1] . rtrim($match[2], '/') . '/';
    }

    private function request(string $method, string $url, array $settings, ?string $payload, array $codes, array $conditions = []): array
    {
        $base = $this->base($settings);
        if (!str_starts_with($url, $base)) throw new RuntimeException('Invalid iCloud destination.');
        $user = $settings['apple_id'] ?? null; $password = $settings['app_password'] ?? null;
        if (!is_string($user) || !filter_var($user, FILTER_VALIDATE_EMAIL) || str_contains($user, ':')
            || !is_string($password) || $password === '' || strlen($password) > 4096 || preg_match('/[\x00-\x20\x7f]/', $password)) throw new RuntimeException('Invalid Apple account or app-specific password.');
        $host = parse_url($base, PHP_URL_HOST); $addresses = gethostbynamel($host) ?: [];
        foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) throw new RuntimeException('Invalid iCloud destination address.');
        if (!$addresses) throw new RuntimeException('iCloud destination is unavailable.');
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('iCloud connection could not be initialized.');
        $body = ''; $etag = '';
        $headers = ['Accept: application/xml,text/calendar', 'Content-Type: ' . ($method === 'PUT' ? 'text/calendar; charset=utf-8' : 'application/xml; charset=utf-8'), ...$conditions];
        if ($method === 'PROPFIND') $headers[] = 'Depth: 0';
        $options = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERPWD=>$user . ':' . $password,
            CURLOPT_HTTPAUTH=>CURLAUTH_BASIC, CURLOPT_RESOLVE=>[$host . ':443:' . $addresses[0]], CURLOPT_PROXY=>'',
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>20, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function ($handle, string $line) use (&$etag): int {
                if (str_starts_with($line, 'HTTP/')) $etag = '';
                if (stripos($line, 'ETag:') === 0) $etag = trim(substr($line, 5));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) return 0;
                $body .= $chunk; return strlen($chunk);
            }];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = $payload;
        curl_setopt_array($curl, $options);
        try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); }
        finally { curl_close($curl); }
        if ($ok === false || !in_array($status, $codes, true)) throw new RuntimeException('iCloud CalDAV request failed.', $status);
        return ['status'=>$status, 'body'=>$body, 'etag'=>$etag];
    }
};
