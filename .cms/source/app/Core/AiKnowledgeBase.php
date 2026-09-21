<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use ZipArchive;

final class AiKnowledgeBase
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_BODY_CHARS = 2_000_000;

    public function __construct(private readonly PDO $db, private readonly string $root) {}

    public function summary(): array
    {
        $documents=$this->db->query("SELECT COUNT(*) total,SUM(status='published') active,SUM(index_status='ready') ready,SUM(index_status='failed') failed,COALESCE(SUM(byte_size),0) bytes FROM ai_knowledge_documents")->fetch()?:[];
        $chunks=(int)$this->db->query('SELECT COUNT(*) FROM ai_knowledge_chunks')->fetchColumn();
        $included=(int)$this->db->query("SELECT (SELECT COUNT(*) FROM pages WHERE ai_knowledge_enabled=1 AND status='published' AND visibility='public')+(SELECT COUNT(*) FROM posts WHERE ai_knowledge_enabled=1 AND status='published' AND (published_at IS NULL OR published_at<=NOW()))")->fetchColumn();
        return['documents'=>(int)($documents['total']??0),'active'=>(int)($documents['active']??0),'ready'=>(int)($documents['ready']??0),'failed'=>(int)($documents['failed']??0),'chunks'=>$chunks,'included_content'=>$included,'bytes'=>(int)($documents['bytes']??0)];
    }

    public function documents(string $query='',string $type='all',string $status='all',int $page=1,int $perPage=15):array
    {
        $where=[];$params=[];$query=mb_substr(trim($query),0,120);
        if($query!==''){$where[]='(d.title LIKE ? OR d.original_name LIKE ? OR d.source_url LIKE ?)';$like='%'.$query.'%';array_push($params,$like,$like,$like);}
        if(in_array($type,['manual','file','page','post'],true)){$where[]='d.source_type=?';$params[]=$type;}
        if(in_array($status,['draft','published','archived'],true)){$where[]='d.status=?';$params[]=$status;}
        $sql=$where?' WHERE '.implode(' AND ',$where):'';$count=$this->db->prepare('SELECT COUNT(*) FROM ai_knowledge_documents d'.$sql);$count->execute($params);$total=(int)$count->fetchColumn();$perPage=max(5,min(50,$perPage));$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,$page));
        $statement=$this->db->prepare("SELECT d.*,COALESCE(u.name,'System') updated_by_name,(SELECT COUNT(*) FROM ai_knowledge_chunks c WHERE c.document_id=d.id) chunk_count FROM ai_knowledge_documents d LEFT JOIN users u ON u.id=d.updated_by{$sql} ORDER BY FIELD(d.status,'published','draft','archived'),d.updated_at DESC,d.id DESC LIMIT ? OFFSET ?");$index=1;foreach($params as$value)$statement->bindValue($index++,$value);$statement->bindValue($index++,$perPage,PDO::PARAM_INT);$statement->bindValue($index,($page-1)*$perPage,PDO::PARAM_INT);$statement->execute();
        return['items'=>$statement->fetchAll(),'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total]];
    }

    public function document(int$id):?array
    {
        $statement=$this->db->prepare('SELECT * FROM ai_knowledge_documents WHERE id=? LIMIT 1');$statement->execute([$id]);return$statement->fetch()?:null;
    }

    public function siteContent(string$query='',string$type='all'):array
    {
        $query=mb_substr(trim($query),0,120);$like='%'.$query.'%';$result=[];
        if($type==='all'||$type==='page'){$sql="SELECT 'page' source_type,p.id,p.facility_id,p.status,p.ai_knowledge_enabled,COALESCE(t.title,CONCAT('Page #',p.id)) title,COALESCE(t.slug,'') slug,(SELECT COUNT(*) FROM page_translations x WHERE x.page_id=p.id) translation_count,p.updated_at FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1)";if($query!=='')$sql.=' WHERE t.title LIKE ? OR t.slug LIKE ?';$sql.=' ORDER BY p.updated_at DESC LIMIT 250';$statement=$this->db->prepare($sql);$statement->execute($query!==''?[$like,$like]:[]);$result=array_merge($result,$statement->fetchAll());}
        if($type==='all'||$type==='post'){$sql="SELECT 'post' source_type,p.id,p.facility_id,p.status,p.ai_knowledge_enabled,COALESCE(t.title,CONCAT('Post #',p.id)) title,COALESCE(t.slug,'') slug,(SELECT COUNT(*) FROM post_translations x WHERE x.post_id=p.id) translation_count,p.updated_at FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1)";if($query!=='')$sql.=' WHERE t.title LIKE ? OR t.slug LIKE ?';$sql.=' ORDER BY p.updated_at DESC LIMIT 250';$statement=$this->db->prepare($sql);$statement->execute($query!==''?[$like,$like]:[]);$result=array_merge($result,$statement->fetchAll());}
        usort($result,static fn(array$a,array$b):int=>strcmp((string)$b['updated_at'],(string)$a['updated_at']));return array_slice($result,0,300);
    }

    public function trainingSummary():array
    {
        return['datasets'=>(int)$this->db->query("SELECT COUNT(*) FROM ai_training_datasets WHERE status<>'archived'")->fetchColumn(),'approved_examples'=>(int)$this->db->query('SELECT COUNT(*) FROM ai_training_examples WHERE approved=1')->fetchColumn(),'jobs'=>(int)$this->db->query('SELECT COUNT(*) FROM ai_training_jobs')->fetchColumn(),'evaluations'=>(int)$this->db->query('SELECT COUNT(*) FROM ai_evaluation_cases WHERE active=1')->fetchColumn()];
    }

    public function trainingWorkspace():array
    {
        $datasets=$this->db->query("SELECT d.*,p.name provider_name,COUNT(e.id) example_count,SUM(e.approved=1) approved_count FROM ai_training_datasets d LEFT JOIN ai_providers p ON p.id=d.provider_id LEFT JOIN ai_training_examples e ON e.dataset_id=d.id WHERE d.status<>'archived' GROUP BY d.id ORDER BY d.updated_at DESC,d.id DESC")->fetchAll();
        $examples=$this->db->query("SELECT e.*,d.name dataset_name,COALESCE(u.name,'System') created_by_name FROM ai_training_examples e INNER JOIN ai_training_datasets d ON d.id=e.dataset_id LEFT JOIN users u ON u.id=e.created_by WHERE d.status<>'archived' ORDER BY e.updated_at DESC,e.id DESC LIMIT 100")->fetchAll();
        $evaluations=$this->db->query("SELECT e.*,COALESCE(u.name,'System') created_by_name FROM ai_evaluation_cases e LEFT JOIN users u ON u.id=e.created_by WHERE e.active=1 ORDER BY e.updated_at DESC,e.id DESC LIMIT 100")->fetchAll();
        return compact('datasets','examples','evaluations');
    }

    public function saveDataset(int$id,string$name,string$description,?int$providerId,string$baseModel,int$userId):int
    {
        $name=mb_substr(trim($name),0,190);$description=mb_substr(trim($description),0,1000);$baseModel=mb_substr(trim($baseModel),0,150);if($name==='')throw new RuntimeException('Enter a dataset name.');if($providerId){$provider=$this->db->prepare('SELECT default_model FROM ai_providers WHERE id=? LIMIT 1');$provider->execute([$providerId]);$model=$provider->fetchColumn();if($model===false)throw new RuntimeException('Choose an available AI provider.');if($baseModel==='')$baseModel=(string)$model;}
        if($id){$statement=$this->db->prepare("UPDATE ai_training_datasets SET name=?,description=?,provider_id=?,base_model=?,status=IF(status='archived','draft',status),updated_at=NOW() WHERE id=?");$statement->execute([$name,$description,$providerId?:null,$baseModel?:null,$id]);if(!$statement->rowCount()&&!$this->exists('ai_training_datasets',$id))throw new RuntimeException('The selected training dataset was not found.');}else{$this->db->prepare('INSERT INTO ai_training_datasets (name,description,provider_id,base_model,status,created_by,created_at,updated_at) VALUES (?,?,?,? ,"draft",?,NOW(),NOW())')->execute([$name,$description,$providerId?:null,$baseModel?:null,$userId]);$id=(int)$this->db->lastInsertId();}$this->audit($userId,'ai.training.dataset.saved',$id,['provider_id'=>$providerId,'base_model'=>$baseModel]);return$id;
    }

    public function saveTrainingExample(int$datasetId,string$input,string$output,string$instructions,?string$locale,bool$approved,int$userId):int
    {
        $statement=$this->db->prepare('SELECT status FROM ai_training_datasets WHERE id=? LIMIT 1');$statement->execute([$datasetId]);$status=$statement->fetchColumn();if($status===false||!in_array($status,['draft','ready'],true))throw new RuntimeException('Choose an editable training dataset.');$input=$this->normalizeTraining($input,12000);$output=$this->normalizeTraining($output,24000);$instructions=$this->normalizeTraining($instructions,4000);if($input===''||$output==='')throw new RuntimeException('Every training example needs an input and an ideal answer.');$this->db->prepare('INSERT INTO ai_training_examples (dataset_id,input_text,ideal_output,instructions,locale,approved,approved_by,approved_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'.($approved?'NOW()':'NULL').',?,NOW(),NOW())')->execute([$datasetId,$input,$output,$instructions?:null,$this->locale($locale),$approved?1:0,$approved?$userId:null,$userId]);$id=(int)$this->db->lastInsertId();$this->db->prepare('UPDATE ai_training_datasets SET status="draft",updated_at=NOW() WHERE id=?')->execute([$datasetId]);$this->audit($userId,'ai.training.example.saved',$id,['dataset_id'=>$datasetId,'approved'=>$approved]);return$id;
    }

    public function saveEvaluation(string$name,string$question,string$expected,?string$locale,?string$sourceKey,int$userId):int
    {
        $name=mb_substr(trim($name),0,190);$question=$this->normalizeTraining($question,8000);$expected=$this->normalizeTraining($expected,24000);$sourceKey=mb_substr(trim((string)$sourceKey),0,191)?:null;if($name===''||$question===''||$expected==='')throw new RuntimeException('Name, question and expected answer are required for an evaluation case.');$this->db->prepare('INSERT INTO ai_evaluation_cases (name,locale,question,expected_answer,required_source_key,active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,1,?,NOW(),NOW())')->execute([$name,$this->locale($locale),$question,$expected,$sourceKey,$userId]);$id=(int)$this->db->lastInsertId();$this->audit($userId,'ai.evaluation.saved',$id,['locale'=>$this->locale($locale)]);return$id;
    }

    public function trainingAction(string$type,int$id,string$action,int$userId):void
    {
        if($type==='dataset'&&in_array($action,['ready','draft','archive'],true)){if(!$this->exists('ai_training_datasets',$id))throw new RuntimeException('The selected training dataset was not found.');$dataset=$this->db->prepare('SELECT COUNT(*) total,SUM(approved=1) approved FROM ai_training_examples WHERE dataset_id=?');$dataset->execute([$id]);$counts=$dataset->fetch()?:[];if($action==='ready'&&((int)($counts['total']??0)<10||(int)($counts['approved']??0)!==(int)$counts['total']))throw new RuntimeException('A ready dataset needs at least 10 examples and every example must be approved.');$statement=$this->db->prepare('UPDATE ai_training_datasets SET status=?,updated_at=NOW() WHERE id=?');$statement->execute([$action,$id]);$this->audit($userId,'ai.training.dataset.status',$id,['status'=>$action]);return;}
        if($type==='example'&&in_array($action,['approve','unapprove','delete'],true)){if(!$this->exists('ai_training_examples',$id))throw new RuntimeException('The selected training example was not found.');if($action==='delete')$this->db->prepare('DELETE FROM ai_training_examples WHERE id=?')->execute([$id]);else$this->db->prepare('UPDATE ai_training_examples SET approved=?,approved_by=?,approved_at='.($action==='approve'?'NOW()':'NULL').',updated_at=NOW() WHERE id=?')->execute([$action==='approve'?1:0,$action==='approve'?$userId:null,$id]);$this->audit($userId,'ai.training.example.'.$action,$id,[]);return;}
        if($type==='evaluation'&&$action==='delete'){if(!$this->exists('ai_evaluation_cases',$id))throw new RuntimeException('The selected evaluation case was not found.');$this->db->prepare('UPDATE ai_evaluation_cases SET active=0,updated_at=NOW() WHERE id=?')->execute([$id]);$this->audit($userId,'ai.evaluation.deleted',$id,[]);return;}throw new RuntimeException('The requested training action is invalid.');
    }

    public function saveManual(int$id,string$title,string$body,?string$locale,bool$active,int$userId):int
    {
        $title=mb_substr(trim($title),0,255);$body=$this->normalize($body);$locale=$this->locale($locale);if($title===''||$body==='')throw new RuntimeException('Enter both a source title and its knowledge content.');if(mb_strlen($body)>self::MAX_BODY_CHARS)throw new RuntimeException('Knowledge text may contain up to 2,000,000 characters.');
        $contentChecksum=hash('sha256',$body);$this->db->beginTransaction();try{if($id){$existing=$this->document($id);if(!$existing||!in_array($existing['source_type'],['manual','file'],true))throw new RuntimeException('The selected editable source was not found.');$this->db->prepare('UPDATE ai_knowledge_documents SET title=?,body=?,locale=?,status=?,checksum=IF(source_type="manual",?,checksum),content_checksum=?,index_status="pending",index_error=NULL,indexed_at=NULL,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$title,$body,$locale,$active?'published':'draft',$contentChecksum,$contentChecksum,$userId,$id]);}else{$this->db->prepare('INSERT INTO ai_knowledge_documents (title,source_type,body,locale,status,index_status,checksum,content_checksum,created_by,updated_by,created_at,updated_at) VALUES (?,"manual",?,?,?,"pending",?,?,?,?,NOW(),NOW())')->execute([$title,$body,$locale,$active?'published':'draft',$contentChecksum,$contentChecksum,$userId,$userId]);$id=(int)$this->db->lastInsertId();}$this->audit($userId,'ai.knowledge.saved',$id,['type'=>(string)($existing['source_type']??'manual'),'active'=>$active]);$this->db->commit();if($active)$this->index($id);else$this->clearIndex($id);return$id;}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function saveUpload(array$file,string$title,?string$locale,bool$active,int$userId):int
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('Choose a completely uploaded TXT, PDF, RTF, DOC or DOCX file.');$source=(string)$file['tmp_name'];$size=(int)filesize($source);if($size<1||$size>self::MAX_FILE_BYTES)throw new RuntimeException('Knowledge files may be up to 20 MiB.');$original=mb_substr(trim((string)($file['name']??'knowledge-file')),0,255);$extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source)?:'application/octet-stream';$allowed=['txt'=>['text/plain','text/csv','application/octet-stream'],'pdf'=>['application/pdf'],'rtf'=>['application/rtf','text/rtf','text/plain'],'doc'=>['application/msword','application/CDFV2','application/vnd.ms-office','application/octet-stream'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream']];if(!isset($allowed[$extension])||!in_array($mime,$allowed[$extension],true))throw new RuntimeException('The selected file type is not supported or does not match its extension.');$body=$this->extract($source,$extension);if($body==='')throw new RuntimeException('No readable text could be extracted from this file. Scanned PDFs require OCR before upload.');
        $directory=$this->root.'/storage/ai-knowledge';if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new RuntimeException('Private Knowledge Base storage could not be prepared.');$name=bin2hex(random_bytes(20)).'.'.$extension;$target=$directory.'/'.$name;if(!move_uploaded_file($source,$target))throw new RuntimeException('The knowledge file could not be stored.');@chmod($target,0640);$title=mb_substr(trim($title),0,255)?:pathinfo($original,PATHINFO_FILENAME);$checksum=hash_file('sha256',$target)?:hash('sha256',$body);$locale=$this->locale($locale);
        try{$this->db->prepare('INSERT INTO ai_knowledge_documents (title,source_type,body,locale,status,index_status,original_name,mime_type,storage_path,checksum,content_checksum,byte_size,created_by,updated_by,created_at,updated_at) VALUES (?,"file",?,?,?,"pending",?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$title,$body,$locale,$active?'published':'draft',$original,$mime,'storage/ai-knowledge/'.$name,$checksum,hash('sha256',$body),$size,$userId,$userId]);$id=(int)$this->db->lastInsertId();$this->audit($userId,'ai.knowledge.uploaded',$id,['mime_type'=>$mime,'bytes'=>$size,'active'=>$active]);}catch(\Throwable$error){@unlink($target);throw$error;}if($active)$this->index($id);return$id;
    }

    public function action(int$id,string$action,int$userId):void
    {
        $document=$this->document($id);if(!$document)throw new RuntimeException('The selected knowledge source was not found.');if($action==='delete'){$this->db->beginTransaction();try{$this->clearIndex($id);$this->db->prepare('DELETE FROM ai_knowledge_documents WHERE id=?')->execute([$id]);$this->audit($userId,'ai.knowledge.deleted',$id,['type'=>$document['source_type']]);$this->db->commit();$path=$this->privatePath((string)($document['storage_path']??''));if($path&&is_file($path))@unlink($path);return;}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}}
        if(!in_array($action,['publish','draft','archive','reindex'],true))throw new RuntimeException('The requested knowledge action is invalid.');if($action==='reindex'){$this->index($id);$this->audit($userId,'ai.knowledge.reindexed',$id,[]);return;}$status=$action==='publish'?'published':$action;$this->db->prepare('UPDATE ai_knowledge_documents SET status=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$status,$userId,$id]);if($status==='published')$this->index($id);else$this->clearIndex($id);$this->audit($userId,'ai.knowledge.status',$id,['status'=>$status]);
    }

    public function setSiteInclusion(string$type,int$id,bool$enabled,int$userId):void
    {
        if(!in_array($type,['page','post'],true)||$id<1)throw new RuntimeException('The selected website source is invalid.');$table=$type==='page'?'pages':'posts';$statement=$this->db->prepare("UPDATE {$table} SET ai_knowledge_enabled=?,updated_at=NOW() WHERE id=?");$statement->execute([$enabled?1:0,$id]);if(!$statement->rowCount()&&!$this->exists($table,$id))throw new RuntimeException('The selected website source no longer exists.');$this->audit($userId,'ai.knowledge.inclusion',$id,['type'=>$type,'enabled'=>$enabled]);
    }

    public function rebuild(int$userId):array
    {
        $synced=$this->syncWebsite($userId);$ids=$this->db->query("SELECT id FROM ai_knowledge_documents WHERE status='published' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);$ready=0;$failed=0;foreach($ids as$id){try{$this->index((int)$id);$ready++;}catch(\Throwable){$failed++;}}$this->audit($userId,'ai.knowledge.rebuilt',0,['synced'=>$synced,'ready'=>$ready,'failed'=>$failed]);return['synced'=>$synced,'ready'=>$ready,'failed'=>$failed];
    }

    private function syncWebsite(int$userId):int
    {
        $this->db->beginTransaction();try{$this->db->exec("UPDATE ai_knowledge_documents SET status='archived',index_status='pending',indexed_at=NULL WHERE source_type IN ('page','post')");$count=0;$blockText=[];$blocks=$this->db->query("SELECT b.page_id,t.locale,t.data FROM content_blocks b INNER JOIN content_block_translations t ON t.block_id=b.id WHERE b.visible=1 AND b.archived_at IS NULL AND (b.visible_from IS NULL OR b.visible_from<=NOW()) AND (b.visible_until IS NULL OR b.visible_until>NOW()) ORDER BY b.page_id,b.sort_order,b.id")->fetchAll();foreach($blocks as$row){$data=json_decode((string)$row['data'],true);if(is_array($data))$blockText[(int)$row['page_id']][(string)$row['locale']][]=$this->flatten($data);}
            $pages=$this->db->query("SELECT p.id,p.facility_id,p.public_path,t.locale,t.title,t.slug,t.excerpt,t.seo_title,t.seo_description FROM pages p INNER JOIN page_translations t ON t.page_id=p.id WHERE p.ai_knowledge_enabled=1 AND p.status='published' AND p.visibility='public' AND (p.published_at IS NULL OR p.published_at<=NOW())")->fetchAll();foreach($pages as$row){$locale=(string)$row['locale'];$body=$this->normalize(implode("\n\n",array_filter([(string)$row['title'],(string)$row['excerpt'],(string)$row['seo_title'],(string)$row['seo_description'],implode("\n\n",$blockText[(int)$row['id']][$locale]??[])])));if($body==='')continue;$url=(string)($row['public_path']??'')?:'/'.$locale.'/'.$row['slug'];$this->upsertWebsite('page',$row,$body,$url,$userId);$count++;}
            $posts=$this->db->query("SELECT p.id,p.facility_id,t.locale,t.title,t.slug,t.excerpt,t.content,t.seo_title,t.seo_description FROM posts p INNER JOIN post_translations t ON t.post_id=p.id WHERE p.ai_knowledge_enabled=1 AND p.status='published' AND (p.published_at IS NULL OR p.published_at<=NOW())")->fetchAll();foreach($posts as$row){$body=$this->normalize(implode("\n\n",array_filter([(string)$row['title'],(string)$row['excerpt'],(string)$row['content'],(string)$row['seo_title'],(string)$row['seo_description']])));if($body==='')continue;$url='/'.$row['locale'].'/posts/'.$row['slug'];$this->upsertWebsite('post',$row,$body,$url,$userId);$count++;}
            $this->db->exec("DELETE c FROM ai_knowledge_chunks c INNER JOIN ai_knowledge_documents d ON d.id=c.document_id WHERE d.source_type IN ('page','post') AND d.status='archived'");$this->db->commit();return$count;
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    private function upsertWebsite(string$type,array$row,string$body,string$url,int$userId):void
    {
        $key=$type.':'.(int)$row['id'].':'.(string)$row['locale'];$checksum=hash('sha256',$body);$statement=$this->db->prepare('INSERT INTO ai_knowledge_documents (title,source_type,source_key,source_id,facility_id,source_url,body,locale,status,index_status,checksum,content_checksum,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,? ,"published","pending",?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),facility_id=VALUES(facility_id),source_url=VALUES(source_url),body=VALUES(body),locale=VALUES(locale),status="published",index_status=IF(content_checksum=VALUES(content_checksum) AND index_status="ready","ready","pending"),checksum=VALUES(checksum),content_checksum=VALUES(content_checksum),updated_by=VALUES(updated_by),updated_at=NOW()');$statement->execute([(string)$row['title'],$type,$key,(int)$row['id'],(int)$row['facility_id'],$url,$body,(string)$row['locale'],$checksum,$checksum,$userId,$userId]);
    }

    private function index(int$id):void
    {
        $document=$this->document($id);if(!$document)throw new RuntimeException('The knowledge source was not found.');$body=$this->normalize((string)($document['body']??''));if($document['status']!=='published'||$body===''){$this->clearIndex($id);return;}$chunks=$this->chunks($body);$this->db->beginTransaction();try{$this->db->prepare('DELETE FROM ai_knowledge_chunks WHERE document_id=?')->execute([$id]);$insert=$this->db->prepare('INSERT INTO ai_knowledge_chunks (document_id,content,embedding,token_count,sort_order) VALUES (?,?,NULL,?,?)');foreach($chunks as$order=>$chunk)$insert->execute([$id,$chunk,(int)ceil(mb_strlen($chunk)/4),$order]);$this->db->prepare('UPDATE ai_knowledge_documents SET index_status="ready",index_error=NULL,indexed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$id]);$this->db->commit();}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();$this->db->prepare('UPDATE ai_knowledge_documents SET index_status="failed",index_error=?,indexed_at=NULL,updated_at=NOW() WHERE id=?')->execute([mb_substr($error->getMessage(),0,500),$id]);throw$error;}
    }

    private function clearIndex(int$id):void{$this->db->prepare('DELETE FROM ai_knowledge_chunks WHERE document_id=?')->execute([$id]);$this->db->prepare('UPDATE ai_knowledge_documents SET index_status="pending",index_error=NULL,indexed_at=NULL WHERE id=?')->execute([$id]);}
    private function chunks(string$body):array
    {
        $units=[];foreach(preg_split('/\n{2,}/u',$body)?:[$body]as$paragraph){$paragraph=trim($paragraph);if($paragraph==='')continue;foreach(preg_split('/(?<=[.!?])\s+/u',$paragraph)?:[$paragraph]as$sentence){while(mb_strlen($sentence)>1200){$units[]=trim(mb_substr($sentence,0,1200));$sentence=mb_substr($sentence,1200);}if(trim($sentence)!=='')$units[]=trim($sentence);}}
        $chunks=[];$current='';foreach($units as$unit){if($current!==''&&mb_strlen($current)+mb_strlen($unit)+2>1400){$chunks[]=$current;$overlap=mb_substr($current,max(0,mb_strlen($current)-180));$current=trim($overlap."\n\n".$unit);}else$current.=($current!==''?"\n\n":'').$unit;}if($current!=='')$chunks[]=$current;return array_slice($chunks,0,2000);
    }
    private function extract(string$path,string$extension):string{return match($extension){'txt'=>$this->normalize((string)file_get_contents($path)),'rtf'=>$this->extractRtf((string)file_get_contents($path)),'docx'=>$this->extractDocx($path),'pdf'=>$this->extractCommand($path,['pdftotext'],['-layout',$path,'-'],'PDF text extraction is unavailable on this server.'),'doc'=>$this->extractLegacyDoc($path),default=>''};}
    private function extractDocx(string$path):string{if(!class_exists(ZipArchive::class))throw new RuntimeException('DOCX extraction requires the PHP Zip extension.');$zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('The DOCX file could not be opened.');$xml=(string)$zip->getFromName('word/document.xml');$zip->close();if($xml==='')return'';$xml=preg_replace('/<w:(?:tab)\b[^>]*\/>/u',"\t",$xml)??$xml;$xml=preg_replace('/<\/w:(?:p|tr)>/u',"\n",$xml)??$xml;return$this->normalize(strip_tags($xml));}
    private function extractRtf(string$rtf):string{$rtf=preg_replace_callback("/\\\\'([0-9a-fA-F]{2})/",static fn(array$m):string=>chr(hexdec($m[1])),$rtf)??$rtf;$rtf=preg_replace('/\\\\u(-?\d+)\??/u',' ',$rtf)??$rtf;$rtf=preg_replace('/\\\\(?:par|line)\b/u',"\n",$rtf)??$rtf;$rtf=preg_replace('/\\\\[a-zA-Z]+-?\d* ?/u',' ',$rtf)??$rtf;return$this->normalize(str_replace(['{','}'],[' ',' '],$rtf));}
    private function extractLegacyDoc(string$path):string{foreach([['antiword'],['catdoc']]as$binary){$found=$this->binary($binary[0]);if($found)return$this->command([$found,$path]);}$soffice=$this->binary('soffice')?:$this->binary('libreoffice');if(!$soffice)throw new RuntimeException('DOC extraction requires Antiword, Catdoc or LibreOffice on this server.');$tmp=sys_get_temp_dir().'/sensecms-ai-'.bin2hex(random_bytes(8));if(!mkdir($tmp,0700,true)&&!is_dir($tmp))throw new RuntimeException('A private conversion directory could not be created.');try{$this->command([$soffice,'--headless','--convert-to','txt:Text','--outdir',$tmp,$path]);$files=glob($tmp.'/*.txt')?:[];return$files?$this->normalize((string)file_get_contents($files[0])):'';}finally{foreach(glob($tmp.'/*')?:[]as$file)@unlink($file);@rmdir($tmp);}}
    private function extractCommand(string$path,array$binaries,array$args,string$error):string{foreach($binaries as$binary){$found=$this->binary($binary);if($found)return$this->normalize($this->command(array_merge([$found],$args)));}throw new RuntimeException($error);}
    private function command(array$command):string{$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('The local document extractor could not be started.');fclose($pipes[0]);$output=(string)stream_get_contents($pipes[1]);$error=(string)stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);if($status!==0)throw new RuntimeException('The document could not be converted to text.'.($error!==''?' Check that the file is valid.':''));return$output;}
    private function binary(string$name):?string{$extensions=PHP_OS_FAMILY==='Windows'?['','.exe','.bat','.cmd']:[''];foreach(explode(PATH_SEPARATOR,(string)getenv('PATH'))as$directory)foreach($extensions as$extension){$path=rtrim($directory,'/\\').DIRECTORY_SEPARATOR.$name.$extension;if(is_file($path)&&is_readable($path))return$path;}foreach(['/usr/bin/','/usr/local/bin/','/snap/bin/']as$directory){$path=$directory.$name;if(is_file($path)&&is_executable($path))return$path;}return null;}
    private function normalize(string$text):string{$text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');$text=str_replace(["\r\n","\r"],"\n",$text);$text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??$text;$text=preg_replace('/[ \t]+/u',' ',$text)??$text;$text=preg_replace('/ *\n */u',"\n",$text)??$text;$text=preg_replace('/\n{3,}/u',"\n\n",$text)??$text;return trim(mb_substr($text,0,self::MAX_BODY_CHARS));}
    private function flatten(array$data):string{$values=[];$walk=function(mixed$value,?string$key=null)use(&$walk,&$values):void{if(is_array($value)){foreach($value as$childKey=>$child)$walk($child,is_string($childKey)?$childKey:null);return;}if(!is_scalar($value)||in_array($key,['url','image','video','poster','icon','color','id','uid'],true))return;$text=trim((string)$value);if($text!==''&&!preg_match('#^(?:https?://|/assets/|/uploads/)#i',$text))$values[]=$text;};$walk($data);return implode("\n",array_values(array_unique($values)));}
    private function locale(?string$locale):?string{$locale=strtolower(trim((string)$locale));return preg_match('/^[a-z]{2,5}(?:-[a-z0-9]{2,8})?$/',$locale)?$locale:null;}
    private function normalizeTraining(string$text,int$limit):string{$text=str_replace(["\r\n","\r"],"\n",trim($text));$text=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??$text;return trim(mb_substr($text,0,$limit));}
    private function exists(string$table,int$id):bool{$statement=$this->db->prepare("SELECT 1 FROM {$table} WHERE id=? LIMIT 1");$statement->execute([$id]);return(bool)$statement->fetchColumn();}
    private function privatePath(string$relative):?string{if(!str_starts_with($relative,'storage/ai-knowledge/'))return null;$path=$this->root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$relative);$base=realpath($this->root.'/storage/ai-knowledge');$parent=realpath(dirname($path));return$base&&$parent&&$parent===$base?$path:null;}
    private function audit(int$userId,string$event,int$id,array$context):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,? ,"ai_knowledge",?,?,NOW())')->execute([$userId?:null,$event,$id?:null,json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);}
}
