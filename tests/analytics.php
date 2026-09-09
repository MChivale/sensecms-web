<?php
declare(strict_types=1);
require dirname(__DIR__).'/.plugins/google-analytics/src/Analytics.php';
use SenseCMS\GoogleAnalytics\Analytics;
$count=0;
$check=static function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";};
$check(Analytics::measurementId(' g-abc123def4 ')==='G-ABC123DEF4','ID normalized');
$check(Analytics::measurementId('abc123def4')==='G-ABC123DEF4','ID suffix accepted');
$check(Analytics::measurementId('')==='','empty ID disables analytics');
foreach(['G-abc','<script>','G-ABCDEF?x=1',str_repeat('A',30)]as$id){try{Analytics::measurementId($id);throw new LogicException('Invalid ID accepted');}catch(RuntimeException $e){$check($e->getCode()===422,'invalid ID rejected');}}
$html='<html><head></head><body>Content</body></html>';
$injected=Analytics::inject($html,'ABC123DEF4');
$check(str_contains($injected,'data-measurement-id="G-ABC123DEF4"'),'validated identifier injected');
$check(Analytics::inject($injected,'ABC123DEF4')===$injected,'no duplicate injection');
$check(Analytics::inject($html,'')===$html,'disabled HTML unchanged');
$check(Analytics::inject('{"ok":true}','ABC123DEF4')==='{"ok":true}','non-HTML unchanged');
$policy=Analytics::policy("default-src 'self'; script-src 'self' 'nonce-test'; frame-ancestors 'none'; object-src 'none'; connect-src 'self'");
$check(str_contains($policy,"frame-ancestors 'none'")&&str_contains($policy,"object-src 'none'")&&str_contains($policy,"'nonce-test'"),'existing CSP protections preserved');
$check(!str_contains($policy,'unsafe-eval')&&!str_contains($policy,'unsafe-inline')&&!str_contains($policy,'doubleclick'),'no unsafe script or advertising permissions added');
$check(str_contains($policy,'https://*.google-analytics.com')&&str_contains($policy,'https://www.googletagmanager.com'),'required analytics hosts scoped');
echo "$count analytics PHP checks passed.\n";
