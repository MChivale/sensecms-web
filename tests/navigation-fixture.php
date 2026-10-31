<?php
declare(strict_types=1);

// Disposable loopback fixture, never deployed to a public application root.
$name=(string)getenv('SENSE_NAV_TEST_DB');
if(PHP_OS_FAMILY==='Windows'||!in_array(PHP_SAPI,['cli','cli-server'],true)||!preg_match('/^sensenav_[a-f0-9]{12}$/D',$name))exit(2);
if(PHP_SAPI==='cli-server'&&($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1')exit(2);
$project=dirname(__DIR__);$root=$project.'/.cms/source';require $root.'/bootstrap.php';
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(PHP_SAPI==='cli'){
    if(($argv[1]??'')==='drop'){$db->exec("DROP DATABASE IF EXISTS `$name`");exit;}
    if(($argv[1]??'')!=='setup')exit(2);
    $db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
    foreach(explode(';',(string)file_get_contents($root.'/database/001_core.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);
    foreach(['owner','editor']as$user)$db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,NOW())')->execute([$user,$user.'@example.test',password_hash((string)getenv('SENSE_NAV_TEST_PASSWORD'),PASSWORD_ARGON2ID)]);
    $db->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'); INSERT INTO user_roles VALUES (1,1)");
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,NOW())')->execute(['001_core',hash_file('sha256',$root.'/database/001_core.sql')]);
    (new App\Installer\WorkspaceMigration($db,$root))->apply();
    $db->exec("INSERT INTO roles (slug,name,active) VALUES ('nav-editor','Navigation editor',1)");$role=(int)$db->lastInsertId();
    $db->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT $role,id FROM permissions WHERE slug IN ('console.access','facilities.view','content.pages.view','content.pages.edit','content.pages.collaborate','content.posts.view','content.posts.edit','content.seo.manage','content.media.manage','surveys.view','surveys.manage','content.workflow.view')");
    $db->exec("INSERT INTO user_roles VALUES (2,$role)");
    $ids=[];
    foreach(['Alpha','Beta']as$index=>$label){
        $db->prepare("INSERT INTO facilities (uid,city_slug,facility_slug,status,is_primary,created_at,updated_at) VALUES (UUID(),?,?,'active',?,NOW(),NOW())")->execute([strtolower($label),strtolower($label),$index===0?1:0]);$id=(int)$db->lastInsertId();$ids[]=$id;
        $db->prepare('INSERT INTO facility_translations (facility_id,locale,name,city_name) VALUES (?,\'en\',?,?)')->execute([$id,$label,$label]);
        $db->prepare("INSERT INTO pages (facility_id,public_path,status,visibility,created_at,updated_at) VALUES (?,?,'published','public',NOW(),NOW())")->execute([$id,'/'.strtolower($label)]);$page=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO page_translations (page_id,facility_id,locale,title,slug) VALUES (?,?,\'en\',?,?)')->execute([$page,$id,$label.' page',strtolower($label)]);
        $db->prepare("INSERT INTO posts (facility_id,status,created_at,updated_at) VALUES (?,'draft',NOW(),NOW())")->execute([$id]);$post=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO post_translations (post_id,facility_id,locale,title,slug,content) VALUES (?,?,\'en\',?,?,?)')->execute([$post,$id,$label.' post',strtolower($label),'<p>Original content.</p>']);
    }
    $db->prepare('INSERT INTO user_facilities (user_id,facility_id,created_at) VALUES (2,?,NOW())')->execute([$ids[0]]);
    echo json_encode(['facilities'=>$ids]);exit;
}
$db->exec("USE `$name`");session_save_path((string)getenv('SENSE_NAV_TEST_SESSIONS'));session_start();
$auth=new App\Core\Auth($db);$access=new App\Core\AccessControl($db,$auth);$facilities=new App\Core\FacilityRepository($db);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
if($path==='/login'){$ok=$auth->attempt((string)($_POST['email']??'owner@example.test'),(string)($_POST['password']??''));header('Content-Type: application/json');echo json_encode(['ok'=>$ok,'csrf'=>$auth->csrf()]);exit;}
if($path==='/context-state'&&$auth->check()){$ctx=new App\Core\WorkspaceContext($auth,$access,$facilities);header('Content-Type: application/json');echo json_encode(['id'=>$ctx->id(),'scope'=>$ctx->scope(),'authorized'=>$access->facilityIds(),'options'=>array_column($ctx->options(),'id')]);exit;}
$cms=new App\Core\CmsRepository($db,new App\Core\EventBus());
$themes=new App\Core\ManifestRegistry($project.'/.themes','theme.json','theme');$plugins=new App\Core\ManifestRegistry($root.'/plugins','plugin.json');$addons=new App\Core\ManifestRegistry($root.'/addons','addon.json');$packages=new App\Core\PackageManager($db,$root,'1.0.0');
$controller=new App\Http\DashboardController($auth,$access,new App\Core\WorkflowRepository($db,$access),new App\Core\MediaLibrary($db,$root.'/public'),new App\Core\SurveyRepository($db,'navigation-qa'),$cms,$facilities,$themes,$plugins,$addons,$packages,new App\Core\MarketplaceGovernance($db),new App\Core\ConsoleSearchIndex($cms,$themes,$plugins,$addons,$packages),new App\Core\AiRepository($db),new App\Core\LicenseService($root.'/storage/nav-test-license',new App\Core\LicenseClient([])));
try{
    $access->enforceRequest($method,$path);
    if($path==='/workspace/context'&&$method==='POST')$controller->switchWorkspace();
    if($path==='/content/pages')$method==='POST'?$controller->savePage():$controller->pages();
    if($path==='/content/posts')$method==='POST'?$controller->savePost():$controller->posts();
    if($path==='/content/pages/new')$controller->pageForm();
    if($path==='/content/posts/new')$controller->postForm();
    if(preg_match('~^/content/(pages|posts)/(\d+)/edit$~',$path,$m))$m[1]==='pages'?$controller->pageForm((int)$m[2]):$controller->postForm((int)$m[2]);
    if($path==='/content/builder')$controller->pageBuilder();
    if($path==='/seo')$controller->seo();
    if($path==='/surveys')$controller->surveys();
    if($path==='/content/media')$controller->mediaLibrary();
    if($path==='/content/facilities')$controller->facilities();
    if($path==='/content/workflow')$controller->workflow();
    if($path==='/dashboard')$controller->dashboard();
    http_response_code(404);
}catch(Throwable$error){http_response_code($error->getCode()===403?403:500);echo json_encode(['error'=>$error->getMessage()]);}
