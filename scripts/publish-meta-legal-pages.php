<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==4||!in_array($argv[2],['--apply','--rollback'],true))exit("Usage: php publish-meta-legal-pages.php <installation> --apply|--rollback <private-backup>\n");
umask(0077);
$root=realpath($argv[1]);$backup=realpath($argv[3]);
if(!$root||!is_file($root.'/bootstrap.php')||!$backup||!str_starts_with(str_replace('\\','/',$backup),'/root/'))throw new RuntimeException('Existing private operator paths are required.');
require$root.'/bootstrap.php';
$runtime=new App\Core\Runtime($root);$runtime->license()->enforce($runtime->baseUrl());
$db=App\Core\Runtime::connect($runtime->read('installed')['database']);$cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$runtime);
$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
$facility=(new App\Core\FacilityRepository($db))->primary('en','en');$theme=(new App\Core\Packages\ThemeManager($runtime))->active();
if(!$owner||!$facility||$cms->defaultLocale()!=='en'||($theme['slug']??'')!=='sensecms')throw new RuntimeException('Expected the licensed English Sense CMS product website.');
$themeRoot=(new App\Core\Packages\ThemeManager($runtime))->activePath();$catalog=App\Core\PageBuilder::catalog(json_decode((string)file_get_contents($themeRoot.'/theme.json'),true,64,JSON_THROW_ON_ERROR));
$journal=$backup.'/meta-legal-pages.json';$write=static function(array$data)use($journal):void{$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";if(file_put_contents($journal,$json,LOCK_EX)!==strlen($json)||!chmod($journal,0600))throw new RuntimeException('Cannot write the legal-page recovery journal.');};
$copy=[
    '/privacy-policy'=>[
        'title'=>'Privacy Policy','description'=>'How Sense CMS Social processes information when an authorised administrator connects a Facebook Page and publishes reviewed content.','sections'=>[
            ['Who operates this service','Sense CMS Social is operated by QUANT Software House Limited for the Sense CMS product website. Privacy enquiries can be sent to info@SenseCMS.com. This policy covers the Meta connection broker hosted at www.sensecms.com and the Facebook Publisher extension installed in a licensed Sense CMS website.'],
            ['Information we process','When an authorised administrator connects Facebook, Meta may provide an app-scoped account identifier, the identifiers and names of Pages they can manage, Page permissions and a Page access token. The customer installation also processes the reviewed post text, selected media, delivery time, provider response identifier and delivery status needed to publish and audit the requested post. We do not request a Facebook password.'],
            ['How the connection works','The central Sense CMS broker validates the calling CMS licence, completes Meta authorization and transfers the selected Page credential through a short-lived, single-use encrypted claim. Pending broker records are encrypted, expire after ten minutes and are removed after a successful claim. The Page token is then stored encrypted in that customer’s own Sense CMS installation; it is not kept as a reusable credential by the central broker.'],
            ['Purposes and legal bases','Information is used to connect the Page selected by its administrator, publish content the administrator has reviewed, prevent replay and duplicate delivery, diagnose failed delivery, protect the service and comply with legal obligations. Processing is based on performing the requested service, the customer’s instructions and our legitimate interests in security, reliability and abuse prevention.'],
            ['Sharing and international transfers','Publishing sends the chosen content and Page token to Meta Platforms through the Graph API. Meta processes that information under its own terms and privacy policy. Infrastructure and security providers may process limited technical data on our behalf under appropriate safeguards. We do not sell Facebook account or Page information.'],
            ['Retention and security','Central authorization state is short-lived. The customer installation retains its encrypted Page credential until an authorised user disconnects Facebook. Delivery history may be retained for audit and retry safety without retaining the removed credential. Server logs are limited and must not contain access tokens. Access is restricted, transport uses HTTPS and sensitive state is encrypted at rest.'],
            ['Your choices and rights','An authorised Sense CMS user can disconnect Facebook in Social Publishing, which removes the stored credential and disables further delivery. A Facebook user can also remove the app from Facebook settings. Depending on applicable law, individuals may request access, correction, deletion, restriction or objection by contacting info@SenseCMS.com. Identity and authority may need to be verified before acting on a request.'],
            ['Changes','We may update this policy when the integration, law or security requirements change. The current version is published at this address. Last updated: 16 September 2026.'],
        ],
    ],
    '/terms-of-service'=>[
        'title'=>'Sense CMS Social Terms of Service','description'=>'Terms for authorised use of the Sense CMS Facebook Publisher integration.','sections'=>[
            ['Scope','These terms apply to Sense CMS Social and the Facebook Publisher extension. They supplement the Sense CMS licence and any agreement between the customer and QUANT Software House Limited. By connecting a Facebook Page, the administrator confirms that they are authorised to act for the customer and manage that Page.'],
            ['Permitted use','The integration may be used to publish lawful, reviewed Sense CMS content to a Page the customer is authorised to manage. The customer remains responsible for the content, media rights, audience, timing, disclosures, records and compliance with Meta policies and applicable law.'],
            ['Prohibited use','Do not use the integration for unlawful, deceptive, infringing, abusive or unauthorised content; to bypass platform controls; to collect credentials; or to access a Page without permission. Do not attempt to extract, share or reuse another installation’s token or connection claim.'],
            ['Meta platform','Facebook and the Meta Graph API are services provided by Meta Platforms and are governed by Meta’s own terms, policies, permissions and availability. Sense CMS does not control Meta review, outages, rate limits, token expiry or policy decisions. No affiliation or endorsement by Meta is implied.'],
            ['Review and publication','Sense CMS provides review-first controls and records delivery outcomes, but the customer decides what to publish. A successful request does not guarantee reach, display format or continued availability on Facebook. Ambiguous delivery outcomes may be held for review to avoid accidental duplicate posts.'],
            ['Security and access','Customers must protect CMS accounts, apply least privilege, keep software current and promptly disconnect a Page when access is no longer required or an administrator leaves. We may suspend the broker to address abuse, security risk, legal requirements or material platform changes.'],
            ['Availability and liability','The integration is provided subject to the applicable Sense CMS agreement. To the maximum extent permitted by law, third-party platform interruption and indirect or consequential loss are excluded. Nothing in these terms limits liability that cannot legally be limited.'],
            ['Termination','An authorised user may stop using the integration at any time by disconnecting Facebook. We may discontinue or change the integration with reasonable notice where practicable. Sections that by their nature should survive termination, including responsibility, security and liability terms, continue to apply. Last updated: 16 September 2026.'],
        ],
    ],
    '/data-deletion'=>[
        'title'=>'Facebook Data Deletion','description'=>'How to disconnect Facebook and request deletion of information used by Sense CMS Social.','sections'=>[
            ['Disconnect in Sense CMS','Sign in to the relevant Sense CMS installation, open Social Publishing, choose Facebook Publisher and select Disconnect. This deletes the encrypted Page credential from that installation and disables future Facebook delivery. Existing delivery history may remain for audit and duplicate-prevention purposes without the removed credential.'],
            ['Remove the app in Facebook','You can also remove Sense CMS Social from your Facebook account settings under Apps and Websites or Business Integrations. Meta will send the app a signed deletion request when required. The central broker removes any matching short-lived authorization or claim state and returns a confirmation code.'],
            ['Request remaining deletion','To request deletion of remaining personal information, email info@SenseCMS.com with the subject “Sense CMS Social data deletion”. Identify the Sense CMS installation domain and Facebook Page, but never send passwords, access tokens or licence keys. We may ask for information needed to verify your identity and authority.'],
            ['What is retained','The central broker does not keep a reusable Page token after the customer installation claims it. Unclaimed authorization data expires after ten minutes. Security logs and non-credential delivery history may be retained only as necessary for security, legal obligations, incident investigation and reliable duplicate prevention.'],
            ['Timing','Verified requests are handled without undue delay and within the period required by applicable law. If this page was opened with a Meta confirmation code, keep that code for your records.'],
        ],
    ],
];
$uuid=static function():string{$value=bin2hex(random_bytes(16));return substr($value,0,8).'-'.substr($value,8,4).'-4'.substr($value,13,3).'-a'.substr($value,17,3).'-'.substr($value,20,12);};
if($argv[2]==='--rollback'){
    if(!is_file($journal))throw new RuntimeException('Legal-page recovery journal is missing.');$state=json_decode((string)file_get_contents($journal),true,16,JSON_THROW_ON_ERROR);
    foreach(array_reverse((array)($state['created']??[]))as$path=>$id){$page=$cms->pageAtPath((string)$path);if(!$page||(int)$page['id']!==(int)$id)throw new RuntimeException('Legal-page identity changed; rollback stopped.');$cms->setPageStatus((int)$id,'archived',$owner);$cms->deletePagePermanently((int)$id,$owner);echo"Removed created page: $path\n";}
    $state['status']='rolled-back';$write($state);exit;
}
if(file_exists($journal))throw new RuntimeException('Existing legal-page journal requires review.');
$state=['status'=>'applying','created'=>[]];$write($state);
foreach($copy as$path=>$page){
    if($cms->pageAtPath($path))throw new RuntimeException('Existing legal page requires manual review: '.$path);
    $slug=ltrim(str_replace('/','-',$path),'-');$check=$db->prepare('SELECT 1 FROM page_translations WHERE facility_id=? AND locale=? AND slug=?');$check->execute([(int)$facility['id'],'en',$slug]);if($check->fetchColumn())throw new RuntimeException('Existing legal-page slug requires manual review: '.$slug);
    $raw=[];foreach($page['sections']as[$title,$text])$raw[]=['uid'=>$uuid(),'type'=>'text','visible'=>true,'shared'=>[],'localized'=>['en'=>['title'=>$title,'text'=>'<p>'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>','cta_label'=>'','cta_url'=>'']]];
    $blocks=App\Core\PageBuilder::sanitizeBlocks($raw,$catalog,['en']);$input=['id'=>0,'facility_id'=>(int)$facility['id'],'template'=>'default','status'=>'draft','visibility'=>'public','published_at'=>null];$translations=['en'=>['title'=>$page['title'],'slug'=>$slug,'excerpt'=>$page['description'],'seo_title'=>$page['title'].' · Sense CMS','seo_description'=>$page['description']]];
    $id=$cms->savePage($input,$translations,$owner);$state['created'][$path]=$id;$write($state);$cms->saveBuilderDocument($id,0,$owner,'sensecms',$blocks,array_keys($catalog));$cms->savePage(array_replace($input,['id'=>$id,'status'=>'published','public_path'=>$path]),$translations,$owner);echo"Published managed page $id: $path\n";
}
$state['status']='applied';$write($state);echo"Published ".count($state['created'])." Meta legal pages.\n";
