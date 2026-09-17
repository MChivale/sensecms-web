<?php
declare(strict_types=1);
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
use App\Core\IpCountry;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$file=tempnam(sys_get_temp_dir(),'sense-ip-');
$range=static fn(string $start,string $end,string $country):string=>inet_pton($start).inet_pton($end).$country;
try {
    file_put_contents($file,'SENSEIP1'.pack('NN',2,1).$range('1.1.1.0','1.1.1.255','AU').$range('8.8.8.0','8.8.8.255','US').$range('2001:4860::','2001:4860:ffff:ffff:ffff:ffff:ffff:ffff','US'));
    $check(IpCountry::ip('::ffff:8.8.8.8')==='8.8.8.8','Mapped IPv4 canonicalized');
    $check(IpCountry::ip('2001:4860:0000::1')==='2001:4860::1','IPv6 canonicalized');
    foreach(['8.8.8.8, 1.1.1.1','bad',[],null,'1.1.1.1:80'] as $invalid)$check(IpCountry::ip($invalid)===null,'Invalid IP rejected');
    foreach(['1.1.1.0','1.1.1.255'] as $ip)$check(IpCountry::lookup($ip,$file)==='AU','Inclusive IPv4 boundary');
    $check(IpCountry::lookup('8.8.8.8',$file)==='US','IPv4 binary search');
    foreach(['2001:4860::','2001:4860:ffff:ffff:ffff:ffff:ffff:ffff'] as $ip)$check(IpCountry::lookup($ip,$file)==='US','Inclusive IPv6 boundary');
    foreach(['127.0.0.1','10.1.2.3','192.168.1.2','::1','fc00::1','2.2.2.2','8.8.9.0'] as $ip)$check(IpCountry::lookup($ip,$file)===null,'Private or uncovered IP remains unknown');
    $check(IpCountry::lookup('1.1.1.1',$file.'-missing')===null,'Missing data does not break chat');
    file_put_contents($file,'SENSEIP1'.pack('NN',2,1));
    $check(IpCountry::lookup('1.1.1.1',$file)===null,'Truncated data fails closed');
    file_put_contents($file,'wrong-format');
    $check(IpCountry::lookup('1.1.1.1',$file)===null,'Invalid data fails closed');
    $check(IpCountry::name(null)==='Unknown','Old conversations remain unknown');
    echo "$count offline IP-country checks passed.\n";
} finally {unlink($file);}
