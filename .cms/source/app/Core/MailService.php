<?php

declare(strict_types=1);

namespace App\Core;

final class MailService
{
    public function __construct(private readonly array $config) {}

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $this->deliver($to,$subject,$body,['Content-Type: text/plain; charset=UTF-8'],$replyTo);
    }

    public function sendHtml(string $to, string $subject, string $html, string $text = '', ?string $replyTo = null): void
    {
        $boundary = 'sensecms_' . bin2hex(random_bytes(16));
        $body = '--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($text)."\r\n--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($html)."\r\n--".$boundary."--";
        $this->deliver($to,$subject,$body,['Content-Type: multipart/alternative; boundary="'.$boundary.'"'],$replyTo);
    }

    private function deliver(string $to, string $subject, string $body, array $contentHeaders, ?string $replyTo): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('The notification recipient is invalid.');
        $from = trim((string) ($this->config['from_address'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('The mail sender is not configured.');
        $headers = array_merge(['From: ' . $this->mailbox((string) ($this->config['from_name'] ?? 'Base CMS'), $from), 'MIME-Version: 1.0'], $contentHeaders);
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
        $host = trim((string) ($this->config['host'] ?? ''));
        if ($host === '') {
            if (!mail($to, $this->encode($subject), $body, implode("\r\n", $headers))) throw new \RuntimeException('The mail notification could not be sent.');
            return;
        }
        $this->smtp($host, (int) ($this->config['port'] ?? 587), $from, $to, $subject, $body, $headers);
    }

    private function smtp(string $host, int $port, string $from, string $to, string $subject, string $body, array $headers): void
    {
        $security = in_array(($this->config['security'] ?? ''), ['ssl','tls','none'], true) ? (string)$this->config['security'] : ($port === 465 ? 'ssl' : 'tls');
        $secure = $security === 'ssl';
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (@gethostbynamel($host) ?: []);
        $context = stream_context_create(['ssl'=>['peer_name'=>$host,'SNI_enabled'=>true,'verify_peer'=>true,'verify_peer_name'=>true]]);
        $stream = false;
        foreach (array_unique($addresses) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $stream = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . $address . ':' . $port, $code, $message, 10, STREAM_CLIENT_CONNECT, $context);
            if (is_resource($stream)) break;
        }
        if (!is_resource($stream)) throw new \RuntimeException('The SMTP service is unavailable.');
        stream_set_timeout($stream, 10);
        try {
            $this->expect($stream, [220]); $this->command($stream, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
            if ($security === 'tls') { $this->command($stream, 'STARTTLS', [220]); if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP encryption failed.'); $this->command($stream, 'EHLO ' . (gethostname() ?: 'localhost'), [250]); }
            $user = (string) ($this->config['username'] ?? '');
            if ($user !== '') { $this->command($stream, 'AUTH LOGIN', [334]); $this->command($stream, base64_encode($user), [334]); $this->command($stream, base64_encode((string) ($this->config['password'] ?? '')), [235]); }
            $this->command($stream, 'MAIL FROM:<' . $from . '>', [250]); $this->command($stream, 'RCPT TO:<' . $to . '>', [250,251]); $this->command($stream, 'DATA', [354]);
            $normalized = str_replace(["\r\n", "\r"], "\n", $body);
            $wireBody = str_replace("\n", "\r\n", preg_replace('/^\./m', '..', $normalized));
            $message = implode("\r\n", array_merge(['Date: ' . date(DATE_RFC2822), 'To: ' . $to, 'Subject: ' . $this->encode($subject)], $headers)) . "\r\n\r\n" . $wireBody . "\r\n.";
            $this->command($stream, $message, [250]);
            // DATA acceptance is delivery handoff; a dropped QUIT must not trigger a duplicate.
            try { $this->command($stream, 'QUIT', [221]); } catch (\RuntimeException) {}
        } finally { fclose($stream); }
    }

    private function command($stream, string $command, array $codes): void { $data=$command."\r\n"; for($offset=0,$length=strlen($data);$offset<$length;$offset+=$written){$written=fwrite($stream,substr($data,$offset));if($written===false||$written===0)throw new \RuntimeException('SMTP write failed.');} $this->expect($stream, $codes); }
    private function expect($stream, array $codes): void
    {
        $response = ''; do { $line = fgets($stream, 1024); if ($line === false) break; $response .= $line; } while (isset($line[3]) && $line[3] === '-');
        if (!in_array((int) substr($response, 0, 3), $codes, true)) throw new \RuntimeException('SMTP rejected the request.');
    }
    private function mailbox(string $name, string $email): string { return $this->encode(str_replace(["\r", "\n"], '', $name)) . ' <' . $email . '>'; }
    private function encode(string $value): string { return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $value)) . '?='; }
}
