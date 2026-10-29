<?php
declare(strict_types=1);

require dirname(__DIR__).'/.plugins/legacy-redirects/src/RedirectRules.php';

use SenseCMS\LegacyRedirects\RedirectRules;

$checks=0;
$check=static function(bool $ok,string $message) use (&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    $checks++;
};
$rejects=static function(callable $run,string $message) use ($check):void {
    try { $run(); } catch (RuntimeException $error) { $check($error->getCode()===422,$message); return; }
    $check(false,$message);
};

$defaults=RedirectRules::defaults();
$check(count($defaults)===1&&RedirectRules::match($defaults,'/gabinety/')===$defaults[0],'Legacy default redirects both former route aliases to the oxygen-therapy page');
$rules=RedirectRules::fromInput([
    ['aliases'=>"/old\n/older/",'destination'=>'/new/','status'=>'301'],
    ['aliases'=>['/campaign','/campaign/'],'destination'=>'https://example.com/landing','status'=>'302'],
]);
$check(count($rules)===2&&count($rules[0]['aliases'])===2&&$rules[1]['status']===302,'Multiple redirect groups support aliases and permanent or temporary status');
$check(RedirectRules::match($rules,'/older/')===$rules[0]&&RedirectRules::match($rules,'/missing')===null,'Redirect matching is exact');
$check(RedirectRules::fromInput([])===[],'An empty configuration disables redirects');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>"/same\n/same",'destination'=>'/target']]),'Duplicate aliases are rejected');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'//evil.example','destination'=>'/target']]),'Protocol-relative aliases are rejected');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old?next=/x','destination'=>'/target']]),'Aliases cannot contain query strings');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old','destination'=>'http://example.com']]),'External destinations require HTTPS');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old','destination'=>'https://user:pass@example.com']]),'External destinations cannot contain credentials');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old','destination'=>'/target','status'=>'307']]),'Only 301 and 302 redirect statuses are accepted');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old','destination'=>'/old']]),'Direct local redirect loops are rejected');
$rejects(static fn()=>RedirectRules::fromInput([['aliases'=>'/old','destination'=>'/older'],['aliases'=>'/older','destination'=>'/old']]),'Indirect local redirect loops are rejected');
$rejects(static fn()=>RedirectRules::fromInput(array_fill(0,101,['aliases'=>'/old','destination'=>'/target'])), 'Redirect group count is bounded');

echo "{$checks} legacy redirect checks passed.\n";
