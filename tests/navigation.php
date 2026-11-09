<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\WorkspaceContext;
$checks=0;
$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
foreach(['https://example.test/content/pages','//example.test/content/pages','/\\example.test/content/pages',"/content/pages\r\nLocation: https://example.test",'/content/pages/1/delete','/api/operator/messages','javascript:alert(1)','/content/pages#fragment','/content/pages/../posts','/%63ontent/pages']as$url)$check(WorkspaceContext::returnUrl($url,'/dashboard')==='/dashboard','Unsafe or non-navigation return path is rejected');
$url=WorkspaceContext::returnUrl('/content/pages?q=hello%20world&status=published&page=2&csrf=secret&x=y','/dashboard');
$check($url==='/content/pages?q=hello%20world&status=published&page=2','List state is retained and unrelated query values are removed');
$check(WorkspaceContext::returnUrl('/content/pages?q[]=x&status=all','/dashboard')==='/content/pages?status=all','Nested query data is rejected');
$check(WorkspaceContext::switchTarget('/content/pages/12/edit?document=12')==='/content/pages','Facility switch leaves an existing editor at its list');
$check(WorkspaceContext::switchTarget('/content/posts/12/edit')==='/content/posts','Post editor also returns to its list on facility switch');
$check(WorkspaceContext::switchTarget('/content/builder?document=12&facility=4')==='/content/builder','Builder does not reopen a record from the preceding facility');
$check(WorkspaceContext::switchTarget('/content/pages?status=draft&q=hello&page=4&facility=3')==='/content/pages?status=draft&q=hello','Switch resets only facility, record and pagination filters');
$check(WorkspaceContext::switchTarget('/seo?type=post&document=2')==='/seo?type=post','SEO retains content type when changing facility');
$check(WorkspaceContext::switchTarget('/ai/knowledge?tab=index')==='/ai/knowledge?tab=index','Shared section retains its active tab');
$check(WorkspaceContext::switchTarget('/conversations/configuration?from=ai')==='/conversations/configuration?from=ai','Assistant navigation retains its AI origin when changing context');
$console=token_get_all((string)file_get_contents(__DIR__.'/../.cms/source/app/Views/console.php'));
$html=implode('',array_map(static fn(array|string$token):string=>is_array($token)&&$token[0]===T_INLINE_HTML?$token[1]:'',$console));
$check(!str_contains($html,'if($screen==='),'PHP navigation condition is not emitted as visible text');
$index=(string)file_get_contents(__DIR__.'/../.cms/source/public/index.php');
preg_match('/\$adminPath = (.*?);/s',$index,$adminRoute);
foreach([[true,'/workspace/context',true],[false,'/workspace/context',false],[true,'/workspace/context-extra',false],[true,'/ai/knowledge',true]]as[$workspaceEnabled,$path,$expected]){
    $check((bool)eval('return '.$adminRoute[1].';')===$expected,'Front controller routes only the enabled context endpoint through administration');
}
$knowledge=explode("\n",(string)file_get_contents(__DIR__.'/../.cms/source/app/Views/console-ai-knowledge.php'))[1];
foreach([[[],'sources'],[['tab'=>'index'],'index'],[['tab'=>'training'],'training'],[['tab'=>'unknown'],'sources']]as[$query,$expected]){
    $_GET=$query;eval($knowledge);$check($tab===$expected,'Knowledge Base defaults and explicit sections match their navigation');
}
echo "$checks navigation URL checks passed.\n";
$languages=[['locale'=>'en','is_default'=>0],['locale'=>'pl','is_default'=>1]];
foreach([['en','en'],['pl','pl'],['xx','pl'],[null,'pl'],[['pl'],'pl']]as[$requested,$expected])$check(WorkspaceContext::locale($languages,$requested)===$expected,'Only an active translation language is selected, with the configured default as fallback');
echo "Locale selection checks passed.\n";
