<?php
declare(strict_types=1);
// Companion to chat-callback.py. Only temporary tables, an isolated local DB and loopback SMTP.
use App\Core\{AiChatService,AiRepository,ChatCallbackTeam,CmsRepository,EmailSystem,EventBus,ExtensionRuntime,PostgresConnection,Secrets};
require dirname(__DIR__).'/.cms/source/bootstrap.php';
if (PHP_SAPI!=='cli' || $argc!==4 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) exit(2);
$db=new PostgresConnection('pgsql:host=127.0.0.1;port='.$argv[1].';dbname=sensecms_scenarios_qa','postgres',getenv('PGPASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if ($db->query('SELECT current_database()')->fetchColumn()!=='sensecms_scenarios_qa') exit(2);
// Use the same PostgreSQL compatibility functions as the installed Calendar package.
if (!$db->query("SELECT to_regprocedure('sense_unique_text(text[])')")->fetchColumn()) {
    $setup=new PDO('pgsql:host=127.0.0.1;port='.$argv[1].';dbname=sensecms_scenarios_qa','postgres',getenv('PGPASSWORD'));
    $setup->exec(file_get_contents(dirname(__DIR__).'/deploy/postgresql/utc.sql'));
}
$db->exec("CREATE TEMP TABLE settings(key varchar PRIMARY KEY,value text);
CREATE TEMP TABLE ai_conversations(id char(36) PRIMARY KEY,locale text,visitor_name text,visitor_ip text,visitor_country text,visitor_email text,email_requested_at timestamp,channel text,status text,assigned_user_id int,assigned_team_id int,queued_at timestamp,ai_takeover_at timestamp,created_at timestamp,updated_at timestamp);
CREATE TEMP TABLE ai_messages(id serial PRIMARY KEY,conversation_id char(36),role text,content text,provider_slug text,created_at timestamp);
CREATE TEMP TABLE users(id int primary key,name text,email text,is_demo int,active int);
CREATE TEMP TABLE roles(id int,active int);CREATE TEMP TABLE permissions(id int,slug text);CREATE TEMP TABLE role_permissions(role_id int,permission_id int);CREATE TEMP TABLE user_roles(user_id int,role_id int);
CREATE TEMP TABLE live_chat_teams(id int primary key,name text,active int);CREATE TEMP TABLE live_chat_team_users(team_id int,user_id int);
CREATE TEMP TABLE languages(id int,locale text,enabled int,is_default int,sort_order int);
CREATE TEMP TABLE user_notifications(id serial primary key,user_id int,type text,title text,message text,url text,created_at timestamp);
CREATE TEMP TABLE activity_log(user_id int,event text,subject_type text,subject_id int,context text,created_at timestamp);
CREATE TEMP TABLE live_chat_reads(conversation_id char(36));CREATE TEMP TABLE live_chat_transfers(conversation_id char(36));
CREATE TEMP TABLE extension_packages(type text,slug text,install_path text,active int);
CREATE TEMP TABLE calendar_events(id serial primary key,uid char(36) unique,owner_id int,title text,description text,event_type text,status text,visibility text,timezone text,start_at timestamp,end_at timestamp,all_day int,created_at timestamp,updated_at timestamp);
CREATE TEMP TABLE calendar_event_participants(event_id int,user_id int,response_status text, primary key(event_id,user_id));
ALTER TABLE calendar_events ADD COLUMN facility_id int;
CREATE TEMP TABLE facilities(id int,facility_slug text);
CREATE TEMP TABLE calendar_event_resources(event_id int,resource_id int);
CREATE TEMP TABLE calendar_resources(id int,name text);
INSERT INTO users VALUES(1,'A','owner@example.test',0,1),(2,'B','team@example.test',0,1),(3,'Demo','demo@example.test',1,1),(4,'No role','norole@example.test',0,1);
INSERT INTO roles VALUES(1,1);INSERT INTO permissions VALUES(1,'console.access'),(2,'chat.view');INSERT INTO role_permissions VALUES(1,1),(1,2);INSERT INTO user_roles VALUES(1,1),(2,1),(3,1);
INSERT INTO live_chat_teams VALUES(1,'Fallback',1),(2,'Assigned',1);INSERT INTO live_chat_team_users VALUES(1,2),(1,3),(1,4),(2,1),(2,2);
INSERT INTO languages VALUES(1,'pl',1,1,1);
INSERT INTO extension_packages VALUES('addon','calendar','addons/calendar',0)");
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMP TABLE',file_get_contents(dirname(__DIR__).'/.cms/source/database/workspace/045_chat_callback_receipts.sql')));
$check=static function(bool $test,string $label):void{if(!$test)throw new RuntimeException($label);echo "PASS $label\n";};
$cms=new CmsRepository($db,new EventBus());$repo=new AiRepository($db);$secrets=new Secrets(str_repeat('x',64));
$chat=new AiChatService($repo,$secrets,$cms);$routing=new ChatCallbackTeam($db,$cms);
$cal=new ExtensionRuntime($db,$argv[3]);
$email=new EmailSystem($db,$cms,$secrets,['mail'=>['host'=>'127.0.0.1','port'=>(int)$argv[2],'security'=>'none','from_address'=>'sender@example.test','from_name'=>'Test Sender']],'https://installation.example');
$cms->saveSetting('site_name','Studio & Test');
$cms->saveSetting('live_chat_settings',['availability'=>['timezone'=>'Europe/Warsaw']]);
$forms=$email->dashboard()['templates'];
$forms['chat_callback_pl']['chat']='Dziękujemy! Zespół {{site_name}} odezwie się do Ciebie.';
$forms['chat_callback_pl']['subject']='Potwierdzenie · {{site_name}}';
$forms['chat_callback_pl']['body']="Dziękujemy za rozmowę z {{site_name}}.\n\nNasz zespół odpowie na ten adres e-mail.";
$original=$forms['welcome'];$email->saveTemplates($forms,1);
$invalid=$forms;$invalid['chat_callback_pl']['subject']="Header\r\nInjected";
try{$email->saveTemplates($invalid,1);throw new LogicException('invalid template accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'template'),'invalid mail template rejected');}
$capture=function(string $id,string $locale,string $to)use($repo,$chat){$repo->conversation($id,$locale);$repo->requestVisitorEmail($id);return $chat->reply('',$locale,$id,'',false,null,$to);};
$repo->conversation('unrequested','pl');
try{$chat->reply('','pl','unrequested','',false,null,'stranger@example.test');throw new LogicException('unsolicited email accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'not been requested'),'unsolicited recipient rejected');}
$assigned='11111111-1111-4111-8111-111111111111';$team='22222222-2222-4222-8222-222222222222';$fallback='33333333-3333-4333-8333-333333333333';
$repo->conversation($assigned,'en'); // The visitor switches to Polish before requesting the callback.
$check($capture($assigned,'pl','visitor-one@example.test')['message']==='Dziękujemy! Zespół Studio & Test odezwie się do Ciebie.','custom chat template uses installation name');
$chat->reply('','pl',$assigned,'',false,null,'visitor-one@example.test');
$capture($team,'en','visitor-two@example.test');
$capture($fallback,'pl','visitor-three@example.test');
$db->exec("UPDATE ai_conversations SET assigned_user_id=1,assigned_team_id=1 WHERE id='$assigned'; UPDATE ai_conversations SET assigned_team_id=2 WHERE id='$team'");
$check((int)$db->query("SELECT count(*) FROM ai_messages WHERE conversation_id='$assigned'")->fetchColumn()===1,'retry produces one chat confirmation');
$chat->reply('Dodatkowa informacja','pl',$assigned,'',false,null,'visitor-one@example.test');
$check((int)$db->query("SELECT count(*) FROM ai_messages WHERE conversation_id='$assigned' AND role='visitor' AND content='Dodatkowa informacja'")->fetchColumn()===1,'subsequent visitor message from cached widget preserved');
$check($routing->route()===2,'assigned operator and team routed; unassigned waits');
$forms['callback_routing']=['team_id'=>1];$email->saveTemplates($forms,1);
$db->exec("UPDATE chat_callback_receipts SET route_after=NULL WHERE conversation_id='$fallback'");
$check($routing->route()===1 && $routing->route()===0,'fallback processes once after configuration');
foreach([$assigned=>[1],$team=>[1,2],$fallback=>[2]] as $id=>$ids){
    $q=$db->prepare('SELECT user_id FROM user_notifications WHERE url=? ORDER BY user_id');$q->execute(['/conversations?conversation='.$id]);
    $check(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))===$ids,'notifications to authorised recipients '.$ids[0].'/'.count($ids));
}
$check($cal->recordChatCalendarTasks()===0,'inactive Calendar leaves tasks pending');
$db->exec("UPDATE extension_packages SET active=1; UPDATE chat_callback_receipts SET work_date='2026-03-29', timezone='Europe/Warsaw'");
$check($cal->recordChatCalendarTasks()===3 && $cal->recordChatCalendarTasks()===0,'three calendar tasks created exactly once');
$check((int)$db->query("SELECT count(*) FROM calendar_events WHERE status='callback_task' AND all_day=1 AND visibility='participants' AND start_at='2026-03-28 23:00:00' AND end_at='2026-03-29 22:00:00'")->fetchColumn()===3,'calendar day respects timezone and DST');
$check((int)$db->query('SELECT count(*) FROM calendar_event_participants')->fetchColumn()===4,'only selected team members on calendar');
require dirname(__DIR__).'/.addons/calendar/src/CalendarRepository.php';
$calendar=new SenseCMS\Calendar\CalendarRepository($db,1);
$visible=$calendar->events('2026-03-29','2026-03-30',null);
$check(count($visible)===2 && $visible[0]['all_day'] && $visible[0]['start_date']==='2026-03-29','tasks visible on request date through Calendar listing');
$check((new SenseCMS\Calendar\CalendarRepository($db,4))->events('2026-03-29','2026-03-30',null)===[],'unassigned team cannot see callback tasks');
$check($calendar->conflictsForInput(['participant_ids'=>[1,2],'timezone'=>'Europe/Warsaw','start_at'=>'2026-03-29T10:00','end_at'=>'2026-03-29T10:30'],null)===[],'internal tasks do not block bookings');
$cms->saveSetting('email_system_settings',['server'=>['mode'=>'disabled']]);
$check($email->deliverChatReceipts()['sent']===0,'disabled mail retains queued requests');
$cms->saveSetting('email_system_settings',['server'=>['mode'=>'smtp'],'appearance'=>['accent_color'=>'#227755','logo_url'=>'/uploads/logo.png']]);
$check($email->deliverChatReceipts()['sent']===3 && $email->deliverChatReceipts()['sent']===0,'one confirmation per visitor');
$check($email->deliverChatReceipts(true)['sent']===4 && $email->deliverChatReceipts(true)['sent']===0,'one internal email per authorised recipient and request');
$check($email->dashboard()['templates']['welcome']===$original,'account email template preserved');
$capture('smtp-retry','en','retry@example.test');$capture('smtp-unknown','pl','unknown@example.test');
$out=$email->deliverChatReceipts();$check($out['retry']===1 && $out['unknown']===1,'known temporary refusal retries; ambiguous acceptance retained for review');
$db->exec("UPDATE chat_callback_receipts SET next_attempt_at=UTC_TIMESTAMP() WHERE conversation_id='smtp-retry'");
$check($email->deliverChatReceipts()['sent']===1,'only safe retry resends');
$capture('interrupted','pl','interrupted@example.test');
$db->exec("UPDATE chat_callback_receipts SET status='sending',next_attempt_at=UTC_TIMESTAMP() WHERE conversation_id='interrupted'");
$check($email->deliverChatReceipts()['unknown']===1,'interrupted mail attempt requires review and is not duplicated');
$capture('deleted','pl','deleted@example.test');$repo->deleteConversation('deleted');
$check($email->deliverChatReceipts()['sent']===0,'deleted contact never mailed');
