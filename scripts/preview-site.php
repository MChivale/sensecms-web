<?php

declare(strict_types=1);

// Developer-only, loopback-bound theme preview. Never a production entry point.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?$/D', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(404); exit;
}
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/_email-preview') {
    $form=['data'=>['fields'=>[['key'=>'name','label'=>'Your name'],['key'=>'email','label'=>'Email address'],['key'=>'topic','label'=>'How can we help?'],['key'=>'message','label'=>'Your message']]]];
    $mail=App\Core\FormMail::render($form,['name'=>'Website visitor','email'=>'visitor@example.test','topic'=>'Platform & projects','message'=>'We are planning a new website for our organisation and would like to discuss content management across several locations.'],'SENSE-PREVIEW',true,['name'=>'Sense CMS','email'=>'info@SenseCMS.com']);
    header('Content-Type: text/html; charset=UTF-8'); header('X-Robots-Tag: noindex, nofollow'); echo $mail['html']; exit;
}
$theme = new App\Core\PublicTheme(dirname(__DIR__) . '/.themes/sensecms', 'https://www.sensecms.com');
[$status, $headers, $body] = $theme->response((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), $_SERVER['REQUEST_METHOD'] ?? 'GET');
http_response_code($status);
foreach ($headers as $name => $value) header($name . ': ' . $value);
header('X-Robots-Tag: noindex, nofollow');
echo $body;
