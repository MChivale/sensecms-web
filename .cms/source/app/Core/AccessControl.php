<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AccessControl
{
    private ?array $permissionCache = null;
    private array|false|null $facilityCache = false;
    private ?bool $demoUserCache = null;
    private ?int $subjectId = null;

    public static function forUser(PDO $db, int $id): self
    {
        $access = new self($db, new Auth($db));
        $check = $db->prepare('SELECT id FROM users WHERE id=? AND active=1 AND is_demo=0');
        $check->execute([$id]);
        $access->subjectId = (int)($check->fetchColumn() ?: 0);
        return $access;
    }

    private function userId(): ?int { return $this->subjectId ?? $this->auth->id(); }
    private function authenticated(): bool { return $this->subjectId !== null ? $this->subjectId > 0 : $this->auth->check(); }

    public function __construct(private readonly PDO $db, private readonly Auth $auth) {}

    public function permissions(): array
    {
        if ($this->permissionCache !== null) return $this->permissionCache;
        $id = $this->userId();
        if (!$id) return $this->permissionCache = [];
        if ($this->isDemoUser()) return $this->permissionCache = array_values(array_map('strval', $this->db->query('SELECT slug FROM permissions ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN)));
        $statement = $this->db->prepare('SELECT DISTINCT p.slug FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id=p.id INNER JOIN roles r ON r.id=rp.role_id AND r.active=1 INNER JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY p.slug');
        $statement->execute([$id]);
        return $this->permissionCache = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function allows(string $permission, ?int $facilityId = null): bool
    {
        if (!$this->authenticated()) return false;
        $permissions = $this->permissions();
        if (!in_array('system.owner', $permissions, true) && !in_array($permission, $permissions, true)) return false;
        if (!$facilityId || in_array('system.owner', $permissions, true)) return true;
        $scope = $this->facilityIds();
        return $scope === null || in_array($facilityId, $scope, true);
    }

    public function assert(string $permission, ?int $facilityId = null): void
    {
        if (!$this->allows($permission, $facilityId)) throw new RuntimeException('You do not have permission to perform this action.', 403);
    }

    public function facilityIds(): ?array
    {
        if ($this->facilityCache !== false) return $this->facilityCache;
        if (in_array('system.owner', $this->permissions(), true)) return $this->facilityCache = null;
        $statement = $this->db->prepare('SELECT facility_id FROM user_facilities WHERE user_id=? UNION SELECT tc.facility_id FROM team_facilities tc INNER JOIN live_chat_team_users tu ON tu.team_id=tc.team_id INNER JOIN live_chat_teams t ON t.id=tu.team_id AND t.active=1 WHERE tu.user_id=? ORDER BY facility_id');
        $statement->execute([$this->userId(), $this->userId()]);
        return $this->facilityCache = array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function roleNames(): array
    {
        $statement = $this->db->prepare('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? AND r.active=1 ORDER BY r.name');
        $statement->execute([$this->userId()]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function isDemoUser(): bool
    {
        if ($this->demoUserCache !== null) return $this->demoUserCache;
        $id = $this->userId();
        if (!$id) return $this->demoUserCache = false;
        $statement = $this->db->prepare('SELECT is_demo FROM users WHERE id=? AND active=1 LIMIT 1');
        $statement->execute([$id]);
        return $this->demoUserCache = (bool)$statement->fetchColumn();
    }

    public function enforceRequest(string $method, string $path): void
    {
        if (!$this->authenticated()) return;
        $method = strtoupper($method);
        if ($this->isDemoUser() && !in_array($method, ['GET','HEAD','OPTIONS'], true) && !in_array($path, ['/login','/logout'], true)) $this->denyReadOnly();
        $permission = $this->permissionFor($method, $path);
        if ($permission === null) return;
        $facilities = $this->requestFacilities($path);
        if (!$facilities && !$this->allows($permission)) $this->deny();
        foreach ($facilities as $facilityId) if (!$this->allows($permission, $facilityId)) $this->deny();
    }

    public function permissionCatalog(): array
    {
        $rows = $this->db->query('SELECT id,slug,name,group_key,description FROM permissions ORDER BY group_key,name')->fetchAll();
        $groups = [];
        foreach ($rows as $row) $groups[(string)$row['group_key']][] = $row;
        return $groups;
    }

    public function roles(): array
    {
        $rows = $this->db->query('SELECT r.*,(SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id=r.id) user_count,(SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id=r.id) permission_count FROM roles r ORDER BY r.is_system DESC,r.name')->fetchAll();
        $permissions = $this->db->query('SELECT rp.role_id,p.slug FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id ORDER BY p.slug')->fetchAll();
        $byRole = [];
        foreach ($permissions as $permission) $byRole[(int)$permission['role_id']][] = (string)$permission['slug'];
        foreach ($rows as &$row) $row['permissions'] = $byRole[(int)$row['id']] ?? [];
        unset($row);
        return $rows;
    }

    public function users(): array
    {
        $rows = $this->db->query("SELECT u.id,u.name,u.email,u.job_title,u.avatar_url,u.active,u.is_demo,u.created_at,u.updated_at,GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ' · ') role_names,GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) facility_ids,GROUP_CONCAT(DISTINCT COALESCE(ct.name,c.facility_slug) ORDER BY c.id SEPARATOR ' · ') facility_names FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id LEFT JOIN user_facilities uc ON uc.user_id=u.id LEFT JOIN facilities c ON c.id=uc.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) GROUP BY u.id ORDER BY u.active DESC,u.name,u.id")->fetchAll();
        foreach ($rows as &$row) {
            $row['role_ids'] = $this->ids('SELECT role_id FROM user_roles WHERE user_id=? ORDER BY role_id', (int)$row['id']);
            $row['facility_ids'] = $this->ids('SELECT facility_id FROM user_facilities WHERE user_id=?', (int)$row['id']);
            if ($row['is_demo']) $row['role_names'] = 'Demo User - read only' . ($row['role_names'] ? ' · ' . $row['role_names'] : '');
        }
        unset($row);
        return $rows;
    }

    public function teams(): array
    {
        $rows = $this->db->query('SELECT t.*,(SELECT COUNT(*) FROM live_chat_team_users tu WHERE tu.team_id=t.id) user_count,(SELECT COUNT(*) FROM team_facilities tc WHERE tc.team_id=t.id) facility_count FROM live_chat_teams t ORDER BY t.active DESC,t.name,t.id')->fetchAll();
        foreach ($rows as &$row) {
            $row['user_ids'] = $this->ids('SELECT user_id FROM live_chat_team_users WHERE team_id=?', (int)$row['id']);
            $row['facility_ids'] = $this->ids('SELECT facility_id FROM team_facilities WHERE team_id=?', (int)$row['id']);
        }
        unset($row);
        return $rows;
    }

    public function user(int $id): ?array
    {
        foreach ($this->users() as $user) if ((int)$user['id'] === $id) return $user;
        return null;
    }

    public function role(int $id): ?array
    {
        foreach ($this->roles() as $role) if ((int)$role['id'] === $id) return $role;
        return null;
    }

    public function team(int $id): ?array
    {
        foreach ($this->teams() as $team) if ((int)$team['id'] === $id) return $team;
        return null;
    }

    public function workflowUsers(?int $facilityId = null): array
    {
        $where = $facilityId ? ' AND (EXISTS(SELECT 1 FROM user_facilities uc WHERE uc.user_id=u.id AND uc.facility_id=?) OR EXISTS(SELECT 1 FROM live_chat_team_users tu INNER JOIN live_chat_teams tm ON tm.id=tu.team_id AND tm.active=1 INNER JOIN team_facilities tc ON tc.team_id=tu.team_id WHERE tu.user_id=u.id AND tc.facility_id=?) OR EXISTS(SELECT 1 FROM user_roles ur2 INNER JOIN roles r2 ON r2.id=ur2.role_id INNER JOIN role_permissions rp2 ON rp2.role_id=r2.id INNER JOIN permissions p2 ON p2.id=rp2.permission_id WHERE ur2.user_id=u.id AND r2.active=1 AND p2.slug="system.owner"))' : '';
        $statement = $this->db->prepare('SELECT u.id,u.name,u.email,u.job_title FROM users u WHERE u.active=1'.$where.' ORDER BY u.name,u.id');
        $statement->execute($facilityId ? [$facilityId,$facilityId] : []);
        return $statement->fetchAll();
    }

    public function saveUser(array $input, int $actorId): int
    {
        $this->assert('users.manage');
        $id = max(0, (int)($input['id'] ?? 0));
        $name = trim((string)($input['name'] ?? ''));
        $email = mb_strtolower(trim((string)($input['email'] ?? '')));
        $job = mb_substr(trim((string)($input['job_title'] ?? '')), 0, 120);
        $active = !empty($input['active']);
        $isDemo = !empty($input['is_demo']);
        $password = (string)($input['password'] ?? '');
        $roleValues = array_key_exists('role_id', $input) ? [(int)$input['role_id']] : array_slice((array)($input['role_ids'] ?? []), 0, 1);
        $roleIds = $this->validIds($roleValues, 'roles');
        $facilityIds = $this->validIds((array)($input['facility_ids'] ?? []), 'facilities');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new RuntimeException('Display name must contain 2 to 120 characters.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) throw new RuntimeException('Enter a valid e-mail address.');
        if (!$roleIds) throw new RuntimeException('Assign at least one role.');
        if ($password !== '' && (strlen($password) < 14 || strlen($password) > 200 || str_contains($password, "\0"))) throw new RuntimeException('The password must contain 14 to 200 characters.');
        $ownerRole = (int)($this->db->query("SELECT id FROM roles WHERE slug='owner'")->fetchColumn() ?: 0);
        if ($id && $this->isLastOwner($id) && (!$active || $isDemo || !in_array($ownerRole, $roleIds, true))) throw new RuntimeException('The final active writable Owner cannot be disabled, converted to Demo User or stripped of the Owner role.');
        $this->db->beginTransaction();
        try {
            if ($id) {
                $values = [$name,$email,$job ?: null,$active ? 1 : 0,$isDemo ? 1 : 0];
                $sql = 'UPDATE users SET name=?,email=?,job_title=?,active=?,is_demo=?,updated_at=NOW(),session_version=session_version+1';
                if ($password !== '') { $sql .= ',password=?'; $values[] = password_hash($password, PASSWORD_ARGON2ID); }
                $values[] = $id; $statement = $this->db->prepare($sql.' WHERE id=?'); $statement->execute($values);
                if (!$statement->rowCount() && !$this->exists('users', $id)) throw new RuntimeException('The user was not found.');
            } else {
                if ($password === '') $password = bin2hex(random_bytes(32));
                $this->db->prepare('INSERT INTO users (name,email,password,job_title,active,is_demo,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())')->execute([$name,$email,password_hash($password,PASSWORD_ARGON2ID),$job ?: null,$active ? 1 : 0,$isDemo ? 1 : 0]);
                $id = (int)$this->db->lastInsertId();
            }
            $this->sync('user_roles','user_id','role_id',$id,$roleIds);
            $this->sync('user_facilities','user_id','facility_id',$id,$facilityIds,true);
            $this->audit($actorId,!empty($input['id'])?'access.user.updated':'access.user.created','user',$id,['roles'=>$roleIds,'facilities'=>$facilityIds,'active'=>$active,'demo_user'=>$isDemo]);
            $this->db->commit(); $this->clear(); return $id;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function saveRole(array $input, int $actorId): int
    {
        $this->assert('roles.manage');
        $id = max(0, (int)($input['id'] ?? 0));
        $existing = $id ? $this->role($id) : null;
        if ($id && !$existing) throw new RuntimeException('The role was not found.');
        if ($existing && (string)$existing['slug'] === 'owner') throw new RuntimeException('The Owner role is protected and cannot be changed.');
        $name = mb_substr(trim((string)($input['name'] ?? '')),0,100);
        $slug = $this->slug((string)($input['slug'] ?? $name));
        $description = mb_substr(trim((string)($input['description'] ?? '')),0,500);
        $color = preg_match('/^#[0-9a-f]{6}$/i',(string)($input['color']??'')) ? strtolower((string)$input['color']) : '#2563eb';
        $permissionIds = $this->validIds((array)($input['permission_ids'] ?? []),'permissions');
        if (mb_strlen($name) < 2) throw new RuntimeException('Role name must contain at least two characters.');
        if (!$permissionIds) throw new RuntimeException('Choose at least one permission.');
        $this->db->beginTransaction();
        try {
            if ($id) $this->db->prepare('UPDATE roles SET name=?,slug=?,description=?,color=?,active=?,updated_at=NOW() WHERE id=?')->execute([$name,$slug,$description ?: null,$color,!empty($input['active'])?1:0,$id]);
            else { $this->db->prepare('INSERT INTO roles (slug,name,description,color,is_system,active,created_at,updated_at) VALUES (?,?,?,?,0,?,NOW(),NOW())')->execute([$slug,$name,$description ?: null,$color,!empty($input['active'])?1:0]); $id=(int)$this->db->lastInsertId(); }
            $this->sync('role_permissions','role_id','permission_id',$id,$permissionIds);
            $this->audit($actorId,!empty($input['id'])?'access.role.updated':'access.role.created','role',$id,['permissions'=>count($permissionIds)]);
            $this->db->commit(); $this->clear(); return $id;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function saveTeam(array $input, int $actorId): int
    {
        $this->assert('users.manage');
        $id=max(0,(int)($input['id']??0));if($id&&!$this->team($id))throw new RuntimeException('The team was not found.');$name=mb_substr(trim((string)($input['name']??'')),0,120);$slug=$this->slug((string)($input['slug']??$name));$description=mb_substr(trim((string)($input['description']??'')),0,500);$color=preg_match('/^#[0-9a-f]{6}$/i',(string)($input['color']??''))?strtolower((string)$input['color']):'#2563eb';
        if(mb_strlen($name)<2)throw new RuntimeException('Team name must contain at least two characters.');$userIds=$this->validIds((array)($input['user_ids']??[]),'users');$facilityIds=$this->validIds((array)($input['facility_ids']??[]),'facilities');
        $this->db->beginTransaction();try{if($id)$this->db->prepare('UPDATE live_chat_teams SET name=?,slug=?,description=?,color=?,active=?,updated_at=NOW() WHERE id=?')->execute([$name,$slug,$description?:null,$color,!empty($input['active'])?1:0,$id]);else{$this->db->prepare('INSERT INTO live_chat_teams (name,slug,description,color,active,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')->execute([$name,$slug,$description?:null,$color,!empty($input['active'])?1:0]);$id=(int)$this->db->lastInsertId();}$this->sync('live_chat_team_users','team_id','user_id',$id,$userIds,true);$this->sync('team_facilities','team_id','facility_id',$id,$facilityIds,true);$this->audit($actorId,!empty($input['id'])?'access.team.updated':'access.team.created','team',$id,['users'=>$userIds,'facilities'=>$facilityIds]);$this->db->commit();return$id;}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function auditRows(int $limit = 100): array
    {
        $limit=max(10,min(250,$limit));$statement=$this->db->prepare('SELECT a.*,COALESCE(u.name,"System") actor_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT ?');$statement->bindValue(1,$limit,PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();foreach($rows as&$row){$context=json_decode((string)($row['context']??''),true);$row['context']=is_array($context)?$context:[];}unset($row);return$rows;
    }

    private function permissionFor(string $method,string $path):?string
    {
        if(in_array($path,['/login','/captcha/login.png','/forgot-password','/set-password','/reset-password','/captcha/recovery.png','/'],true)||str_starts_with($path,'/api/ai/chat')||str_starts_with($path,'/api/chat/state')||str_starts_with($path,'/api/search')||str_starts_with($path,'/api/facilities')||str_starts_with($path,'/captcha/forms/')||str_starts_with($path,'/captcha/surveys/')||str_starts_with($path,'/api/surveys/')&&str_ends_with($path,'/start')||str_starts_with($path,'/api/survey-responses/')&&str_ends_with($path,'/submit')||str_starts_with($path,'/api/forms/')&&str_ends_with($path,'/submit'))return null;
        if(in_array($path,['/dashboard','/profile','/settings','/settings/password','/logout','/api/notifications','/api/notifications/read','/api/console-search','/api/telegram-connection'],true))return'console.access';
        if($path==='/content/workflow'||str_starts_with($path,'/content/workflow/'))return'content.workflow.view';
        if(str_starts_with($path,'/system/notifications'))return 'system.manage';
        if(str_starts_with($path,'/system/access'))return $method==='GET'?'users.view':(str_contains($path,'/roles')?'roles.manage':'users.manage');
        if(str_starts_with($path,'/content/media')||str_starts_with($path,'/api/media'))return str_contains($path,'/delete')||str_contains($path,'/trash')||str_contains($path,'/restore')?'content.media.delete':'content.media.manage';
        if($path==='/api/content/builder/media')return'content.media.manage';
        if(str_starts_with($path,'/content/pages')){if($method==='GET')return str_ends_with($path,'/new')||str_ends_with($path,'/edit')?'content.pages.edit':'content.pages.view';if(str_ends_with($path,'/delete')||str_ends_with($path,'/archive')||str_contains($path,'/trash/'))return'content.pages.delete';return'content.pages.edit';}
        if(str_starts_with($path,'/content/builder'))return $method==='GET'?'content.pages.edit':(str_contains($path,'/media')?'content.media.manage':'content.pages.edit');
        if(str_starts_with($path,'/content/posts')){if($method==='GET')return str_ends_with($path,'/new')||str_ends_with($path,'/edit')?'content.posts.edit':'content.posts.view';if(str_ends_with($path,'/archive'))return'content.posts.delete';return'content.posts.edit';}
        if(str_starts_with($path,'/content/categories')||str_starts_with($path,'/content/navigation'))return'content.navigation.manage';
        if($path==='/seo'||str_starts_with($path,'/seo/'))return'content.seo.manage';
        if($path==='/content/home'||str_starts_with($path,'/system/extensions/popups'))return'content.pages.edit';
        if(str_starts_with($path,'/content/facilities'))return $method==='GET'?'facilities.view':'facilities.manage';
        if(str_starts_with($path,'/forms/submissions')||str_starts_with($path,'/api/forms/submissions'))return str_contains($path,'/export')?'forms.export':($method==='GET'?'forms.view':'forms.manage');
        if(str_starts_with($path,'/surveys')||str_starts_with($path,'/api/surveys')){if(str_contains($path,'/export.'))return'surveys.export';if(str_contains($path,'/anonymize'))return'surveys.anonymize';if(str_contains($path,'/responses'))return'surveys.responses';if(str_contains($path,'/statistics')||$method==='GET')return'surveys.view';return'surveys.manage';}
        if(str_starts_with($path,'/conversations')||str_starts_with($path,'/api/operator/'))return $method==='GET'?'chat.view':'chat.manage';
        if($path==='/ai')return'ai.manage';
        if(str_starts_with($path,'/appearance'))return'appearance.manage';
        if(str_starts_with($path,'/system/extensions')||str_starts_with($path,'/marketplace')||str_starts_with($path,'/api/marketplace'))return'extensions.manage';
        if(str_starts_with($path,'/system/')||$path==='/license')return'system.manage';
        return null;
    }

    private function requestFacilities(string $path):array
    {
        $ids=[];$posted=max(0,(int)($_POST['facility_id']??0));$queried=max(0,(int)($_GET['facility']??0));if($posted)$ids[]=$posted;if($queried)$ids[]=$queried;
        $lookups=[['#^/content/pages/(\d+)#','pages'],['#^/content/builder/(\d+)#','pages'],['#^/content/posts/(\d+)#','posts'],['#^/api/forms/submissions/(\d+)#','form_submissions'],['#^/content/facilities/(\d+)#','facilities']];
        foreach($lookups as[$pattern,$table])if(preg_match($pattern,$path,$match)){$facility=$table==='facilities'?(int)$match[1]:$this->facilityOf($table,(int)$match[1]);if($facility)$ids[]=$facility;}
        if(preg_match('#^/content/(pages|posts)/(\d+)/edit$#',$path,$match)){$facility=$this->facilityOf($match[1],(int)$match[2]);if($facility)$ids[]=$facility;}
        $edit=max(0,(int)($_GET['edit']??0));if($path==='/content/facilities'&&$edit)$ids[]=$edit;
        $seoDocument=max(0,(int)($_POST['document_id']??$_POST['page_id']??0));if($path==='/seo/page'&&$seoDocument){$seoTable=($_POST['document_type']??'page')==='post'?'posts':'pages';$facility=$this->facilityOf($seoTable,$seoDocument);if($facility)$ids[]=$facility;}
        $record=max(0,(int)($_POST['id']??0));if($record&&in_array($path,['/content/pages','/content/posts'],true)){$facility=$this->facilityOf($path==='/content/pages'?'pages':'posts',$record);if($facility)$ids[]=$facility;}
        if($record && $path==='/content/facilities')$ids[]=$record;
        return array_values(array_unique(array_filter($ids)));
    }

    private function facilityOf(string$table,int$id):int{if(!in_array($table,['pages','posts','form_submissions'],true))return 0;$statement=$this->db->prepare("SELECT facility_id FROM {$table} WHERE id=? LIMIT 1");$statement->execute([$id]);return(int)($statement->fetchColumn()?:0);}
    private function deny():never{$json=str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')||(($_SERVER['HTTP_X_SENSECMS_REQUEST']??'')==='1');http_response_code(403);if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>'You do not have permission to perform this action.'],JSON_UNESCAPED_SLASHES);exit;}header('Content-Type: text/plain; charset=utf-8');exit('Forbidden');}
    private function denyReadOnly():never{$message='Demo User mode is read-only. You can explore every area, but changes cannot be saved.';$json=str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')||(($_SERVER['HTTP_X_SENSECMS_REQUEST']??'')==='1');header('X-SenseCMS-Demo-Mode: 1');if($json){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>$message],JSON_UNESCAPED_SLASHES);exit;}$_SESSION['flash']=$message;$_SESSION['flash_type']='warning';$target='/dashboard';$referer=(string)($_SERVER['HTTP_REFERER']??'');$host=(string)($_SERVER['HTTP_HOST']??'');if($referer!==''&&strcasecmp((string)parse_url($referer,PHP_URL_HOST),preg_replace('/:\d+$/','',$host)??$host)===0){$refererPath=(string)(parse_url($referer,PHP_URL_PATH)?:'/dashboard');$query=(string)(parse_url($referer,PHP_URL_QUERY)?:'');if(str_starts_with($refererPath,'/'))$target=$refererPath.($query!==''?'?'.$query:'');}header('Location: '.$target,true,303);exit;}
    private function ids(string$sql,int$id):array{$statement=$this->db->prepare($sql);$statement->execute([$id]);return array_values(array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN)));}
    private function validIds(array$values,string$table):array{if(!in_array($table,['users','roles','permissions','facilities'],true))return[];$ids=array_values(array_unique(array_filter(array_map('intval',$values))));if(!$ids)return[];$marks=implode(',',array_fill(0,count($ids),'?'));$statement=$this->db->prepare("SELECT id FROM {$table} WHERE id IN ({$marks})");$statement->execute($ids);return array_values(array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN)));}
    private function sync(string$table,string$owner,string$value,int$id,array$values,bool$createdAt=false):void{$allowed=['user_roles'=>['user_id','role_id'],'user_facilities'=>['user_id','facility_id'],'role_permissions'=>['role_id','permission_id'],'live_chat_team_users'=>['team_id','user_id'],'team_facilities'=>['team_id','facility_id']];if(($allowed[$table]??null)!==[$owner,$value])throw new RuntimeException('Unsupported access relation.');$this->db->prepare("DELETE FROM {$table} WHERE {$owner}=?")->execute([$id]);if(!$values)return;$sql="INSERT INTO {$table} ({$owner},{$value}".($createdAt?',created_at':'').') VALUES (?,?'.($createdAt?',NOW()':'').')';$statement=$this->db->prepare($sql);foreach($values as$item)$statement->execute([$id,$item]);}
    private function isLastOwner(int$userId):bool{$statement=$this->db->prepare("SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE u.active=1 AND u.is_demo=0 AND r.slug='owner' AND u.id<>?");$statement->execute([$userId]);return(int)$statement->fetchColumn()===0;}
    private function exists(string$table,int$id):bool{if(!in_array($table,['users'],true))return false;$statement=$this->db->prepare("SELECT 1 FROM {$table} WHERE id=?");$statement->execute([$id]);return(bool)$statement->fetchColumn();}
    private function slug(string$value):string{$slug=trim(preg_replace('/[^a-z0-9]+/','-',strtolower(trim($value)))??'','-');if($slug===''||strlen($slug)>80)throw new RuntimeException('Use a valid role or team slug.');return$slug;}
    private function audit(int$userId,string$event,string$type,int$id,array$context):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId?:null,$event,$type,$id,json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
    private function clear():void{$this->permissionCache=null;$this->facilityCache=false;$this->demoUserCache=null;}
}
