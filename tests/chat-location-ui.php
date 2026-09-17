<?php
declare(strict_types=1);
// Loopback-only visual fixture. No database, credentials or real visitor information.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$root=dirname(__DIR__).'/.cms/source';require $root.'/bootstrap.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('#^/(theme|sensecms)/[a-zA-Z0-9/_.-]+\.(css|js|mp3|woff2?)$#D',$path,$match) && !str_contains($path,'..')) {
    $file=$root.'/public'.$path;if(!is_file($file)){http_response_code(404);exit;}
    header('Content-Type: '.(['css'=>'text/css','js'=>'text/javascript','mp3'=>'audio/mpeg','woff'=>'font/woff','woff2'=>'font/woff2'][$match[2]]));readfile($file);exit;
}
$conversation=['id'=>'11111111-1111-4111-a111-111111111111','visitor_name'=>'QA visitor','visitor_email'=>null,'visitor_ip'=>'2001:db8:abcd:1234:5678:90ab:cdef:1234','visitor_country'=>'TH','status'=>'queued','channel'=>'human','team_name'=>null,'agent_name'=>null,'locale'=>'en','preview'=>'Visual QA message','last_message'=>'Visual QA message','unread_count'=>1,'messages'=>[]];
if($path==='/api/operator/chat-events'){header('Content-Type: application/json');echo json_encode(['ok'=>true,'data'=>['pending'=>[$conversation],'assignments'=>[],'messages'=>[],'unread'=>[],'unread_total'=>1,'server_time'=>time()]]);exit;}
if($path!=='/'){http_response_code(404);exit;}
$screen='conversations';$conversations=[$conversation];$liveChatUnread=1;$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$statusClass=static fn($v)=>'text-primary';$csrf='visual-fixture';$user=['id'=>1];$chatTeams=[];$chatUsers=[];
?><!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/theme/sensecms-conversations.css"><link rel="stylesheet" href="/theme/sensecms-live-chat.css"><style>*{box-sizing:border-box}body{font:14px system-ui;margin:16px;background:#f8fafc;color:#17243a}.card{background:white}button,input,textarea{font:inherit}.btn{padding:12px;border:1px solid #ccc;border-radius:8px}.bg-primary{background:#2563eb;color:white}a{color:#2563eb}</style></head><body data-sensecms-user='{"id":1}' data-sensecms-csrf="visual-fixture"><?php require $root.'/app/Views/console-live-chat.php'; ?><?php if(isset($_GET['popup'])): ?><script src="/theme/sensecms-live-chat.js"></script><?php endif; ?></body></html>
