<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class AiRepository
{
    public function __construct(private readonly PDO $db) {}

    public function providers(): array
    {
        $rows=$this->db->query('SELECT id,slug,name,driver,base_url,default_model,options,enabled,priority,verified_at,last_error,updated_at,CASE WHEN api_key_encrypted IS NULL OR api_key_encrypted="" THEN 0 ELSE 1 END configured FROM ai_providers ORDER BY priority,name,id')->fetchAll();
        foreach($rows as&$row){$row['options']=$this->decodeOptions($row['options']??null);$row['usage']=$this->usageSummary($row);}unset($row);
        return$rows;
    }
    public function provider(string $slug): ?array { $statement = $this->db->prepare('SELECT * FROM ai_providers WHERE slug = ? AND enabled = 1 LIMIT 1'); $statement->execute([$slug]);$row=$statement->fetch()?:null;if($row)$row['options']=$this->decodeOptions($row['options']??null);return$row; }
    public function providerById(int$id,bool$enabled=false):?array{$statement=$this->db->prepare('SELECT * FROM ai_providers WHERE id=?'.($enabled?' AND enabled=1':'').' LIMIT 1');$statement->execute([$id]);$row=$statement->fetch()?:null;if($row)$row['options']=$this->decodeOptions($row['options']??null);return$row;}
    public function enabledProviders(string$purpose='builder'):array
    {
        $rows=$this->db->query('SELECT * FROM ai_providers WHERE enabled=1 AND api_key_encrypted IS NOT NULL AND api_key_encrypted<>"" ORDER BY priority,name,id')->fetchAll();$result=[];
        foreach($rows as$row){$row['options']=$this->decodeOptions($row['options']??null);$purposes=array_values(array_filter((array)($row['options']['purposes']??[]),'is_string'));if(!$purposes||in_array($purpose,$purposes,true))$result[]=$row;}
        return$result;
    }
    public function preferredProvider(string$purpose='chat'):?array{return$this->enabledProviders($purpose)[0]??null;}
    public function saveProvider(array$data):int
    {
        $id=max(0,(int)($data['id']??0));$options=json_encode((array)($data['options']??[]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if($id){$sql='UPDATE ai_providers SET slug=?,name=?,driver=?,base_url=?,default_model=?,options=?,enabled=?,priority=?,verified_at=NULL,last_error=NULL,updated_at=NOW()';$params=[$data['slug'],$data['name'],$data['driver'],$data['base_url'],$data['default_model'],$options,!empty($data['enabled'])?1:0,(int)$data['priority']];if(array_key_exists('api_key_encrypted',$data)){$sql.=',api_key_encrypted=?';$params[]=$data['api_key_encrypted'];}$sql.=' WHERE id=?';$params[]=$id;$statement=$this->db->prepare($sql);$statement->execute($params);if(!$statement->rowCount()&&!$this->providerById($id))throw new \RuntimeException('The selected AI provider no longer exists.');return$id;}
        $statement=$this->db->prepare('INSERT INTO ai_providers (slug,name,driver,base_url,default_model,api_key_encrypted,options,enabled,priority,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');$statement->execute([$data['slug'],$data['name'],$data['driver'],$data['base_url'],$data['default_model'],$data['api_key_encrypted']??null,$options,!empty($data['enabled'])?1:0,(int)$data['priority']]);return(int)$this->db->lastInsertId();
    }
    public function providerVerification(int$id,bool$ok,?string$error=null):void{$this->db->prepare('UPDATE ai_providers SET verified_at='.($ok?'NOW()':'NULL').',last_error=?,updated_at=NOW() WHERE id=?')->execute([$ok?null:mb_substr(trim((string)$error),0,500),$id]);}
    public function auditProvider(int$userId,int$id,string$event,array$context=[]):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId?:null,$event,'ai_provider',$id,json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);}
    public function dashboard(): array
    {
        $usage=$this->db->query("SELECT COUNT(*) requests,COALESCE(SUM(COALESCE(input_tokens,0)+COALESCE(output_tokens,0)),0) tokens,COALESCE(SUM(COALESCE(actual_cost_usd,0)),0) cost FROM ai_usage_events WHERE status='completed' AND created_at>=DATE_FORMAT(NOW(),'%Y-%m-01 00:00:00')")->fetch();
        return['providers'=>(int)$this->db->query('SELECT COUNT(*) FROM ai_providers WHERE enabled=1')->fetchColumn(),'knowledge'=>(int)$this->db->query("SELECT COUNT(*) FROM ai_knowledge_documents WHERE status='published'")->fetchColumn(),'open_chats'=>(int)$this->db->query("SELECT COUNT(*) FROM ai_conversations WHERE status IN ('open','queued','assigned')")->fetchColumn(),'AI requests this month'=>(int)($usage['requests']??0),'AI tokens this month'=>(int)($usage['tokens']??0),'estimated cost this month'=>'$'.number_format((float)($usage['cost']??0),2)];
    }

    public function beginBuilderRun(int$pageId,int$facilityId,int$userId,array$provider,string$action,string$scope,string$locale,int$inputChars):int
    {
        $limit=$this->db->prepare('SELECT COUNT(*) FROM page_builder_ai_runs WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 10 MINUTE)');$limit->execute([$userId]);if((int)$limit->fetchColumn()>=20)throw new \RuntimeException('AI request limit reached. Wait a few minutes and try again.',429);
        $statement=$this->db->prepare('INSERT INTO page_builder_ai_runs (page_id,facility_id,user_id,provider_slug,model,action,scope,locale,status,input_chars,created_at) VALUES (?,?,?,?,?,?,?,?,"requested",?,NOW())');$statement->execute([$pageId,$facilityId,$userId,$provider['slug'],$provider['default_model'],$action,$scope,$locale,max(0,$inputChars)]);return(int)$this->db->lastInsertId();
    }
    public function finishBuilderRun(int$id,bool$ok,int$outputChars=0,?array$usage=null,?string$error=null):void{$this->db->prepare('UPDATE page_builder_ai_runs SET status=?,output_chars=?,input_tokens=?,output_tokens=?,error_code=?,completed_at=NOW() WHERE id=?')->execute([$ok?'completed':'failed',max(0,$outputChars),isset($usage['input'])?max(0,(int)$usage['input']):null,isset($usage['output'])?max(0,(int)$usage['output']):null,$ok?null:mb_substr((string)$error,0,80),$id]);}

    public function beginUsage(array$provider,string$purpose,?int$userId,int$inputChars,int$maxOutputTokens):int
    {
        if(!in_array($purpose,['builder','posts','chat','verification'],true))throw new \RuntimeException('The AI usage purpose is invalid.');if(in_array($purpose,['builder','posts'],true)&&$userId){$rate=$this->db->prepare("SELECT COUNT(*) FROM ai_usage_events WHERE user_id=? AND purpose IN ('builder','posts') AND created_at>DATE_SUB(NOW(),INTERVAL 10 MINUTE)");$rate->execute([$userId]);if((int)$rate->fetchColumn()>=20)throw new \RuntimeException('AI request limit reached. Wait a few minutes and try again.',429);}$limits=$this->usageLimits($provider);$input=(int)ceil(max(0,$inputChars)/4);$output=max(0,$maxOutputTokens);$estimated=$this->cost($input,$output,$limits);
        $this->db->beginTransaction();try{$providerId=(int)$provider['id'];$lock=$this->db->prepare('SELECT id FROM ai_providers WHERE id=? FOR UPDATE');$lock->execute([$providerId]);if(!$lock->fetchColumn())throw new \RuntimeException('The selected AI provider no longer exists.');$daily=$this->usageWindow($providerId,'CURDATE()');$monthly=$this->usageWindow($providerId,"DATE_FORMAT(NOW(),'%Y-%m-01 00:00:00')");$this->assertBudget('Daily AI request',(float)$daily['requests'],1,(float)$limits['daily_requests']);$this->assertBudget('Monthly AI request',(float)$monthly['requests'],1,(float)$limits['monthly_requests']);$this->assertBudget('Daily AI token',(float)$daily['tokens'],$input+$output,(float)$limits['daily_tokens']);$this->assertBudget('Monthly AI token',(float)$monthly['tokens'],$input+$output,(float)$limits['monthly_tokens']);$this->assertBudget('Daily AI cost',(float)$daily['cost'],$estimated,(float)$limits['daily_cost_usd']);$this->assertBudget('Monthly AI cost',(float)$monthly['cost'],$estimated,(float)$limits['monthly_cost_usd']);$statement=$this->db->prepare("INSERT INTO ai_usage_events (provider_id,provider_slug,model,purpose,user_id,status,reserved_input_tokens,reserved_output_tokens,input_cost_per_million,output_cost_per_million,estimated_cost_usd,created_at) VALUES (?,?,?,?,?,'requested',?,?,?,?,?,NOW())");$statement->execute([$providerId,$provider['slug'],$provider['default_model'],$purpose,$userId?:null,$input,$output,$limits['input_cost_per_million'],$limits['output_cost_per_million'],$estimated]);$id=(int)$this->db->lastInsertId();$this->db->commit();return$id;}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function finishUsage(int$id,bool$ok,?array$usage=null,?string$error=null):void
    {
        $input=$ok?max(0,(int)($usage['input']??0)):null;$output=$ok?max(0,(int)($usage['output']??0)):null;$row=$this->db->prepare('SELECT reserved_input_tokens,reserved_output_tokens,input_cost_per_million,output_cost_per_million FROM ai_usage_events WHERE id=? LIMIT 1');$row->execute([$id]);$pricing=$row->fetch()?:['reserved_input_tokens'=>0,'reserved_output_tokens'=>0,'input_cost_per_million'=>0,'output_cost_per_million'=>0];if($ok&&$input+$output===0){$input=(int)$pricing['reserved_input_tokens'];$output=(int)$pricing['reserved_output_tokens'];}$cost=$ok?(($input*(float)$pricing['input_cost_per_million']+$output*(float)$pricing['output_cost_per_million'])/1000000):null;$this->db->prepare("UPDATE ai_usage_events SET status=?,input_tokens=?,output_tokens=?,actual_cost_usd=?,error_code=?,completed_at=NOW() WHERE id=? AND status='requested'")->execute([$ok?'completed':'failed',$input,$output,$cost,$ok?null:mb_substr((string)$error,0,80),$id]);
    }

    public function usageSummary(array$provider):array
    {
        $limits=$this->usageLimits($provider);$daily=$this->usageWindow((int)$provider['id'],'CURDATE()');$monthly=$this->usageWindow((int)$provider['id'],"DATE_FORMAT(NOW(),'%Y-%m-01 00:00:00')");$ratios=[];foreach([['daily_requests',$daily['requests']],['monthly_requests',$monthly['requests']],['daily_tokens',$daily['tokens']],['monthly_tokens',$monthly['tokens']],['daily_cost_usd',$daily['cost']],['monthly_cost_usd',$monthly['cost']]]as[$key,$used])if((float)$limits[$key]>0)$ratios[$key]=min(100,round((float)$used/(float)$limits[$key]*100,1));return['today'=>$daily,'month'=>$monthly,'limits'=>$limits,'ratios'=>$ratios,'warning'=>$ratios&&max($ratios)>=(float)$limits['warning_percent']];
    }

    private function usageWindow(int$providerId,string$since):array
    {
        $sql="SELECT COUNT(*) requests,COALESCE(SUM(CASE WHEN status='requested' THEN reserved_input_tokens+reserved_output_tokens WHEN status='completed' THEN COALESCE(input_tokens,0)+COALESCE(output_tokens,0) ELSE 0 END),0) tokens,COALESCE(SUM(CASE WHEN status='requested' THEN estimated_cost_usd WHEN status='completed' THEN COALESCE(actual_cost_usd,0) ELSE 0 END),0) cost FROM ai_usage_events WHERE provider_id=? AND created_at>={$since}";$statement=$this->db->prepare($sql);$statement->execute([$providerId]);$row=$statement->fetch()?:[];return['requests'=>(int)($row['requests']??0),'tokens'=>(int)($row['tokens']??0),'cost'=>(float)($row['cost']??0)];
    }
    private function usageLimits(array$provider):array{$limits=(array)($provider['options']['limits']??[]);return['daily_requests'=>max(0,(int)($limits['daily_requests']??0)),'monthly_requests'=>max(0,(int)($limits['monthly_requests']??0)),'daily_tokens'=>max(0,(int)($limits['daily_tokens']??0)),'monthly_tokens'=>max(0,(int)($limits['monthly_tokens']??0)),'daily_cost_usd'=>max(0,(float)($limits['daily_cost_usd']??0)),'monthly_cost_usd'=>max(0,(float)($limits['monthly_cost_usd']??0)),'input_cost_per_million'=>max(0,(float)($limits['input_cost_per_million']??0)),'output_cost_per_million'=>max(0,(float)($limits['output_cost_per_million']??0)),'warning_percent'=>max(50,min(100,(int)($limits['warning_percent']??80)))];}
    private function cost(int$input,int$output,array$limits):float{return($input*(float)$limits['input_cost_per_million']+$output*(float)$limits['output_cost_per_million'])/1000000;}
    private function assertBudget(string$label,float$used,float$reserved,float$limit):void{if($limit>0&&$used+$reserved>$limit)throw new \RuntimeException($label.' budget reached for this provider. Increase the limit or wait for its reset.',429);}

    public function contextMatches(string $message, string $locale): array
    {
        $terms = array_values(array_unique(array_slice(array_filter(preg_split('/[^[:alnum:]]+/u', mb_strtolower($message)) ?: [], static fn(string $term): bool => mb_strlen($term) > 2), 0, 10)));
        if (!$terms) return [];
        $score=implode('+',array_fill(0,count($terms),'CASE WHEN LOWER(c.content) LIKE ? THEN 1 ELSE 0 END'));$where=implode(' OR ',array_fill(0,count($terms),'LOWER(c.content) LIKE ?'));$likes=array_map(static fn(string$term):string=>'%'.$term.'%',$terms);
        $statement=$this->db->prepare("SELECT c.content,d.title,d.source_url,({$score}) relevance FROM ai_knowledge_chunks c INNER JOIN ai_knowledge_documents d ON d.id=c.document_id WHERE d.status='published' AND d.index_status='ready' AND (d.locale=? OR d.locale IS NULL) AND ({$where}) ORDER BY relevance DESC,d.updated_at DESC,c.sort_order LIMIT 8");
        $statement->execute(array_merge($likes,[$locale],$likes));return array_map(static fn(array$row):array=>['title'=>trim((string)$row['title']),'url'=>trim((string)($row['source_url']??'')),'content'=>(string)$row['content']],$statement->fetchAll());
    }

    public function context(string $message,string$locale):array{return array_map(static function(array$row):string{$source=$row['title'];if($row['url']!=='')$source.=' · '.$row['url'];return'[Source: '.$source."]\n".$row['content'];},$this->contextMatches($message,$locale));}
    public function recentConversationMessages(string$id,int$limit=6):array{$limit=max(1,min(12,$limit));$statement=$this->db->prepare("SELECT role,content FROM ai_messages WHERE conversation_id=? AND role IN ('visitor','assistant','agent') ORDER BY id DESC LIMIT {$limit}");$statement->execute([$id]);return array_reverse($statement->fetchAll());}
    public function usageCount(string$purpose,string$period='day'):int{$since=$period==='month'?"DATE_FORMAT(NOW(),'%Y-%m-01 00:00:00')":'CURDATE()';$statement=$this->db->prepare("SELECT COUNT(*) FROM ai_usage_events WHERE purpose=? AND status IN ('requested','completed') AND created_at>={$since}");$statement->execute([$purpose]);return(int)$statement->fetchColumn();}

    public function conversation(string $id, string $locale, ?string $ip = null): void
    {
        $ip = IpCountry::ip($ip);
        $statement = $this->db->prepare('INSERT IGNORE INTO ai_conversations (id,locale,visitor_ip,visitor_country,channel,status,created_at,updated_at) VALUES (?, ?, ?, ?, "ai", "open", NOW(), NOW())');
        $statement->execute([$id, $locale, $ip, IpCountry::lookup($ip)]);
    }
    public function setVisitorName(string $conversation, string $name): void { $this->db->prepare('UPDATE ai_conversations SET visitor_name = ? WHERE id = ? AND (visitor_name IS NULL OR visitor_name = "")')->execute([$name, $conversation]); }
    public function setVisitorEmail(string $conversation, string $email): void { $this->db->prepare('UPDATE ai_conversations SET visitor_email = ? WHERE id = ? AND (visitor_email IS NULL OR visitor_email = "")')->execute([$email, $conversation]); }
    public function requestVisitorEmail(string $conversation): bool { $statement=$this->db->prepare('UPDATE ai_conversations SET email_requested_at=COALESCE(email_requested_at,NOW()),updated_at=NOW() WHERE id=? AND (visitor_email IS NULL OR visitor_email="")');$statement->execute([$conversation]);return $statement->rowCount()===1; }
    public function message(string $conversation, string $role, string $content, ?string $provider = null): void { $statement = $this->db->prepare('INSERT INTO ai_messages (conversation_id,role,content,provider_slug,created_at) VALUES (?,?,?,?,NOW())'); $statement->execute([$conversation, $role, $content, $provider]); $this->db->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversation]); }

    public function queueForHuman(string $conversation, ?int $takeoverSeconds = null): bool
    {
        $takeoverAt = $takeoverSeconds === null ? null : (new \DateTimeImmutable())->modify('+' . max(0, min(300, $takeoverSeconds)) . ' seconds')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare("UPDATE ai_conversations SET channel = 'human', status = 'queued', assigned_user_id = NULL, assigned_team_id = NULL, queued_at = NOW(), ai_takeover_at = ?, updated_at = NOW() WHERE id = ? AND status = 'open'");
        $statement->execute([$takeoverAt, $conversation]);
        return $statement->rowCount() === 1;
    }

    public function touchOperatorPresence(int $userId): void
    {
        if ($userId < 1) return;
        $this->db->prepare('INSERT INTO live_chat_operator_presence (user_id,last_seen_at) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_seen_at=NOW()')->execute([$userId]);
    }

    public function onlineOperatorCount(int $withinSeconds = 20): int
    {
        $threshold = (new \DateTimeImmutable())->modify('-' . max(5, min(120, $withinSeconds)) . ' seconds')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare('SELECT COUNT(*) FROM live_chat_operator_presence p INNER JOIN users u ON u.id=p.user_id AND u.active=1 WHERE p.last_seen_at>=?');
        $statement->execute([$threshold]);
        return (int) $statement->fetchColumn();
    }

    public function claimAiTakeover(string $conversation): ?array
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare("UPDATE ai_conversations SET channel='ai',status='open',assigned_user_id=NULL,assigned_team_id=NULL,queued_at=NULL,ai_takeover_at=NULL,updated_at=NOW() WHERE id=? AND status='queued' AND assigned_user_id IS NULL AND ai_takeover_at IS NOT NULL AND ai_takeover_at<=NOW()");
            $statement->execute([$conversation]);
            if ($statement->rowCount() !== 1) { $this->db->rollBack(); return null; }
            $latest = $this->db->prepare("SELECT c.locale,m.content FROM ai_conversations c INNER JOIN ai_messages m ON m.conversation_id=c.id AND m.role='visitor' WHERE c.id=? ORDER BY m.id DESC LIMIT 1");
            $latest->execute([$conversation]);
            $row = $latest->fetch();
            $this->db->commit();
            return $row ?: null;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function conversationStatus(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT channel, status, assigned_user_id, assigned_team_id, visitor_email, queued_at, ai_takeover_at, email_requested_at FROM ai_conversations WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function conversations(int $userId): array
    {
        $sql = "SELECT c.*, u.name AS agent_name, t.name AS team_name, t.color AS team_color,
                    (SELECT content FROM ai_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM ai_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                    (SELECT COUNT(*) FROM ai_messages m WHERE m.conversation_id = c.id AND m.role = 'visitor' AND m.id > COALESCE((SELECT r.last_read_message_id FROM live_chat_reads r WHERE r.conversation_id = c.id AND r.user_id = ?), 0)) AS unread_count
                FROM ai_conversations c
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id
                WHERE c.status <> 'closed' AND " . $this->accessSql('c') . "
                ORDER BY FIELD(c.status, 'queued', 'assigned', 'open'), unread_count DESC, c.updated_at DESC LIMIT 100";
        $statement = $this->db->prepare($sql);
        $statement->execute([$userId, $userId, $userId]);
        return $statement->fetchAll();
    }

    public function conversationWithMessages(string $id, ?int $userId = null): ?array
    {
        $sql = 'SELECT c.*, u.name AS agent_name, u.avatar_url AS agent_profile_avatar, t.name AS team_name, t.color AS team_color FROM ai_conversations c LEFT JOIN users u ON u.id = c.assigned_user_id LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id WHERE c.id = ?';
        $parameters = [$id];
        if ($userId !== null) { $sql .= ' AND ' . $this->accessSql('c'); array_push($parameters, $userId, $userId); }
        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);
        $conversation = $statement->fetch();
        if (!$conversation) return null;
        if ($userId === null) unset($conversation['visitor_ip'], $conversation['visitor_country'], $conversation['visitor_email']);
        $messages = $this->db->prepare("SELECT m.id, m.role, m.content, m.created_at, COALESCE(mu.name, IF(m.role = 'agent', u.name, NULL)) AS agent_name FROM ai_messages m LEFT JOIN users mu ON mu.id = m.user_id LEFT JOIN users u ON u.id = ? WHERE m.conversation_id = ? ORDER BY m.id");
        $messages->execute([$conversation['assigned_user_id'], $id]);
        $conversation['messages'] = $messages->fetchAll();
        return $conversation;
    }

    public function queuedConversations(int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, c.locale, c.visitor_name, c.visitor_email, c.visitor_ip, c.visitor_country, c.assigned_team_id, c.assigned_user_id, c.created_at, c.updated_at, t.name AS team_name, (SELECT m.content FROM ai_messages m WHERE m.conversation_id = c.id AND m.role = 'visitor' ORDER BY m.id DESC LIMIT 1) AS preview FROM ai_conversations c LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id WHERE c.status = 'queued' AND " . $this->accessSql('c') . ' ORDER BY c.updated_at ASC LIMIT 20');
        $statement->execute([$userId, $userId]);
        return $statement->fetchAll();
    }

    public function recentAssignments(int $since, int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, c.visitor_name, c.assigned_user_id, c.assigned_team_id, c.updated_at, u.name AS agent_name FROM ai_conversations c INNER JOIN users u ON u.id = c.assigned_user_id WHERE c.status = 'assigned' AND c.updated_at >= FROM_UNIXTIME(?) AND (c.assigned_user_id = ? OR c.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users tu WHERE tu.team_id = c.assigned_team_id AND tu.user_id = ?)) ORDER BY c.updated_at ASC LIMIT 100");
        $statement->execute([max(0, $since), $userId, $userId]);
        return $statement->fetchAll();
    }

    public function recentVisitorMessages(int $since, int $userId): array
    {
        $statement = $this->db->prepare("SELECT m.id, m.conversation_id, m.content, m.created_at, c.visitor_name FROM ai_messages m INNER JOIN ai_conversations c ON c.id = m.conversation_id WHERE m.role = 'visitor' AND c.status IN ('queued','assigned') AND m.created_at >= FROM_UNIXTIME(?) AND " . $this->accessSql('c') . ' ORDER BY m.id ASC LIMIT 100');
        $statement->execute([max(0, $since), $userId, $userId]);
        return $statement->fetchAll();
    }

    public function claimConversation(string $conversation, int $userId): bool
    {
        $statement = $this->db->prepare("UPDATE ai_conversations c SET c.channel = 'human', c.status = 'assigned', c.assigned_user_id = ?, c.queued_at=NULL, c.ai_takeover_at=NULL, c.updated_at = NOW() WHERE c.id = ? AND c.status = 'queued' AND (c.assigned_user_id = ? OR (c.assigned_user_id IS NULL AND (c.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users tu WHERE tu.team_id = c.assigned_team_id AND tu.user_id = ?))))");
        $statement->execute([$userId, $conversation, $userId, $userId]);
        return $statement->rowCount() === 1;
    }

    public function agentReply(string $conversation, int $userId, string $content): bool
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT c.id FROM ai_conversations c WHERE c.id = ? AND c.status <> 'closed' AND " . $this->accessSql('c') . ' FOR UPDATE');
            $lock->execute([$conversation, $userId, $userId]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return false; }
            $this->db->prepare("UPDATE ai_conversations SET channel = 'human', status = 'assigned', assigned_user_id = COALESCE(assigned_user_id, ?), queued_at=NULL, ai_takeover_at=NULL, updated_at = NOW() WHERE id = ?")->execute([$userId, $conversation]);
            $message = $this->db->prepare("INSERT INTO ai_messages (conversation_id, role, content, user_id, created_at) VALUES (?, 'agent', ?, ?, NOW())");
            $message->execute([$conversation, $content, $userId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function markRead(string $conversation, int $userId): bool
    {
        if (!$this->canAccessConversation($conversation, $userId)) return false;
        $maximum = $this->db->prepare("SELECT COALESCE(MAX(id), 0) FROM ai_messages WHERE conversation_id = ? AND role = 'visitor'");
        $maximum->execute([$conversation]);
        $messageId = (int) $maximum->fetchColumn();
        $statement = $this->db->prepare('INSERT INTO live_chat_reads (conversation_id,user_id,last_read_message_id,read_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),read_at=NOW()');
        $statement->execute([$conversation, $userId, $messageId]);
        return true;
    }

    public function unreadCounts(int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, COUNT(m.id) AS unread_count FROM ai_conversations c JOIN ai_messages m ON m.conversation_id = c.id AND m.role = 'visitor' LEFT JOIN live_chat_reads r ON r.conversation_id = c.id AND r.user_id = ? WHERE c.status <> 'closed' AND m.id > COALESCE(r.last_read_message_id, 0) AND " . $this->accessSql('c') . ' GROUP BY c.id');
        $statement->execute([$userId, $userId, $userId]);
        return array_map('intval', array_column($statement->fetchAll(), 'unread_count', 'id'));
    }

    public function unreadTotal(int $userId): int { return array_sum($this->unreadCounts($userId)); }

    public function users(): array { return $this->db->query('SELECT id, name, email, avatar_url FROM users WHERE active = 1 ORDER BY name, id')->fetchAll(); }

    public function teams(bool $includeInactive = true): array
    {
        $available = " WHERE t.active = 1 AND EXISTS (SELECT 1 FROM live_chat_team_users available_team INNER JOIN users available_user ON available_user.id = available_team.user_id AND available_user.active = 1 WHERE available_team.team_id = t.id)";
        $sql = 'SELECT t.*, COUNT(tu.user_id) AS member_count FROM live_chat_teams t LEFT JOIN live_chat_team_users tu ON tu.team_id = t.id' . ($includeInactive ? '' : $available) . ' GROUP BY t.id ORDER BY t.active DESC, t.name, t.id';
        $teams = $this->db->query($sql)->fetchAll();
        $members = $this->db->query('SELECT team_id, user_id FROM live_chat_team_users ORDER BY team_id, user_id')->fetchAll();
        $byTeam = [];
        foreach ($members as $member) $byTeam[(int) $member['team_id']][] = (int) $member['user_id'];
        foreach ($teams as &$team) $team['members'] = $byTeam[(int) $team['id']] ?? [];
        unset($team);
        return $teams;
    }

    public function syncTeams(array $teams): void
    {
        $validUsers = array_fill_keys(array_map('intval', array_column($this->users(), 'id')), true);
        $this->db->beginTransaction();
        try {
            $submitted = [];
            foreach ($teams as $team) {
                $slug = (string) $team['slug'];
                $submitted[] = $slug;
                $members = array_values(array_unique(array_map('intval', $team['members'])));
                foreach ($members as $userId) if (!isset($validUsers[$userId])) throw new \RuntimeException('A selected team member is no longer an active operator.');
                if ($team['active'] && !$members) throw new \RuntimeException('Every active team must have at least one active operator.');
                $statement = $this->db->prepare('INSERT INTO live_chat_teams (name,slug,description,color,active,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),color=VALUES(color),active=VALUES(active),updated_at=NOW()');
                $statement->execute([$team['name'], $slug, $team['description'], $team['color'], $team['active'] ? 1 : 0]);
                $lookup = $this->db->prepare('SELECT id FROM live_chat_teams WHERE slug = ? LIMIT 1');
                $lookup->execute([$slug]);
                $teamId = (int) $lookup->fetchColumn();
                $this->db->prepare('DELETE FROM live_chat_team_users WHERE team_id = ?')->execute([$teamId]);
                $membership = $this->db->prepare('INSERT INTO live_chat_team_users (team_id,user_id,created_at) VALUES (?,?,NOW())');
                foreach ($members as $userId) $membership->execute([$teamId, $userId]);
            }
            if ($submitted) {
                $placeholders = implode(',', array_fill(0, count($submitted), '?'));
                $this->db->prepare("UPDATE live_chat_teams SET active = 0, updated_at = NOW() WHERE slug NOT IN ({$placeholders})")->execute($submitted);
            } else {
                $this->db->exec('UPDATE live_chat_teams SET active = 0, updated_at = NOW()');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function transferConversation(string $conversation, int $fromUserId, ?int $toUserId, ?int $toTeamId, string $note): ?array
    {
        if (($toUserId === null) === ($toTeamId === null)) return null;
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM ai_conversations c WHERE c.id = ? AND c.status <> \'closed\' AND ' . $this->accessSql('c') . ' FOR UPDATE');
            $lock->execute([$conversation, $fromUserId, $fromUserId]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return null; }
            if ($toUserId !== null) {
                $target = $this->db->prepare('SELECT id, name FROM users WHERE id = ? AND active = 1 LIMIT 1');
                $target->execute([$toUserId]);
                $row = $target->fetch();
                if (!$row) { $this->db->rollBack(); return null; }
                $this->db->prepare("UPDATE ai_conversations SET channel='human',status='queued',assigned_user_id=?,assigned_team_id=NULL,queued_at=NOW(),ai_takeover_at=NULL,updated_at=NOW() WHERE id=?")->execute([$toUserId, $conversation]);
                $label = (string) $row['name'];
            } else {
                $target = $this->db->prepare('SELECT t.id, t.name FROM live_chat_teams t WHERE t.id = ? AND t.active = 1 AND EXISTS (SELECT 1 FROM live_chat_team_users tu INNER JOIN users u ON u.id = tu.user_id AND u.active = 1 WHERE tu.team_id = t.id) LIMIT 1');
                $target->execute([$toTeamId]);
                $row = $target->fetch();
                if (!$row) { $this->db->rollBack(); return null; }
                $this->db->prepare("UPDATE ai_conversations SET channel='human',status='queued',assigned_user_id=NULL,assigned_team_id=?,queued_at=NOW(),ai_takeover_at=NULL,updated_at=NOW() WHERE id=?")->execute([$toTeamId, $conversation]);
                $label = (string) $row['name'];
            }
            $this->db->prepare('INSERT INTO live_chat_transfers (conversation_id,from_user_id,to_user_id,to_team_id,note,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$conversation, $fromUserId, $toUserId, $toTeamId, $note !== '' ? $note : null]);
            $this->db->commit();
            return ['label' => $label, 'type' => $toUserId !== null ? 'user' : 'team'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function transferToAi(string $conversation, int $fromUserId, string $note): bool
    {
        $this->db->beginTransaction();
        try {
            $lock=$this->db->prepare('SELECT id FROM ai_conversations c WHERE c.id=? AND c.status<>\'closed\' AND '.$this->accessSql('c').' FOR UPDATE');
            $lock->execute([$conversation,$fromUserId,$fromUserId]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return false; }
            $this->db->prepare("UPDATE ai_conversations SET channel='ai',status='open',assigned_user_id=NULL,assigned_team_id=NULL,queued_at=NULL,ai_takeover_at=NULL,updated_at=NOW() WHERE id=?")->execute([$conversation]);
            $this->db->prepare('INSERT INTO live_chat_transfers (conversation_id,from_user_id,to_user_id,to_team_id,note,created_at) VALUES (?,?,NULL,NULL,?,NOW())')->execute([$conversation,$fromUserId,$note!==''?$note:'Transferred to AI assistant']);
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $exception; }
    }

    public function transferHistory(string $conversation): array
    {
        $statement = $this->db->prepare('SELECT tr.*, source.name AS from_name, target.name AS to_user_name, team.name AS to_team_name FROM live_chat_transfers tr LEFT JOIN users source ON source.id=tr.from_user_id LEFT JOIN users target ON target.id=tr.to_user_id LEFT JOIN live_chat_teams team ON team.id=tr.to_team_id WHERE tr.conversation_id=? ORDER BY tr.id');
        $statement->execute([$conversation]);
        return $statement->fetchAll();
    }

    public function deleteConversation(string $conversation): bool
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM ai_conversations WHERE id = ? FOR UPDATE');
            $lock->execute([$conversation]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return false; }
            $this->db->prepare('DELETE FROM live_chat_reads WHERE conversation_id = ?')->execute([$conversation]);
            $this->db->prepare('DELETE FROM live_chat_transfers WHERE conversation_id = ?')->execute([$conversation]);
            $this->db->prepare('DELETE FROM ai_messages WHERE conversation_id = ?')->execute([$conversation]);
            $statement = $this->db->prepare("UPDATE ai_conversations SET visitor_name = NULL, visitor_email = NULL, visitor_ip = NULL, visitor_country = NULL, channel = 'human', status = 'closed', assigned_user_id = NULL, assigned_team_id = NULL, updated_at = NOW() WHERE id = ?");
            $statement->execute([$conversation]);
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function purgeClosedConversations(int $days, int $limit = 500): int
    {
        $threshold = (new \DateTimeImmutable())->modify('-' . max(1, min(365, $days)) . ' days')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare('SELECT id FROM ai_conversations WHERE status = \'closed\' AND updated_at < ? ORDER BY updated_at LIMIT ?');
        $statement->bindValue(1, $threshold);
        $statement->bindValue(2, max(1, min(2000, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $ids = array_column($statement->fetchAll(), 'id');
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->beginTransaction();
        try {
            foreach (['live_chat_reads', 'live_chat_transfers', 'ai_messages'] as $table) $this->db->prepare("DELETE FROM {$table} WHERE conversation_id IN ({$placeholders})")->execute($ids);
            $delete = $this->db->prepare("DELETE FROM ai_conversations WHERE status = 'closed' AND id IN ({$placeholders})");
            $delete->execute($ids);
            $count = $delete->rowCount();
            $this->db->commit();
            return $count;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function canAccessConversation(string $conversation, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ai_conversations c WHERE c.id = ? AND ' . $this->accessSql('c') . ' LIMIT 1');
        $statement->execute([$conversation, $userId, $userId]);
        return (bool) $statement->fetchColumn();
    }

    private function accessSql(string $alias): string
    {
        return "({$alias}.assigned_user_id = ? OR ({$alias}.assigned_user_id IS NULL AND ({$alias}.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users access_team WHERE access_team.team_id = {$alias}.assigned_team_id AND access_team.user_id = ?))))";
    }
    private function decodeOptions(mixed$value):array{$decoded=is_string($value)&&$value!==''?json_decode($value,true):[];return is_array($decoded)?$decoded:[];}
}
