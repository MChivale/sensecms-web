<?php
declare(strict_types=1);

// Operator-only product-site setup, excluded from the clean CMS distribution.
if (PHP_SAPI !== 'cli' || !in_array($argv[2] ?? '', ['--apply','--rollback','--test-mail'],true)) exit("Specify installation, action and private backup directory.\n");
$root=realpath($argv[1]); $backup=realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup, '/root/')) throw new RuntimeException('Private operator paths required.');
require $root.'/bootstrap.php';
$runtime=new App\Core\Runtime($root); $installed=$runtime->read('installed'); $baseUrl=$runtime->baseUrl();
$config=require $root.'/config/workspace.php';
$db=App\Core\Runtime::connect($installed['database']); $cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$runtime);
$email=new App\Core\EmailSystem($db,$cms,new App\Core\Secrets($config['secrets_key']),$config,$baseUrl);
$page=$cms->pageAtPath('/contact');
if (!$page || (int)$page['facility_id']!==1) throw new RuntimeException('Expected product contact page.');
$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();
$stateFile=$backup.'/contact-before.json';
if ($argv[2]==='--rollback') {
    $state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
    // Withdraw only the one newly inserted form; preserve concurrent edits and received messages.
    $db->prepare('UPDATE content_blocks SET visible=0,archived_at=NOW() WHERE uid=? AND page_id=?')->execute([$state['uid'],$page['id']]);
    $cms->saveSetting('email_system_settings',$state['mail']);
    echo "Contact form withdrawn; messages and original content retained.\n"; exit;
}
if ($argv[2]==='--test-mail') {
    if ($baseUrl!=='https://www.sensecms.com') throw new RuntimeException('Explicit production test only.');
    $marker=$backup.'/test-mail-started'; if (file_exists($marker)) throw new RuntimeException('Test already attempted; inspect instead of sending twice.');
    $form=$cms->contactForm('03000000-0000-4000-a000-000000000001','en','en');
    if (!$form) throw new RuntimeException('Product form not available.');
    $message=App\Core\FormMail::render($form,['name'=>'Mario','email'=>'mario@ittsp.com','topic'=>'Platform & projects','message'=>'Wiadomość testowa formularza kontaktowego Sense CMS. Tak wygląda kopia wiadomości, którą otrzyma nadawca po wysłaniu formularza. To test wyglądu szablonu i konfiguracji poczty.','consent'=>true],'SENSE-TEST-'.gmdate('Ymd-His'),true,$email->formBrand());
    file_put_contents($marker,gmdate(DATE_ATOM),LOCK_EX);
    $email->mailer()->sendHtml('mario@ittsp.com','[TEST] '.$message['subject'],$message['html'],$message['text']);
    file_put_contents($backup.'/test-mail-accepted',gmdate(DATE_ATOM));
    echo "Test message accepted by SMTP for the requested recipient.\n"; exit;
}
$uid='03000000-0000-4000-a000-000000000001'; $doc=$cms->builderDocument((int)$page['id']);
if (file_exists($stateFile) || array_filter($doc['blocks'],fn($b)=>$b['type']==='contact-form')) throw new RuntimeException('Contact setup already exists; inspect before rerunning.');
file_put_contents($stateFile,json_encode(['uid'=>$uid,'mail'=>$cms->setting('email_system_settings',[]),'document'=>$doc],JSON_THROW_ON_ERROR));
$qa=str_starts_with($root,'/root/sense-workspace-test.');
if ($qa) $server=['mode'=>'smtp','host'=>'127.0.0.1','port'=>2526,'security'=>'none','username'=>'','password'=>'','from_address'=>'info@sensecms.com','from_name'=>'Sense CMS'];
else {
    $private=json_decode(file_get_contents('/root/sensecms-private/contact-mail.json'),true,512,JSON_THROW_ON_ERROR);
    $server=['mode'=>'smtp','host'=>$private['host'],'port'=>$private['port'],'security'=>$private['security'],'username'=>$private['username'],'password'=>$private['password'],'from_address'=>$private['from_address'],'from_name'=>'Sense CMS'];
}
$email->saveServer($server,$owner);
$fields=[
    ['key'=>'name','type'=>'text','label'=>'Your name','placeholder'=>'First and last name','required'=>true],
    ['key'=>'email','type'=>'email','label'=>'Email address','placeholder'=>'you@company.com','required'=>true],
    ['key'=>'company','type'=>'text','label'=>'Company / organisation','placeholder'=>'Optional','required'=>false],
    ['key'=>'topic','type'=>'select','label'=>'How can we help?','options'=>"Platform & projects\nTechnical question\nLicensing & releases\nSomething else",'required'=>true],
    ['key'=>'message','type'=>'textarea','label'=>'Your message','placeholder'=>'Tell us about your project or question…','required'=>true],
    ['key'=>'consent','type'=>'checkbox','label'=>'I agree that Sense CMS may use these details to respond to my enquiry and email me a copy.','required'=>true],
];
$new=['uid'=>$uid,'type'=>'contact-form','visible'=>true,'shared'=>['recipient_email'=>$qa?'team@example.test':'info@sensecms.com','store_submissions'=>true,'email_notifications'=>true,'sender_copy'=>true,'captcha'=>true,'rate_limit'=>5],'localized'=>['en'=>['eyebrow'=>'START THE CONVERSATION','title'=>'Send us a message','text'=>'A few details are all we need. We’ll send a copy of your message to your email address.','fields'=>$fields,'submit_label'=>'Send message','success_message'=>'Thank you. Your message has been received.','error_message'=>'We could not send your message. Please try again later.','privacy_text'=>'Your details are used to handle this enquiry and send your message copy. Sending this form does not subscribe you to marketing emails.']]];
$theme=json_decode(file_get_contents((new App\Core\Packages\ThemeManager($runtime))->activePath().'/theme.json'),true);
$catalog=App\Core\PageBuilder::catalog($theme);
$blocks=App\Core\PageBuilder::sanitizeBlocks(array_merge([$new],$doc['blocks']),$catalog,array_keys($doc['translations']));
$cms->saveBuilderDocument((int)$page['id'],(int)$doc['builder_version'],$owner,'sensecms',$blocks,array_keys($catalog));
echo "Contact form added through the page builder; existing blocks retained.\n";
