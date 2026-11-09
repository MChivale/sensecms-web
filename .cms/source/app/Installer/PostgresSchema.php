<?php

declare(strict_types=1);

namespace App\Installer;

use PDO;
use RuntimeException;

/** Converts the reviewed portable MySQL schema into PostgreSQL DDL for a new empty installation. */
final class PostgresSchema
{
    public static function prepare(PDO $db): void
    {
        $db->exec("CREATE OR REPLACE FUNCTION public.json_extract(document text,path text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN jsonb_path_query_first(document::jsonb,path::jsonpath) #>> '{}'");
        $db->exec("CREATE OR REPLACE FUNCTION public.json_set(document text,path text,replacement text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN jsonb_set(COALESCE(NULLIF(document,'')::jsonb,'{}'::jsonb), string_to_array(regexp_replace(path,'^\\$\\.',''),'.'), replacement::jsonb)::text");
        $db->exec("CREATE OR REPLACE FUNCTION public.json_merge_patch(overrides text,base text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN (COALESCE(base::jsonb,'{}'::jsonb) || COALESCE(overrides::jsonb,'{}'::jsonb))::text");
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $result=[];
        foreach (self::split($sql) as $statement) {
            $statement=trim($statement);
            if ($statement==='') continue;
            foreach (self::convert($statement) as $converted) $result[]=$converted;
        }
        return $result;
    }

