<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\CaptchaService;
use App\Core\CmsRepository;
use App\Core\SurveyRepository;
use RuntimeException;

final class SurveyController extends Controller
{
    public function __construct(private readonly SurveyRepository $surveys,private readonly CmsRepository $cms,private readonly array$config){}

    public function show(string$locale,string$slug):never
    {
        $states=(array)$this->cms->setting('extension_states',[]);if(array_key_exists('surveys',$states)&&!$states['surveys']){$this->notFound();}$languages=$this->cms->languages();if(!in_array($locale,array_column($languages,'locale'),true))$this->notFound();$facility=max(0,(int)($_GET['facility']??0))?:null;$survey=$this->surveys->publicSurvey($slug,$locale,$facility);if(!$survey)$this->notFound();$token=$this->csrf();$embed=filter_var($_GET['embed']??false,FILTER_VALIDATE_BOOL);$captcha=(new CaptchaService('survey_'.$survey['uid']))->config($this->cms->setting('captcha_settings',[]));$this->view(dirname(__DIR__,2).'/addons/surveys/views/public.php',['survey'=>$survey,'languages'=>$languages,'locale'=>$locale,'csrf'=>$token,'embed'=>$embed,'captchaEnabled'=>(bool)$survey['captcha_enabled']&&(bool)$captcha['enabled'],'baseUrl'=>$this->config['base_url']??'']);
    }

    public function captcha(string$uid):never{$service=new CaptchaService('survey_'.$uid);$settings=$service->config($this->cms->setting('captcha_settings',[]));$service->saveCodeForImage($settings);$service->image();}

    public function start(string$uid):never
    {
        if(!$this->wantsJson())$this->result(false,'AJAX requests are required.',null,406);$payload=$this->jsonPayload();if(!hash_equals($this->csrf(),(string)($payload['csrf']??'')))$this->result(false,'Your survey session expired. Refresh and try again.',null,419);$locale=strtolower(trim((string)($payload['locale']??'')));$facility=max(0,(int)($payload['facility_id']??0))?:null;$survey=$this->surveys->publicSurveyByUid($uid,$locale,$facility);if(!$survey)$this->result(false,'This survey is not accepting responses.',null,404);try{$response=$this->surveys->start((int)$survey['id'],$locale,$facility,$this->visitorHash(),['referrer'=>mb_substr((string)($_SERVER['HTTP_REFERER']??''),0,500)]);$this->result(true,'Secure survey session started.',null,200,$response);}catch(RuntimeException$error){$this->result(false,$error->getMessage(),null,in_array($error->getCode(),[409,429],true)?$error->getCode():422);}
    }

    public function submit(string$uid):never
    {
        if(!$this->wantsJson())$this->result(false,'AJAX requests are required.',null,406);$payload=$this->jsonPayload();if(!hash_equals($this->csrf(),(string)($payload['csrf']??'')))$this->result(false,'Your survey session expired. Refresh and try again.',null,419);$context=$this->surveys->responseContext($uid);if(!$context)$this->result(false,'Survey response session is invalid.',null,419);$surveyUid=(string)$context['survey_uid'];if(!hash_equals($surveyUid,(string)($payload['survey_uid']??'')))$this->result(false,'Survey response session is invalid.',null,419);if(!empty($context['captcha_enabled'])){$settings=(new CaptchaService('survey_'.$surveyUid))->config($this->cms->setting('captcha_settings',[]));if(($settings['enabled']??true)&&!(new CaptchaService('survey_'.$surveyUid))->valid((string)($payload['captcha']??''),$settings))$this->result(false,'The security code is incorrect. Try a new code.',null,422,['captcha_url'=>'/captcha/surveys/'.$surveyUid.'.png?t='.time()]);}try{$result=$this->surveys->submit($uid,(string)($payload['token']??''),$payload);$this->result(true,'Thank you. Your response has been recorded.',null,200,$result);}catch(RuntimeException$error){$code=in_array($error->getCode(),[409,419,429],true)?$error->getCode():422;$this->result(false,$error->getMessage(),null,$code);}
    }

    private function csrf():string{if(empty($_SESSION['survey_csrf']))$_SESSION['survey_csrf']=bin2hex(random_bytes(32));return(string)$_SESSION['survey_csrf'];}
    private function visitorHash():string{return$this->surveys->visitorHash((string)($_SERVER['REMOTE_ADDR']??'unknown'),(string)($_SERVER['HTTP_USER_AGENT']??''));}
    private function notFound():never{http_response_code(404);exit('Survey not found');}
}
