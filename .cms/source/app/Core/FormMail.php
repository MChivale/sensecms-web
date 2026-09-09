<?php
declare(strict_types=1);

namespace App\Core;

final class FormMail
{
    public static function render(array $form, array $payload, string $reference, bool $copy, array $brand = []): array
    {
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name=trim((string)($brand['name']??'')) ?: 'Sense CMS';
        $address=filter_var($brand['email']??'',FILTER_VALIDATE_EMAIL) ? (string)$brand['email'] : '';
        $heading = $copy ? 'Thank you for getting in touch.' : 'A new conversation starts here.';
        $intro = $copy ? 'We have received your message. Below is a copy for your records. Our team will review your enquiry and reply to the email address you provided.' : 'A website visitor has sent the following enquiry. Reply directly to this email to continue the conversation.';
        $rows = ''; $plain = [];
        foreach ((array) ($form['data']['fields'] ?? []) as $field) {
            $key = (string) ($field['key'] ?? '');
            if (!array_key_exists($key, $payload)) continue;
            $label = (string) ($field['label'] ?? $key);
            $value = is_bool($payload[$key]) ? ($payload[$key] ? 'Yes' : 'No') : (string) $payload[$key];
            if ($value === '') continue;
            $plain[] = $label . ': ' . $value;
            $rows .= '<tr><td style="padding:16px 0;border-bottom:1px solid #e2eaf4"><p style="margin:0 0 7px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#58708e">'.$e($label).'</p><p style="margin:0;color:#153251;font-size:15px;line-height:1.7;overflow-wrap:anywhere;word-break:break-word">'.nl2br($e($value), false).'</p></td></tr>';
        }
        $footer = $copy ? 'This copy was requested through our website using your email address. If you did not send this enquiry, please let us know. This is a service message, not a marketing subscription.' : 'This message is also available in the Sense CMS form inbox. Treat visitor content as untrusted.';
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($heading).'</title></head><body style="margin:0;background:#eef3fa;font-family:Arial,Helvetica,sans-serif"><div style="display:none;max-height:0;overflow:hidden">'.$e($intro).'</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3fa"><tr><td align="center" style="padding:36px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dce6f2;border-radius:16px;overflow:hidden"><tr><td style="padding:32px;background:#09244d;color:#fff"><div style="font-size:27px;letter-spacing:-1px;font-weight:bold">'.$e($name).'</div><p style="margin:24px 0 0;font-size:10px;letter-spacing:2px;color:#b5cff3">A CLEAR FOUNDATION. A DIRECT CONVERSATION.</p></td></tr><tr><td style="padding:32px"><h1 style="margin:0 0 18px;font-size:28px;line-height:1.2;letter-spacing:-.8px;color:#09244d">'.$e($heading).'</h1><p style="font-size:15px;line-height:1.8;color:#526580;margin:0 0 24px">'.$e($intro).'</p><p style="padding:12px 16px;background:#edf4ff;color:#285589;font-size:12px;border-radius:6px">REFERENCE · '.$e($reference).'</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table><p style="margin:28px 0 0;font-size:14px;line-height:1.8;color:#526580">'.$e($name).' team<br>'.($address!==''?'<a href="mailto:'.$e($address).'" style="color:#145cde;text-decoration:none">'.$e($address).'</a>':'').'</p></td></tr><tr><td style="padding:24px 32px;background:#f7faff;border-top:1px solid #e2eaf4;color:#657990;font-size:11px;line-height:1.8">'.$e($footer).'</td></tr></table><p style="color:#71829a;font-size:11px;line-height:1.8;margin:22px 0 0">'.$e($name).'</p></td></tr></table></body></html>';
        return ['subject'=>($copy?'Your message to '.$name:'New enquiry for '.$name).' · '.$reference, 'html'=>$html, 'text'=>$heading."\n\n".$intro."\nReference: ".$reference."\n\n".implode("\n\n",$plain)."\n\n".$footer];
    }
}
