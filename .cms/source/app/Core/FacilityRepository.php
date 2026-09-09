<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class FacilityRepository
{
    public function __construct(private readonly PDO $db) {}

    public function adminList(?array$facilityIds=null): array
    {
        $locale=$this->defaultLocale();
        $scope=$this->scope($facilityIds,'c.id');$statement=$this->db->prepare("SELECT c.*,COALESCE(t.name,CONCAT(c.city_slug,' / ',c.facility_slug)) name,COALESCE(t.city_name,c.city_slug) city_name,COALESCE(t.address,'') address,(SELECT COUNT(*) FROM pages p WHERE p.facility_id=c.id AND p.status<>'archived') page_count,(SELECT COUNT(*) FROM posts p WHERE p.facility_id=c.id AND p.status<>'archived') post_count,(SELECT COUNT(*) FROM facility_translations x WHERE x.facility_id=c.id) translation_count FROM facilities c LEFT JOIN facility_translations t ON t.facility_id=c.id AND t.locale=? {$scope['sql']} ORDER BY c.is_primary DESC,c.sort_order,c.id");
        $statement->execute([$locale,...$scope['params']]);
        return $statement->fetchAll();
    }

    public function admin(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM facilities WHERE id=? LIMIT 1');$statement->execute([$id]);$facility=$statement->fetch();
        if(!$facility)return null;
        $translations=$this->db->prepare('SELECT * FROM facility_translations WHERE facility_id=?');$translations->execute([$id]);$facility['translations']=array_column($translations->fetchAll(),null,'locale');
        return $facility;
    }

    public function active(string $locale,string $fallback): array
    {
        $statement=$this->db->prepare("SELECT c.*,COALESCE(t.name,ft.name) name,COALESCE(t.city_name,ft.city_name) city_name,COALESCE(t.short_description,ft.short_description,'') short_description,COALESCE(t.address,ft.address,'') address,COALESCE(t.seo_title,ft.seo_title,'') seo_title,COALESCE(t.seo_description,ft.seo_description,'') seo_description FROM facilities c LEFT JOIN facility_translations t ON t.facility_id=c.id AND t.locale=? LEFT JOIN facility_translations ft ON ft.facility_id=c.id AND ft.locale=? WHERE c.status='active' ORDER BY c.is_primary DESC,c.sort_order,c.id");
        $statement->execute([$locale,$fallback]);return array_map([$this,'normalize'],$statement->fetchAll());
    }

    public function primary(string $locale,string $fallback): ?array
    {
        foreach($this->active($locale,$fallback)as$facility)if($facility['is_primary'])return$facility;
        return $this->active($locale,$fallback)[0]??null;
    }

    public function resolve(string $citySlug,string $facilitySlug,string $locale,string $fallback): ?array
    {
        foreach($this->active($locale,$fallback)as$facility)if(hash_equals((string)$facility['city_slug'],$citySlug)&&hash_equals((string)$facility['facility_slug'],$facilitySlug))return$facility;
        return null;
    }

    public function activeById(int$id,string$locale,string$fallback):?array
    {
        foreach($this->active($locale,$fallback)as$facility)if((int)$facility['id']===$id)return$facility;return null;
    }

    public function options(bool$includeArchived=false,?array$facilityIds=null):array
    {
        $locale=$this->defaultLocale();$where=[];if(!$includeArchived)$where[]="c.status<>'archived'";$scope=$this->scope($facilityIds,'c.id');if($scope['sql'])$where[]=substr($scope['sql'],6);$sql=$where?'WHERE '.implode(' AND ',$where):'';$statement=$this->db->prepare("SELECT c.id,c.city_slug,c.facility_slug,c.status,c.is_primary,COALESCE(t.name,CONCAT(c.city_slug,' / ',c.facility_slug)) name,COALESCE(t.city_name,c.city_slug) city_name FROM facilities c LEFT JOIN facility_translations t ON t.facility_id=c.id AND t.locale=? {$sql} ORDER BY c.is_primary DESC,c.sort_order,c.id");$statement->execute([$locale,...$scope['params']]);return$statement->fetchAll();
    }

    public function pageOptions(int$facilityId):array
    {
        $locale=$this->defaultLocale();$statement=$this->db->prepare("SELECT p.id,COALESCE(t.title,CONCAT('Page #',p.id)) title,p.status FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=? WHERE p.facility_id=? AND p.status<>'archived' ORDER BY t.title,p.id");$statement->execute([$locale,$facilityId]);return$statement->fetchAll();
    }

    public function save(array$input,array$translations,int$userId):int
    {
        $id=(int)($input['id']??0);$city=$this->slug((string)($input['city_slug']??''),'City');$slug=$this->slug((string)($input['facility_slug']??''),'Facility');$status=(string)($input['status']??'draft');
        if(!in_array($status,['draft','active'],true))throw new RuntimeException('Choose a valid facility status.');
        $latitude=$this->coordinate($input['latitude']??null,-90,90,'Latitude');$longitude=$this->coordinate($input['longitude']??null,-180,180,'Longitude');
        if(($latitude===null)!==($longitude===null))throw new RuntimeException('Latitude and longitude must be provided together.');
        $timezone=trim((string)($input['timezone']??'UTC'));if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new RuntimeException('Choose a valid IANA timezone.');
        $email=trim((string)($input['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid facility email address.');
        $website=$this->optionalUrl((string)($input['website_url']??''),'Website URL');$map=$this->optionalUrl((string)($input['map_url']??''),'Map URL');$homepage=(int)($input['homepage_page_id']??0);
        $this->db->beginTransaction();
        try{
            if($id){$existing=$this->db->prepare('SELECT is_primary FROM facilities WHERE id=? FOR UPDATE');$existing->execute([$id]);$primary=$existing->fetchColumn();if($primary===false)throw new RuntimeException('Facility was not found.');if((bool)$primary&&$status!=='active')throw new RuntimeException('The primary facility must remain active. Choose another primary facility first.');$statement=$this->db->prepare('UPDATE facilities SET city_slug=?,facility_slug=?,status=?,email=?,phone=?,secondary_phone=?,website_url=?,map_url=?,latitude=?,longitude=?,timezone=?,search_enabled=?,sort_order=?,updated_at=NOW() WHERE id=?');$statement->execute([$city,$slug,$status,$email?:null,$this->short($input['phone']??'',50),$this->short($input['secondary_phone']??'',50),$website,$map,$latitude,$longitude,$timezone,!empty($input['search_enabled'])?1:0,max(-9999,min(9999,(int)($input['sort_order']??0))),$id]);}
            else{$this->db->prepare('INSERT INTO facilities (uid,city_slug,facility_slug,status,is_primary,email,phone,secondary_phone,website_url,map_url,latitude,longitude,timezone,search_enabled,sort_order,created_at,updated_at) VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$this->uuid(),$city,$slug,$status,$email?:null,$this->short($input['phone']??'',50),$this->short($input['secondary_phone']??'',50),$website,$map,$latitude,$longitude,$timezone,!empty($input['search_enabled'])?1:0,max(-9999,min(9999,(int)($input['sort_order']??0)))]);$id=(int)$this->db->lastInsertId();}
            $upsert=$this->db->prepare('INSERT INTO facility_translations (facility_id,locale,name,city_name,short_description,address,seo_title,seo_description) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),city_name=VALUES(city_name),short_description=VALUES(short_description),address=VALUES(address),seo_title=VALUES(seo_title),seo_description=VALUES(seo_description)');
            foreach($translations as$locale=>$value)$upsert->execute([$id,$locale,$value['name'],$value['city_name'],$value['short_description'],$value['address'],$value['seo_title'],$value['seo_description']]);
            if($homepage){$check=$this->db->prepare("SELECT 1 FROM pages WHERE id=? AND facility_id=? AND status<>'archived'");$check->execute([$homepage,$id]);if(!$check->fetchColumn())throw new RuntimeException('Choose a page belonging to this facility as its homepage.');$this->db->prepare('UPDATE facilities SET homepage_page_id=? WHERE id=?')->execute([$homepage,$id]);}else{$this->db->prepare('UPDATE facilities SET homepage_page_id=NULL WHERE id=?')->execute([$id]);}
            $this->audit($userId,$input['id']?'facility.updated':'facility.created',$id,['route'=>$city.'/'.$slug,'status'=>$status]);$this->db->commit();return$id;
        }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function setStatus(int$id,string$status,int$userId):void
    {
        if(!in_array($status,['active','archived'],true))throw new RuntimeException('Unsupported facility action.');
        $statement=$this->db->prepare('SELECT is_primary FROM facilities WHERE id=?');$statement->execute([$id]);$primary=$statement->fetchColumn();if($primary===false)throw new RuntimeException('Facility was not found.');if($status==='archived'&&(bool)$primary)throw new RuntimeException('Choose another primary facility before archiving this one.');
        $this->db->prepare('UPDATE facilities SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$id]);$this->audit($userId,$status==='active'?'facility.restored':'facility.archived',$id,[]);
    }

    public function setPrimary(int$id,int$userId):void
    {
        $this->db->beginTransaction();try{$statement=$this->db->prepare("SELECT 1 FROM facilities WHERE id=? AND status='active' FOR UPDATE");$statement->execute([$id]);if(!$statement->fetchColumn())throw new RuntimeException('Only an active facility can become primary.');$this->db->exec('UPDATE facilities SET is_primary=0,updated_at=NOW() WHERE is_primary=1');$this->db->prepare('UPDATE facilities SET is_primary=1,updated_at=NOW() WHERE id=?')->execute([$id]);$this->audit($userId,'facility.primary_changed',$id,[]);$this->db->commit();}catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function publicList(string$locale,string$fallback,?float$latitude=null,?float$longitude=null):array
    {
        $rows=$this->active($locale,$fallback);foreach($rows as&$row){$row['url']='/'.rawurlencode($locale).'/facilities/'.rawurlencode($row['city_slug']).'/'.rawurlencode($row['facility_slug']);$row['distance_km']=$latitude!==null&&$longitude!==null&&$row['latitude']!==null&&$row['longitude']!==null?round($this->distance($latitude,$longitude,(float)$row['latitude'],(float)$row['longitude']),1):null;}unset($row);
        if($latitude!==null&&$longitude!==null)usort($rows,static fn(array$a,array$b):int=>($a['distance_km']===null?PHP_FLOAT_MAX:$a['distance_km'])<=>($b['distance_km']===null?PHP_FLOAT_MAX:$b['distance_km']));
        return$rows;
    }

    public function exists(int$id,bool$activeOnly=false):bool{$statement=$this->db->prepare('SELECT 1 FROM facilities WHERE id=?'.($activeOnly?" AND status='active'":''));$statement->execute([$id]);return(bool)$statement->fetchColumn();}

    private function normalize(array$row):array{$row['id']=(int)$row['id'];$row['homepage_page_id']=$row['homepage_page_id']!==null?(int)$row['homepage_page_id']:null;$row['is_primary']=(bool)$row['is_primary'];$row['search_enabled']=(bool)$row['search_enabled'];$row['latitude']=$row['latitude']!==null?(float)$row['latitude']:null;$row['longitude']=$row['longitude']!==null?(float)$row['longitude']:null;return$row;}
    private function defaultLocale():string{return(string)($this->db->query('SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1')->fetchColumn()?:'en');}
    private function slug(string$value,string$label):string{$value=strtolower(trim($value));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$value)||strlen($value)>120)throw new RuntimeException($label.' slug must use lowercase letters, numbers and single hyphens.');return$value;}
    private function coordinate(mixed$value,float$min,float$max,string$label):?float{$value=trim((string)$value);if($value==='')return null;if(!is_numeric($value)||($number=(float)$value)<$min||$number>$max)throw new RuntimeException($label.' is outside its valid range.');return round($number,7);}
    private function optionalUrl(string$value,string$label):?string{$value=trim($value);if($value==='')return null;if(strlen($value)>1000||!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['https','http'],true))throw new RuntimeException($label.' must be a valid HTTP or HTTPS URL.');return$value;}
    private function short(mixed$value,int$length):?string{$value=trim((string)$value);return$value===''?null:mb_substr($value,0,$length);}
    private function distance(float$lat1,float$lng1,float$lat2,float$lng2):float{$earth=6371.0088;$lat=deg2rad($lat2-$lat1);$lng=deg2rad($lng2-$lng1);$a=sin($lat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($lng/2)**2;return$earth*2*atan2(sqrt($a),sqrt(1-$a));}
    private function audit(int$userId,string$event,int$id,array$context):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId?:null,$event,'facility',$id,json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);}
    private function scope(?array$facilityIds,string$column):array{if($facilityIds===null)return['sql'=>'','params'=>[]];$ids=array_values(array_unique(array_filter(array_map('intval',$facilityIds))));if(!$ids)return['sql'=>'WHERE 1=0','params'=>[]];return['sql'=>'WHERE '.$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')','params'=>$ids];}
}
