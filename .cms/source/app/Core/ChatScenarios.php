<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class ChatScenarios
{
    private const PREFIX = 'chat-scenario-';

    public function __construct(private readonly PDO $db) {}

    public function rules(string $locale): array
    {
        $query = $this->db->prepare('SELECT value FROM settings WHERE settings.key LIKE ? ORDER BY settings.key');
        $query->execute([self::PREFIX . $locale . ':%']);
        return array_map(static fn(string $row): array => json_decode($row, true, 32, JSON_THROW_ON_ERROR), $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function sources(string $locale): array
    {
        $query = $this->db->prepare("SELECT id,title FROM ai_knowledge_documents WHERE status='published' AND index_status='ready' AND (locale=? OR locale IS NULL) AND source_type IN ('manual','page','post') ORDER BY title,id");
        $query->execute([$locale]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $input, array $locales, int $userId): void
    {
        $locale = (string) ($input['locale'] ?? '');
        $id = (string) ($input['id'] ?? '');
        if (!in_array($locale, $locales, true) || !preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})?$/iD',$locale)
            || ($id !== '' && !preg_match('/^[a-f0-9]{16}$/D',$id))) throw new RuntimeException('Invalid scenario or language.');
        $new = $id === '';
        if ($new) $id = bin2hex(random_bytes(8));
        $delete = ($input['operation'] ?? '') === 'delete';
        $key = self::PREFIX . $locale . ':' . $id;
        $this->db->beginTransaction();
        try {
            // Serialize name changes per language, including two submissions of the same new form.
            $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $this->db->prepare($mysql
                ? 'INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)'
                : 'INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT (key) DO UPDATE SET key=EXCLUDED.key'
            )->execute(['chat-scenarios-lock:'.$locale,'{}']);
            if (!$new) {
                $query = $this->db->prepare('SELECT value FROM settings WHERE settings.key=? FOR UPDATE');
                $query->execute([$key]);
                $row = json_decode((string)$query->fetchColumn(), true);
                if (!$row || ($row['revision'] ?? null) !== ($input['revision'] ?? '')) throw new RuntimeException('This scenario changed. Reload it before saving.');
            }
            if ($delete) {
                if ($new) throw new RuntimeException('Select a saved scenario to delete.');
                $this->db->prepare('DELETE FROM settings WHERE settings.key=?')->execute([$key]);
            } else {
                $rule = $this->validate($input, $locale) + ['id'=>$id,'revision'=>bin2hex(random_bytes(12))];
                foreach ($this->rules($locale) as $other) {
                    if ($other['id']!==$id && self::words($other['name'])===self::words($rule['name'])) {
                        throw new RuntimeException('Choose a distinct scenario name in this language.');
                    }
                }
                $encoded = json_encode($rule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if ($new) {
                    $column = $mysql ? '`key`' : 'key';
                    $this->db->prepare("INSERT INTO settings ($column,value) VALUES (?,?)")->execute([$key,$encoded]);
                } else {
                    $this->db->prepare('UPDATE settings SET value=? WHERE settings.key=?')->execute([$encoded,$key]);
                }
            }
            (new CmsRepository($this->db,new EventBus()))->recordActivity($userId,$delete?'chat_scenario.deleted':'chat_scenario.saved','chat_scenario',['id'=>$id,'locale'=>$locale]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function validate(array $input, string $locale): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $action = (string)($input['action'] ?? '');
        $questions = array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',(string)($input['questions'] ?? '')) ?: []),'strlen')));
        $text = trim((string)($input['text'] ?? ''));
        $until = trim((string)($input['until'] ?? ''));
        if (self::words($name) === '' || mb_strlen($name)>100 || !$questions || count($questions)>30
            || !in_array($action,['source','clarify','contact','human'],true)) throw new RuntimeException('Enter a name, an action and 1–30 example questions.');
        foreach ($questions as $question) {
            if (mb_strlen($question)>240 || self::words($question)==='') throw new RuntimeException('Use questions of up to 240 characters.');
        }
        if (mb_strlen($text)>1000 || ($action==='clarify' && $text==='')) throw new RuntimeException('Enter a clarification of up to 1,000 characters.');
        if ($until !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$until,$d) || !checkdate((int)$d[2],(int)$d[3],(int)$d[1]))) throw new RuntimeException('Invalid last valid date.');
        $source = $action==='source' ? (int)($input['source'] ?? 0) : 0;
        if ($action==='source' && $this->source($source,$locale)===null) throw new RuntimeException('Choose an approved short source or a public page or post with a short excerpt in this language.');
        return ['name'=>$name,'questions'=>$questions,'action'=>$action,'source'=>$source,'text'=>$text,'until'=>$until];
    }

    private static function words(string $value): string
    {
        $value = strtr(mb_strtolower($value),['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
        $value=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$value));
        return trim((string)preg_replace('/^(?:dzien dobry|hello|please) | (?:prosze|please)$/u','',$value));
    }

    /** Allow one typo in a longer word. Preserve word order, short words, numbers and negation. */
    private function near(string $phrase,string $example): bool
    {
        $words=explode(' ',$phrase);
        $other=explode(' ',$example);
        if (count($words)!==count($other)) return false;
        $changes=0;
        foreach ($words as $i=>$word) {
            if ($word===$other[$i]) continue;
            if (++$changes>1 || !preg_match('/^[a-z]{6,}$/D',$word) || !preg_match('/^[a-z]{6,}$/D',$other[$i])
                || str_starts_with($word,'nie')!==str_starts_with($other[$i],'nie') || levenshtein($word,$other[$i])!==1) return false;
        }
        return $changes===1;
    }

    public function match(string $message,string $locale,array $history): ?array
    {
        $phrase = self::words($message);
        if ($phrase==='') return null;
        $matches = $near = [];
        $named=null;
        foreach ($this->rules($locale) as $rule) {
            if (self::words($rule['name'])===$phrase) $named=$rule;
            foreach ([$rule['name'],...$rule['questions']] as $question) {
                $example=self::words($question);
                if ($example===$phrase) { $matches[$rule['id']]=$rule; break; }
                if ($this->near($phrase,$example)) $near[$rule['id']]=$rule;
            }
        }
        if ($named!==null) $matches=[$named['id']=>$named];
        if (!$matches) $matches=$near;
        if (!$matches) return null;
        if (count($matches)>1) {
            // Only the immediately preceding assistant answer can disambiguate a shared phrase.
            $previous=end($history);
            $marker=(string)($previous['provider_slug']??'');
            $id=str_starts_with($marker,self::PREFIX)?substr($marker,strlen(self::PREFIX)):'';
            if (($previous['role']??'')!=='assistant' || !isset($matches[$id])) {
                $choices=implode(' / ',array_column($matches,'name'));
                return ['action'=>'clarify','message'=>($locale==='pl'?'Którą sprawę masz na myśli? ':'Which of these do you mean? ').$choices,'marker'=>'core'];
            }
            $rule=$matches[$id];
        } else {
            $rule=reset($matches);
        }
        $text=$rule['text'];
        $expired=$rule['until']!=='' && $rule['until']<gmdate('Y-m-d');
        if (!$expired && $rule['action']==='source') {
            $source=$this->source($rule['source'],$locale);
            $text=$source===null ? null : $source['content'].($source['url']!=='' ? "\n\nSource: ".$source['url'] : '');
        }
        if ($expired || $text===null) return ['action'=>'clarify','message'=>$locale==='pl'?'Brak aktualnej, potwierdzonej informacji w tej sprawie. Możesz poprosić o kontakt z zespołem.':'There is no current confirmed information on this topic. You can ask to speak to the team.','marker'=>'core'];
        return ['action'=>$rule['action'],'message'=>$text,'marker'=>self::PREFIX.$rule['id']];
    }

    /** Apply the same source lifecycle when an unfamiliar phrase falls through to generative retrieval. */
    public function currentKnowledge(array $matches,string $locale): array
    {
        if (!$matches) return [];
        $available=[];
        foreach ($this->rules($locale) as $rule) {
            if ($rule['action']!=='source') continue;
            $id=$rule['source'];
            $available[$id]=($available[$id]??false) || $rule['until']==='' || $rule['until']>=gmdate('Y-m-d');
        }
        $result=[];
        $seen=[];
        $documents=[];
        foreach ($matches as $match) {
            $id=(int)$match['document_id'];
            if (!isset($available[$id])) {
                if (!array_key_exists($id,$documents)) $documents[$id]=$this->currentDocument($id,$locale,false);
                if ($documents[$id]!==null) $result[]=$match;
            } elseif ($available[$id] && !isset($seen[$id])) {
                $seen[$id]=true;
                $source=$this->source($id,$locale);
                if ($source!==null) $result[]=array_replace($match,$source);
            }
        }
        return $result;
    }

    private function source(int $id,string $locale): ?array
    {
        $doc=$this->currentDocument($id,$locale,true);
        if ($doc===null || !in_array($doc['source_type'],['manual','page','post'],true)) return null;
        $body=trim(html_entity_decode(strip_tags((string)$doc['body']),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if ($body==='' || mb_strlen($body)>1600) return null;
        $url=(string)$doc['source_url'];
        return ['content'=>$body,'url'=>preg_match('~^/(?!/)[^\s<>\\\\]*$~uD',$url)?$url:''];
    }

    private function currentDocument(int $id,string $locale,bool $withBody): ?array
    {
        $columns='source_type,source_id,source_url'.($withBody?',body':'');
        $query=$this->db->prepare("SELECT $columns FROM ai_knowledge_documents WHERE id=? AND status='published' AND index_status='ready' AND (locale=? OR locale IS NULL)");
        $query->execute([$id,$locale]);
        $doc=$query->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return null;
        if (in_array($doc['source_type'],['page','post'],true)) {
            $translation=$this->liveTranslation($doc,$locale);
            if ($translation===null) return null;
            if ($withBody) $doc['body']=$translation['excerpt'];
        } elseif ($doc['source_type']==='manual' && str_starts_with((string)$doc['source_url'],'/')) {
            // A curated FAQ may cite the same CMS URL as an indexed page or post.
            // Archived index rows still identify that target after a sync.
            $query=$this->db->prepare("SELECT source_type,source_id FROM ai_knowledge_documents
                WHERE source_url=? AND locale=? AND source_type IN ('page','post')");
            $query->execute([$doc['source_url'],$locale]);
            $targets=$query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($targets as $target) {
                if ($this->liveTranslation($target,$locale)!==null) return $doc;
            }
            if ($targets) return null;
        }
        return $doc;
    }

    private function liveTranslation(array $target,string $locale): ?array
    {
        $kind=$target['source_type'];
        $visibility=$kind==='page'?" AND p.visibility='public'":'';
        $query=$this->db->prepare("SELECT t.excerpt FROM {$kind}s p
            JOIN {$kind}_translations t ON t.{$kind}_id=p.id
            JOIN languages l ON l.locale=t.locale AND l.enabled=1
            JOIN facilities f ON f.id=p.facility_id
            WHERE p.id=? AND t.locale=? AND f.status='active' AND p.ai_knowledge_enabled=1
              AND p.status='published' AND (p.published_at IS NULL OR p.published_at<=CURRENT_TIMESTAMP)$visibility");
        $query->execute([$target['source_id'],$locale]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
