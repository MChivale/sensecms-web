<?php

declare(strict_types=1);

use App\Core\AccessControl;
use App\Core\HtmlSanitizer;
use App\Core\PageBuilder;

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $count++; echo 'PASS ' . $label . PHP_EOL;
};
$unsafe = [
    '<script>alert(1)</script>', '<img src="/missing.png" onerror="alert(1)">',
    '<a href="javascript:alert(1)">link</a>', '<a href="jav&#x61;script:alert(1)">link</a>',
    '<iframe src="https://example.test"></iframe>', '<svg onload="alert(1)"><script>alert(1)</script></svg>',
];
foreach ($unsafe as $payload) {
    foreach ([0, 1, 2, 8] as $depth) {
        $html = str_repeat('<unknown>', $depth) . $payload . str_repeat('</unknown>', $depth);
        $safe = HtmlSanitizer::sanitize($html);
        $check(!preg_match('/<script|<iframe|\bonerror\s*=|\bonload\s*=|javascript:/i', $safe), 'Unsafe descendants removed at depth ' . $depth);
        $check(HtmlSanitizer::sanitize($safe) === $safe, 'Sanitization is idempotent at depth ' . $depth);
    }
}
$safe = HtmlSanitizer::sanitize('<unknown><p>Hello <strong>world</strong> <a href="https://example.test" target="_blank">link</a></p></unknown>');
$check(str_contains($safe, '<strong>world</strong>') && str_contains($safe, 'rel="noopener noreferrer"'), 'Unwrapping preserves safe content and link protection');
$catalog = PageBuilder::catalog(['slug'=>'sensecms', 'supported_blocks'=>['custom-html']]);
$blocks = PageBuilder::sanitizeBlocks([['uid'=>'12345678-1234-4234-8234-123456789abc', 'type'=>'custom-html', 'localized'=>['en'=>['html'=>'<unknown><unknown><img src="/image.png" onerror="alert(1)"><p>Keep me</p></unknown></unknown>']]]], $catalog, ['en']);
$saved = $blocks[0]['localized']['en']['html'];
$check(!str_contains($saved, 'onerror') && str_contains($saved, 'Keep me'), 'Real builder save sanitizes nested HTML');
$blocks = [['type'=>'custom-html', 'payload'=>['html'=>$saved]]];
ob_start(); require dirname(__DIR__) . '/.themes/sensecms/views/blocks.php'; $rendered = ob_get_clean();
$check(!str_contains($rendered, 'onerror') && str_contains($rendered, 'Keep me'), 'Real theme renders sanitized saved content');

// Optional Linux mode uses a new random database, never an installation database.
$mysql = ($argv[1] ?? '') === '--mysql';
if ($mysql && (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows')) throw new RuntimeException('MariaDB fixture requires private Linux QA.');
$db = $mysql
    ? new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC])
    : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schema = 'senseqa_security_' . bin2hex(random_bytes(6));