    /** @return list<string> */
    private static function convert(string $statement): array
    {
        $statement=preg_replace('/\s+ENGINE\s*=\s*\w+\s*(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=\s*\w+(?:\s+COLLATE\s*=\s*\w+)?\s*$/i','',$statement)??$statement;
        $statement=preg_replace('/\s+(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=\s*\w+(?:\s+COLLATE\s*=\s*\w+)?\s*$/i','',$statement)??$statement;
        $statement=preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i','',$statement)??$statement;
        $statement=preg_replace('/\s+AFTER\s+`?[a-z][a-z0-9_]*`?/i','',$statement)??$statement;
        $statement=preg_replace('/\s+FIRST\b/i','',$statement)??$statement;
        $statement=preg_replace('/\s+(?:CHARACTER\s+SET|CHARSET)\s+\w+/i','',$statement)??$statement;
        $statement=preg_replace('/\s+COLLATE\s+\w+/i','',$statement)??$statement;
        $statement=self::types($statement);
        if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-z][a-z0-9_]*)`?\s*\((.*)\)$/is',$statement,$match)) {
            return self::createTable($match[1],$match[2]);
        }
        if (preg_match('/^ALTER\s+TABLE\s+`?([a-z][a-z0-9_]*)`?\s+(.+)$/is',$statement,$match)) return self::alter($match[1],$match[2]);
        return [$statement];
    }

    /** @return list<string> */
    private static function createTable(string $table,string $body): array
    {
        $parts=self::parts($body);$columns=[];$indexes=[];
        foreach ($parts as $part) {
            $trim=trim($part);
            if ($table === 'ai_knowledge_chunks') $trim=preg_replace('/^embedding\s+TEXT\b/i','embedding vector(1536)',$trim)??$trim;
            if (preg_match('/^(?:INDEX|KEY)\s*(?:`?([a-z][a-z0-9_]*)`?)?\s*\((.+)\)$/is',$trim,$match)) {$indexes[]=self::index($table,$match[1]?:self::anonymousIndex($match[2]),$match[2]);continue;}
            if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?([a-z][a-z0-9_]*)`?\s*\((.+)\)$/is',$trim,$match)) {$indexes[]=self::index($table,$match[1],$match[2],true);continue;}
            $columns[]=$trim;
        }
        if (!$columns) throw new RuntimeException('PostgreSQL schema conversion produced an empty table.');
        return array_merge(['CREATE TABLE IF NOT EXISTS '.$table.' ('.implode(',',$columns).')'],$indexes);
    }

    private static function index(string $table,string $name,string $columns,bool $unique=false): string
    {
        return 'CREATE '.($unique?'UNIQUE ':'').'INDEX IF NOT EXISTS '.self::indexName($table,$name).' ON '.$table.' ('.$columns.')';
    }

    /** @return list<string> */
    private static function alter(string $table,string $body): array
    {
        $result=[];
        foreach (self::parts($body) as $action) {
            $action=trim($action);
            if ($action==='' || preg_match('/^(?:ALGORITHM|LOCK)\s*=/i',$action)) continue;
            if (preg_match('/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z][a-z0-9_]*)`?\s*\((.+)\)$/is',$action,$match)) {
                $result[]='CREATE '.(!empty($match[1])?'UNIQUE ':'').'INDEX IF NOT EXISTS '.self::indexName($table,$match[2]).' ON '.$table.' ('.$match[3].')';
                continue;
            }
            if (preg_match('/^DROP\s+INDEX\s+(?:IF\s+EXISTS\s+)?`?([a-z][a-z0-9_]*)`?$/i',$action,$match)) {
                $result[]='DROP INDEX IF EXISTS '.self::indexName($table,$match[1]);
                continue;
            }
            if (preg_match('/^MODIFY(?:\s+COLUMN)?\s+`?([a-z][a-z0-9_]*)`?\s+(.+)$/is',$action,$match)) {
                $result=array_merge($result,self::modify($table,$match[1],$match[2]));
                continue;
            }
            $result[]='ALTER TABLE '.$table.' '.$action;
        }
        if (!$result) throw new RuntimeException('PostgreSQL schema conversion removed an entire ALTER TABLE statement.');
        return $result;
    }

    /** @return list<string> */
    private static function modify(string $table,string $column,string $definition): array
    {
        $definition=trim($definition);
        if (!preg_match('/^([A-Z]+(?:\s+PRECISION)?(?:\s*\([^)]*\))?)(.*)$/is',$definition,$match)) throw new RuntimeException('Unsupported PostgreSQL column modification.');
        $type=trim($match[1]);$rest=trim($match[2]);
        $result=['ALTER TABLE '.$table.' ALTER COLUMN '.$column.' TYPE '.$type];
        if (preg_match('/\bNOT\s+NULL\b/i',$rest)) $result[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' SET NOT NULL';
        elseif (preg_match('/\bNULL\b/i',$rest)) $result[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' DROP NOT NULL';
        if (preg_match('/\bDEFAULT\s+(.+)$/is',$rest,$default)) $result[]='ALTER TABLE '.$table.' ALTER COLUMN '.$column.' SET DEFAULT '.trim($default[1]);
        return $result;
    }

    private static function anonymousIndex(string $columns): string
    {
        return 'idx_'.substr(hash('sha256',$columns),0,16);
    }

    private static function indexName(string $table,string $name): string
    {
        return $table.'_'.$name;
    }

    private static function types(string $sql): string
    {
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\s+AUTO_INCREMENT\b/i','BIGINT GENERATED BY DEFAULT AS IDENTITY',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+UNSIGNED\s+AUTO_INCREMENT\b/i','INTEGER GENERATED BY DEFAULT AS IDENTITY',$sql)??$sql;
        $sql=preg_replace('/\bSMALLINT\s+UNSIGNED\s+AUTO_INCREMENT\b/i','SMALLINT GENERATED BY DEFAULT AS IDENTITY',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\b/i','BIGINT',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+UNSIGNED\b/i','INTEGER',$sql)??$sql;
        $sql=preg_replace('/\bSMALLINT\s+UNSIGNED\b/i','SMALLINT',$sql)??$sql;
        // Keep the historic 0/1 representation: distributed Core SQL compares these
        // flags to integers and PostgreSQL booleans reject those expressions.
        $sql=preg_replace('/\bTINYINT\s+UNSIGNED/i','SMALLINT',$sql)??$sql;
        $sql=preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i','SMALLINT',$sql)??$sql;
        $sql=preg_replace('/\bBOOLEAN\b/i','SMALLINT',$sql)??$sql;
        $sql=preg_replace('/\bTINYINT\b/i','SMALLINT',$sql)??$sql;
        $sql=preg_replace('/\s+UNSIGNED\b/i','',$sql)??$sql;
        $sql=preg_replace('/\b(?:LONGTEXT|MEDIUMTEXT)\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bDATETIME\b/i','TIMESTAMP',$sql)??$sql;
        // The application stores JSON as encoded text and its PostgreSQL compatibility
        // functions deliberately operate on text. Keep that contract for a new install.
        $sql=preg_replace('/\bJSON\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bDOUBLE\b/i','DOUBLE PRECISION',$sql)??$sql;
        $sql=preg_replace('/\bENUM\s*\((?:[^()]|\([^()]*\))*\)/i','VARCHAR(64)',$sql)??$sql;
        return $sql;
    }

    /** @return list<string> */
    private static function split(string $sql): array
    {
        $parts=[];$start=0;$quote='';$depth=0;
        for($i=0,$length=strlen($sql);$i<$length;$i++){
            $char=$sql[$i];
            if($quote!==''){if($char===$quote&&($i===0||$sql[$i-1]!=='\\'))$quote='';continue;}
            if($char==="'"||$char==='"'||$char==='`'){$quote=$char;continue;}
            if($char==='(')$depth++;elseif($char===')')$depth--;elseif($char===';'&&$depth===0){$parts[]=substr($sql,$start,$i-$start);$start=$i+1;}
        }
        $parts[]=substr($sql,$start);return $parts;
    }

    /** @return list<string> */
    private static function parts(string $sql): array
    {
        $parts=[];$start=0;$quote='';$depth=0;
        for($i=0,$length=strlen($sql);$i<$length;$i++){
            $char=$sql[$i];
            if($quote!==''){if($char===$quote&&($i===0||$sql[$i-1]!=='\\'))$quote='';continue;}
            if($char==="'"||$char==='"'||$char==='`'){$quote=$char;continue;}
            if($char==='(')$depth++;elseif($char===')')$depth--;elseif($char===','&&$depth===0){$parts[]=substr($sql,$start,$i-$start);$start=$i+1;}
        }
        $parts[]=substr($sql,$start);return $parts;
    }
}
