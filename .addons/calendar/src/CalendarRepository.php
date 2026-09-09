<?php

declare(strict_types=1);

namespace SenseCMS\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class CalendarRepository
{
    private const RECURRENCE = ['none', 'daily', 'weekly', 'monthly'];
    private const CHANNELS = ['internal', 'email', 'browser', 'telegram', 'whatsapp'];

    private readonly ?\Closure $translate;

    public function __construct(private readonly PDO $db, private readonly int $viewerId = 0, private readonly bool $readAll = false, ?callable $translate = null)
    {
        $this->translate = $translate === null ? null : \Closure::fromCallable($translate);
    }

    public function events(string $from, string $to, ?array $facilityScope, array $filters = []): array
    {
        [$start, $end] = $this->range($from, $to);
        $where = ['e.start_at < ?', 'e.end_at > ?', "e.status <> 'cancelled'"];
        $parameters = [$end, $start];
        $this->scope($where, $parameters, $facilityScope, 'e.facility_id');
        if (!$this->readAll) { $where[] = '(e.owner_id=? OR e.visibility IN ("facility","public") OR (e.visibility="participants" AND EXISTS(SELECT 1 FROM calendar_event_participants vp WHERE vp.event_id=e.id AND vp.user_id=?)))'; array_push($parameters, $this->viewerId, $this->viewerId); }
        $facility = max(0, (int) ($filters['facility_id'] ?? 0));
        if ($facility) { $where[] = 'e.facility_id=?'; $parameters[] = $facility; }
        $type = (string) ($filters['event_type'] ?? '');
        if ($type !== '') {
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $type) || strlen($type) > 40) throw new RuntimeException('Choose a valid category.');
            $where[] = 'e.event_type=?'; $parameters[] = $type;
        }
        $user = max(0, (int) ($filters['user_id'] ?? 0));
        if ($user) { $where[] = 'EXISTS(SELECT 1 FROM calendar_event_participants ep WHERE ep.event_id=e.id AND ep.user_id=?)'; $parameters[] = $user; }
        $resource = max(0, (int) ($filters['resource_id'] ?? 0));
        if ($resource) { $where[] = 'EXISTS(SELECT 1 FROM calendar_event_resources er WHERE er.event_id=e.id AND er.resource_id=?)'; $parameters[] = $resource; }
        $sql = 'SELECT e.*,COALESCE(c.facility_slug,"") facility_slug,(SELECT GROUP_CONCAT(ep.user_id ORDER BY ep.user_id) FROM calendar_event_participants ep WHERE ep.event_id=e.id) participant_ids,(SELECT GROUP_CONCAT(er.resource_id ORDER BY er.resource_id) FROM calendar_event_resources er WHERE er.event_id=e.id) resource_ids FROM calendar_events e LEFT JOIN facilities c ON c.id=e.facility_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.start_at,e.id LIMIT 3000';
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return array_map([$this, 'eventRow'], $statement->fetchAll());
    }

    public function event(int $id, ?array $facilityScope): ?array
    {
        $statement = $this->db->prepare('SELECT e.*,COALESCE(c.facility_slug,"") facility_slug,(SELECT GROUP_CONCAT(ep.user_id ORDER BY ep.user_id) FROM calendar_event_participants ep WHERE ep.event_id=e.id) participant_ids,(SELECT GROUP_CONCAT(er.resource_id ORDER BY er.resource_id) FROM calendar_event_resources er WHERE er.event_id=e.id) resource_ids FROM calendar_events e LEFT JOIN facilities c ON c.id=e.facility_id WHERE e.id=? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row || !$this->facilityAllowed((int) ($row['facility_id'] ?? 0), $facilityScope)) return null;
        $event = $this->eventRow($row);
        if (!$this->readAll && $event['owner_id'] !== $this->viewerId && !in_array($event['visibility'], ['facility','public'], true) && !($event['visibility'] === 'participants' && in_array($this->viewerId, $event['participant_ids'], true))) return null;
        $event['reminders'] = $this->reminders($id);
        return $event;
    }

    public function options(?array $facilityScope): array
    {
        $where = ['c.status<>"archived"']; $parameters = [];
        $this->scope($where, $parameters, $facilityScope, 'c.id');
        $facilities = $this->db->prepare("SELECT c.id,COALESCE(ct.name,c.facility_slug) name,c.city_slug FROM facilities c LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) WHERE " . implode(' AND ', $where) . ' ORDER BY name,c.id');
        $facilities->execute($parameters);
        $users = $this->db->query('SELECT id,name,job_title FROM users WHERE active=1 ORDER BY name,id')->fetchAll();
        $resourceWhere = ['r.active=1']; $resourceParameters = [];
        $this->scope($resourceWhere, $resourceParameters, $facilityScope, 'r.facility_id');
        $resources = $this->db->prepare('SELECT r.*,COALESCE(c.facility_slug,"") facility_slug FROM calendar_resources r LEFT JOIN facilities c ON c.id=r.facility_id WHERE ' . implode(' AND ', $resourceWhere) . ' ORDER BY r.resource_type,r.name,r.id');
        $resources->execute($resourceParameters);
        $categories = $this->categories();
        return ['facilities' => $facilities->fetchAll(), 'users' => $users, 'resources' => $resources->fetchAll(), 'categories' => $categories, 'types' => array_column(array_filter($categories, static fn(array $item): bool => (bool) $item['active']), 'slug'), 'channels' => self::CHANNELS, 'reminder_offsets' => $this->allowedReminderOffsets()];
    }

    public function categories(): array
    {
        return $this->db->query('SELECT id,slug,name,color,active FROM calendar_categories ORDER BY active DESC,name,id LIMIT 200')->fetchAll();
    }

    public function saveCategory(array $input, int $userId): array
    {
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        $slug = trim((string) ($input['slug'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $color = strtolower((string) ($input['color'] ?? '#2563eb'));
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($id === false || $id < 0 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 40 || mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f]/u', $name) || !preg_match('/^#[a-f0-9]{6}$/D', $color) || $active === null) throw new RuntimeException('Enter a valid category name, identifier and color.');
        if ($slug === 'general' && !$active) throw new RuntimeException('The general category must remain active.');
        return $this->writeLocked(function () use ($id, $slug, $name, $color, $active, $userId): array {
            $this->db->beginTransaction();
            try {
                if ($id) {
                    $statement = $this->db->prepare('SELECT slug FROM calendar_categories WHERE id=? FOR UPDATE'); $statement->execute([$id]);
                    if ($statement->fetchColumn() !== $slug) throw new RuntimeException('The category identifier cannot be changed.', 409);
                    $this->db->prepare('UPDATE calendar_categories SET name=?,color=?,active=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$name,$color,(int) $active,$id]);
                } else {
                    if ((int) $this->db->query('SELECT COUNT(*) FROM calendar_categories')->fetchColumn() >= 200) throw new RuntimeException('The calendar supports up to 200 categories.');
                    $statement = $this->db->prepare('SELECT id FROM calendar_categories WHERE slug=?'); $statement->execute([$slug]);
                    if ($statement->fetchColumn()) throw new RuntimeException('That category identifier is already in use.', 409);
                    $this->db->prepare('INSERT INTO calendar_categories (slug,name,color,active,created_at,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$slug,$name,$color,(int) $active]);
                    $id = (int) $this->db->lastInsertId();
                }
                $this->audit($userId, 'calendar.category.saved', $id, ['slug'=>$slug,'active'=>$active]);
                $this->db->commit();
                return ['id'=>$id,'slug'=>$slug,'name'=>$name,'color'=>$color,'active'=>(int) $active];
            } finally { if ($this->db->inTransaction()) $this->db->rollBack(); }
        });
    }

    public function save(array $input, int $userId, ?array $facilityScope, bool $canOverride): array
    {
        return $this->writeLocked(fn(): array => $this->saveLocked($input, $userId, $facilityScope, $canOverride));
    }

    private function saveLocked(array $input, int $userId, ?array $facilityScope, bool $canOverride): array
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $existing = $id ? $this->event($id, $facilityScope) : null;
        if ($id && !$existing) throw new RuntimeException('The calendar event was not found.', 404);
        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 180);
        if (mb_strlen($title) < 2) throw new RuntimeException('Event title must contain at least two characters.');
        $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000);
        $location = mb_substr(trim((string) ($input['location'] ?? '')), 0, 255);
        $type = (string) ($input['event_type'] ?? 'general');
        $category = $this->db->prepare('SELECT active,color FROM calendar_categories WHERE slug=?'); $category->execute([$type]); $category = $category->fetch();
        if (!$category || !(bool) $category['active'] && ($existing['event_type'] ?? null) !== $type) throw new RuntimeException('Choose an active calendar category.');
        $visibility = in_array($input['visibility'] ?? '', ['participants', 'facility', 'public', 'private'], true) ? (string) $input['visibility'] : 'participants';
        $color = preg_match('/^#[0-9a-f]{6}$/iD', (string) ($input['color'] ?? '')) ? strtolower((string) $input['color']) : (string) $category['color'];
        $timezone = $this->timezone((string) ($input['timezone'] ?? ($existing['timezone'] ?? 'UTC')));
        $allDay = !empty($input['all_day']);
        [$start, $end] = $this->inputTimes($input, $timezone, $allDay);
        $facilityId = max(0, (int) ($input['facility_id'] ?? 0));
        if (!$this->facilityAllowed($facilityId, $facilityScope) || (!$facilityId && $facilityScope !== null) || ($facilityId && !$this->exists('facilities', $facilityId))) throw new RuntimeException('Choose an available facility.');
        $participants = $this->validIds((array) ($input['participant_ids'] ?? []), 'users');
        $ownerId = (int) ($existing['owner_id'] ?? $userId);
        if (!in_array($ownerId, $participants, true)) $participants[] = $ownerId;
        sort($participants);
        if ($visibility === 'private') $participants = [(int) ($existing['owner_id'] ?? $userId)];
        $resources = $this->validResources((array) ($input['resource_ids'] ?? []), $facilityId, $facilityScope);
        $editScope = $id && !empty($existing['series_uid']) && in_array($input['edit_scope'] ?? '', ['following','series'], true) ? (string)$input['edit_scope'] : 'single';
        $repeat = (!$id || $editScope !== 'single') && in_array($input['recurrence'] ?? '', self::RECURRENCE, true) ? (string) $input['recurrence'] : 'none';
        $affected = $id ? ($editScope === 'single' ? [$existing] : $this->seriesEvents((string)$existing['series_uid'], $editScope === 'following' ? (string)$existing['start_at'] : null, $facilityScope)) : [];
        if ($id && $editScope === 'series' && $affected) [$start,$end]=$this->shiftedSeriesAnchor((string)$affected[0]['start_at'],(string)$existing['start_at'],$start,$end,$timezone);
        $until = $this->recurrenceUntil($repeat, (string) ($input['recurrence_until'] ?? ''), $start, $timezone);
        $occurrences = $this->occurrences($start, $end, $repeat, $until, $timezone);
        if ($id && $editScope === 'single') $occurrences = [[$start,$end]];
        $excludedIds = array_values(array_map(static fn(array $event):int=>(int)$event['id'],$affected));
        $allConflicts = [];
        foreach ($occurrences as [$occurrenceStart, $occurrenceEnd]) {
            foreach ($this->conflicts($occurrenceStart, $occurrenceEnd, $participants, $resources, $excludedIds, $facilityScope) as $conflict) $allConflicts[$conflict['key']] = $conflict;
        }
        if ($allConflicts && (!$canOverride || empty($input['override_conflicts']))) throw new CalendarConflictException(array_values($allConflicts));
        $offsets = $this->reminderOffsets((array) ($input['reminder_offsets'] ?? []));
        $channels = array_values(array_intersect(self::CHANNELS, array_unique(array_map('strval', (array) ($input['channels'] ?? ['internal'])))));
        if (!$channels) $channels = ['internal'];
        $seriesUid = $id ? ($existing['series_uid'] ?: null) : ($repeat === 'none' ? null : $this->uuid());
        $this->db->beginTransaction();
        try {
            if ($id && ($existing['status'] ?? '') === 'cancelled') throw new RuntimeException('A cancelled event cannot be edited.', 409);
            $saved = [];
            foreach ($occurrences as $index => [$occurrenceStart, $occurrenceEnd]) {
                $reuse = $id ? ($affected[$index] ?? null) : null;
                if ($reuse) {
                    $eventId=(int)$reuse['id'];
                    $statement = $this->db->prepare('UPDATE calendar_events SET series_uid=?,facility_id=?,title=?,description=?,event_type=?,status="confirmed",visibility=?,location=?,color=?,timezone=?,start_at=?,end_at=?,all_day=?,recurrence=?,recurrence_until=?,updated_at=NOW() WHERE id=?');
                    $storedRepeat=$editScope==='single'?(string)($existing['recurrence']??'none'):$repeat;
                    $storedUntil=$editScope==='single'?($existing['recurrence_until']??null):$until?->format('Y-m-d');
                    $statement->execute([$seriesUid,$facilityId ?: null, $title, $description ?: null, $type, $visibility, $location ?: null, $color, $timezone, $occurrenceStart, $occurrenceEnd, $allDay ? 1 : 0,$storedRepeat,$storedUntil,$eventId]);
                    $this->db->prepare('DELETE FROM calendar_reminders WHERE event_id=?')->execute([$eventId]);
                } else {
                    $uid = $this->uuid();
                    $statement = $this->db->prepare('INSERT INTO calendar_events (uid,series_uid,facility_id,owner_id,title,description,event_type,status,visibility,location,color,timezone,start_at,end_at,all_day,recurrence,recurrence_until,created_at,updated_at) VALUES (?,?,?,?,?,?,?,"confirmed",?,?,?,?,?,?,?,?,?,NOW(),NOW())');
                    $statement->execute([$uid, $seriesUid, $facilityId ?: null, $userId, $title, $description ?: null, $type, $visibility, $location ?: null, $color, $timezone, $occurrenceStart, $occurrenceEnd, $allDay ? 1 : 0, $repeat, $until?->format('Y-m-d')]);
                    $eventId = (int) $this->db->lastInsertId();
                }
                $this->syncRelations($eventId, $participants, $resources);
                $this->syncReminders($eventId, $occurrenceStart, $offsets, $channels);
                $saved[] = $eventId;
            }
            $retired=[];
            if($id&&count($affected)>count($saved))foreach(array_slice($affected,count($saved))as$obsolete){$obsoleteId=(int)$obsolete['id'];$this->db->prepare("UPDATE calendar_events SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$obsoleteId]);$this->db->prepare("UPDATE calendar_reminders SET status='cancelled' WHERE event_id=? AND status='pending'")->execute([$obsoleteId]);$this->queueSync($obsoleteId,'delete');$retired[]=$obsoleteId;}
            $this->audit($userId, $id ? 'calendar.event.updated' : 'calendar.event.created', $saved[0], ['occurrences' => count($saved), 'scope'=>$editScope,'retired'=>count($retired),'conflicts_overridden' => count($allConflicts)]);
            foreach ($saved as $savedId) $this->queueSync($savedId, 'upsert');
            if ($id) $this->changeNotifications($saved[0], $participants, $title, $occurrences[0][0],$timezone,count($saved));
            $this->db->commit();
            return ['event' => $this->event($saved[0], $facilityScope), 'occurrences' => count($saved), 'series_updated'=>$id&&$editScope!=='single', 'conflicts' => array_values($allConflicts)];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function cancel(int $id, int $userId, ?array $facilityScope, string $scope='single'): int
    {
        return $this->writeLocked(fn():int=>$this->cancelLocked($id,$userId,$facilityScope,$scope));
    }

    private function cancelLocked(int $id, int $userId, ?array $facilityScope, string $scope): int
    {
        $event = $this->event($id, $facilityScope);
        if (!$event) throw new RuntimeException('The calendar event was not found.', 404);
        if ($event['status'] === 'cancelled') return 0;
        $scope=!empty($event['series_uid'])&&in_array($scope,['following','series'],true)?$scope:'single';
        $events=$scope==='single'?[$event]:$this->seriesEvents((string)$event['series_uid'],$scope==='following'?(string)$event['start_at']:null,$facilityScope);
        $events=array_values(array_filter($events,static fn(array$item):bool=>$item['status']!=='cancelled'));
        if(!$events)return 0;
        $this->db->beginTransaction();
        try {
            $participants=[];
            foreach($events as$item){$eventId=(int)$item['id'];$this->db->prepare("UPDATE calendar_events SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$eventId]);$this->db->prepare("UPDATE calendar_reminders SET status='cancelled' WHERE event_id=? AND status='pending'")->execute([$eventId]);foreach($item['participant_ids']as$participant)$participants[(int)$participant]=true;$this->queueSync($eventId,'delete');}
            $title=rtrim($this->text('messages.cancelled','Event cancelled'),'. ').': '.$event['title'];
            $message=count($events)>1?$this->text('messages.events_cancelled','{count} calendar events cancelled.',['count'=>count($events)]):$this->displayTime($event['start_at'],$event['timezone']);
            foreach(array_keys($participants)as$participant)$this->notification($participant,$id,$title,$message,'/calendar?event='.$id);
            $this->audit($userId, 'calendar.event.cancelled', $id, ['scope'=>$scope,'occurrences'=>count($events)]);
            $this->db->commit();
            return count($events);
        } catch (Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function saveResource(array $input, int $userId, ?array $facilityScope): array
    {
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 150);
        if (mb_strlen($name) < 2) throw new RuntimeException('Resource name must contain at least two characters.');
        $type = in_array($input['resource_type'] ?? '', ['room', 'equipment', 'vehicle', 'facility'], true) ? (string) $input['resource_type'] : 'room';
        $facilityId = max(0, (int) ($input['facility_id'] ?? 0));
        if (!$this->facilityAllowed($facilityId, $facilityScope) || (!$facilityId && $facilityScope !== null) || ($facilityId && !$this->exists('facilities', $facilityId))) throw new RuntimeException('Choose an available facility.');
        $capacity = max(0, min(100000, (int) ($input['capacity'] ?? 0)));
        $color = preg_match('/^#[0-9a-f]{6}$/iD', (string) ($input['color'] ?? '')) ? strtolower((string) $input['color']) : '#0f9f6e';
        $this->db->prepare('INSERT INTO calendar_resources (facility_id,resource_type,name,description,capacity,color,active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW())')->execute([$facilityId ?: null, $type, $name, mb_substr(trim((string) ($input['description'] ?? '')), 0, 500) ?: null, $capacity ?: null, $color]);
        $id = (int) $this->db->lastInsertId();
        $this->audit($userId, 'calendar.resource.created', $id, ['type' => $type, 'facility_id' => $facilityId ?: null]);
        return ['id' => $id, 'name' => $name, 'resource_type' => $type, 'facility_id' => $facilityId ?: null, 'capacity' => $capacity ?: null, 'color' => $color];
    }

    public function notifications(int $userId, int $limit = 40): array
    {
        $statement = $this->db->prepare('SELECT id,event_id,channel,title,message,action_url,read_at,created_at FROM calendar_notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ?');
        $statement->bindValue(1, $userId, PDO::PARAM_INT); $statement->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT); $statement->execute();
        return $statement->fetchAll();
    }

    public function readNotifications(int $userId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return 0;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("UPDATE calendar_notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND id IN ({$marks})");
        $statement->execute([$userId, ...$ids]);
        return $statement->rowCount();
    }

    public function deliveryStatus():array
    {
        $result=['eligible'=>0,'review'=>0,'sync_failed'=>0,'recent'=>[]];
        if($this->tableExists('notification_deliveries')){
            $statement=$this->db->query("SELECT status,COUNT(*) total FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.source'))='calendar' GROUP BY status");
            foreach($statement->fetchAll()as$row){$status=(string)$row['status'];$count=(int)$row['total'];if($status==='skipped')$result['eligible']+=$count;if(in_array($status,['unknown','processing'],true))$result['review']+=$count;}
            $recent=$this->db->query("SELECT id,plugin_slug,status,last_error,updated_at FROM notification_deliveries WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.source'))='calendar' AND status IN ('skipped','unknown','processing') ORDER BY updated_at DESC,id DESC LIMIT 20")->fetchAll();
            foreach($recent as&$row)$row['id']=(int)$row['id'];unset($row);$result['recent']=$recent;
        }
        $result['sync_failed']=(int)$this->db->query("SELECT COUNT(*) FROM calendar_sync_jobs WHERE status='failed'")->fetchColumn();
        $result['review']+=(int)$this->db->query("SELECT COUNT(*) FROM calendar_reminders WHERE status='failed'")->fetchColumn();
        return$result;
    }

    public function retrySafeDeliveries(int$userId):array
    {
        $this->db->beginTransaction();
        try{
            $notifications=0;if($this->tableExists('notification_deliveries')){$statement=$this->db->prepare("UPDATE notification_deliveries SET status='pending',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE status='skipped' AND updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.source'))='calendar'");$statement->execute();$notifications=$statement->rowCount();}
            $sync=(int)$this->db->exec("UPDATE calendar_sync_jobs SET status='pending',attempts=0,last_error=NULL,available_at=UTC_TIMESTAMP(),locked_at=NULL WHERE status='failed'");
            $this->audit($userId,'calendar.delivery.retry',0,['notifications'=>$notifications,'sync'=>$sync]);$this->db->commit();return['notifications'=>$notifications,'sync'=>$sync];
        }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function conflictsForInput(array $input, ?array $facilityScope): array
    {
        $timezone = $this->timezone((string) ($input['timezone'] ?? 'UTC'));
        [$start, $end] = $this->inputTimes($input,$timezone,!empty($input['all_day']));
        $id=max(0,(int)($input['id']??0));$exclude=$id?[$id]:[];
        if($id&&in_array($input['edit_scope']??'', ['following','series'],true)){
            $existing=$this->event($id,$facilityScope);
            if($existing&&!empty($existing['series_uid']))$exclude=array_map(static fn(array$event):int=>(int)$event['id'],$this->seriesEvents((string)$existing['series_uid'],($input['edit_scope']??'')==='following'?(string)$existing['start_at']:null,$facilityScope));
        }
        return $this->conflicts($start, $end, $this->validIds((array) ($input['participant_ids'] ?? []), 'users'), $this->validResources((array) ($input['resource_ids'] ?? []), max(0, (int) ($input['facility_id'] ?? 0)), $facilityScope), $exclude, $facilityScope);
    }

    private function conflicts(string $start, string $end, array $participants, array $resources, array $excludeIds, ?array $facilityScope): array
    {
        $where = ['e.status<>"cancelled"', 'e.start_at<?', 'e.end_at>?']; $parameters = [$end, $start];
        $excludeIds=array_values(array_unique(array_filter(array_map('intval',$excludeIds))));
        if ($excludeIds) { $where[] = 'e.id NOT IN ('.implode(',',array_fill(0,count($excludeIds),'?')).')'; array_push($parameters,...$excludeIds); }
        $conditions = [];
        if ($participants) { $marks = implode(',', array_fill(0, count($participants), '?')); $conditions[] = "EXISTS(SELECT 1 FROM calendar_event_participants ep WHERE ep.event_id=e.id AND ep.user_id IN ({$marks}))"; array_push($parameters, ...$participants); }
        if ($resources) { $marks = implode(',', array_fill(0, count($resources), '?')); $conditions[] = "EXISTS(SELECT 1 FROM calendar_event_resources er WHERE er.event_id=e.id AND er.resource_id IN ({$marks}))"; array_push($parameters, ...$resources); }
        if (!$conditions) return [];
        $where[] = '(' . implode(' OR ', $conditions) . ')';
        // A participant cannot be booked at two facilities simultaneously. Details outside visibility are redacted below.
        $statement = $this->db->prepare('SELECT e.id,e.title,e.start_at,e.end_at,(SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ", ") FROM calendar_event_participants ep INNER JOIN users u ON u.id=ep.user_id WHERE ep.event_id=e.id' . ($participants ? ' AND ep.user_id IN (' . implode(',', array_fill(0, count($participants), '?')) . ')' : '') . ') participant_conflicts,(SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ") FROM calendar_event_resources er INNER JOIN calendar_resources r ON r.id=er.resource_id WHERE er.event_id=e.id' . ($resources ? ' AND er.resource_id IN (' . implode(',', array_fill(0, count($resources), '?')) . ')' : '') . ') resource_conflicts FROM calendar_events e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.start_at LIMIT 100');
        $tail = [...$participants, ...$resources, ...$parameters];
        $statement->execute($tail);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $reasons = [];
            if ($row['participant_conflicts']) $reasons[] = 'Participants: ' . $row['participant_conflicts'];
            if ($row['resource_conflicts']) $reasons[] = 'Resources: ' . $row['resource_conflicts'];
            if (!$this->event((int) $row['id'], $facilityScope)) { $row['title'] = 'Unavailable time'; $reasons = ['A selected participant or resource is already booked.']; }
            $result[] = ['key' => $row['id'] . ':' . implode('|', $reasons), 'event_id' => (int) $row['id'], 'title' => $row['title'], 'start_at' => $row['start_at'], 'end_at' => $row['end_at'], 'reasons' => $reasons, 'severity' => 'hard'];
        }
        return $result;
    }

    private function syncRelations(int $eventId, array $participants, array $resources): void
    {
        $this->db->prepare('DELETE FROM calendar_event_participants WHERE event_id=?')->execute([$eventId]);
        $participant = $this->db->prepare('INSERT INTO calendar_event_participants (event_id,user_id,participant_role,response_status) VALUES (?,? ,"required","pending")');
        foreach ($participants as $userId) $participant->execute([$eventId, $userId]);
        $this->db->prepare('DELETE FROM calendar_event_resources WHERE event_id=?')->execute([$eventId]);
        $resource = $this->db->prepare('INSERT INTO calendar_event_resources (event_id,resource_id) VALUES (?,?)');
        foreach ($resources as $resourceId) $resource->execute([$eventId, $resourceId]);
    }

    private function syncReminders(int $eventId, string $start, array $offsets, array $channels): void
    {
        $statement = $this->db->prepare('INSERT INTO calendar_reminders (event_id,offset_minutes,channel,scheduled_at,status,created_at) VALUES (?,?,?,?,"pending",NOW()) ON DUPLICATE KEY UPDATE scheduled_at=VALUES(scheduled_at),status="pending",attempts=0,last_error=NULL,sent_at=NULL');
        $eventStart = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        foreach ($offsets as $offset) foreach ($channels as $channel) {
            $scheduled = $eventStart->sub(new DateInterval('PT' . $offset . 'M'))->format('Y-m-d H:i:s');
            $statement->execute([$eventId, $offset, $channel, $scheduled]);
        }
    }

    private function changeNotifications(int $eventId, array $participants, string $title, string $start,string $timezone,int $count=1): void
    {
        $noticeTitle=$this->text('messages.series_updated','Schedule changed: '.$title,['count'=>$count]);
        if($count===1)$noticeTitle='Schedule changed: '.$title;
        $message='New start: '.$this->displayTime($start,$timezone);
        foreach ($participants as $participant) $this->notification($participant, $eventId, $noticeTitle, $message, '/calendar?event=' . $eventId);
    }

    private function notification(int $userId, ?int $eventId, string $title, string $message, string $url): void
    {
        $this->db->prepare('INSERT INTO calendar_notifications (user_id,event_id,channel,title,message,action_url,created_at) VALUES (?,? ,"internal",?,?,?,NOW())')->execute([$userId, $eventId, mb_substr($title, 0, 180), mb_substr($message, 0, 1000), $url]);
        \App\Core\SystemNotifications::record($this->db,'notice:'.$this->db->lastInsertId(),$userId,'calendar',(string)$eventId,$title,$message,$url);
    }

    private function reminders(int $eventId): array
    {
        $statement = $this->db->prepare('SELECT offset_minutes,channel,scheduled_at,status FROM calendar_reminders WHERE event_id=? ORDER BY offset_minutes,channel');
        $statement->execute([$eventId]);
        return $statement->fetchAll();
    }

    private function eventRow(array $row): array
    {
        $row['id'] = (int) $row['id']; $row['facility_id'] = $row['facility_id'] === null ? null : (int) $row['facility_id']; $row['owner_id'] = (int) $row['owner_id']; $row['all_day'] = (bool) $row['all_day'];
        $row['participant_ids'] = $row['participant_ids'] ? array_values(array_map('intval', explode(',', (string) $row['participant_ids']))) : [];
        $row['resource_ids'] = $row['resource_ids'] ? array_values(array_map('intval', explode(',', (string) $row['resource_ids']))) : [];
        try{$zone=new DateTimeZone((string)$row['timezone']);$start=(new DateTimeImmutable((string)$row['start_at'],new DateTimeZone('UTC')))->setTimezone($zone);$end=(new DateTimeImmutable((string)$row['end_at'],new DateTimeZone('UTC')))->setTimezone($zone);$row['local_start_at']=$start->format('Y-m-d\TH:i:s');$row['local_end_at']=$end->format('Y-m-d\TH:i:s');$row['start_date']=$start->format('Y-m-d');$row['end_date']=$row['all_day']?$end->modify('-1 day')->format('Y-m-d'):$end->format('Y-m-d');}catch(Throwable){$row['local_start_at']=str_replace(' ','T',(string)$row['start_at']);$row['local_end_at']=str_replace(' ','T',(string)$row['end_at']);$row['start_date']=substr((string)$row['start_at'],0,10);$row['end_date']=substr((string)$row['end_at'],0,10);}
        return $row;
    }

    private function range(string $from, string $to): array
    {
        try { $start = new DateTimeImmutable($from ?: 'first day of this month 00:00:00', new DateTimeZone('UTC')); $end = new DateTimeImmutable($to ?: 'first day of next month 00:00:00', new DateTimeZone('UTC')); }
        catch (Throwable) { throw new RuntimeException('Choose a valid calendar range.'); }
        if ($end <= $start || $end > $start->add(new DateInterval('P2Y'))) throw new RuntimeException('Calendar range must be positive and no longer than two years.');
        return [$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    private function times(string $start, string $end, string $timezone): array
    {
        try {
            $zone = new DateTimeZone($timezone);
            $parse = static function(string $value) use ($zone): DateTimeImmutable {
                $value = str_replace('T', ' ', $value); if (strlen($value) === 16) $value .= ':00';
                $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
                if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('Invalid local date.');
                return $date;
            };
            $from = $parse($start); $to = $parse($end);
        }
        catch (Throwable) { throw new RuntimeException('Choose a valid start and end time.'); }
        if ($to <= $from || $to > $from->add(new DateInterval('P31D'))) throw new RuntimeException('Event end must follow its start and remain within 31 days.');
        $utc = new DateTimeZone('UTC');
        return [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function inputTimes(array $input,string $timezone,bool $allDay):array
    {
        if(!$allDay)return$this->times((string)($input['start_at']??''),(string)($input['end_at']??''),$timezone);
        $start=(string)($input['start_date']??substr((string)($input['start_at']??''),0,10));$end=(string)($input['end_date']??substr((string)($input['end_at']??''),0,10));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end))throw new RuntimeException('Choose valid all-day dates.');
        $zone=new DateTimeZone($timezone);$from=DateTimeImmutable::createFromFormat('!Y-m-d',$start,$zone);$inclusive=DateTimeImmutable::createFromFormat('!Y-m-d',$end,$zone);
        if(!$from||!$inclusive||$from->format('Y-m-d')!==$start||$inclusive->format('Y-m-d')!==$end||$inclusive<$from)throw new RuntimeException('Choose valid all-day dates.');
        $to=$inclusive->add(new DateInterval('P1D'));if($to>$from->add(new DateInterval('P31D')))throw new RuntimeException('Event end must follow its start and remain within 31 days.');$utc=new DateTimeZone('UTC');
        return[$from->setTimezone($utc)->format('Y-m-d H:i:s'),$to->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function recurrenceUntil(string $repeat, string $value, string $start, string $timezone): ?DateTimeImmutable
    {
        if ($repeat === 'none') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) throw new RuntimeException('Choose the final date for the recurring event.');
        $until = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone));
        $first = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        if (!$until || $until->format('Y-m-d') !== $value) throw new RuntimeException('Choose a valid final date.');
        if (!$until || $until < $first->setTime(0, 0) || $until > $first->add(new DateInterval('P2Y'))) throw new RuntimeException('Recurring events may cover up to two years.');
        return $until;
    }

    private function occurrences(string $start, string $end, string $repeat, ?DateTimeImmutable $until, string $timezone): array
    {
        $utc = new DateTimeZone('UTC'); $zone = new DateTimeZone($timezone);
        $first = (new DateTimeImmutable($start, $utc))->setTimezone($zone); $last = (new DateTimeImmutable($end, $utc))->setTimezone($zone); $items = [[$start, $end]];
        if ($repeat === 'none' || !$until) return $items;
        for ($index = 1; $index <= 732; $index++) {
            if ($repeat === 'monthly') {
                $month = $first->modify('first day of this month')->modify('+' . $index . ' months');
                if ((int) $first->format('d') > (int) $month->format('t')) continue;
                $from = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), (int)$first->format('d'));
                $to = $from->add($first->diff($last));
            } else {
                $interval = new DateInterval('P' . ($index * ($repeat === 'weekly' ? 7 : 1)) . 'D');
                $from = $first->add($interval); $to = $last->add($interval);
            }
            if ($from > $until->setTime(23, 59, 59)) break;
            $pair = [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s')];
            if ($pair[0] < $items[count($items)-1][1]) throw new RuntimeException('Recurring occurrences overlap each other.');
            $items[] = $pair;
            if (count($items) > 499) throw new RuntimeException('A recurring series may contain at most 499 occurrences.');
        }
        if (count($items) >= 500) throw new RuntimeException('A recurring series may contain at most 499 occurrences.');
        return $items;
    }

    private function reminderOffsets(array $values): array
    {
        foreach ($values as $value) if (!is_int($value) && !(is_string($value) && ctype_digit($value))) throw new RuntimeException('Choose valid reminder times.');
        $allowed = array_column($this->allowedReminderOffsets(), 'value');
        $offsets = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $value): bool => in_array($value, $allowed, true))));
        sort($offsets);
        if (count($offsets) !== count(array_unique($values)) || count($offsets) > 5) throw new RuntimeException('Choose up to five supported reminder times.');
        return $offsets;
    }

    private function allowedReminderOffsets(): array
    {
        $values = [['value' => 0, 'label' => 'At event time']];
        for ($minutes = 5; $minutes <= 55; $minutes += 5) $values[] = ['value' => $minutes, 'label' => $minutes . ' minutes before'];
        for ($hours = 1; $hours <= 23; $hours++) $values[] = ['value' => $hours * 60, 'label' => $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') . ' before'];
        for ($days = 1; $days <= 15; $days++) $values[] = ['value' => $days * 1440, 'label' => $days . ' ' . ($days === 1 ? 'day' : 'days') . ' before'];
        return $values;
    }

    private function validIds(array $values, string $table): array
    {
        if ($table !== 'users') return [];
        if (count($values) > 100) throw new RuntimeException('Choose at most 100 participants.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $values))));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("SELECT id FROM users WHERE active=1 AND id IN ({$marks}) ORDER BY id");
        $statement->execute($ids);
        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function validResources(array $values, int $facilityId, ?array $facilityScope): array
    {
        if (count($values) > 100) throw new RuntimeException('Choose at most 100 resources.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $values))));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare("SELECT id,facility_id FROM calendar_resources WHERE active=1 AND id IN ({$marks})");
        $statement->execute($ids); $result = [];
        foreach ($statement->fetchAll() as $row) {
            $resourceFacility = (int) ($row['facility_id'] ?? 0);
            if (!$this->facilityAllowed($resourceFacility, $facilityScope) || ($facilityId && $resourceFacility && $resourceFacility !== $facilityId)) throw new RuntimeException('A selected resource is unavailable for this facility.');
            $result[] = (int) $row['id'];
        }
        if (count($result) !== count($ids)) throw new RuntimeException('A selected calendar resource is unavailable.');
        sort($result); return $result;
    }

    private function seriesEvents(string$seriesUid,?string$from,?array$facilityScope):array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$seriesUid))return[];$sql='SELECT e.*,COALESCE(c.facility_slug,"") facility_slug,(SELECT GROUP_CONCAT(ep.user_id ORDER BY ep.user_id) FROM calendar_event_participants ep WHERE ep.event_id=e.id) participant_ids,(SELECT GROUP_CONCAT(er.resource_id ORDER BY er.resource_id) FROM calendar_event_resources er WHERE er.event_id=e.id) resource_ids FROM calendar_events e LEFT JOIN facilities c ON c.id=e.facility_id WHERE e.series_uid=? AND e.status<>"cancelled"';$values=[$seriesUid];if($from!==null){$sql.=' AND e.start_at>=?';$values[]=$from;}$sql.=' ORDER BY e.start_at,e.id';$statement=$this->db->prepare($sql);$statement->execute($values);$events=[];foreach($statement->fetchAll()as$row)if($this->facilityAllowed((int)($row['facility_id']??0),$facilityScope))$events[]=$this->eventRow($row);return$events;
    }

    private function shiftedSeriesAnchor(string$firstUtc,string$selectedUtc,string$newStartUtc,string$newEndUtc,string$timezone):array
    {
        $utc=new DateTimeZone('UTC');$zone=new DateTimeZone($timezone);$first=(new DateTimeImmutable($firstUtc,$utc))->setTimezone($zone);$selected=(new DateTimeImmutable($selectedUtc,$utc))->setTimezone($zone);$newStart=(new DateTimeImmutable($newStartUtc,$utc))->setTimezone($zone);$newEnd=(new DateTimeImmutable($newEndUtc,$utc))->setTimezone($zone);$days=(int)$selected->setTime(0,0)->diff($newStart->setTime(0,0))->format('%r%a');$anchor=$first->modify(($days>=0?'+':'').$days.' days')->setTime((int)$newStart->format('H'),(int)$newStart->format('i'),(int)$newStart->format('s'));$anchorEnd=$anchor->add($newStart->diff($newEnd));return[$anchor->setTimezone($utc)->format('Y-m-d H:i:s'),$anchorEnd->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function scope(array &$where, array &$parameters, ?array $scope, string $column): void
    {
        if ($scope === null) return;
        if (!$scope) { $where[] = '1=0'; return; }
        $where[] = '(' . $column . ' IS NULL OR ' . $column . ' IN (' . implode(',', array_fill(0, count($scope), '?')) . '))';
        array_push($parameters, ...array_map('intval', $scope));
    }

    private function facilityAllowed(int $facilityId, ?array $scope): bool { return $scope === null || ($scope !== [] && (!$facilityId || in_array($facilityId, $scope, true))); }
    private function exists(string $table, int $id): bool { if ($table !== 'facilities') return false; $statement = $this->db->prepare('SELECT 1 FROM facilities WHERE id=? AND status<>"archived"'); $statement->execute([$id]); return (bool) $statement->fetchColumn(); }
    private function tableExists(string$table):bool{$statement=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$statement->execute([$table]);return(bool)$statement->fetchColumn();}
    private function writeLocked(callable $action): mixed
    {
        $name = 'sensecms.calendar.' . hash('sha256', (string)$this->db->query('SELECT DATABASE()')->fetchColumn());
        $name = substr($name, 0, 64);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,5)'); $lock->execute([$name]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Calendar is busy. Please try again.', 409);
        try { return $action(); } finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }
    private function timezone(string $value): string { try { return (new DateTimeZone($value))->getName(); } catch (Throwable) { throw new RuntimeException('Choose a valid time zone.'); } }
    private function displayTime(string $utc, string $timezone): string { try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i'); } catch (Throwable) { return $utc; } }
    private function text(string$key,string$fallback,array$replace=[]):string{$text=$this->translate?($this->translate)($key,$replace):$fallback;return is_string($text)&&$text!==$key?$text:strtr($fallback,array_combine(array_map(static fn(string$name):string=>'{'.$name.'}',array_keys($replace)),array_map('strval',array_values($replace)))?:[]);}
    private function uuid(): string { $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)); }
    private function audit(int $userId, string $event, int $subjectId, array $context): void { $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,? ,"calendar_event",?,?,NOW())')->execute([$userId ?: null, $event, $subjectId, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]); }
    private function queueSync(int $eventId, string $action): void { $this->db->prepare('INSERT INTO calendar_sync_jobs (event_id,action,status,available_at,created_at) VALUES (?,? ,"pending",NOW(),NOW())')->execute([$eventId, $action]); }
}
