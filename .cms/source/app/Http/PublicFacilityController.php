<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\FacilityRepository;
use App\Core\CmsRepository;

final class PublicFacilityController extends Controller
{
    public function __construct(private readonly FacilityRepository $facilities,private readonly CmsRepository $cms,private readonly array$config){}

    public function index():never
    {
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
        $locale=strtolower(trim((string)($_GET['locale']??'')));if(!in_array($locale,array_column($this->cms->languages(),'locale'),true))$this->respond(false,[],'Language not found.',404);
        $latitude=$this->coordinate($_GET['lat']??null,-90,90);$longitude=$this->coordinate($_GET['lng']??null,-180,180);if(($latitude===null)!==($longitude===null))$this->respond(false,[],'Both coordinates are required.',422);
        $rows=$this->facilities->publicList($locale,$this->config['default_locale'],$latitude,$longitude);$data=array_map(static fn(array$row):array=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'city'=>(string)$row['city_name'],'address'=>(string)$row['address'],'url'=>(string)$row['url'],'latitude'=>$row['latitude'],'longitude'=>$row['longitude'],'distance_km'=>$row['distance_km'],'primary'=>(bool)$row['is_primary']],$rows);$this->respond(true,$data,null);
    }

    private function coordinate(mixed$value,float$min,float$max):?float{$value=trim((string)$value);if($value==='')return null;if(!is_numeric($value)||($number=(float)$value)<$min||$number>$max)$this->respond(false,[],'Coordinates are outside their valid range.',422);return round($number,7);}
    private function respond(bool$ok,array$data,?string$message,int$status=200):never{http_response_code($status);echo json_encode(['ok'=>$ok,'data'=>['facilities'=>$data],'meta'=>['count'=>count($data)],'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
}
