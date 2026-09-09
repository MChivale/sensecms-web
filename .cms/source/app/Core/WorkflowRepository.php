<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class WorkflowRepository
{
    public function __construct(private readonly PDO $db, private readonly AccessControl $access) {}

    public function queue(?array $facilityIds = null, string $state = 'all'): array
    {
        $states = ['all','draft','in_review','changes_requested','approved'];
        if (!in_array($state,$states,true)) $state='all';
        $scope = $this->scopeSql($facilityIds,'x');
        $stateSql = $state === 'all' ? "(x.workflow_state IN ('in_review','changes_requested') OR (x.workflow_state='approved' AND x.status='draft'))" : ($state==='approved'?'x.workflow_state=? AND x.status=\'draft\'':'x.workflow_state=?');
        $parameters=[];
        if($state!=='all')$parameters[]=$state;
        $parameters=array_merge($parameters,$scope['params']);
        $page="SELECT 'page' entity_type,x.id,x.facility_id,x.status,x.workflow_state,x.editorial_note,x.review_requested_at,x.reviewed_at,x.updated_at,COALESCE(t.title,CONCAT('Page #',x.id)) title,COALESCE(cn.name,c.facility_slug) facility_name,COALESCE(o.name,'Unassigned') owner_name,COALESCE(a.name,'Review team') assigned_name FROM pages x LEFT JOIN page_translations t ON t.page_id=x.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) LEFT JOIN facilities c ON c.id=x.facility_id LEFT JOIN facility_translations cn ON cn.facility_id=c.id AND cn.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) LEFT JOIN users o ON o.id=x.owner_user_id LEFT JOIN users a ON a.id=x.assigned_user_id WHERE x.status<>'archived' AND {$stateSql}{$scope['sql']}";
        $post="SELECT 'post' entity_type,x.id,x.facility_id,x.status,x.workflow_state,x.editorial_note,x.review_requested_at,x.reviewed_at,x.updated_at,COALESCE(t.title,CONCAT('Post #',x.id)) title,COALESCE(cn.name,c.facility_slug) facility_name,COALESCE(o.name,'Unassigned') owner_name,COALESCE(a.name,'Review team') assigned_name FROM posts x LEFT JOIN post_translations t ON t.post_id=x.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) LEFT JOIN facilities c ON c.id=x.facility_id LEFT JOIN facility_translations cn ON cn.facility_id=c.id AND cn.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) LEFT JOIN users o ON o.id=x.owner_user_id LEFT JOIN users a ON a.id=x.assigned_user_id WHERE x.status<>'archived' AND {$stateSql}{$scope['sql']}";
        $statement=$this->db->prepare("SELECT * FROM ({$page} UNION ALL {$post}) q ORDER BY FIELD(q.workflow_state,'in_review','changes_requested','approved','draft'),COALESCE(q.review_requested_at,q.updated_at) DESC LIMIT 250");
        $statement->execute(array_merge($parameters,$parameters));return$statement->fetchAll();
    }

    public function counts(?array $facilityIds = null): array
    {
        $counts=['all'=>0,'in_review'=>0,'changes_requested'=>0,'approved'=>0,'draft'=>0];foreach(['in_review','changes_requested','approved','draft']as$state){$counts[$state]=count($this->queue($facilityIds,$state));$counts['all']+=$counts[$state];}return$counts;
    }

    public function history(string $type,int$id):array
    {
        $this->table($type);$statement=$this->db->prepare('SELECT e.*,COALESCE(u.name,"System") actor_name,COALESCE(a.name,"") assigned_name FROM content_workflow_events e LEFT JOIN users u ON u.id=e.actor_user_id LEFT JOIN users a ON a.id=e.assigned_user_id WHERE e.entity_type=? AND e.entity_id=? ORDER BY e.id DESC LIMIT 100');$statement->execute([$type,$id]);return$statement->fetchAll();
    }

    public function transition(string$type,int$id,string$action,int$actorId,?int$assignedUserId,string$note):array
    {
        $table=$this->table($type);$permission=$type==='page'?'content.pages.':'content.posts.';$note=mb_substr(trim($note),0,1000);$allowed=['submit','approve','request-changes','publish','withdraw','assign'];if(!in_array($action,$allowed,true))throw new RuntimeException('Unsupported workflow action.');
        $this->db->beginTransaction();
        try{
            $statement=$this->db->prepare("SELECT id,facility_id,status,workflow_state,owner_user_id,assigned_user_id FROM {$table} WHERE id=? FOR UPDATE");$statement->execute([$id]);$record=$statement->fetch();if(!$record)throw new RuntimeException('The content item was not found.');$facilityId=(int)$record['facility_id'];$from=(string)$record['workflow_state'];$to=$from;$status=(string)$record['status'];$assigned=$assignedUserId?:((int)($record['assigned_user_id']??0)?:null);
            if($status==='archived')throw new RuntimeException('Archived content cannot enter the editorial workflow.');
            if($assigned!==null&&!$this->activeUserInFacility($assigned,$facilityId))throw new RuntimeException('Choose an active user with access to this facility.');
            if($action==='submit'){$this->access->assert($permission.'edit',$facilityId);if(!in_array($from,['draft','changes_requested'],true)||in_array($status,['published','scheduled','private'],true))throw new RuntimeException('Only an unpublished draft can be submitted for review.');$to='in_review';}
            elseif($action==='approve'){$this->access->assert($permission.'review',$facilityId);if($from!=='in_review')throw new RuntimeException('Only content awaiting review can be approved.');$to='approved';}
            elseif($action==='request-changes'){$this->access->assert($permission.'review',$facilityId);if(!in_array($from,['in_review','approved'],true))throw new RuntimeException('This item is not available for editorial review.');if($note==='')throw new RuntimeException('Explain the requested changes.');$to='changes_requested';$status='draft';}
            elseif($action==='publish'){$this->access->assert($permission.'publish',$facilityId);if($from!=='approved')throw new RuntimeException('Approve this content before publishing it.');$to='approved';$status='published';}
            elseif($action==='withdraw'){$this->access->assert($permission.'edit',$facilityId);if($from!=='in_review')throw new RuntimeException('Only a submitted item can be withdrawn.');$to='draft';}
            else{$this->access->assert($permission.'review',$facilityId);if(!$assigned)throw new RuntimeException('Choose a reviewer or assignee.');}
            $reviewRequested=$action==='submit'?'NOW()':'review_requested_at';$reviewed=in_array($action,['approve','request-changes'],true)?'NOW()':'reviewed_at';$reviewedBy=in_array($action,['approve','request-changes'],true)?'?':'reviewed_by';$values=[$assigned,$to,$note?:null,$status];if($reviewedBy==='?')$values[]=$actorId;if($action==='publish')$published='COALESCE(published_at,NOW())';else$published='published_at';$values[]=$id;
            $this->db->prepare("UPDATE {$table} SET assigned_user_id=?,workflow_state=?,editorial_note=?,status=?,review_requested_at={$reviewRequested},reviewed_at={$reviewed},reviewed_by={$reviewedBy},published_at={$published},updated_at=NOW() WHERE id=?")->execute($values);
            $this->db->prepare('INSERT INTO content_workflow_events (entity_type,entity_id,facility_id,actor_user_id,assigned_user_id,from_state,to_state,action,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')->execute([$type,$id,$facilityId,$actorId,$assigned,$from,$to,$action,$note?:null]);
            $this->audit($actorId,'workflow.'.$action,$type,$id,['from'=>$from,'to'=>$to,'facility_id'=>$facilityId,'assigned_user_id'=>$assigned]);
            $title=$this->title($type,$id);$url='/content/'.($type==='page'?'pages':'posts').'/'.$id.'/edit';$recipients=[];
            if($action==='submit')$recipients=$assigned?[$assigned]:$this->recipients($permission.'review',$facilityId);
            elseif(in_array($action,['approve','request-changes','publish'],true))$recipients=array_filter([(int)($record['owner_user_id']??0),$assigned]);
            elseif($action==='assign'&&$assigned)$recipients=[$assigned];
            foreach(array_unique(array_map('intval',$recipients))as$userId)if($userId&&$userId!==$actorId)$this->notify($userId,'workflow','Editorial workflow',$this->message($action,$title),$url);
            $this->db->commit();return['type'=>$type,'id'=>$id,'state'=>$to,'status'=>$status,'history'=>$this->history($type,$id)];
        }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function notifications(int$userId,int$limit=8):array{$limit=max(1,min(25,$limit));$statement=$this->db->prepare('SELECT id,type,title,message,url,created_at FROM user_notifications WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT ?');$statement->bindValue(1,$userId,PDO::PARAM_INT);$statement->bindValue(2,$limit,PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();}
    public function unreadCount(int$userId):int{$statement=$this->db->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND read_at IS NULL');$statement->execute([$userId]);return(int)$statement->fetchColumn();}
    public function markRead(int$userId):void{$this->db->prepare('UPDATE user_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);}

    private function scopeSql(?array$ids,string$alias):array{if($ids===null)return['sql'=>'','params'=>[]];$ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return['sql'=>' AND 1=0','params'=>[]];return['sql'=>' AND '.$alias.'.facility_id IN ('.implode(',',array_fill(0,count($ids),'?')).')','params'=>$ids];}
    private function table(string$type):string{if($type==='page')return'pages';if($type==='post')return'posts';throw new RuntimeException('Unsupported content type.');}
    private function activeUserInFacility(int$userId,int$facilityId):bool{$statement=$this->db->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.active=1 AND (EXISTS(SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id INNER JOIN role_permissions rp ON rp.role_id=r.id INNER JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND r.active=1 AND p.slug='system.owner') OR EXISTS(SELECT 1 FROM user_facilities uc WHERE uc.user_id=u.id AND uc.facility_id=?) OR EXISTS(SELECT 1 FROM live_chat_team_users tu INNER JOIN live_chat_teams tm ON tm.id=tu.team_id AND tm.active=1 INNER JOIN team_facilities tc ON tc.team_id=tu.team_id WHERE tu.user_id=u.id AND tc.facility_id=?))");$statement->execute([$userId,$facilityId,$facilityId]);return(bool)$statement->fetchColumn();}
    private function recipients(string$permission,int$facilityId):array{$statement=$this->db->prepare("SELECT DISTINCT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id AND r.active=1 INNER JOIN role_permissions rp ON rp.role_id=r.id INNER JOIN permissions p ON p.id=rp.permission_id WHERE u.active=1 AND p.slug IN (?, 'system.owner') AND (p.slug='system.owner' OR EXISTS(SELECT 1 FROM user_facilities uc WHERE uc.user_id=u.id AND uc.facility_id=?) OR EXISTS(SELECT 1 FROM live_chat_team_users tu INNER JOIN live_chat_teams tm ON tm.id=tu.team_id AND tm.active=1 INNER JOIN team_facilities tc ON tc.team_id=tu.team_id WHERE tu.user_id=u.id AND tc.facility_id=?))");$statement->execute([$permission,$facilityId,$facilityId]);return array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));}
    private function title(string$type,int$id):string{$translation=$type==='page'?'page_translations':'post_translations';$owner=$type.'_id';$statement=$this->db->prepare("SELECT title FROM {$translation} WHERE {$owner}=? ORDER BY locale LIMIT 1");$statement->execute([$id]);return(string)($statement->fetchColumn()?:ucfirst($type).' #'.$id);}
    private function message(string$action,string$title):string{return match($action){'submit'=>$title.' is ready for review.','approve'=>$title.' was approved.','request-changes'=>'Changes were requested for '.$title.'.','publish'=>$title.' was published.','assign'=>$title.' was assigned to you.',default=>'Workflow state changed for '.$title.'.'};}
    private function notify(int$userId,string$type,string$title,string$message,string$url):void{$this->db->prepare('INSERT INTO user_notifications (user_id,type,title,message,url,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId,$type,$title,mb_substr($message,0,500),$url]);}
    private function audit(int$userId,string$event,string$type,int$id,array$context):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId?:null,$event,$type,$id,json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
}
