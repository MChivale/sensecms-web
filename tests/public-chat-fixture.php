<?php
declare(strict_types=1);
// Isolated loopback fixture only. Never copy this into a deployed public root.
$name = (string) getenv('SENSE_CHAT_TEST_DB');
if (PHP_OS_FAMILY === 'Windows' || !in_array(PHP_SAPI, ['cli','cli-server'], true) || !preg_match('/^sensechat_[a-f0-9]{12}$/D', $name)) exit(2);
if (PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') exit(2);
$root = dirname(__DIR__) . '/.cms/source'; require $root . '/bootstrap.php';
$db = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'drop') { $db->exec("DROP DATABASE IF EXISTS `$name`"); exit; }
    if (($argv[1] ?? '') !== 'setup') exit(2);
    $db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $db->exec("USE `$name`");
    foreach (explode(';', (string)file_get_contents($root.'/database/001_core.sql')) as $sql) if (trim($sql) !== '') $db->exec($sql);
    $db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute(['QA operator','operator@example.test',password_hash((string)getenv('SENSE_CHAT_TEST_PASSWORD'),PASSWORD_ARGON2ID)]);
    $db->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'); INSERT INTO user_roles VALUES (1,1)");
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP())')->execute(['001_core',hash_file('sha256',$root.'/database/001_core.sql')]);
    (new App\Installer\WorkspaceMigration($db,$root))->apply(); exit;
}
$db->exec("USE `$name`");
session_save_path((string)getenv('SENSE_CHAT_TEST_SESSIONS')); session_start();
$cms = new App\Core\CmsRepository($db,new App\Core\EventBus());
$repository = new App\Core\AiRepository($db);
$auth = new App\Core\Auth($db);
$chat = new App\Http\AiChatController(new App\Core\AiChatService($repository,new App\Core\Secrets(str_repeat('a',64)),$cms),$cms);
$path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path === '/login') {
    $ok = $auth->attempt('operator@example.test',(string)($_POST['password'] ?? ''));
    header('Content-Type: application/json'); echo json_encode(['ok'=>$ok,'csrf'=>App\Core\Auth::csrf()]); exit;
}
if ($path === '/api/chat/state') $chat->state();
if ($path === '/api/chat/message') $chat->reply(true);
if ($path === '/toggle' && $auth->check() && App\Core\Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $cms->saveSetting('extension_states',['live-chat'=>($_POST['enabled'] ?? '') === '1']); echo '{}'; exit;
}
if (preg_match('#^/operator/([a-f0-9-]{36})/(connect|reply|close|state)$#D',$path,$match) && $auth->check() && App\Core\Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    if ($match[2] === 'state') {echo json_encode(['data'=>$repository->conversationWithMessages($match[1],1)]);exit;}
    if ($match[2] === 'connect') (new App\Http\LiveChatController($auth,$repository))->connect($match[1]);
    $ok = $match[2] === 'reply' ? $repository->agentReply($match[1],1,(string)$_POST['content']) : $repository->deleteConversation($match[1]);
    echo json_encode(['ok'=>$ok]); exit;
}
if ($path === '/') {
    if (isset($_GET['static'])) {
        [$status,$headers,$body]=(new App\Core\PublicTheme(dirname(__DIR__).'/.themes/sensecms','https://www.sensecms.com'))->response('/');
        echo App\Core\PublicChat::inject($body,App\Core\LiveChatSettings::defaults(),'en');exit;
    }
    $data=['page'=>['id'=>1,'title'=>'QA','excerpt'=>'Chat fixture','template'=>'default','blocks'=>[]], 'locale'=>'en','themeSettings'=>[], 'baseUrl'=>'https://www.sensecms.com','navigation'=>[], 'extensionStates'=>array_replace(['live-chat'=>true],(array)$cms->setting('extension_states',[])), 'liveChatSettings'=>App\Core\LiveChatSettings::defaults(), 'isThemePreview'=>isset($_GET['preview'])];
    $data['page']['blocks']=[['type'=>'text','payload'=>['title'=>'Rendered block','text'=>'Theme blocks reuse the data variable.']]];
    $controller=(new ReflectionClass(App\Http\PublicController::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod($controller,'view'))->invoke($controller,dirname(__DIR__).'/.themes/sensecms/views/page.php',$data);
}
http_response_code(404);
