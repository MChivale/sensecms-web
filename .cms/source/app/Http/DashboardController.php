<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AiRepository;
use App\Core\Auth;
use App\Core\CmsRepository;
use App\Core\ManifestRegistry;
use App\Core\LicenseService;
use App\Core\CacheService;
use App\Core\SoundSettings;
use App\Core\CaptchaService;
use App\Core\LiveChatSettings;
use App\Core\PageBuilder;
use App\Core\ExtensionCatalog;
use App\Core\PackageManager;
use App\Core\MarketplaceCatalog;
use App\Core\FacilityRepository;
use App\Core\AccessControl;
use App\Core\WorkflowRepository;
use App\Core\MediaLibrary;
use App\Core\SurveyRepository;
use App\Core\SurveyExport;
use App\Core\ThemeContract;
use App\Core\MarketplaceGovernance;
use App\Core\ConsoleSearchIndex;
use App\Core\SiteChrome;
use App\Core\SeoMeta;
use App\Core\EmailSystem;

final class DashboardController extends Controller
{
    public function __construct(private readonly Auth $auth, private readonly AccessControl $access, private readonly WorkflowRepository $workflowRepository, private readonly MediaLibrary $media, private readonly SurveyRepository $surveys, private readonly CmsRepository $cms, private readonly FacilityRepository $facilities, private readonly ManifestRegistry $themes, private readonly ManifestRegistry $plugins, private readonly ManifestRegistry $addons, private readonly PackageManager $packages, private readonly MarketplaceGovernance $governance, private readonly ConsoleSearchIndex $consoleSearchIndex, private readonly AiRepository $ai, private readonly LicenseService $license, private readonly ?\App\Core\SystemUpdate $updater = null, private readonly ?EmailSystem $emailSystem = null) {}
    public function dashboard(): never
    {
        $this->guard();
        $scope = $this->access->facilityIds();
        $workflow = $this->access->allows('content.workflow.view') ? $this->workflowRepository->queue($scope) : [];
        $media = $this->access->allows('content.media.manage') ? $this->media->listing([], $scope, 1, 12)['summary'] : [];
        $surveys = $this->access->allows('surveys.view') ? $this->surveys->listing([], $scope, 1, 5)['summary'] : [];
        $this->render('Overview', 'dashboard', [
            'stats' => $this->cms->dashboard($scope),
            'dashboardWorkflow' => array_slice($workflow, 0, 5),
            'dashboardWorkflowCount' => count($workflow),
            'dashboardFacilities' => $this->access->allows('facilities.view') ? $this->facilities->adminList($scope) : [],
            'dashboardMedia' => $media,
            'dashboardSurveys' => $surveys,
            'dashboardAi' => $this->access->allows('ai.manage') ? $this->ai->dashboard() : [],
        ]);
    }
    public function profile(): never { $this->guard(); $this->render('My profile', 'profile', ['user' => $this->auth->user()]); }
    public function notificationSettings(array $data): never { $this->guard();$this->access->assert('system.manage');$this->render('Notification channels','notification-settings',$data); }
    public function systemUpdate(array $state): never { $this->guard();$this->access->assert('system.manage');$this->render('Update','system-update',['updateState'=>$state]); }
    public function settings(): never { $this->guard(); $this->render('My settings', 'settings', ['user' => $this->auth->user()]); }
    public function extensionPage(string $title, string $view, array $data = []): never
    {
        $this->guard();
        $root = realpath(dirname(__DIR__, 2));
        $file = realpath($view);
        if ($root === false || $file === false || !is_file($file) || (!str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR) && !str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR))) $this->result(false, 'The extension view is unavailable.', '/system/extensions', 404);
        $this->render($title, 'extension', ['extensionView' => $file] + $data);
    }
    public function consoleSearch(): never { $this->guard(); $query=mb_substr(trim((string)($_GET['q']??'')),0,100);$items=$this->consoleSearchIndex->search($query,$this->access->permissions(),12);$this->result(true,count($items).' matching administration destinations.',null,200,['query'=>$query,'items'=>$items]); }
    public function searchIndex(): never { $this->guard();$this->render('Search index','search-index',['searchIndex'=>$this->consoleSearchIndex->overview()]); }
    public function rebuildSearchIndex(): never { $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$index=$this->consoleSearchIndex->rebuild($this->auth->id()??0);$this->result(true,'Administration search index rebuilt successfully.','/system/search-index',200,['index'=>$index]); }
    public function saveProfile(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $name = trim((string) ($_POST['name'] ?? '')); if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $this->result(false, 'Display name must contain 2 to 120 characters.', null, 422);
        try { $avatar = isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $this->storeAvatar() : null; $this->auth->updateProfile($name, $avatar); $this->result(true, 'Profile updated.', '/profile'); }
        catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function saveSettings(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $first = trim((string) ($_POST['first_name'] ?? '')); $last = trim((string) ($_POST['last_name'] ?? '')); $address = trim((string) ($_POST['address'] ?? ''));
        if (mb_strlen($first) > 80 || mb_strlen($last) > 80 || mb_strlen($address) > 500) $this->result(false, 'One of the entered values is too long.', null, 422);
        $this->auth->updateSettings($first, $last, $address); $this->result(true, 'Personal settings updated.', '/settings');
    }
    public function changePassword(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $current = (string) ($_POST['current_password'] ?? ''); $new = (string) ($_POST['new_password'] ?? ''); $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) $this->result(false, 'The new passwords do not match.', null, 422);
        if (strlen($new) < 14 || strlen($new) > 255) $this->result(false, 'The new password must contain at least 14 characters.', null, 422);
        if (!$this->auth->changePassword($this->auth->id() ?? 0, $current, $new, $confirm)) $this->result(false, 'The current password is incorrect.', null, 422);
        $this->result(true, 'Password changed securely.', '/settings');
    }
    public function email(): never { $this->guard();$this->access->assert('system.manage');if(!$this->emailSystem)throw new \RuntimeException('The e-mail system is unavailable.');$data=$this->emailSystem->dashboard();$this->render('E-mail','email',['emailSettings'=>$data['settings'],'emailTemplates'=>$data['templates'],'placeholders'=>$data['placeholders']]); }
    public function saveEmailServer(): never
    {
        $this->guard();$this->access->assert('system.manage');$this->emailCsrf();try{$this->emailSystem?->saveServer($_POST,$this->auth->id()??0);$this->result(true,'E-mail server settings saved securely.','/system/email');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function saveEmailAppearance(): never
    {
        $this->guard();$this->access->assert('system.manage');$this->emailCsrf();try{$this->emailSystem?->saveAppearance($_POST,$_FILES['logo']??null,$this->auth->id()??0);$this->result(true,'E-mail appearance saved.','/system/email');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function saveEmailTemplates(): never
    {
        $this->guard();$this->access->assert('system.manage');$this->emailCsrf();try{$this->emailSystem?->saveTemplates((array)($_POST['templates']??[]),$this->auth->id()??0);$this->result(true,'E-mail templates saved.','/system/email');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function testEmail(): never
    {
        $this->guard();$this->access->assert('system.manage');$this->emailCsrf();try{$recipient=$this->emailSystem?->sendTest($this->auth->id()??0,(string)($_POST['test_email']??''))??'';$this->result(true,'Test e-mail delivered to '.$recipient.'.');}catch(\RuntimeException $error){$message=$error->getMessage();if(str_starts_with($message,'Enter a valid test recipient'))$this->result(false,$message,null,422);error_log('E-mail test failed: '.$message);$this->result(false,'The test e-mail could not be delivered. Verify the server settings and try again.',null,422);}catch(\Throwable $error){error_log('E-mail test failed: '.$error->getMessage());$this->result(false,'The test e-mail could not be delivered. Verify the server settings and try again.',null,422);}
    }
    public function resendWelcome(int $userId): never
    {
        $this->guard();$this->access->assert('users.manage');$this->emailCsrf();try{$this->emailSystem?->sendWelcome($userId);$this->result(true,'A new secure welcome link has been sent.','/system/access?tab=users&edit_user='.$userId);}catch(\Throwable $error){error_log('Welcome e-mail failed: '.$error->getMessage());$this->result(false,'The welcome e-mail could not be delivered. Verify the E-mail settings and try again.',null,422);}
    }
    public function pages(): never
    {
        $this->guard();$scope=$this->access->facilityIds();$query=mb_substr(trim((string)($_GET['q']??'')),0,120);$requested=(string)($_GET['status']??'all');$status=in_array($requested,['all','draft','published','private','scheduled','archived'],true)?$requested:'all';$page=max(1,(int)($_GET['page']??1));$facility=max(0,(int)($_GET['facility']??0));if($facility&&!$this->access->allows('content.pages.view',$facility))$facility=0;$this->render('Pages','pages',['pageList'=>$this->cms->contentPages($query,$status,$page,12,$facility?:null,$scope),'contentQuery'=>$query,'contentStatus'=>$status,'facilityFilter'=>$facility,'facilities'=>$this->facilities->options(true,$scope),'languages'=>$this->cms->languages()]);
    }
    public function pageBuilder(): never
    {
        $this->guard(); $pages = $this->cms->pages(null,$this->access->facilityIds());
        if (!$pages) $this->result(false, 'Create a page before opening Page Builder.', '/content/pages/new', 404);
        $requested = max(0, (int) ($_GET['document'] ?? 0));
        $homeIndex = array_search('home', array_column($pages, 'slug'), true);
        $defaultPage = $pages[$homeIndex === false ? 0 : $homeIndex];
        $selected = $requested && in_array($requested, array_map('intval', array_column($pages, 'id')), true) ? $requested : (int) $defaultPage['id'];
        $themeSlug = (string) $this->cms->setting('active_theme', 'sensecms'); $theme = $this->themes->find($themeSlug); $catalog = $this->builderCatalog($theme);
        if (!$catalog) $this->result(false, 'The active theme does not expose Page Builder sections.', '/appearance/themes', 422);
        $document = $this->cms->builderDocument($selected);
        if (!$document) $this->result(false, 'The selected page does not exist.', '/content/pages', 404);
        $document['blocks'] = array_values(array_filter((array) ($document['blocks'] ?? []), static fn (array $block): bool => isset($catalog[(string) ($block['type'] ?? '')])));
        $this->render('Page Builder', 'page-builder', ['pages'=>$pages,'builderDocument'=>$document,'builderCatalog'=>$catalog,'builderGlobals'=>$this->cms->globalSections(),'builderRevisions'=>$this->cms->builderRevisions($selected),'languages'=>$this->cms->languages(),'builderTheme'=>$theme]);
    }
    public function savePageBuilder(int $pageId): never
    {
        $this->guard(); $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) $this->result(false, 'The Page Builder payload is invalid.', null, 400);
        if (!$this->auth->verifyCsrf($payload['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $document=$this->cms->pageAdmin($pageId);if(!$document)$this->result(false,'The selected page does not exist.',null,404);if(in_array((string)$document['status'],['published','scheduled','private'],true)&&!$this->access->allows('content.pages.publish',(int)$document['facility_id']))$this->result(false,'Published content can only be edited by a publisher. Duplicate it to create a reviewed revision.',null,403);if(($document['workflow_state']??'draft')==='in_review'&&!$this->access->allows('content.pages.review',(int)$document['facility_id']))$this->result(false,'This page is locked while it is awaiting review.',null,423);
        $themeSlug = (string) $this->cms->setting('active_theme', 'sensecms'); $catalog = $this->builderCatalog($this->themes->find($themeSlug)); $locales = array_column($this->cms->languages(), 'locale');
        try {
            $blocks = PageBuilder::sanitizeBlocks((array) ($payload['blocks'] ?? []), $catalog, $locales);
            $version = $this->cms->saveBuilderDocument($pageId, max(0, (int) ($payload['version'] ?? 0)), $this->auth->id() ?? 0, $themeSlug, $blocks, array_keys($catalog));
            $this->result(true, 'Page sections saved.', null, 200, ['version'=>$version,'blocks'=>count($blocks),'revisions'=>$this->cms->builderRevisions($pageId),'globals'=>$this->cms->globalSections()]);
        } catch (\DomainException $error) { $this->result(false, $error->getMessage(), null, 409); }
        catch (\RuntimeException $error) { $this->result(false, $error->getMessage(), null, 422); }
    }
    public function uploadPageBuilderMedia(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$pageId=max(0,(int)($_POST['page_id']??0));$facilityId=null;if($pageId){$page=$this->cms->pageAdmin($pageId);if(!$page)$this->result(false,'The selected page does not exist.',null,404);$facilityId=(int)$page['facility_id'];$this->access->assert('content.media.manage',$facilityId);}elseif(!$this->access->allows('content.media.global')){$scope=$this->access->facilityIds();$facilityId=(int)($scope[0]??0)?:null;if(!$facilityId)$this->result(false,'Choose a facility before uploading media.',null,422);}
        try{$item=$this->media->store((array)($_FILES['asset']??[]),$this->auth->id()??0,$facilityId);$this->result(true,'Media uploaded and image variants prepared.',null,200,['id'=>$item['id'],'url'=>$item['path'],'mime_type'=>$item['mime_type'],'width'=>$item['width'],'height'=>$item['height']]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function pageBuilderMedia():never
    {
        $this->guard();$filters=['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120),'kind'=>in_array($_GET['kind']??'all',['all','image','video'],true)?(string)$_GET['kind']:'all','status'=>'active'];$this->result(true,'Media library loaded.',null,200,$this->media->listing($filters,$this->access->facilityIds(),max(1,(int)($_GET['page']??1)),24));
    }

    public function mediaLibrary():never{$this->guard();$filters=$this->mediaFilters();$scope=$this->access->facilityIds();$this->render('Media Library','media-library',['mediaData'=>$this->media->listing($filters,$scope,max(1,(int)($_GET['page']??1)),24),'mediaFilters'=>$filters,'mediaFolders'=>$this->media->folders($scope),'mediaTags'=>$this->media->tags($scope),'languages'=>$this->cms->languages(),'facilities'=>$this->facilities->options(true,$scope),'mediaCanGlobal'=>$this->access->allows('content.media.global'),'mediaCanDelete'=>$this->access->allows('content.media.delete')]);}
    public function mediaData():never{$this->guard();$scope=$this->access->facilityIds();$this->result(true,'Media library loaded.',null,200,array_merge($this->media->listing($this->mediaFilters(),$scope,max(1,(int)($_GET['page']??1)),24),['folders'=>$this->media->folders($scope),'tags'=>$this->media->tags($scope)]));}
    public function mediaDetail(int$id):never{$this->guard();try{$this->result(true,'Media details loaded.',null,200,$this->media->detail($id,$this->access->facilityIds()));}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,404);}}
    public function uploadMedia():never{$this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$facility=max(0,(int)($_POST['facility_id']??0))?:null;if($facility&&!$this->access->allows('content.media.manage',$facility))$this->result(false,'You do not have access to the selected facility.',null,403);if(!$facility&&!$this->access->allows('content.media.global'))$this->result(false,'You cannot upload to the shared media library.',null,403);$folder=max(0,(int)($_POST['folder_id']??0))?:null;$files=$this->uploadedMediaFiles();if(!$files)$this->result(false,'Choose at least one media file.',null,422);$stored=[];$errors=[];foreach(array_slice($files,0,10)as$file){try{$stored[]=$this->media->store($file,$this->auth->id()??0,$facility,$folder);}catch(\RuntimeException$error){$errors[]=(string)($file['name']??'File').': '.$error->getMessage();}}if(!$stored)$this->result(false,implode(' ',$errors),null,422);$scope=$this->access->facilityIds();$message=count($stored).' media '.(count($stored)===1?'item':'items').' uploaded'.($errors?' with '.count($errors).' skipped':'').'.';$this->result(true,$message,null,200,['items'=>$stored,'errors'=>$errors,'folders'=>$this->media->folders($scope),'tags'=>$this->media->tags($scope)]);}
    public function saveMedia(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$item=$this->media->save($id,$payload,$this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,'Media metadata saved for every active language.',null,200,$item);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function saveMediaFolder():never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$folder=$this->media->saveFolder($payload,$this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,'Media folder saved.',null,200,['folder'=>$folder,'folders'=>$this->media->folders($this->access->facilityIds())]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function deleteMediaFolder(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$this->media->deleteFolder($id,$this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,'Empty folder removed.',null,200,['folders'=>$this->media->folders($this->access->facilityIds())]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function mediaAction(int$id,string$action):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);if(($action==='trash'||$action==='restore')&&!$this->access->allows('content.media.delete'))$this->result(false,'You cannot manage the media trash.',null,403);try{$item=$this->media->action($id,$action,$this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,match($action){'trash'=>'Media moved to trash safely.','restore'=>'Media restored.','regenerate'=>'Image variants regenerated.',default=>'Media updated.'},null,200,$item);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function deleteMedia(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);if(!$this->access->allows('content.media.delete'))$this->result(false,'You cannot permanently delete media.',null,403);try{$this->media->destroy($id,$this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,'Media and generated variants permanently deleted.');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function emptyMediaTrash():never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);if(!$this->access->allows('content.media.delete'))$this->result(false,'You cannot permanently delete media.',null,403);$result=$this->media->emptyTrash($this->auth->id()??0,$this->access->allows('content.media.global'),$this->access->facilityIds());$this->result(true,$result['deleted'].' trashed media '.($result['deleted']===1?'item':'items').' permanently deleted.'.($result['blocked']?' '.$result['blocked'].' referenced items were preserved.':''),null,200,$result);}

    public function surveys():never{$this->guard();$scope=$this->access->facilityIds();$filters=$this->surveyFilters();$this->render('Professional Surveys','surveys',['surveyData'=>$this->surveys->listing($filters,$scope,max(1,(int)($_GET['page']??1)),20),'surveyFilters'=>$filters,'languages'=>$this->cms->languages(),'facilities'=>$this->facilities->options(true,$scope),'surveyPages'=>$this->cms->pages(null,$scope),'surveyPermissions'=>['manage'=>$this->access->allows('surveys.manage'),'publish'=>$this->access->allows('surveys.publish'),'responses'=>$this->access->allows('surveys.responses'),'export'=>$this->access->allows('surveys.export'),'anonymize'=>$this->access->allows('surveys.anonymize')]]);}
    public function surveyData():never{$this->guard();$this->result(true,'Surveys loaded.',null,200,$this->surveys->listing($this->surveyFilters(),$this->access->facilityIds(),max(1,(int)($_GET['page']??1)),20));}
    public function surveyDetail(int$id):never{$this->guard();try{$this->result(true,'Survey loaded.',null,200,$this->surveys->detail($id,$this->access->facilityIds()));}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===404?404:422);}}
    public function saveSurvey():never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$saved=$this->surveys->save($payload,$this->auth->id()??0,$this->access->facilityIds(),$this->access->allows('surveys.publish'));$this->result(true,'Survey saved for every active language.',null,200,$saved);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}}
    public function surveyAction(int$id,string$action):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$saved=$this->surveys->action($id,$action,$this->auth->id()??0,$this->access->facilityIds(),$this->access->allows('surveys.publish'));$message=match($action){'archive'=>'Survey archived safely.','restore'=>'Survey restored as a draft.','duplicate'=>'Independent survey draft created.','close'=>'Survey closed to new responses.',default=>'Survey updated.'};$this->result(true,$message,null,200,$saved);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}}
    public function surveyStatistics(int$id):never{$this->guard();try{$filters=['facility_id'=>max(0,(int)($_GET['facility_id']??0))];$this->result(true,'Survey analytics loaded.',null,200,$this->surveys->statistics($id,$filters,$this->access->facilityIds()));}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===404?404:422);}}
    public function surveyResponses(int$id):never{$this->guard();try{$filters=['status'=>(string)($_GET['status']??'all'),'facility_id'=>max(0,(int)($_GET['facility_id']??0)),'from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??'')];$this->result(true,'Survey responses loaded.',null,200,$this->surveys->responses($id,$filters,$this->access->facilityIds(),max(1,(int)($_GET['page']??1)),30));}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===404?404:422);}}
    public function surveyResponse(int$id,string$uid):never{$this->guard();try{$this->result(true,'Survey response loaded.',null,200,$this->surveys->response($id,$uid,$this->access->facilityIds()));}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===404?404:422);}}
    public function anonymizeSurvey(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$count=$this->surveys->anonymizeNow($id,$this->auth->id()??0,$this->access->facilityIds());$this->result(true,$count.' completed '.($count===1?'response was':'responses were').' anonymized.',null,200,['anonymized'=>$count]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===404?404:422);}}
    public function exportSurvey(int$id,string$format):never{$this->guard();try{$data=$this->surveys->exportRows($id,$this->access->facilityIds());$stats=$this->surveys->statistics($id,[],$this->access->facilityIds());$title=(string)($data['survey']['translations'][$this->cms->languages()[0]['locale']]['title']??$data['survey']['slug']);$slug=preg_replace('/[^a-z0-9-]/','-',strtolower((string)$data['survey']['slug']))?:'survey';if($format==='csv'){$content=SurveyExport::csv($data['rows']);$mime='text/csv; charset=utf-8';$name=$slug.'-responses.csv';}elseif($format==='xlsx'){$content=SurveyExport::xlsx($data['rows'],$stats['summary'],$title);$mime='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';$name=$slug.'-report.xlsx';}elseif($format==='pdf'){$content=SurveyExport::pdf($stats['summary'],$stats['questions'],$title);$mime='application/pdf';$name=$slug.'-report.pdf';}else throw new \RuntimeException('Unsupported survey export format.');header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($content));header('Cache-Control: private, no-store');echo$content;exit;}catch(\RuntimeException$error){http_response_code($error->getCode()===404?404:422);header('Content-Type: text/plain; charset=utf-8');exit($error->getMessage());}}
    public function createPageBuilderGlobal():never
    {
        $this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload))$this->result(false,'The shared section payload is invalid.',null,400);if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        $themeSlug=(string)$this->cms->setting('active_theme','sensecms');$catalog=$this->builderCatalog($this->themes->find($themeSlug));$locales=array_column($this->cms->languages(),'locale');
        try{$blocks=PageBuilder::sanitizeBlocks([(array)($payload['block']??[])],$catalog,$locales);if(count($blocks)!==1)throw new \RuntimeException('Choose a valid section to share.');$global=$this->cms->createGlobalSection((string)($payload['name']??''),$blocks[0],$this->auth->id()??0);$this->result(true,'Shared section created.',null,200,$global);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function archivePageBuilderGlobal(int $globalId):never
    {
        $this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload)||!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$this->cms->archiveGlobalSection($globalId,$this->auth->id()??0);$this->result(true,'Shared section archived.');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function renamePageBuilderGlobal(int $globalId):never
    {
        $this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload)||!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$this->cms->renameGlobalSection($globalId,(string)($payload['name']??''),$this->auth->id()??0);$this->result(true,'Shared section renamed.',null,200,['globals'=>$this->cms->globalSections()]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function restorePageBuilderRevision(int $pageId,int $revisionId):never
    {
        $this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload)||!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$revision=$this->cms->builderRevision($pageId,$revisionId);if(!$revision)$this->result(false,'The selected revision no longer exists.',null,404);
        $themeSlug=(string)$this->cms->setting('active_theme','sensecms');$catalog=$this->builderCatalog($this->themes->find($themeSlug));$locales=array_column($this->cms->languages(),'locale');$raw=(array)($revision['snapshot']['blocks']??[]);foreach($raw as&$block)$block['global_section_id']=null;unset($block);
        try{$blocks=PageBuilder::sanitizeBlocks($raw,$catalog,$locales);$version=$this->cms->saveBuilderDocument($pageId,max(0,(int)($payload['version']??0)),$this->auth->id()??0,$themeSlug,$blocks,array_keys($catalog));$this->result(true,'Revision '.$revision['version'].' restored as a new version.',null,200,['version'=>$version,'document'=>$this->cms->builderDocument($pageId),'revisions'=>$this->cms->builderRevisions($pageId)]);}catch(\DomainException$error){$this->result(false,$error->getMessage(),null,409);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function seo(): never
    {
        $this->guard(); $languages = $this->cms->languages();
        $documentType = in_array($_GET['type'] ?? '', ['page','post'], true) ? (string) $_GET['type'] : 'page';
        $documents = $this->cms->seoDocuments($documentType, $this->access->facilityIds());
        $requested = max(0, (int) ($_GET['document'] ?? 0)); $ids = array_map('intval', array_column($documents, 'id'));
        $selected = $requested && in_array($requested, $ids, true) ? $requested : (int) ($documents[0]['id'] ?? 0);
        $translations = $this->cms->seoDocumentTranslations($documentType, $selected); $documentSeo = [];
        foreach ($languages as $language) {
            $locale = (string) $language['locale']; $stored = (array) $this->cms->setting('seo_' . $documentType . '_' . $selected . '_' . $locale, []);
            $translation = (array) ($translations[$locale] ?? []);
            $documentSeo[$locale] = array_replace(SeoMeta::documentDefaults($documentType), ['title'=>(string)($translation['seo_title']??''),'description'=>(string)($translation['seo_description']??'')], $stored);
        }
        $this->render('SEO', 'seo', compact('documents', 'languages', 'selected', 'documentSeo', 'documentType') + ['seoGlobal' => $this->seoGlobal()]);
    }
    public function saveSeoGlobal(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        try { $seo = SeoMeta::sanitizeGlobal($_POST, $this->cms->languages()); }
        catch (\InvalidArgumentException $error) { $this->result(false, $error->getMessage(), null, 422); }
        $this->cms->saveSetting('seo_global', $seo); $this->cms->recordActivity($this->auth->id()??0,'seo.global.saved','seo',['languages'=>count($seo['localized'])]);
        $this->result(true,'Global SEO, social and verification settings saved.','/seo');
    }
    public function saveSeoPage(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $type=in_array($_POST['document_type']??'', ['page','post'], true)?(string)$_POST['document_type']:'page';
        $id=filter_input(INPUT_POST,'document_id',FILTER_VALIDATE_INT)?:0; $languages=$this->cms->languages(); $values=is_array($_POST['seo']??null)?$_POST['seo']:[];
        if(!$id||!in_array($id,array_map('intval',array_column($this->cms->seoDocuments($type,$this->access->facilityIds()),'id')),true))$this->result(false,'Choose a valid '. $type .'.',null,422);
        try { foreach($languages as$language){$locale=(string)$language['locale'];$source=is_array($values[$locale]??null)?$values[$locale]:[];$this->cms->saveSetting('seo_'.$type.'_'.$id.'_'.$locale,SeoMeta::sanitizeDocument($source,$type));} }
        catch (\InvalidArgumentException $error) { $this->result(false,$error->getMessage(),null,422); }
        $this->cms->recordActivity($this->auth->id()??0,'seo.document.saved',$type,['id'=>$id,'languages'=>count($languages)]);
        $lang=preg_replace('/[^a-z0-9-]/','',strtolower((string)($_POST['ui_locale']??'')))?:'en';$this->result(true,ucfirst($type).' SEO saved for every active language.','/seo?type='.$type.'&document='.$id.'&lang='.$lang);
    }
    public function pageForm(?int $id = null): never
    {
        $this->guard();$scope=$this->access->facilityIds();$document=$id?$this->cms->pageAdmin($id):null;if($id&&!$document)$this->result(false,'The page was not found.','/content/pages',404);$this->render($id?'Edit page':'New page','page-form',['document'=>$document,'languages'=>$this->cms->languages(),'pageOptions'=>$this->cms->pages(null,$scope),'pageTemplates'=>$this->pageTemplates(),'facilities'=>$this->facilities->options(false,$scope),'workflowUsers'=>$this->access->workflowUsers($document?(int)$document['facility_id']:null),'workflowHistory'=>$document?$this->workflowRepository->history('page',(int)$document['id']):[]]);
    }
    public function savePage(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$status=in_array($_POST['status']??'',['draft','published','private','scheduled'],true)?(string)$_POST['status']:'draft';$translations=$this->localizedInput('translations',['title'=>255,'slug'=>255,'excerpt'=>1200,'seo_title'=>255,'seo_description'=>500]);$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;$parent=filter_input(INPUT_POST,'parent_id',FILTER_VALIDATE_INT)?:null;$facility=filter_input(INPUT_POST,'facility_id',FILTER_VALIDATE_INT)?:0;if(!$this->facilities->exists($facility))throw new \RuntimeException('Choose a valid facility.');$existing=$id?$this->cms->pageAdmin($id):null;if(array_key_exists('public_path',$_POST)&&\App\Core\PublicPagePath::validate($_POST['public_path'])!==($existing['public_path']??null))$this->access->assert('system.owner');if(in_array($status,['published','private','scheduled'],true))$this->access->assert('content.pages.publish',$facility);if($existing&&in_array((string)$existing['status'],['published','private','scheduled'],true)&&!$this->access->allows('content.pages.publish',(int)$existing['facility_id']))throw new \RuntimeException('Published content can only be edited by a publisher. Duplicate it to create a reviewed revision.');if($existing&&($existing['workflow_state']??'draft')==='in_review'&&!$this->access->allows('content.pages.review',$facility))throw new \RuntimeException('This page is locked while it is awaiting review.');$publishedAt=$this->publicationDate($status,(string)($_POST['published_at']??''));if($parent===$id)$this->result(false,'A page cannot be its own parent.',null,422);$template=trim((string)($_POST['template']??'default'));if(!in_array($template,array_column($this->pageTemplates(),'key'),true))throw new \RuntimeException('Choose a template exposed by the active theme.');$input=['id'=>$id,'parent_id'=>$parent,'facility_id'=>$facility,'assigned_user_id'=>filter_input(INPUT_POST,'assigned_user_id',FILTER_VALIDATE_INT)?:null,'editorial_note'=>(string)($_POST['editorial_note']??''),'public_path'=>$_POST['public_path']??($existing['public_path']??null),'template'=>$template,'status'=>$status,'visibility'=>($_POST['visibility']??'')==='private'?'private':'public','published_at'=>$publishedAt];$saved=$this->cms->savePage($input,$translations,$this->auth->id()??0);$locale=$this->safeLocale((string)($_POST['ui_locale']??''));$this->result(true,$id?'Page updated for every configured language.':'Page created for every configured language.','/content/pages/'.$saved.'/edit?lang='.$locale);}catch(\PDOException $error){$this->result(false,$this->duplicateContentMessage($error,'page'),null,422);}catch(\RuntimeException $error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }

    public function pageAction(int $id, string $action): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{if($action==='duplicate'){$copy=$this->cms->duplicatePage($id,$this->auth->id()??0);$this->result(true,'Page duplicated as a draft.','/content/pages/'.$copy.'/edit');}if($action==='delete'){if(!$this->cms->deletePagePermanently($id,$this->auth->id()??0))$this->result(false,'The page was not found.',null,404);$this->result(true,'Page permanently deleted from the trash.','/content/pages?status=archived');}$status=$action==='archive'?'archived':($action==='restore'?'draft':'');if($status===''||!$this->cms->setPageStatus($id,$status,$this->auth->id()??0))$this->result(false,'The page action could not be completed.',null,422);$this->result(true,$action==='archive'?'Page moved to the trash safely.':'Page restored as a draft.',$action==='archive'?'/content/pages':'/content/pages?status=archived');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function emptyPageTrash():never{$this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$count=$this->cms->emptyPageTrash($this->auth->id()??0,$this->access->facilityIds());$this->result(true,$count?$count.' archived page'.($count===1?' was':'s were').' permanently deleted.':'The page trash is already empty.','/content/pages?status=archived',200,['deleted'=>$count]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}

    public function facilities():never
    {
        $this->guard();$scope=$this->access->facilityIds();$edit=max(0,(int)($_GET['edit']??0));$document=$edit?$this->facilities->admin($edit):null;if($edit&&!$document)$this->result(false,'The facility was not found.','/content/facilities',404);$this->render('Facilities','facilities',['facilityList'=>$this->facilities->adminList($scope),'facilityDocument'=>$document,'languages'=>$this->cms->languages(),'facilityPages'=>$edit?$this->facilities->pageOptions($edit):[],'geolocation'=>$this->facilityGeolocationSettings(),'geolocationActive'=>(bool)($this->extensionStates()['facility-geolocation']??true)]);
    }

    public function saveFacility():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$languages=$this->cms->languages();$source=is_array($_POST['translations']??null)?$_POST['translations']:[];$translations=[];$default=(string)((array_values(array_filter($languages,static fn(array$l):bool=>(bool)($l['is_default']??false)))[0]??($languages[0]??['locale'=>'en']))['locale']);foreach($languages as$language){$locale=(string)$language['locale'];$values=is_array($source[$locale]??null)?$source[$locale]:[];$row=[];foreach(['name'=>180,'city_name'=>150,'short_description'=>1200,'address'=>1500,'seo_title'=>255,'seo_description'=>500]as$key=>$limit)$row[$key]=mb_substr(trim((string)($values[$key]??'')),0,$limit);if($row['name']===''&&$row['city_name']===''&&$locale!==$default)continue;if($row['name']===''||$row['city_name']==='')throw new \RuntimeException($language['name'].' requires both facility and city names.');$translations[$locale]=$row;}if(!isset($translations[$default]))throw new \RuntimeException('The default language facility name and city are required.');$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;$input=['id'=>$id,'city_slug'=>$_POST['city_slug']??'','facility_slug'=>$_POST['facility_slug']??'','status'=>$_POST['status']??'draft','homepage_page_id'=>filter_input(INPUT_POST,'homepage_page_id',FILTER_VALIDATE_INT)?:null,'email'=>$_POST['email']??'','phone'=>$_POST['phone']??'','secondary_phone'=>$_POST['secondary_phone']??'','website_url'=>$_POST['website_url']??'','map_url'=>$_POST['map_url']??'','latitude'=>$_POST['latitude']??'','longitude'=>$_POST['longitude']??'','timezone'=>$_POST['timezone']??'UTC','search_enabled'=>isset($_POST['search_enabled']),'sort_order'=>(int)($_POST['sort_order']??0)];$saved=$this->facilities->save($input,$translations,$this->auth->id()??0);$this->result(true,$id?'Facility configuration updated.':'Facility created and ready for content.','/content/facilities?edit='.$saved.'&lang='.$this->safeLocale((string)($_POST['ui_locale']??'')));}catch(\PDOException$error){$this->result(false,str_contains(strtolower($error->getMessage()),'duplicate')?'That city and facility slug combination is already in use.':'The facility could not be saved.',null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function facilityAction(int$id,string$action):never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{if($action==='primary'){$this->facilities->setPrimary($id,$this->auth->id()??0);$message='Primary facility updated; legacy URLs now resolve to it.';}else{$this->facilities->setStatus($id,$action==='archive'?'archived':'active',$this->auth->id()??0);$message=$action==='archive'?'Facility archived; its content was preserved.':'Facility restored and available publicly.';}$this->result(true,$message,'/content/facilities');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function saveFacilityGeolocation():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$mode=in_array($_POST['mode']??'',['redirect','suggest'],true)?(string)$_POST['mode']:'redirect';$settings=['mode'=>$mode,'auto_prompt'=>isset($_POST['auto_prompt']),'remember_days'=>max(1,min(365,(int)($_POST['remember_days']??30)))];$this->cms->saveSetting('facility_geolocation',$settings);$this->result(true,'Facility geolocation settings saved.','/content/facilities?tab=geolocation');
    }
    public function home(): never { $this->popups(); }
    public function popups(): never
    {
        $this->guard(); $pages=$this->cms->pages(null,$this->access->facilityIds()); $requested=max(0,(int)($_GET['document']??0));$home=array_values(array_filter($pages,static fn(array$page):bool=>($page['slug']??'')==='home'))[0]??($pages[0]??null);$selected=$requested&&in_array($requested,array_map('intval',array_column($pages,'id')),true)?$requested:(int)($home['id']??0);
        $campaigns=(array)$this->cms->setting('page_popups',[]);$legacy=(array)$this->cms->setting('home_popup',[]);$popup=array_replace_recursive($this->defaultPopup(),(array)($campaigns[(string)$selected]??(($selected===(int)($home['id']??0))?$legacy:[])));
        $this->render('Page Pop-ups','home-popup',['popup'=>$popup,'pages'=>$pages,'selected'=>$selected,'languages'=>$this->cms->languages()]);
    }
    public function saveHome(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $pageId=filter_input(INPUT_POST,'page_id',FILTER_VALIDATE_INT)?:0;$pages=$this->cms->pages(null,$this->access->facilityIds());if(!$pageId||!in_array($pageId,array_map('intval',array_column($pages,'id')),true))$this->result(false,'Choose a valid page.',null,422);$campaigns=(array)$this->cms->setting('page_popups',[]);
        $popup = array_replace_recursive($this->defaultPopup(), (array) ($campaigns[(string)$pageId] ?? []));
        try {
            $popup['enabled'] = isset($_POST['popup_enabled']);
            foreach (['eyebrow','title','text','cta_label','cta_url','image_url','start_at','end_at','frequency'] as $key) $popup[$key] = trim((string) ($_POST['popup_' . $key] ?? ''));
            foreach (['eyebrow' => 80, 'title' => 160, 'text' => 700, 'cta_label' => 60] as $key => $limit) $popup[$key] = mb_substr($popup[$key], 0, $limit);
            foreach (['cta_url', 'image_url'] as $key) if ($popup[$key] !== '' && !$this->validPublicUrl($popup[$key], $key === 'image_url')) throw new \RuntimeException('The popup ' . str_replace('_', ' ', $key) . ' is not valid.');
            foreach (['background_color','accent_color','text_color','icon_color'] as $key) if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['popup_' . $key] ?? ''))) $popup[$key] = strtolower((string) $_POST['popup_' . $key]);
            if (!in_array($popup['frequency'], ['always', 'session', 'once'], true)) $popup['frequency'] = 'session';
            foreach (['start_at', 'end_at'] as $key) if ($popup[$key] !== '' && !$this->validPopupDate($popup[$key])) throw new \RuntimeException('Popup dates must use a valid date and time.');
            if ($popup['start_at'] !== '' && $popup['end_at'] !== '' && strtotime($popup['start_at']) > strtotime($popup['end_at'])) throw new \RuntimeException('The popup end date must be after its start date.');
            $icons = ['book-open','calendar-days','chart-no-axes-combined','compass','earth','flame','globe','graduation','graduation-cap','heart','house','lightbulb','medal','moon-star','music','rocket','shield-check','sparkles','star','sun','trophy','users']; $popup['facts'] = [];
            foreach ((array) ($_POST['popup_fact_title'] ?? []) as $index => $title) { $title = trim((string) $title); $text = trim((string) (($_POST['popup_fact_text'] ?? [])[$index] ?? '')); $icon = (string) (($_POST['popup_fact_icon'] ?? [])[$index] ?? 'sparkles'); if ($title !== '' && $text !== '') $popup['facts'][] = ['icon' => in_array($icon, $icons, true) ? $icon : 'sparkles', 'title' => mb_substr($title, 0, 90), 'text' => mb_substr($text, 0, 150)]; }
            $popup['facts'] = array_slice($popup['facts'], 0, 3);
            foreach(['width'=>[720,1440,1040],'image_width'=>[260,640,390],'height'=>[440,900,645]]as$key=>[$min,$max,$fallback]){$value=filter_var($_POST['popup_'.$key]??null,FILTER_VALIDATE_INT);$popup[$key]=$value===false?$fallback:max($min,min($max,(int)$value));}
            $localized=[];foreach($this->cms->languages()as$language){$locale=(string)$language['locale'];$source=is_array($_POST['popup_localized'][$locale]??null)?$_POST['popup_localized'][$locale]:[];$stored=(array)($popup['localized'][$locale]??[]);foreach(['eyebrow'=>80,'title'=>160,'text'=>700,'cta_label'=>60,'image_url'=>500]as$key=>$limit){$value=mb_substr(trim((string)($source[$key]??($stored[$key]??''))),0,$limit);if($key==='image_url'&&$value!==''&&!$this->validPublicUrl($value,true))throw new \RuntimeException('The '.$language['name'].' popup image URL is invalid.');$stored[$key]=$value;}$localized[$locale]=$stored;}$popup['localized']=$localized;
            if (isset($_FILES['popup_image']) && ($_FILES['popup_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $popup['image_url'] = $this->storeHomeImage('popup_image');
            $campaigns[(string)$pageId]=$popup;$this->cms->saveSetting('page_popups',$campaigns);$this->result(true,'Page popup saved for every active language.','/system/extensions/popups?document='.$pageId.'&lang='.rawurlencode((string)($_POST['ui_locale']??'en')));
        } catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function navigation(): never { $this->guard(); $this->render('Navigation', 'navigation', ['navigation' => $this->cms->navigation(), 'siteChrome'=>$this->cms->setting('site_chrome',SiteChrome::defaults()), 'languages'=>$this->cms->languages(), 'pageOptions'=>$this->cms->pages(null,$this->access->facilityIds())]); }
    public function saveNavigation(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$location=in_array($_POST['location']??'',['primary','footer','footer-connect'],true)?(string)$_POST['location']:'primary';$source=is_array($_POST['items']??null)?$_POST['items']:[];$languages=$this->cms->languages();$defaultLocale=(string)((array_values(array_filter($languages,static fn(array$language):bool=>(bool)($language['is_default']??false)))[0]??($languages[0]??['locale'=>'en']))['locale']);$items=[];foreach(array_slice($source,0,60,true)as$item){if(!is_array($item))continue;$localized=[];foreach($languages as$language){$locale=(string)$language['locale'];$value=is_array($item['translations'][$locale]??null)?$item['translations'][$locale]:[];$label=mb_substr(trim((string)($value['label']??'')),0,120);$url=mb_substr(trim((string)($value['url']??'')),0,500);if(($label===''&&$url===''))continue;if($label===''||!$this->validMenuUrl($url))$this->result(false,'Every completed navigation translation needs a label and a valid destination.',null,422);$localized[$locale]=compact('label','url');}if(!$localized)continue;if(!isset($localized[$defaultLocale]))$this->result(false,'Every navigation link requires content in the default language.',null,422);$items[]=['id'=>max(0,(int)($item['id']??0)),'target'=>($item['target']??'')==='_blank'?'_blank':'_self','visible'=>isset($item['visible']),'translations'=>$localized];}$names=['primary'=>'Header navigation','footer'=>'Explore footer links','footer-connect'=>'Connect footer links'];$this->cms->saveNavigation($location,$names[$location],$items,$this->auth->id()??0);$locale=$this->safeLocale((string)($_POST['ui_locale']??''));$redirectLocation=$location==='primary'?'primary':'footer';$message=$location==='footer-connect'?'Connect links saved for every active language.':ucfirst($location).' navigation saved for every active language.';$this->result(true,$message,'/content/navigation?location='.$redirectLocation.'&lang='.$locale);
    }
    public function saveSiteChrome(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        $location=($_POST['location']??'')==='footer'?'footer':'header';$source=is_array($_POST['chrome']??null)?$_POST['chrome']:[];$settings=(array)$this->cms->setting('site_chrome',SiteChrome::defaults());
        try{foreach($this->cms->languages()as$language){$locale=(string)$language['locale'];$values=is_array($source[$locale]??null)?$source[$locale]:[];$settings=SiteChrome::saveLocale($settings,$locale,$location,$values);}$this->cms->saveSetting('site_chrome',$settings);$locale=$this->safeLocale((string)($_POST['ui_locale']??''));$this->result(true,ucfirst($location).' content saved for every active language.','/content/navigation?location='.($location==='header'?'primary':'footer').'&lang='.$locale);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function posts(): never { $this->guard();$scope=$this->access->facilityIds();$query=mb_substr(trim((string)($_GET['q']??'')),0,120);$status=(string)($_GET['status']??'all');$page=max(1,(int)($_GET['page']??1));$facility=max(0,(int)($_GET['facility']??0));if($facility&&!$this->access->allows('content.posts.view',$facility))$facility=0;$this->render('Posts','posts',['postList'=>$this->cms->posts($query,$status,$page,12,$facility?:null,$scope),'contentQuery'=>$query,'contentStatus'=>$status,'facilityFilter'=>$facility,'facilities'=>$this->facilities->options(true,$scope),'languages'=>$this->cms->languages()]); }
    public function postForm(?int $id = null): never { $this->guard();$scope=$this->access->facilityIds();$document=$id?$this->cms->postAdmin($id):null;if($id&&!$document)$this->result(false,'The post was not found.','/content/posts',404);$this->render($id?'Edit post':'New post','post-form',['document'=>$document,'categories'=>$this->cms->categories(false),'facilities'=>$this->facilities->options(false,$scope),'languages'=>$this->cms->languages(),'workflowUsers'=>$this->access->workflowUsers($document?(int)$document['facility_id']:null),'workflowHistory'=>$document?$this->workflowRepository->history('post',(int)$document['id']):[]]); }
    public function savePost(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$status=in_array($_POST['status']??'',['draft','published','scheduled'],true)?(string)$_POST['status']:'draft';$translations=$this->localizedInput('translations',['title'=>255,'slug'=>255,'excerpt'=>1200,'content'=>50000,'seo_title'=>255,'seo_description'=>500]);$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;$facility=filter_input(INPUT_POST,'facility_id',FILTER_VALIDATE_INT)?:0;if(!$this->facilities->exists($facility))throw new \RuntimeException('Choose a valid facility.');$existing=$id?$this->cms->postAdmin($id):null;if(in_array($status,['published','scheduled'],true))$this->access->assert('content.posts.publish',$facility);if($existing&&in_array((string)$existing['status'],['published','scheduled'],true)&&!$this->access->allows('content.posts.publish',(int)$existing['facility_id']))throw new \RuntimeException('Published content can only be edited by a publisher. Duplicate it to create a reviewed revision.');if($existing&&($existing['workflow_state']??'draft')==='in_review'&&!$this->access->allows('content.posts.review',$facility))throw new \RuntimeException('This post is locked while it is awaiting review.');$publishedAt=$this->publicationDate($status,(string)($_POST['published_at']??''));$input=['id'=>$id,'author_id'=>$this->auth->id(),'facility_id'=>$facility,'assigned_user_id'=>filter_input(INPUT_POST,'assigned_user_id',FILTER_VALIDATE_INT)?:null,'editorial_note'=>(string)($_POST['editorial_note']??''),'category_id'=>filter_input(INPUT_POST,'category_id',FILTER_VALIDATE_INT)?:null,'featured_media_id'=>null,'status'=>$status,'published_at'=>$publishedAt];$saved=$this->cms->savePost($input,$translations,$this->auth->id()??0);$locale=$this->safeLocale((string)($_POST['ui_locale']??''));$this->result(true,$id?'Post updated for every configured language.':'Post created for every configured language.','/content/posts/'.$saved.'/edit?lang='.$locale);}catch(\PDOException$error){$this->result(false,$this->duplicateContentMessage($error,'post'),null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }
    public function postAction(int $id,string $action):never{$this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{if($action==='duplicate'){$copy=$this->cms->duplicatePost($id,$this->auth->id()??0);$this->result(true,'Post duplicated as a draft.','/content/posts/'.$copy.'/edit');}$status=$action==='archive'?'archived':($action==='restore'?'draft':'');if($status===''||!$this->cms->setPostStatus($id,$status,$this->auth->id()??0))$this->result(false,'The post action could not be completed.',null,422);$this->result(true,$action==='archive'?'Post archived safely.':'Post restored as a draft.','/content/posts');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}}
    public function categories(): never { $this->guard(); $this->render('Categories','categories',['categories'=>$this->cms->categories(),'languages'=>$this->cms->languages()]); }
    public function saveCategory(): never { $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;$slug=strtolower(trim((string)($_POST['slug']??'')));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))$this->result(false,'Use a lowercase category slug with optional hyphens.',null,422);try{$translations=$this->localizedInput('translations',['name'=>150,'description'=>1000],false,$slug);$saved=$this->cms->saveCategory($id,$slug,$translations,$this->auth->id()??0);$this->result(true,$id?'Category updated.':'Category created.','/content/categories?edit='.$saved.'&lang='.$this->safeLocale((string)($_POST['ui_locale']??'')));}catch(\PDOException$error){$this->result(false,'That category slug is already in use.',null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);} }
    public function categoryAction(int$id,string$action):never{$this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$archived=$action==='archive';if(!in_array($action,['archive','restore'],true)||!$this->cms->setCategoryArchived($id,$archived,$this->auth->id()??0))$this->result(false,'The category action could not be completed.',null,422);$this->result(true,$archived?'Category archived; assigned posts were preserved.':'Category restored.','/content/categories');}
    public function themes(): never
    {
        $this->guard();
        $states=$this->extensionStates();$themeManifests=$this->themes->all();$pluginManifests=$this->plugins->all();$addons=ExtensionCatalog::addons($states);
        if (!$themeManifests) {
            $manager = $this->packages->themeManager();
            $this->render('Themes', 'themes', ['themes'=>[], 'themeItems'=>[], 'themeWorkspace'=>[], 'languages'=>$this->cms->languages(), 'activeTheme'=>'', 'themeReleases'=>$manager->releases(), 'activeRelease'=>$manager->active(), 'supportedModuleCount'=>0]);
        }
        $this->packages->syncBundled($themeManifests,$pluginManifests,$addons,$this->cms->installedThemes(),$this->cms->installedPlugins());
        $marketplace=$this->marketplacePayload(['type'=>'theme','per_page'=>24]);
        $themeItems=array_values(array_filter($marketplace['items'],static fn(array$item):bool=>$item['installed']));$themeSummary=['themes'=>count($themeItems),'active'=>count(array_filter($themeItems,static fn(array$item):bool=>$item['active'])),'updates'=>count(array_filter($themeItems,static fn(array$item):bool=>$item['update_available']))];
        $active=(string)$this->cms->setting('active_theme','sensecms');$requested=preg_replace('/[^a-z0-9-]/','',(string)($_GET['configure']??$active))?:$active;$selected=$themeManifests[$requested]??$themeManifests[$active]??reset($themeManifests);if(!is_array($selected))$this->result(false,'No presentation theme is installed.',null,404);
        $selectedSlug=(string)$selected['slug'];$configs=(array)$this->cms->setting('theme_configurations',[]);$drafts=(array)$this->cms->setting('theme_drafts',[]);$published=$this->themeConfiguration($selectedSlug,$selected,$configs);$draft=is_array($drafts[$selectedSlug]??null)?$drafts[$selectedSlug]:[];$settings=ThemeContract::sanitize($selected,(array)($draft['settings']??[]),$published);$parent=(string)($selected['parent']??'');$inherited=$parent!==''?$this->themeConfiguration($parent,$this->themes->find($parent),$configs):ThemeContract::defaults($selected);
        $workspace=['theme'=>$selected,'slug'=>$selectedSlug,'published'=>$published,'settings'=>$settings,'inherited'=>$inherited,'inherit'=>array_values(array_filter(array_map('strval',(array)($draft['inherit']??[])))),'is_draft'=>$draft!==[],'draft_updated_at'=>$draft['updated_at']??null];
        $manager = $this->packages->themeManager(); $releases = $manager->releases(); $activeRelease = $manager->active();
        if ($releases) { $themeItems = []; $themeSummary['themes'] = count(array_unique(array_column($releases, 'slug'))); $themeSummary['active'] = $activeRelease === null ? 0 : 1; }
        $this->render('Themes','themes',['themes'=>$themeManifests,'themePackages'=>array_column($this->packages->packages('theme'),null,'slug'),'themeItems'=>$themeItems,'themeSummary'=>$themeSummary,'themeWorkspace'=>$workspace,'languages'=>$this->cms->languages(),'activeTheme'=>$active,'packageUpdateCount'=>$this->packages->updateCount(),'themeReleases'=>$releases,'activeRelease'=>$activeRelease,'supportedModuleCount'=>count($this->builderCatalog($selected))]);
    }

    public function marketplace(): never
    {
        $this->guard();$this->render('SenseCMS Marketplace','marketplace',['marketplace'=>$this->marketplacePayload($_GET),'marketplaceGovernance'=>$this->governance->overview(),'marketplacePublishers'=>$this->packages->publishers(),'officialMarketplace'=>$this->updater?->status()??[]]);
    }

    public function marketplaceData(): never
    {
        $this->guard();$this->result(true,'Marketplace catalog loaded.',null,200,$this->marketplacePayload($_GET));
    }
    public function saveMarketplaceSource():never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$source=$this->governance->addSource((string)($payload['name']??''),(string)($payload['catalog_url']??''),(string)($payload['publisher_key_id']??''),$this->auth->id()??0);$this->result(true,'Governed catalog source saved.',null,200,['source'=>$source,'governance'=>$this->governance->overview()]);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function syncMarketplaceSource(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$sync=$this->governance->sync($id,$this->auth->id()??0);$this->result(true,$sync['entries'].' signed catalog releases verified and synchronized.',null,200,['sync'=>$sync,'governance'=>$this->governance->overview()]);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function submitMarketplacePackage():never{$this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$staged=$this->packages->stageUpload((array)($_FILES['package']??[]),$this->auth->id()??0);$archive=$this->packages->preserveStage((string)$staged['token'],$this->auth->id()??0);$submission=$this->governance->submit($staged,$archive,$this->auth->id()??0);$this->result(true,'Signed package submitted to moderation.',null,200,['submission'=>$submission,'governance'=>$this->governance->overview()]);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function moderateMarketplaceSubmission(int$id,string$action):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$this->governance->moderate($id,$action==='approve'?'approved':'rejected',(string)($payload['note']??''),$this->auth->id()??0);$this->result(true,'Submission '.$action.'d.',null,200,['governance'=>$this->governance->overview()]);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function stageMarketplaceEntry(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$row=$this->governance->entryForInstall($id);$staged=$this->packages->stageCatalogRelease((string)$row['package_url'],(string)$row['package_checksum'],(string)$row['type'],(string)$row['slug'],(string)$row['version'],$this->auth->id()??0);$this->result(true,'Catalog package downloaded; checksum and publisher signature verified.',null,200,$staged);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function stageOfficialMarketplaceProduct(string$id):never
    {
        $this->guard();$this->access->assert('extensions.manage');$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$product=$this->officialMarketplaceProduct($id);$staged=$this->packages->stageOfficialRelease($product,(string)($payload['license']??''),$this->auth->id()??0);$this->result(true,'License, checksum and publisher signature verified.',null,200,$staged);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function uninstallMarketplacePackage(string$type,string$slug):never
    {
        $this->guard();$this->access->assert('extensions.manage');$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$removed=$this->packages->uninstall($type,$slug,$this->auth->id()??0);$this->consoleSearchIndex->rebuild($this->auth->id()??0);$this->result(true,$removed['name'].' was uninstalled. A private recovery package was retained.',null,200,$removed);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function stageMarketplaceSubmission(int$id):never{$this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$row=$this->governance->submissionForInstall($id);$staged=$this->packages->stageApprovedSubmission((string)$row['archive_path'],(string)$row['package_checksum'],$this->auth->id()??0);$this->result(true,'Approved submission integrity and publisher signature reverified.',null,200,$staged);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}}
    public function appearance(): never { $this->guard(); $this->render('Appearance', 'appearance', ['appearance' => $this->cms->setting('theme_settings', [])]); }
    public function saveAppearance(): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $setting = $this->cms->setting('theme_settings', []);
        foreach (['primary_color', 'secondary_color'] as $key) if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST[$key] ?? ''))) $setting[$key] = strtolower((string) $_POST[$key]);
        foreach (['body_font', 'heading_font'] as $key) if (in_array($_POST[$key] ?? '', ['DM Sans', 'Inter', 'Nunito Sans', 'Playfair Display', 'Source Sans 3'], true)) $setting[$key] = $_POST[$key];
        foreach (['logo_url', 'favicon_url'] as $key) if (filter_var($_POST[$key] ?? '', FILTER_VALIDATE_URL) || str_starts_with((string) ($_POST[$key] ?? ''), '/')) $setting[$key] = trim((string) $_POST[$key]);
        foreach (['logo', 'favicon'] as $field) if (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $setting[$field . '_url'] = $this->storeBranding($field);
        $active=(string)$this->cms->setting('active_theme','sensecms');$theme=$this->themes->find($active);$setting=ThemeContract::sanitize($theme,$setting);$this->cms->saveSetting('theme_settings',$setting);$configs=(array)$this->cms->setting('theme_configurations',[]);$parent=(string)($theme['parent']??'');$configs[$active]=$parent!==''?ThemeContract::overrides($theme,$setting,$this->themeConfiguration($parent,$this->themes->find($parent),$configs)):$setting;$this->cms->saveSetting('theme_configurations',$configs);
        $this->result(true, 'Appearance settings saved.', '/appearance');
    }
    public function saveThemeDraft(string $slug): never
    {
        $this->guard(); $this->access->assert('appearance.manage'); $payload = $this->jsonPayload();
        if (!$this->auth->verifyCsrf($payload['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        try {
            $theme = $this->themes->find($slug);
            if (!is_array($payload['settings'] ?? null) || !is_array($payload['inherit'] ?? [])) throw new \RuntimeException('Provide valid theme settings.');
            foreach ($payload['inherit'] ?? [] as $key) if (!is_string($key)) throw new \RuntimeException('Provide valid inherited fields.');
            $draft = $this->cms->withThemeConfigurationLock(function () use ($slug, $theme, $payload): array {
                $configs = (array) $this->cms->setting('theme_configurations', []);
                $settings = ThemeContract::sanitize($theme, $payload['settings'], $this->themeConfiguration($slug, $theme, $configs));
                $inherit = array_values(array_intersect($payload['inherit'] ?? [], array_keys(ThemeContract::defaults($theme))));
                $parent = (string) ($theme['parent'] ?? '');
                if ($parent !== '' && $inherit) {
                    $inherited = $this->themeConfiguration($parent, $this->themes->find($parent), $configs);
                    foreach ($inherit as $key) if (array_key_exists($key, $inherited)) $settings[$key] = $inherited[$key];
                }
                $drafts = (array) $this->cms->setting('theme_drafts', []);
                $drafts[$slug] = ['settings'=>$settings, 'inherit'=>$inherit, 'updated_at'=>date(DATE_ATOM), 'user_id'=>$this->auth->id() ?? 0, 'base_version'=>(string) $theme['version']];
                $this->cms->saveSetting('theme_drafts', $drafts);
                $this->cms->recordActivity($this->auth->id() ?? 0, 'theme.draft.saved', 'theme', ['slug'=>$slug, 'version'=>$theme['version']]);
                return $drafts[$slug];
            });
        } catch (\RuntimeException $error) { $this->result(false, $error->getMessage(), null, 422); }
        $this->result(true, 'Theme draft saved. The public presentation has not changed.', null, 200, ['settings'=>$draft['settings'], 'updated_at'=>$draft['updated_at']]);
    }
    public function publishThemeDraft(string $slug): never
    {
        $this->guard(); $this->access->assert('appearance.manage'); $payload = $this->jsonPayload();
        if (!$this->auth->verifyCsrf($payload['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        try {
            $theme = $this->themes->find($slug);
            $settings = $this->cms->withThemeConfigurationLock(function () use ($slug, $theme): array {
                $drafts = (array) $this->cms->setting('theme_drafts', []);
                $draft = $drafts[$slug] ?? null;
                if (!is_array($draft)) throw new \RuntimeException('Save a draft before publishing theme settings.', 409);
                if (($draft['base_version'] ?? '') !== (string) $theme['version']) throw new \RuntimeException('The theme release changed. Review and save the draft again.', 409);
                $configs = (array) $this->cms->setting('theme_configurations', []);
                $settings = ThemeContract::sanitize($theme, (array) $draft['settings'], $this->themeConfiguration($slug, $theme, $configs));
                $parent = (string) ($theme['parent'] ?? '');
                $inherited = $parent !== '' ? $this->themeConfiguration($parent, $this->themes->find($parent), $configs) : ThemeContract::defaults($theme);
                $configs[$slug] = $parent !== '' ? ThemeContract::overrides($theme, $settings, $inherited, (array) ($draft['inherit'] ?? [])) : $settings;
                $this->cms->saveSetting('theme_configurations', $configs);
                if ((string) $this->cms->setting('active_theme', 'sensecms') === $slug) $this->cms->saveSetting('theme_settings', $settings);
                unset($drafts[$slug]); $this->cms->saveSetting('theme_drafts', $drafts);
                $this->cms->recordActivity($this->auth->id() ?? 0, 'theme.configuration.published', 'theme', ['slug'=>$slug, 'version'=>$theme['version'], 'overrides'=>count($configs[$slug])]);
                return $settings;
            });
        } catch (\RuntimeException $error) { $this->result(false, $error->getMessage(), null, $error->getCode() === 409 ? 409 : 422); }
        $this->result(true, 'Theme configuration published.', null, 200, ['settings'=>$settings]);
    }
    public function discardThemeDraft(string $slug): never
    {
        $this->guard(); $this->access->assert('appearance.manage'); $payload = $this->jsonPayload();
        if (!$this->auth->verifyCsrf($payload['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $theme = $this->themes->find($slug);
        $settings = $this->cms->withThemeConfigurationLock(function () use ($slug, $theme): array {
            $drafts = (array) $this->cms->setting('theme_drafts', []); unset($drafts[$slug]);
            $this->cms->saveSetting('theme_drafts', $drafts);
            $this->cms->recordActivity($this->auth->id() ?? 0, 'theme.draft.discarded', 'theme', ['slug'=>$slug]);
            return $this->themeConfiguration($slug, $theme, (array) $this->cms->setting('theme_configurations', []));
        });
        $this->result(true, 'Theme draft discarded. Published settings remain unchanged.', null, 200, ['settings'=>$settings]);
    }
    public function previewTheme(string $slug): never
    {
        $this->guard(); $this->access->assert('appearance.manage');
        $manager = $this->packages->themeManager();
        if (($manager->active()['slug'] ?? '') !== $slug || ($root = $manager->activePath()) === null) $this->result(false, 'Preview is available for the active signed presentation.', null, 409);
        $theme = $this->themes->find($slug);
        $configs = (array) $this->cms->setting('theme_configurations', []);
        $draft = ((array) $this->cms->setting('theme_drafts', []))[$slug] ?? [];
        if ($draft && ($draft['base_version'] ?? '') !== (string) $theme['version']) $this->result(false, 'The theme release changed. Review and save the draft again.', null, 409);
        $settings = ThemeContract::sanitize($theme, (array) ($draft['settings'] ?? []), $this->themeConfiguration($slug, $theme, $configs));
        $runtime = new \App\Core\Runtime(dirname(__DIR__, 2));
        $locale = $this->cms->defaultLocale('en');
        [$status, $headers, $body] = (new \App\Core\PublicTheme($root, $runtime->baseUrl()))->response('/', 'GET', ['theme_settings'=>$settings, 'navigation'=>$this->cms->publicNavigation($locale, $locale)]);
        $headers['Cache-Control'] = 'private, no-store'; $headers['X-Robots-Tag'] = 'noindex, nofollow';
        http_response_code($status);
        foreach ($headers as $name => $value) header($name . ': ' . $value);
        echo $body; exit;
    }
    public function activateTheme(string $slug): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $this->result(false, 'Choose an exact signed release in the Themes workspace.', '/appearance/themes', 409);
    }

    public function changeThemeRelease(bool $rollback = false): never
    {
        $this->guard(); $this->access->assert('appearance.manage');
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        try {
            $manager = $this->packages->themeManager();
            $release = $rollback ? $manager->rollback($this->packages->trustedKeys()) : $manager->activate((string) ($_POST['directory'] ?? ''), $this->packages->trustedKeys());
        } catch (\RuntimeException $error) { $this->result(false, $error->getMessage(), null, 422); }
        try { $this->cms->recordActivity($this->auth->id() ?? 0, $rollback ? 'theme.release.restored' : 'theme.release.activated', 'theme', $release); }
        catch (\Throwable) { error_log('Theme release changed; activity audit could not be saved.'); }
        $this->result(true, 'Public theme changed to ' . $release['slug'] . ' ' . $release['version'] . '.', '/appearance/themes', 200, $release);
    }
    public function plugins(): never
    {
        $this->guard();$states=$this->extensionStates();$installed=$this->cms->installedPlugins();$themeManifests=$this->themes->all();$pluginManifests=$this->plugins->all();$addons=ExtensionCatalog::addons($states);
        $this->packages->syncBundled($themeManifests,$pluginManifests,$addons,$this->cms->installedThemes(),$installed);$rows=$this->packages->packages();$byIdentity=[];foreach($rows as$row)$byIdentity[$row['type'].':'.$row['slug']]=$row;
        $plugins=[];foreach($pluginManifests as$slug=>$manifest){$row=$installed[$slug]??[];$package=$byIdentity['plugin:'.$slug]??[];$pluginActive=$slug==='forms'?($states['forms']??true):(bool)($row['active']??false);$plugins[]=['slug'=>$slug,'name'=>$manifest['name']??$slug,'description'=>$manifest['description']??'','publisher'=>$package['publisher']??$manifest['author']??'SenseCMS','version'=>$package['version']??$manifest['version']??'1.0.0','icon'=>$manifest['icon']??($slug==='ai-chat'?'bot-message-square':'plug'),'group'=>$manifest['group']??($slug==='ai-chat'?'Communication':'Integrations'),'status'=>$pluginActive?'active':'inactive','config_url'=>$manifest['config_url']??($slug==='ai-chat'?'/conversations/configuration':($slug==='forms'?'/forms/submissions':'/system/extensions?tab=plugins'))]+$this->packagePresentation($package);}
        foreach($this->packages->packages('addon')as$package){$found=false;foreach($addons as&$addon)if($addon['slug']===$package['slug']){$addon+=$this->packagePresentation($package);$found=true;break;}unset($addon);if(!$found)$addons[]=['slug'=>$package['slug'],'name'=>$package['name'],'description'=>$package['description'],'publisher'=>$package['publisher'],'version'=>$package['version'],'icon'=>$package['manifest']['icon']??'blocks','group'=>$package['manifest']['group']??'Extensions','status'=>$package['active']?'active':'inactive','config_url'=>$package['manifest']['config_url']??'/system/extensions']+$this->packagePresentation($package);}
        $this->render('Extensions','plugins',['modules'=>ExtensionCatalog::modules(),'addons'=>$addons,'plugins'=>$plugins,'extensionCatalog'=>ExtensionCatalog::catalog(),'marketplaceSummary'=>$this->marketplacePayload(['per_page'=>1])['summary'],'packagePublishers'=>$this->packages->publishers(),'packageUpdateCount'=>$this->packages->updateCount()]);
    }
    public function toggleExtension(string $type,string $slug): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$active=filter_var($_POST['active']??false,FILTER_VALIDATE_BOOL);
        try{$this->packages->setActive($type,$slug,$active,$this->auth->id()??0);if($type==='addon'||($type==='plugin'&&$slug==='forms')){$states=(array)$this->cms->setting('extension_states',[]);$states[$slug]=$active;$this->cms->saveSetting('extension_states',$states);}}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
        $this->consoleSearchIndex->rebuild($this->auth->id()??0);$this->result(true,($active?'Enabled ':'Disabled ').$slug.'.','/system/extensions?tab='.($type==='addon'?'addons':'plugins'),200,['active'=>$active]);
    }

    public function inspectPackage(): never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$this->result(true,'Publisher signature and package integrity verified.',null,200,$this->packages->stageUpload((array)($_FILES['package']??[]),$this->auth->id()??0));}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function installPackage(): never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$installed=$this->packages->install((string)($payload['token']??''),$this->auth->id()??0);$this->consoleSearchIndex->rebuild($this->auth->id()??0);$this->result(true,($installed['updated']?'Updated ':'Installed ').$installed['name'].' '.$installed['version'].'.'.($installed['type']==='theme'?' The public website is unchanged. Activate this release from Themes.':''),null,200,$installed);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function checkPackageUpdates(): never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$result=$this->packages->checkUpdates();$this->result(true,$result['available']?$result['available'].' package update'.($result['available']===1?' is':'s are').' available.':'All managed packages are up to date.',null,200,$result);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function stagePackageUpdate(string$type,string$slug):never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$this->result(true,'Update downloaded and its publisher signature verified.',null,200,$this->packages->stageUpdate($type,$slug,$this->auth->id()??0));}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function rollbackPackage(string$type,string$slug):never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$restored=$this->packages->rollback($type,$slug,$this->auth->id()??0);$this->consoleSearchIndex->rebuild($this->auth->id()??0);$this->result(true,$restored['name'].' was rolled back to '.$restored['version'].'.',null,200,$restored);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function trustPublisher():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$publisher=$this->packages->trustPublisher((string)($_POST['key_id']??''),(string)($_POST['name']??''),(string)($_POST['website']??''),(string)($_POST['public_key']??''),$this->auth->id()??0);$this->result(true,'Publisher key trusted.',null,200,$publisher);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }

    public function togglePublisher(string$keyId):never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);
        try{$active=filter_var($payload['active']??false,FILTER_VALIDATE_BOOL);$this->packages->setPublisherActive($keyId,$active,$this->auth->id()??0);$this->result(true,$active?'Publisher key enabled.':'Publisher key disabled.',null,200,['active'=>$active]);}catch(\Throwable$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function notifications(): never
    {
        $this->guard();$userId=$this->auth->id()??0;$scope=$this->access->facilityIds();$chat=$this->access->allows('chat.view')?$this->ai->unreadTotal($userId):0;$forms=$this->access->allows('forms.view')?$this->cms->formUnreadCount($scope):0;$surveyResponses=$this->access->allows('surveys.responses')?$this->surveys->recentCompletedCount($scope):0;$queued=$this->access->allows('chat.view')?$this->ai->queuedConversations($userId):[];$items=[];
        if($chat>0)$items[]=['type'=>'chat','title'=>'Live chat','message'=>$chat.' unread '.($chat===1?'message':'messages').' require attention.','count'=>$chat,'url'=>'/conversations','icon'=>'messages-square'];
        if($forms>0)$items[]=['type'=>'forms','title'=>'Form Inbox','message'=>$forms.' new '.($forms===1?'submission':'submissions').' waiting in the inbox.','count'=>$forms,'url'=>'/forms/submissions','icon'=>'inbox'];
        if($surveyResponses>0)$items[]=['type'=>'surveys','title'=>'Survey responses','message'=>$surveyResponses.' completed '.($surveyResponses===1?'response':'responses').' received in the last 24 hours.','count'=>$surveyResponses,'url'=>'/surveys','icon'=>'clipboard-check'];
        $updates=$this->access->allows('extensions.manage')?$this->packages->updateCount():0;if($this->access->allows('extensions.manage'))$items[]=['type'=>'updates','title'=>'Extension updates','message'=>$updates?$updates.' signed package update'.($updates===1?' is':'s are').' ready for review.':'No extension updates are currently recorded.','count'=>$updates,'url'=>'/system/extensions?tab=catalog','icon'=>$updates?'package-open':'badge-check'];$core=$this->access->allows('system.manage')?($this->updater?->status()??[]):[];if(!empty($core['available'])){$updates++;$items[]=['type'=>'updates','title'=>'SenseCMS update available','message'=>'Build '.$core['latest']['version'].' is ready for review.','count'=>1,'url'=>'/system/update','icon'=>'download'];}$editorial=$this->workflowRepository->notifications($userId);foreach($editorial as$item)$items[]=['type'=>'workflow','title'=>$item['title'],'message'=>$item['message'],'count'=>1,'url'=>$item['url'],'icon'=>'git-pull-request-arrow'];$editorialCount=$this->workflowRepository->unreadCount($userId);
        $this->result(true,'Notifications loaded.',null,200,['total'=>$chat+$forms+$surveyResponses+$updates+$editorialCount,'chat'=>$chat,'forms'=>$forms,'surveys'=>$surveyResponses,'updates'=>$updates,'editorial'=>$editorialCount,'queued'=>count($queued),'items'=>$items]);
    }
    public function formInbox(): never
    {
        $this->guard(); $query=trim((string)($_GET['q']??''));$status=(string)($_GET['status']??'all');$page=max(1,(int)($_GET['page']??1));
        $this->render('Form inbox','form-inbox',['formInbox'=>$this->cms->formSubmissions($query,$status,$page,20,$this->access->facilityIds()),'formQuery'=>$query,'formStatus'=>$status]);
    }
    public function formInboxData(): never { $this->guard();$this->result(true,'Form inbox loaded.',null,200,$this->cms->formSubmissions(trim((string)($_GET['q']??'')),(string)($_GET['status']??'all'),max(1,(int)($_GET['page']??1)),20,$this->access->facilityIds())); }
    public function formSubmissionDetail(int$id):never{$this->guard();$submission=$this->cms->formSubmission($id,$this->auth->id()??0);if(!$submission)$this->result(false,'The submission was not found.',null,404);$this->result(true,'Submission loaded.',null,200,$submission);}
    public function updateFormSubmission(int$id):never{$this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload)||!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);if(!$this->cms->updateFormSubmission($id,(string)($payload['status']??''),$this->auth->id()??0))$this->result(false,'The submission could not be updated.',null,422);$this->result(true,'Submission status updated.');}
    public function deleteFormSubmission(int$id):never{$this->guard();$payload=json_decode((string)file_get_contents('php://input'),true);if(!is_array($payload)||!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);if(!$this->cms->deleteFormSubmission($id,$this->auth->id()??0))$this->result(false,'The submission was not found.',null,404);$this->result(true,'Submission removed securely.');}
    public function exportFormSubmissions():never
    {
        $this->guard();$rows=$this->cms->formSubmissionExport((string)($_GET['status']??'all'),$this->access->facilityIds());header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="form-submissions-'.date('Y-m-d').'.csv"');$output=fopen('php://output','wb');fwrite($output,"\xEF\xBB\xBF");fputcsv($output,['ID','Form','Language','Status','Submitted','Fields']);foreach($rows as$row)fputcsv($output,[$row['uid'],$row['form_name'],$row['locale'],$row['status'],$row['created_at'],json_encode($row['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);fclose($output);exit;
    }
    public function accessControl():never
    {
        $this->guard();$requested=(string)($_GET['tab']??'users');$tab=in_array($requested,['users','teams','roles','audit'],true)?$requested:'users';if($tab==='audit'&&!$this->access->allows('audit.view'))$tab='users';$userId=max(0,(int)($_GET['edit_user']??0));$teamId=max(0,(int)($_GET['edit_team']??0));$roleId=max(0,(int)($_GET['edit_role']??0));
        $this->render('Access & workflow','access-control',['accessTab'=>$tab,'accessUsers'=>$this->access->users(),'accessTeams'=>$this->access->teams(),'accessRoles'=>$this->access->roles(),'permissionGroups'=>$this->access->permissionCatalog(),'accessFacilities'=>$this->facilities->options(true),'accessUser'=>$userId?$this->access->user($userId):null,'accessTeam'=>$teamId?$this->access->team($teamId):null,'accessRole'=>$roleId?$this->access->role($roleId):null,'accessAudit'=>$tab==='audit'?$this->access->auditRows():[]]);
    }
    public function saveAccessUser():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$new=empty($_POST['id']);$id=$this->access->saveUser($_POST,$this->auth->id()??0);$message='User access, primary role and facility scope saved.';if($new&&!empty($_POST['active'])&&$this->emailSystem){try{$this->emailSystem->sendWelcome($id);$message='User created and a secure welcome e-mail has been sent.';}catch(\Throwable$error){error_log('Welcome e-mail failed: '.$error->getMessage());$message='User created, but the welcome e-mail could not be delivered. Review System - E-mail and resend the invitation.';}}$this->result(true,$message,'/system/access?tab=users&edit_user='.$id);}catch(\PDOException$error){$this->result(false,str_contains(strtolower($error->getMessage()),'duplicate')?'That e-mail address is already assigned to another user.':'The user could not be saved.',null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }
    public function saveAccessTeam():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$id=$this->access->saveTeam($_POST,$this->auth->id()??0);$this->result(true,'Team membership and facility scope saved.','/system/access?tab=teams&edit_team='.$id);}catch(\PDOException$error){$this->result(false,str_contains(strtolower($error->getMessage()),'duplicate')?'That team slug is already in use.':'The team could not be saved.',null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }
    public function saveAccessRole():never
    {
        $this->guard();if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$id=$this->access->saveRole($_POST,$this->auth->id()??0);$this->result(true,'Role permissions saved.','/system/access?tab=roles&edit_role='.$id);}catch(\PDOException$error){$this->result(false,str_contains(strtolower($error->getMessage()),'duplicate')?'That role slug is already in use.':'The role could not be saved.',null,422);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }
    public function workflow():never
    {
        $this->guard();$requested=(string)($_GET['state']??'all');$state=in_array($requested,['all','draft','in_review','changes_requested','approved'],true)?$requested:'all';$scope=$this->access->facilityIds();$items=$this->workflowRepository->queue($scope,$state);$this->workflowRepository->markRead($this->auth->id()??0);$this->render('Editorial workflow','workflow',['workflowItems'=>$items,'workflowCounts'=>$this->workflowRepository->counts($scope),'workflowState'=>$state,'workflowUsers'=>$this->access->workflowUsers(),'workflowPermissions'=>$this->access->permissions()]);
    }
    public function workflowAction(string$type,int$id,string$action):never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);try{$result=$this->workflowRepository->transition($type,$id,$action,$this->auth->id()??0,(int)($payload['assigned_user_id']??0)?:null,(string)($payload['note']??''));$this->result(true,match($action){'submit'=>'Content submitted for review.','approve'=>'Content approved.','request-changes'=>'Changes requested and the author notified.','publish'=>'Content published.','withdraw'=>'Review request withdrawn.','assign'=>'Assignment updated.',default=>'Workflow updated.'},null,200,$result);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,$error->getCode()===403?403:422);}
    }
    public function readNotifications():never
    {
        $this->guard();$payload=$this->jsonPayload();if(!$this->auth->verifyCsrf($payload['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419);$this->workflowRepository->markRead($this->auth->id()??0);$this->result(true,'Editorial notifications marked as read.');
    }
    public function cache(): never { $this->guard(); $settings = $this->cacheSettings(); $this->render('Cache', 'cache', ['cache' => $settings, 'cacheStatus' => $this->cacheStore($settings)->status()]); }
    public function sounds(): never { $this->guard(); $this->render('Sounds', 'sounds', ['sounds' => $this->soundSettings()]); }
    public function license(): never
    {
        $this->guard(); $this->access->assert('system.manage'); $licenseStatus = null;
        try { $licenseStatus = $this->license->enforce((new \App\Core\Runtime(dirname(__DIR__, 2)))->baseUrl()); }
        catch (\App\Core\LicenseException) {}
        $this->render('License', 'license', compact('licenseStatus'));
    }
    public function saveHostingCredit(): never
    {
        $this->guard();
        $this->access->assert('system.manage');
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $enabled = isset($_POST['show_hosting_credit']);
        $this->cms->saveSetting('show_hosting_credit', $enabled);
        $this->cms->recordActivity($this->auth->id() ?? 0, 'system.hosting-credit.updated', 'setting', ['enabled' => $enabled]);
        $this->result(true, 'Public hosting credit preference saved.', '/license');
    }
    public function captcha(): never { $this->guard(); $this->render('CAPTCHA', 'captcha', ['captcha' => (new CaptchaService())->config($this->cms->setting('captcha_settings', []))]); }
    public function saveCaptcha(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $captcha = (new CaptchaService())->config(['enabled'=>isset($_POST['enabled']),'letters'=>isset($_POST['letters']),'numbers'=>isset($_POST['numbers']),'symbols'=>isset($_POST['symbols']),'length'=>(int)($_POST['length']??6)]);
        if (!$captcha['letters'] && !$captcha['numbers'] && !$captcha['symbols']) $this->result(false, 'Choose at least one CAPTCHA character group.', null, 422);
        $this->cms->saveSetting('captcha_settings', $captcha); $this->result(true, 'CAPTCHA settings saved.', '/system/captcha');
    }
    public function languages(): never
    {
        $this->guard();$edit=preg_replace('/[^a-z-]/','',strtolower((string)($_GET['edit']??'')))?:'';$flags=['/sensecms/images/flags/gb.svg'=>'United Kingdom','/sensecms/images/flags/de.svg'=>'Germany','/sensecms/images/flags/cn.svg'=>'China','/sensecms/images/flags/pl.svg'=>'Poland','/sensecms/images/flags/kh.svg'=>'Cambodia'];
        $this->render('Languages','languages',['languages'=>$this->cms->allLanguages(),'editingLanguage'=>$edit!==''?$this->cms->language($edit):null,'languageFlags'=>$flags]);
    }
    public function saveLanguage(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $locale=strtolower(trim((string)($_POST['locale']??'')));$action=(string)($_POST['action']??'save');if(!preg_match('/^[a-z]{2,5}$/',$locale))$this->result(false,'Choose a valid language.',null,422);
        try{if($action==='toggle'){$this->cms->setLanguageEnabled($locale,filter_var($_POST['enabled']??false,FILTER_VALIDATE_BOOL));$this->result(true,'Language activation updated.','/system/languages');}if($action==='default'){$this->cms->setDefaultLanguage($locale);$this->result(true,'Default language and public fallback updated.','/system/languages');}$name=mb_substr(trim((string)($_POST['name']??'')),0,80);$native=mb_substr(trim((string)($_POST['native_name']??'')),0,80);$flag=trim((string)($_POST['flag']??''));if($name===''||$native===''||!preg_match('#^/sensecms/images/flags/[a-z0-9._-]+\.(?:svg|png|jpe?g|webp)$#i',$flag))throw new \RuntimeException('Enter both language names and choose a valid flag.');$this->cms->saveLanguage(['locale'=>$locale,'name'=>$name,'native_name'=>$native,'flag'=>$flag,'enabled'=>isset($_POST['enabled'])?1:0,'is_default'=>isset($_POST['is_default'])?1:0,'sort_order'=>max(0,min(999,(int)($_POST['sort_order']??0)))]);}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}catch(\Throwable){$this->result(false,'This language could not be saved.',null,422);} $this->result(true,'Language saved.','/system/languages?edit='.$locale);
    }
    public function saveSounds(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $sounds = $this->soundSettings();
        try { $customLogin = false; foreach (['success', 'warning', 'error', 'login'] as $type) { $sounds[$type]['enabled'] = isset($_POST['enabled_' . $type]); if (isset($_FILES['sound_' . $type]) && ($_FILES['sound_' . $type]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $sounds[$type]['file'] = $this->storeSound('sound_' . $type); $customLogin = $customLogin || $type === 'login'; } }
            $choice = (string) ($_POST['login_choice'] ?? 'login-voice.mp3'); if (!$customLogin && in_array($choice, ['login-voice.mp3', 'login-sound.mp3'], true)) $sounds['login']['file'] = $choice;
            $sounds['license'] = SoundSettings::defaults()['license']; $this->cms->saveSetting('sound_settings', $sounds); $this->result(true, 'Sound settings saved.', '/system/sounds'); }
        catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function resetSounds(): never { $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419); $this->cms->saveSetting('sound_settings', SoundSettings::defaults()); $this->result(true, 'Sound settings reset to defaults.', '/system/sounds'); }
    public function saveCache(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $driver = in_array($_POST['driver'] ?? '', ['none', 'file', 'memcache', 'memcached', 'redis'], true) ? $_POST['driver'] : 'file';
        $settings = ['driver' => $driver, 'ttl' => max(60, min(2_592_000, (int) ($_POST['ttl'] ?? 3600))), 'host' => trim((string) ($_POST['host'] ?? '127.0.0.1')), 'port' => max(1, min(65535, (int) ($_POST['port'] ?? ($driver === 'redis' ? 6379 : 11211)))), 'database' => max(0, min(15, (int) ($_POST['database'] ?? 2)))];
        try { $this->cacheStore($settings)->test(); $this->cms->saveSetting('cache_settings', $settings); $this->result(true, $driver === 'none' ? 'Application cache disabled.' : ucfirst($driver) . ' cache configuration verified and saved.', '/system/cache'); }
        catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function clearCache(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        try { $this->cacheStore($this->cacheSettings())->clear(); $this->result(true, 'Application cache cleared.', '/system/cache'); }
        catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function resetCache(): never
    {
        $this->guard(); if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $this->cms->saveSetting('cache_settings', $this->defaultCacheSettings()); $this->result(true, 'Cache settings reset to defaults.', '/system/cache');
    }
    public function ai(): never { $this->guard(); $this->render('AI & Live Support', 'ai', ['ai' => $this->ai->dashboard(), 'providers' => $this->ai->providers()]); }
    public function conversations(): never
    {
        $this->guard();
        $userId = $this->auth->id() ?? 0;
        $conversations = $this->ai->conversations($userId);
        $selected = trim((string) ($_GET['conversation'] ?? ($conversations[0]['id'] ?? '')));
        $conversation = $this->validConversationId($selected) ? $this->ai->conversationWithMessages($selected, $userId) : null;
        if ($conversation) { $this->ai->markRead($selected, $userId); $conversations = $this->ai->conversations($userId); }
        $this->render('Live chat', 'conversations', ['conversations' => $conversations, 'conversation' => $conversation, 'chatUsers' => $this->ai->users(), 'chatTeams' => $this->ai->teams(false)]);
    }
    public function liveChatConfiguration(): never
    {
        $this->guard();
        $settings = $this->liveChatSettings();
        $userSettings = LiveChatSettings::userFrom($this->cms->setting('live_chat_user_' . ($this->auth->id() ?? 0), []));
        $userSettings['effective_avatar'] = LiveChatSettings::avatar($this->auth->user(), $userSettings);
        $this->render('Live chat configuration', 'live-chat-config', ['liveChatSettings' => $settings, 'liveChatUserSettings' => $userSettings, 'languages' => $this->cms->languages(), 'chatUsers' => $this->ai->users(), 'chatTeams' => $this->ai->teams()]);
    }
    public function saveLiveChatConfiguration(): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $settings = $this->liveChatSettings();
        foreach ($this->cms->languages() as $language) {
            $locale = (string) $language['locale'];
            foreach (['title' => 80, 'welcome' => 120, 'intro' => 220, 'online' => 100, 'offline' => 120] as $field => $limit) {
                $value = trim((string) ($_POST['localized'][$locale][$field] ?? ''));
                if ($value === '' || mb_strlen($value) > $limit) $this->result(false, 'Complete every active language field within its character limit.', null, 422);
                $settings['general']['localized'][$locale][$field] = $value;
            }
        }
        $allowed = array_map(static fn(int $number): string => sprintf('notification-%02d.mp3', $number), range(1, 5));
        $settings['sounds']['incoming'] = 'ring.mp3';
        $settings['sounds']['admin_message'] = in_array($_POST['admin_message_sound'] ?? '', $allowed, true) ? $_POST['admin_message_sound'] : 'notification-05.mp3';
        $settings['sounds']['visitor_message'] = in_array($_POST['visitor_message_sound'] ?? '', $allowed, true) ? $_POST['visitor_message_sound'] : 'notification-03.mp3';
        $settings['availability']['enabled'] = isset($_POST['availability_enabled']);
        $timezone = trim((string) ($_POST['availability_timezone'] ?? 'Asia/Phnom_Penh'));
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) $this->result(false, 'Choose a valid availability timezone.', null, 422);
        $settings['availability']['timezone'] = $timezone;
        foreach (array_keys(LiveChatSettings::defaults()['availability']['schedule']) as $day) {
            $start = trim((string) ($_POST['availability'][$day]['start'] ?? ''));
            $end = trim((string) ($_POST['availability'][$day]['end'] ?? ''));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) $this->result(false, 'Enter valid start and end times for every day.', null, 422);
            $settings['availability']['schedule'][$day] = ['enabled' => isset($_POST['availability'][$day]['enabled']), 'start' => $start, 'end' => $end];
        }
        $settings['retention'] = ['enabled' => isset($_POST['retention_enabled']), 'days' => max(1, min(365, (int) ($_POST['retention_days'] ?? 30)))];
        $teams = [];
        foreach ((array) ($_POST['teams'] ?? []) as $row) {
            if (!is_array($row) || isset($row['removed'])) continue;
            $name = trim((string) ($row['name'] ?? ''));
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            $description = trim((string) ($row['description'] ?? ''));
            $color = trim((string) ($row['color'] ?? '#2563eb'));
            if ($name === '' && $description === '') continue;
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !preg_match('/^[a-z0-9][a-z0-9-]{5,119}$/', $slug) || mb_strlen($description) > 500 || !preg_match('/^#[0-9a-f]{6}$/i', $color)) $this->result(false, 'Check the team name, description and colour.', null, 422);
            $members = array_values(array_filter(array_map('intval', (array) ($row['members'] ?? []))));
            if (isset($row['active']) && !$members) $this->result(false, 'Every active team must have at least one operator.', null, 422);
            $teams[] = ['name' => $name, 'slug' => $slug, 'description' => $description, 'color' => strtolower($color), 'active' => isset($row['active']), 'members' => $members];
        }
        if (count($teams) > 50 || count(array_unique(array_column($teams, 'slug'))) !== count($teams)) $this->result(false, 'Team identifiers must be unique and limited to 50 teams.', null, 422);
        $userKey = 'live_chat_user_' . ($this->auth->id() ?? 0);
        $userSettings = LiveChatSettings::userFrom($this->cms->setting($userKey, []));
        $mode = in_array($_POST['avatar_mode'] ?? '', ['default', 'profile', 'custom'], true) ? (string) $_POST['avatar_mode'] : 'default';
        try {
            if (isset($_FILES['chat_avatar']) && ($_FILES['chat_avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $userSettings['custom_avatar_url'] = $this->storeChatAvatar();
            if ($mode === 'profile' && empty($this->auth->user()['avatar_url'])) $this->result(false, 'Upload a profile photo before selecting the profile photo option.', null, 422);
            if ($mode === 'custom' && empty($userSettings['custom_avatar_url'])) $this->result(false, 'Select and crop a custom chat avatar.', null, 422);
            $userSettings['avatar_mode'] = $mode;
            $this->ai->syncTeams($teams);
            $this->cms->saveSetting('live_chat_settings', $settings);
            $this->cms->saveSetting($userKey, $userSettings);
            $activeTab = in_array($_POST['active_tab'] ?? '', ['general', 'teams', 'sounds', 'appearance', 'operations'], true) ? (string) $_POST['active_tab'] : 'general';
            $uiLocale=preg_replace('/[^a-z0-9-]/','',strtolower((string)($_POST['ui_locale']??'en')))?:'en';$this->result(true, 'Live chat configuration saved.', '/conversations/configuration?lang='.$uiLocale.'#' . $activeTab);
        } catch (\RuntimeException $exception) { $this->result(false, $exception->getMessage(), null, 422); }
    }
    public function sendConversationMessage(string $id): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        if (!$this->validConversationId($id)) $this->result(false, 'Conversation not found.', null, 404);
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 4000) $this->result(false, 'Enter a reply up to 4,000 characters.', null, 422);
        if (!$this->ai->agentReply($id, $this->auth->id() ?? 0, $content)) $this->result(false, 'This conversation is closed or unavailable.', null, 409);
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => 'Reply sent.', 'data' => ['content' => $content, 'agent_name' => (string) ($this->auth->user()['name'] ?? 'Support team'), 'created_at' => date('Y-m-d H:i:s')]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $this->result(true, 'Reply sent.', '/conversations?conversation=' . rawurlencode($id));
    }
    public function deleteConversation(string $id): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        $userId = $this->auth->id() ?? 0;
        if (!$this->validConversationId($id) || !$this->ai->canAccessConversation($id, $userId) || !$this->ai->deleteConversation($id)) $this->result(false, 'Conversation not found.', null, 404);
        $this->result(true, 'Conversation deleted and the visitor chat ended.', '/conversations');
    }
    public function transferConversation(string $id): never
    {
        $this->guard();
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Your session token is invalid. Refresh and try again.', null, 419);
        if (!$this->validConversationId($id)) $this->result(false, 'Conversation not found.', null, 404);
        $target = trim((string) ($_POST['target'] ?? ''));
        $toUser = preg_match('/^user:(\d+)$/', $target, $match) ? (int) $match[1] : null;
        $toTeam = preg_match('/^team:(\d+)$/', $target, $match) ? (int) $match[1] : null;
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        $result = $this->ai->transferConversation($id, $this->auth->id() ?? 0, $toUser, $toTeam, $note);
        if (!$result) $this->result(false, 'The conversation could not be transferred to that destination.', null, 409);
        $this->result(true, 'Conversation transferred to ' . $result['label'] . '.', '/conversations');
    }
    public function exportConversation(string $id): never
    {
        $this->guard();
        $userId = $this->auth->id() ?? 0;
        $conversation = $this->validConversationId($id) ? $this->ai->conversationWithMessages($id, $userId) : null;
        if (!$conversation || ($conversation['status'] ?? '') === 'closed') { http_response_code(404); exit('Conversation not found'); }
        $format = ($_GET['format'] ?? '') === 'json' ? 'json' : 'txt';
        $visitor = trim((string) ($conversation['visitor_name'] ?: $conversation['visitor_email'] ?: 'Website visitor'));
        $payload = ['conversation' => ['id' => $conversation['id'], 'visitor' => $visitor, 'locale' => $conversation['locale'], 'status' => $conversation['status'], 'team' => $conversation['team_name'] ?? null, 'operator' => $conversation['agent_name'] ?? null, 'created_at' => $conversation['created_at'], 'updated_at' => $conversation['updated_at']], 'messages' => $conversation['messages'], 'transfers' => $this->ai->transferHistory($id), 'exported_at' => date(DATE_ATOM)];
        $filename = 'sensecms-chat-' . substr($id, 0, 8) . '-' . date('Ymd-His') . '.' . $format;
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($format === 'json') { header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        header('Content-Type: text/plain; charset=utf-8');
        echo "SenseCMS Live Chat transcript\nConversation: {$id}\nVisitor: {$visitor}\nLocale: {$conversation['locale']}\nCreated: {$conversation['created_at']}\n\n";
        foreach ($conversation['messages'] as $message) { $author = $message['agent_name'] ?: ucfirst((string) $message['role']); echo '[' . $message['created_at'] . '] ' . $author . ":\n" . $message['content'] . "\n\n"; }
        exit;
    }
    private function storeBranding(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 2_000_000) { throw new \RuntimeException('Upload a valid image smaller than 2 MB.'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
        if (!isset($extensions[$mime])) throw new \RuntimeException('Only PNG, JPG, WebP, SVG or ICO files are accepted.');
        $dir = dirname(__DIR__, 2) . '/public/media/branding';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Branding storage could not be created.');
        $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new \RuntimeException('Branding file could not be stored.');
        @chmod($dir . '/' . $name, 0640);
        return '/media/branding/' . $name;
    }
    private function storeVideo(string $field, string $prefix = 'hero'): string
    {
        $file=$_FILES[$field]??null; if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||(int)$file['size']>80_000_000) throw new \RuntimeException('Upload a valid MP4 video smaller than 80 MB.');
        if((new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='video/mp4') throw new \RuntimeException('Only MP4 video is accepted.'); $dir=dirname(__DIR__,2).'/public/media/video'; if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) throw new \RuntimeException('Video storage could not be created.'); $name=preg_replace('/[^a-z0-9-]/', '', strtolower($prefix)) . '-'.bin2hex(random_bytes(10)).'.mp4'; if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new \RuntimeException('Video could not be stored.'); @chmod($dir.'/'.$name,0640); return '/media/video/'.$name;
    }
    private function storeHomeImage(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 5_000_000) throw new \RuntimeException('Upload a PNG, JPG or WebP image smaller than 5 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) throw new \RuntimeException('Only PNG, JPG or WebP images are accepted for the homepage popup.');
        $size = @getimagesize($file['tmp_name']); if (!$size || $size[0] > 9000 || $size[1] > 9000) throw new \RuntimeException('The popup image dimensions are not supported.');
        $dir = dirname(__DIR__, 2) . '/public/media/home'; if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Homepage image storage could not be created.');
        $name = 'popup-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime]; if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new \RuntimeException('Popup image could not be stored.'); @chmod($dir . '/' . $name, 0640); return '/media/home/' . $name;
    }
    private function storeAvatar(): string
    {
        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 2_000_000) throw new \RuntimeException('Upload a valid image smaller than 2 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new \RuntimeException('Only PNG, JPG or WebP profile photos are accepted.');
        $size = @getimagesize($file['tmp_name']); if (!$size || $size[0] > 8000 || $size[1] > 8000 || !function_exists('imagecreatefromstring')) throw new \RuntimeException('The profile photo dimensions are not supported.');
        $dir = dirname(__DIR__, 2) . '/public/media/avatars'; if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Profile photo storage could not be created.');
        $source = @imagecreatefromstring((string) file_get_contents($file['tmp_name'])); if (!$source) throw new \RuntimeException('The profile photo could not be processed.');
        $edge = min(imagesx($source), imagesy($source)); $x = (imagesx($source) - $edge) / 2; $y = (imagesy($source) - $edge) / 2; $avatar = imagecreatetruecolor(320, 320); imagealphablending($avatar, false); imagesavealpha($avatar, true); imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127)); imagecopyresampled($avatar, $source, 0, 0, (int) $x, (int) $y, 320, 320, $edge, $edge);
        $name = 'avatar-' . bin2hex(random_bytes(10)) . '.webp'; $saved = imagewebp($avatar, $dir . '/' . $name, 86); imagedestroy($avatar); imagedestroy($source); if (!$saved) throw new \RuntimeException('Profile photo could not be stored.'); @chmod($dir . '/' . $name, 0640); return '/media/avatars/' . $name;
    }
    private function storeSound(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 2_000_000) throw new \RuntimeException('Upload a valid MP3 file smaller than 2 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if (!in_array($mime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)) throw new \RuntimeException('Only MP3 sound files are accepted.');
        $dir = dirname(__DIR__, 2) . '/public/sensecms/audio/custom'; if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Sound storage could not be created.');
        $name = 'sound-' . bin2hex(random_bytes(10)) . '.mp3'; if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new \RuntimeException('Sound file could not be stored.'); @chmod($dir . '/' . $name, 0640); return 'custom/' . $name;
    }
    private function storeChatAvatar(): string
    {
        $file = $_FILES['chat_avatar'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 2_000_000) throw new \RuntimeException('Upload a valid image smaller than 2 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new \RuntimeException('Only PNG, JPG or WebP chat avatars are accepted.');
        $size = @getimagesize($file['tmp_name']);
        if (!$size || $size[0] > 8000 || $size[1] > 8000 || !function_exists('imagecreatefromstring')) throw new \RuntimeException('The chat avatar dimensions are not supported.');
        $source = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$source) throw new \RuntimeException('The chat avatar could not be processed.');
        $dir = dirname(__DIR__, 2) . '/public/media/chat-avatars';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Chat avatar storage could not be created.');
        $edge = min(imagesx($source), imagesy($source)); $x = (imagesx($source) - $edge) / 2; $y = (imagesy($source) - $edge) / 2;
        $avatar = imagecreatetruecolor(320, 320); imagealphablending($avatar, false); imagesavealpha($avatar, true); imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127)); imagecopyresampled($avatar, $source, 0, 0, (int) $x, (int) $y, 320, 320, $edge, $edge);
        $name = 'chat-avatar-' . bin2hex(random_bytes(10)) . '.webp'; $saved = imagewebp($avatar, $dir . '/' . $name, 86); imagedestroy($avatar); imagedestroy($source);
        if (!$saved) throw new \RuntimeException('The chat avatar could not be stored.');
        @chmod($dir . '/' . $name, 0640);
        return '/media/chat-avatars/' . $name;
    }
    private function localizedInput(string $group,array $fields,bool $requireSlug=true,string $fallbackName=''):array
    {
        $source=is_array($_POST[$group]??null)?$_POST[$group]:[];$languages=$this->cms->languages();$localized=[];$defaultLocale=(string)((array_values(array_filter($languages,static fn(array$language):bool=>(bool)($language['is_default']??false)))[0]??($languages[0]??['locale'=>'en']))['locale']);foreach($languages as$language){$locale=(string)$language['locale'];$values=is_array($source[$locale]??null)?$source[$locale]:[];$row=[];foreach($fields as$key=>$limit)$row[$key]=mb_substr(trim((string)($values[$key]??'')),0,$limit);if(!$requireSlug){if($row['name']==='')$row['name']=$fallbackName!==''?ucwords(str_replace('-',' ',$fallbackName)):'Untitled';$localized[$locale]=$row;continue;}$hasContent=implode('',array_values($row))!=='';if(!$hasContent&&$locale!==$defaultLocale)continue;if(($row['title']??'')===''||($row['slug']??'')==='')throw new \RuntimeException($language['name'].' requires both a title and URL slug.');$row['slug']=strtolower($row['slug']);if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$row['slug']))throw new \RuntimeException($language['name'].' URL slug must use lowercase letters, numbers and hyphens.');$localized[$locale]=$row;}if(!isset($localized[$defaultLocale]))throw new \RuntimeException('The default language content is required.');return$localized;
    }

    private function publicationDate(string $status,string $value):?string
    {
        if($status==='draft'||$status==='private')return null;if($value==='')return$status==='published'?null:throw new \RuntimeException('Choose a future publication date.');if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/',$value,$parts)||(int)$parts[1]<1000||(int)$parts[1]>9999)throw new \RuntimeException('Choose a valid publication date and time.');$date=\DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$value);if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new \RuntimeException('Choose a valid publication date and time.');if($status==='scheduled'&&$date<=new \DateTimeImmutable('now'))throw new \RuntimeException('Scheduled publication must be in the future.');return$date->format('Y-m-d H:i:s');
    }

    private function safeLocale(string $locale):string{$locale=preg_replace('/[^a-z0-9-]/','',strtolower($locale))?:'';$active=array_column($this->cms->languages(),'locale');return in_array($locale,$active,true)?$locale:(string)($active[0]??'en');}
    private function surveyFilters():array{$status=(string)($_GET['status']??'all');return['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120),'status'=>in_array($status,['all','draft','scheduled','published','closed'],true)?$status:'all','facility_id'=>max(0,(int)($_GET['facility_id']??0))];}
    private function builderCatalog(array$theme):array{$states=$this->extensionStates();return PageBuilder::catalog($theme,$this->plugins->all(),$this->cms->activePluginSlugs(),$this->addons->all(),array_keys(array_filter($states)));}
    private function pageTemplates():array
    {
        $slug=(string)$this->cms->setting('active_theme','sensecms');$theme=$this->themes->all()[$slug]??[];$templates=[];foreach((array)($theme['page_templates']??[])as$template){$key=(string)($template['key']??'');$label=trim((string)($template['label']??''));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$key)||$label==='')continue;$templates[]=['key'=>$key,'label'=>$label,'description'=>mb_substr(trim((string)($template['description']??'')),0,240)];}return$templates?:[['key'=>'default','label'=>'Default','description'=>'Uses the active theme and Page Builder sections.']];
    }
    private function mediaFilters():array{$kind=(string)($_GET['kind']??'all');$facility=(string)($_GET['facility']??'all');$orientation=(string)($_GET['orientation']??'all');$sort=(string)($_GET['sort']??'newest');return['q'=>mb_substr(trim((string)($_GET['q']??'')),0,120),'kind'=>in_array($kind,['all','image','video','audio','document'],true)?$kind:'all','folder'=>(int)($_GET['folder']??-1),'tag'=>max(0,(int)($_GET['tag']??0)),'facility'=>preg_match('/^(?:all|shared|\d+)$/',$facility)?$facility:'all','orientation'=>in_array($orientation,['all','landscape','portrait','square'],true)?$orientation:'all','status'=>($_GET['status']??'active')==='trash'?'trash':'active','sort'=>in_array($sort,['newest','oldest','name','size'],true)?$sort:'newest'];}
    private function uploadedMediaFiles():array{$upload=$_FILES['assets']??null;if(!is_array($upload))return[];if(!is_array($upload['name']??null))return[$upload];$files=[];foreach($upload['name']as$index=>$name)$files[]=['name'=>$name,'type'=>$upload['type'][$index]??'','tmp_name'=>$upload['tmp_name'][$index]??'','error'=>$upload['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$upload['size'][$index]??0];return$files;}
    private function validMenuUrl(string $url):bool{return $url!==''&&$this->validPublicUrl($url);}
    private function duplicateContentMessage(\PDOException$error,string$type):string{return str_contains(strtolower($error->getMessage()),'duplicate')?'That '.$type.' URL slug is already used in one of the languages.':'The '.$type.' could not be saved.';}
    private function extensionStates():array{return array_replace(['page-popups'=>true,'forms'=>true,'live-chat'=>true,'facility-geolocation'=>true,'surveys'=>true],(array)$this->cms->setting('extension_states',[]));}
    private function marketplacePayload(array$input):array
    {
        $states=$this->extensionStates();$themes=$this->themes->all();$plugins=$this->plugins->all();$addons=ExtensionCatalog::addons($states);$installedThemes=$this->cms->installedThemes();$installedPlugins=$this->cms->installedPlugins();
        $this->packages->syncBundled($themes,$plugins,$addons,$installedThemes,$installedPlugins);$installedThemes=$this->cms->installedThemes();$installedPlugins=$this->cms->installedPlugins();$packages=$this->packages->packages();$remote=array_merge($this->officialMarketplaceEntries(),$this->governance->publishedEntries());$identities=[];foreach($remote as$entry)$identities[(string)($entry['type']??'').':'.(string)($entry['slug']??'')]=true;foreach($packages as$package)if(($package['source']??'')==='package')$identities[$package['type'].':'.$package['slug']]=true;
        $themes=array_filter($themes,static fn(array$manifest,string$slug):bool=>isset($identities['theme:'.$slug]),ARRAY_FILTER_USE_BOTH);$plugins=array_filter($plugins,static fn(array$manifest,string$slug):bool=>isset($identities['plugin:'.$slug]),ARRAY_FILTER_USE_BOTH);$addons=array_values(array_filter($addons,static fn(array$addon):bool=>isset($identities['addon:'.($addon['slug']??'')])));$packages=array_values(array_filter($packages,static fn(array$package):bool=>isset($identities[$package['type'].':'.$package['slug']])));
        $result=MarketplaceCatalog::build($this->packages->engineVersion(),$themes,$plugins,$addons,$packages,$installedThemes,$installedPlugins,$input,$remote);$history=$this->packages->auditHistory();foreach($result['items']as&$item){if($item['type']==='theme')$item['config_url']=!empty($item['managed_releases'])?'/appearance/themes#installed-releases-title':'/appearance/themes?configure='.rawurlencode($item['slug']).'#theme-workspace';if($item['type']==='theme'&&$item['active'])$item['uninstallable']=false;$item['audit']=$history[$item['identity']]??[];}unset($item);return$result;
    }
    private function officialMarketplaceEntries():array
    {
        $products=(array)(($this->updater?->status()??[])['products']??[]);$locale=$this->officialMarketplaceLocale();$entries=[];foreach($products as$product){if(empty($product['installable']))continue;$id=(string)($product['id']??'');$type=(string)($product['type']??'');$slug=(string)($product['slug']??'');$version=(string)($product['version']??'');$checksum=(string)($product['package_checksum']??'');$url=(string)($product['package_url']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($type,['theme','plugin','addon'],true)||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||!preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/D',$version)||!preg_match('/^[a-f0-9]{64}$/D',$checksum)||!str_starts_with($url,\App\Core\OfficialCatalog::BASE.'/package/?id='))continue;$copy=(array)($product['copy'][$locale]??$product['copy']['en']??[]);$price=(string)($product['pricing']??'free');$manifest=['name'=>(string)($product['name']??$slug),'description'=>(string)($copy['description']??''),'icon'=>(string)($product['icon']??'package'),'group'=>match($type){'theme'=>'Presentation themes','addon'=>'SenseCMS add-ons',default=>'Integrations'},'author'=>'SenseCMS','price_model'=>in_array($price,['free','paid'],true)?$price:'free','price_label'=>(string)(($copy['meta'][0]??null)?:($price==='paid'?'Paid':'Free')),'release_channel'=>(string)($product['channel']??'stable')];$entries[]=['official_id'=>$id,'type'=>$type,'slug'=>$slug,'version'=>$version,'release_channel'=>$manifest['release_channel'],'publisher'=>'SenseCMS','publisher_url'=>'https://www.sensecms.com','manifest'=>$manifest];}return$entries;
    }
    private function officialMarketplaceProduct(string$id):array
    {
        foreach((array)(($this->updater?->status()??[])['products']??[])as$product)if(hash_equals((string)($product['id']??''),$id)&&!empty($product['installable']))return$product;throw new \RuntimeException('The official Marketplace product is unavailable.');
    }
    private function officialMarketplaceLocale():string
    {
        $value=strtolower(substr((string)($_GET['lang']??$_SERVER['HTTP_ACCEPT_LANGUAGE']??'en'),0,2));return in_array($value,['en','de','zh','vi','th','lo','pl'],true)?$value:'en';
    }
    private function themeConfiguration(string$slug,array$theme,array$configs):array
    {
        $parent=(string)($theme['parent']??'');$base=$parent!==''?$this->themeConfiguration($parent,$this->themes->find($parent),$configs):ThemeContract::defaults($theme);if($slug===(string)$this->cms->setting('active_theme','sensecms'))$base=array_replace($base,(array)$this->cms->setting('theme_settings',[]));return ThemeContract::sanitize($theme,(array)($configs[$slug]??[]),$base);
    }
    private function facilityGeolocationSettings():array{return array_replace(['mode'=>'redirect','auto_prompt'=>true,'remember_days'=>30],(array)$this->cms->setting('facility_geolocation',[]));}
    private function packagePresentation(array$package):array{return['source'=>$package['source']??'bundled','signature_status'=>$package['signature_status']??'distribution','rollback_count'=>(int)($package['rollback_count']??0),'available_version'=>$package['available_version']??null,'last_error'=>$package['last_error']??null,'installed_at'=>$package['installed_at']??null,'package_type'=>$package['type']??null];}
    private function jsonPayload():array{$payload=json_decode((string)file_get_contents('php://input'),true);return is_array($payload)?$payload:$_POST;}
    private function guard(): void { if (!$this->auth->check()) $this->redirect('/login'); }
    private function validConversationId(string $id): bool { return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id); }
    private function defaultCacheSettings(): array { return ['driver' => 'file', 'ttl' => 3600, 'host' => '127.0.0.1', 'port' => 11211, 'database' => 2]; }
    private function cacheSettings(): array { return $this->cms->setting('cache_settings', $this->defaultCacheSettings()); }
    private function soundSettings(): array { return SoundSettings::from($this->cms->setting('sound_settings', [])); }
    private function liveChatSettings(): array { return LiveChatSettings::from($this->cms->setting('live_chat_settings', [])); }
    private function defaultHome(): array
    {
        return [
            'hero_video' => '', 'hero_eyebrow' => 'Our learning community', 'hero_title' => 'A confident start for every learner.', 'hero_text' => 'A welcoming school community where children grow with curiosity, character and purpose.',
            'hero_primary_label' => 'Discover our school', 'hero_primary_url' => '#programs', 'hero_secondary_label' => 'Book a visit', 'hero_secondary_url' => '#contact',
            'motion_video' => '', 'motion_eyebrow' => 'Facility in motion', 'motion_title' => 'See a day at our school unfold.', 'motion_text' => 'A glimpse of the energy, connection and purposeful learning that shape our facility every day.', 'motion_cta_label' => 'Explore school life', 'motion_cta_url' => '#moments',
            'admissions_eyebrow' => 'Admissions', 'admissions_title' => 'A clear path to your child’s next chapter.', 'admissions_text' => 'Start with a conversation, experience the facility, then join a community where every learner is known.', 'admissions_cta_label' => 'Talk to admissions', 'admissions_cta_url' => '#contact',
            'admissions_step_1_title' => 'Discover', 'admissions_step_1_text' => 'Explore our learning approach and school community.', 'admissions_step_1_url' => '#programs', 'admissions_step_2_title' => 'Visit', 'admissions_step_2_text' => 'Meet our team, see the facility and ask the questions that matter to your family.', 'admissions_step_2_url' => '#contact', 'admissions_step_3_title' => 'Join', 'admissions_step_3_text' => 'Take your next step with personalised guidance from our admissions team.', 'admissions_step_3_url' => '#contact',
            'news_eyebrow' => 'What’s new', 'news_title' => 'Stories from our community.', 'news_text' => 'Read the latest news, learning moments and community updates.', 'cta_title' => 'Begin your family’s school journey.', 'cta_text' => 'Meet our team and experience the school in person.', 'cta_label' => 'Talk to admissions', 'cta_url' => '#contact',
        ];
    }
    private function defaultPopup(): array
    {
        return ['enabled' => false, 'width' => 1040, 'image_width' => 390, 'height' => 645, 'eyebrow' => 'Admissions', 'title' => 'Your next chapter starts here.', 'text' => 'Meet our community, explore the learning experience and speak with the admissions team.', 'cta_label' => 'Schedule a visit', 'cta_url' => '#contact', 'image_url' => '/theme-assets/sensecms/images/sensecms-facility.webp', 'start_at' => '', 'end_at' => '', 'frequency' => 'session', 'background_color' => '#123b4a', 'accent_color' => '#e96c4a', 'text_color' => '#ffffff', 'icon_color' => '#f4c95d', 'facts' => [['icon' => 'graduation-cap', 'title' => 'Purposeful learning', 'text' => 'A clear pathway for every learner.'], ['icon' => 'globe', 'title' => 'Global perspective', 'text' => 'Connected learning for a changing world.'], ['icon' => 'heart', 'title' => 'Known and supported', 'text' => 'A community built around belonging.']], 'localized' => [
            'en' => ['eyebrow' => 'Admissions', 'title' => 'Your next chapter starts here.', 'text' => 'Meet our community, explore the learning experience and speak with the admissions team.', 'cta_label' => 'Schedule a visit', 'image_url' => '/theme-assets/sensecms/images/sensecms-facility.webp'],
            'km' => ['eyebrow' => 'ការចុះឈ្មោះចូលរៀន', 'title' => 'ជំហានបន្ទាប់របស់អ្នកចាប់ផ្តើមនៅទីនេះ។', 'text' => 'ស្គាល់សហគមន៍របស់យើង ស្វែងយល់ពីបទពិសោធន៍សិក្សា និងពិភាក្សាជាមួយក្រុមចុះឈ្មោះ។', 'cta_label' => 'កក់ទស្សនាសាលា', 'image_url' => '/theme-assets/sensecms/images/sensecms-facility.webp'],
            'zh' => ['eyebrow' => '招生', 'title' => '您的下一段旅程从这里开始。', 'text' => '认识我们的社区，了解学习体验，并与招生团队沟通。', 'cta_label' => '预约参观', 'image_url' => '/theme-assets/sensecms/images/sensecms-facility.webp'],
        ]];
    }
    private function seoGlobal(): array { return array_replace_recursive(SeoMeta::globalDefaults(), (array)$this->cms->setting('seo_global', [])); }
    private function validPopupDate(string $value): bool { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $value, $parts) || (int) $parts[1] < 1000 || (int) $parts[1] > 9999) return false; $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value); return $date !== false && $date->format('Y-m-d\TH:i') === $value; }
    private function emailCsrf(): void { if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your session token is invalid. Refresh and try again.',null,419); }
    private function validPublicUrl(string $value, bool $image = false): bool { if (str_starts_with($value, '/')) return !str_starts_with($value, '//'); if (str_starts_with($value, '#')) return !$image; if (!$image && (str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:'))) return true; return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true); }
    private function cacheStore(array $settings): CacheService { return new CacheService($settings, dirname(__DIR__, 2) . '/storage/cache'); }
    private function render(string $title, string $screen, array $data = []): never { $csrf = $this->auth->csrf(); $flash = $_SESSION['flash'] ?? null; $flashType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash'],$_SESSION['flash_type']); $licenseNotice = $this->license->expiringNotice(); $sounds = $this->soundSettings(); $user = $this->auth->user(); $liveChatSettings = $this->liveChatSettings(); $accessPermissions=$this->access->permissions();$extensionNavigation=$this->packages->navigation($accessPermissions);$currentRoleNames=$this->access->roleNames();$isDemoUser=$this->access->isDemoUser();$showDemoWelcome=$screen==='dashboard'&&$isDemoUser&&!empty($_SESSION['demo_notice_pending']);if($showDemoWelcome)unset($_SESSION['demo_notice_pending']);$scope=$this->access->facilityIds();$liveChatUnread = $this->access->allows('chat.view')?$this->ai->unreadTotal($this->auth->id() ?? 0):0; $formUnread = $this->access->allows('forms.view')?$this->cms->formUnreadCount($scope):0;$surveyUnread=$this->access->allows('surveys.responses')?$this->surveys->recentCompletedCount($scope):0; $packageUpdates=$this->access->allows('extensions.manage')?$this->packages->updateCount():0;$editorialUnread=$this->workflowRepository->unreadCount($this->auth->id()??0); $this->view(dirname(__DIR__) . '/Views/console.php', compact('title', 'screen', 'csrf', 'flash','flashType', 'licenseNotice', 'sounds', 'user', 'liveChatSettings', 'liveChatUnread', 'formUnread','surveyUnread','packageUpdates','editorialUnread','accessPermissions','extensionNavigation','currentRoleNames','isDemoUser','showDemoWelcome') + $data); }
}