$created = false;
try {
    if ($mysql) { $db->exec("CREATE DATABASE `$schema`"); $created = true; $db->exec("USE `$schema`"); }
    else $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pk = $mysql ? 'INTEGER AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY';
    foreach ([
        "CREATE TABLE users(id $pk,name TEXT,email TEXT,password TEXT,job_title TEXT,active INTEGER,is_demo INTEGER,session_version INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT)",
        "CREATE TABLE roles(id $pk,slug TEXT,name TEXT,description TEXT,color TEXT,is_system INTEGER DEFAULT 0,active INTEGER,created_at TEXT,updated_at TEXT)",
        'CREATE TABLE permissions(id INTEGER PRIMARY KEY,slug TEXT)',
        'CREATE TABLE role_permissions(role_id INTEGER,permission_id INTEGER)',
        'CREATE TABLE user_roles(user_id INTEGER,role_id INTEGER)',
        'CREATE TABLE facilities(id INTEGER PRIMARY KEY)',
        'CREATE TABLE user_facilities(user_id INTEGER,facility_id INTEGER,created_at TEXT)',
        'CREATE TABLE activity_log(user_id INTEGER,event TEXT,subject_type TEXT,subject_id INTEGER,context TEXT,created_at TEXT)',
        "INSERT INTO users(id,name,email,active,is_demo) VALUES(1,'Owner','owner@example.test',1,0),(2,'Manager','manager@example.test',1,0),(3,'Editor','editor@example.test',1,0),(4,'Privileged','privileged@example.test',0,0)",
        "INSERT INTO roles(id,slug,name,active) VALUES(1,'owner','Owner',1),(2,'manager','Manager',1),(3,'editor','Editor',1),(4,'dormant-owner','Dormant owner',0),(5,'restricted','Restricted',1)",
        "INSERT INTO permissions VALUES(1,'system.owner'),(2,'users.manage'),(3,'roles.manage'),(4,'console.access'),(5,'system.manage')",
        'INSERT INTO role_permissions VALUES(1,1),(2,2),(2,3),(2,4),(3,4),(4,1),(5,5)',
        'INSERT INTO user_roles VALUES(1,1),(2,2),(3,3),(4,4)',
    ] as $sql) $db->exec($sql);
    $db->exec("INSERT INTO users(id,name,email,active,is_demo) VALUES(5,'Demo','demo@example.test',1,1)");
    $db->exec('INSERT INTO user_roles VALUES(5,3)');
    $_SESSION=['user_id'=>5,'version'=>1,'created'=>time(),'seen'=>time()];
    $demo=new AccessControl($db,new App\Core\Auth($db));
    $check($demo->isDemoUser(),'Demo marker retained');
    $check($demo->permissions()===['console.access','roles.manage','system.manage','users.manage'],'Demo can view all backend sections');
    $check(!$demo->allows('system.owner')&&$demo->facilityIds()===null,'Demo has global visibility without writable Owner capability');
    $check($demo->allows('console.access'),'Assigned demo read access remains available');
    $_SESSION=[];
    $actor = static fn(int $id): AccessControl => AccessControl::forUser($db, $id);
    $snapshot = static function () use ($db): array {
        $data = [];
        foreach (['users','roles','permissions','role_permissions','user_roles','user_facilities','activity_log'] as $table) $data[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll();
        return $data;
    };
    $reject = static function (callable $action, string $label, int $code = 403) use ($check, $snapshot): void {
        $before = $snapshot(); $rejected = false;
        try { $action(); } catch (RuntimeException $error) { $rejected = $error->getCode() === $code; }
        $check($rejected, $label);
        $check($snapshot() === $before, 'Rejected operation preserves accounts, roles and audit');
    };
    $user = ['id'=>2,'name'=>'Manager','email'=>'manager@example.test','active'=>1,'role_id'=>1];
    $reject(fn()=>$actor(2)->saveUser(['id'=>3,'name'=>'Editor','email'=>'editor@example.test','role_id'=>3,'active'=>0],2),'Non-owner cannot disable an account');
    $reject(fn()=>$actor(2)->saveUser(['name'=>'New User','email'=>'new@example.test','role_id'=>3,'active'=>1],2),'Non-owner cannot enable a new account');
    $reject(fn()=>$actor(2)->saveUser(['id'=>5,'name'=>'Demo','email'=>'demo@example.test','role_id'=>3,'active'=>1],2),'Non-owner cannot remove demo protection');
    $demoInput=['id'=>5,'name'=>'Demo','email'=>'demo@example.test','role_id'=>3,'is_demo'=>1,'active'=>0];
    $actor(1)->saveUser($demoInput,1);
    $_SESSION=['user_id'=>5,'version'=>1,'created'=>time(),'seen'=>time()];
    $check(!(new App\Core\Auth($db))->check(),'Owner disabling demo invalidates its session');
    $actor(1)->saveUser(array_replace($demoInput,['active'=>1]),1);
    $check(!(new App\Core\Auth($db))->check(),'Re-enabling does not revive an old session');
    $_SESSION=[];
    $reject(fn()=>$actor(2)->saveUser($user,2), 'Manager cannot become Owner');
    if ($mysql) {
        $other = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=' . $schema, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $lock = 'sense-access-' . substr(hash('sha256', $schema), 0, 40);
        $other->prepare('SELECT GET_LOCK(?,0)')->execute([$lock]);
        $reject(fn()=>$actor(1)->saveUser($user,1), 'Concurrent user edits fail before writes', 409);
        $reject(fn()=>$actor(1)->saveRole(['name'=>'Concurrent','permission_ids'=>[4]],1), 'Role grants share the user-edit lock', 409);
        $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
    $reject(fn()=>$actor(2)->saveUser(array_replace($user,['role_id'=>4]),2), 'Inactive custom owner role cannot be assigned');
    $reject(fn()=>$actor(2)->saveUser(array_replace($user,['role_id'=>5]),2), 'Roles beyond actor permissions cannot be assigned');
    $reject(fn()=>$actor(2)->saveUser(array_replace($user,['id'=>1,'role_id'=>3,'password'=>'not-a-real-password']),2), 'Manager cannot change owner password or downgrade owner');
    $reject(fn()=>$actor(2)->saveUser(array_replace($user,['id'=>4,'role_id'=>3]),2), 'Inactive privileged account is protected');
    $reject(fn()=>$actor(3)->saveUser($user,3), 'Editor cannot manage users');
    $role = ['name'=>'Custom role','slug'=>'custom','active'=>1,'permission_ids'=>[1]];
    $reject(fn()=>$actor(2)->saveRole($role,2), 'Manager cannot grant system.owner');
    $reject(fn()=>$actor(2)->saveRole(array_replace($role,['permission_ids'=>[5]]),2), 'Manager cannot grant any missing permission');
    $reject(fn()=>$actor(2)->saveRole(array_replace($role,['id'=>4,'permission_ids'=>[4]]),2), 'Manager cannot alter dormant privileged role');
    $reject(fn()=>$actor(2)->saveRole(array_replace($role,['id'=>3,'slug'=>'owner','permission_ids'=>[4]]),2), 'Owner slug cannot be assigned through renaming', 0);
    $reject(fn()=>$actor(1)->saveUser(['id'=>1,'name'=>'Owner','email'=>'owner@example.test','active'=>0,'role_id'=>1],1), 'Final writable owner remains protected', 0);
    $actor(2)->saveUser(['id'=>3,'name'=>'Updated editor','email'=>'editor@example.test','active'=>1,'role_id'=>3],2);
    $check($db->query('SELECT name FROM users WHERE id=3')->fetchColumn() === 'Updated editor', 'Manager can still edit a lower-privilege account');
    $id = $actor(2)->saveRole(array_replace($role,['permission_ids'=>[4]]),2);
    $check($id > 5, 'Manager can create a role within own permissions');
    $actor(2)->saveRole(array_replace($role,['id'=>$id,'permission_ids'=>[4],'name'=>'Updated role']),2);
    $check($db->query('SELECT name FROM roles WHERE id=' . $id)->fetchColumn() === 'Updated role', 'Manager can edit an ordinary role');
    $actor(1)->saveUser(array_replace($user,['id'=>3,'name'=>'Second owner','email'=>'editor@example.test']),1);
    $check($actor(3)->allows('system.owner'), 'Owner can intentionally appoint another owner');
    $actor(1)->saveUser(['id'=>1,'name'=>'Former owner','email'=>'owner@example.test','active'=>1,'role_id'=>3],1);
    $check(!$actor(1)->allows('system.owner') && $actor(3)->allows('system.owner'), 'Owner handover preserves a writable owner');
    $cached = $actor(2); $cached->permissions();
    $db->exec('DELETE FROM role_permissions WHERE role_id=2 AND permission_id=3');
    $reject(fn()=>$cached->saveRole(array_replace($role,['slug'=>'revoked','permission_ids'=>[4]]),2), 'Cached permissions do not survive revocation');
    echo $count . ' security regression checks passed (' . ($mysql ? 'isolated MariaDB' : 'SQLite memory') . ').' . PHP_EOL;
} finally {
    if ($created && preg_match('/^senseqa_security_[a-f0-9]{12}$/D', $schema)) $db->exec("DROP DATABASE `$schema`");
}
