<?php
declare(strict_types=1);

require dirname(__DIR__).'/.addons/social-publishing/src/SocialRepository.php';

$checks=0;
$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo"PASS $label\n";};
$repository=(new ReflectionClass(SenseCMS\Social\SocialRepository::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod($repository,'defaultMessage');
$payload=['title'=>'A reviewed title','excerpt'=>'<p>'.str_repeat('Long editorial excerpt ',40).'</p>'];
foreach([250,300,400,5000]as$limit){$message=$method->invoke($repository,$payload,$limit);$check(mb_strlen($message)<=min(5000,$limit),'default social message respects the '.$limit.' character provider limit');}
$short=$method->invoke($repository,['title'=>'Short title','excerpt'=>'<p>Short excerpt</p>'],300);
$check($short==="Short title\n\nShort excerpt",'default social message preserves short reviewed content');
$source=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/src/SocialRepository.php');
$check(str_contains($source,'$limits[(string)$target[\'plugin_slug\']] ?? 5000'),'queue selects the declared limit for each provider');
$manager=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/src/SocialIntegrationManager.php');
$positions=array_map(static fn(string$platform):int=>strpos($manager,"'{$platform}'=>[")?:PHP_INT_MAX,['linkedin','x','facebook','telegram_channels','youtube','tiktok','pinterest','bluesky','mastodon']);$sorted=$positions;sort($sorted);
$check($positions===$sorted&&count(array_unique($positions))===count($positions),'provider presentation registry keeps the approved installed-platform order');
$check(str_contains($source,'$fallback=(int)($postMedia'),'post video is the default provider media without audio conversion');
echo"$checks Social Publishing regression checks passed.\n";
