<?php
declare(strict_types=1);

namespace App\Core;

/** Adapts the read expressions in previously distributed Sense CMS packages.
 * PDO binds all values after this step. Never substitute bound parameters here.
 */
final class PostgresQuery
{
    // Use the logical key of each operation, never an arbitrary unique index.
    private const CONFLICT_KEYS = [
        'settings'=>'key', 'login_attempts'=>'bucket', 'languages'=>'locale', 'categories'=>'slug',
        'category_translations'=>'category_id,locale', 'facility_translations'=>'facility_id,locale',
        'post_translations'=>'post_id,locale', 'page_translations'=>'page_id,locale', 'post_tags'=>'locale,slug',
        'menus'=>'location', 'menu_item_translations'=>'menu_item_id,locale', 'forms'=>'block_uid',
        'content_block_translations'=>'block_id,locale', 'global_section_translations'=>'global_section_id,locale',
        'page_builder_states'=>'page_id', 'page_builder_drafts'=>'page_id,user_id', 'page_builder_clipboards'=>'user_id',
        'page_builder_section_workflow'=>'page_id,section_uid', 'page_builder_section_locks'=>'page_id,section_uid',
        'media_translations'=>'media_id,locale', 'media_variants'=>'media_id,name', 'media_tags'=>'slug',
        'ai_knowledge_documents'=>'source_key', 'ai_knowledge_sync_queue'=>'source_type,source_id',
        'live_chat_operator_presence'=>'user_id', 'live_chat_reads'=>'conversation_id,user_id', 'live_chat_teams'=>'slug',
        'notification_channel_settings'=>'plugin_slug,subject_type,subject_id', 'notification_cursors'=>'source',
        'notification_deliveries'=>'plugin_slug,user_id,event_key', 'notification_events'=>'event_key,user_id',
        'web_push_deliveries'=>'subscription_id,event_key', 'web_push_preferences'=>'user_id',
        'installed_themes'=>'slug', 'installed_plugins'=>'slug', 'extension_publishers'=>'key_id',
        'extension_packages'=>'type,slug', 'extension_migrations'=>'package_type,package_slug,migration_id',
        'marketplace_entries'=>'source_id,type,slug,version,release_channel', 'marketplace_sources'=>'catalog_url',
        'marketplace_submissions'=>'type,slug,version,publisher_key_id',
        'social_connections'=>'plugin_slug,external_account_id', 'calendar_reminders'=>'event_id,offset_minutes,channel',
        'calendar_deliveries'=>'reminder_id,user_id', 'calendar_external_events'=>'plugin_slug,account_key,external_id',
        'calendar_integration_settings'=>'plugin_slug,subject_type,subject_id',
    ];

    public static function translate(string $sql): string
    {
        if (strcasecmp(trim($sql, " \n\r\t;"), 'SHOW TABLES') === 0) {
            return 'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname=current_schema() ORDER BY tablename';
        }
        if (str_contains($sql, "\x1f")) throw new \InvalidArgumentException('Invalid SQL source.');
        $protected = [];
        $pattern = <<<'REGEX'
~'(?:''|\\.|[^'\\])*'|"(?:""|\\.|[^"\\])*"|`(?:``|[^`])*`|/\*.*?\*/|--[^\n]*~s
REGEX;
        $sql = preg_replace_callback($pattern, static function (array $match) use (&$protected): string {
            $raw = $match[0];
            $quote = $raw[0];
            if ($quote === '`') {
                $value = str_replace('``', '`', substr($raw, 1, -1));
                $raw = '"' . str_replace('"', '""', $value) . '"';
            } elseif ($quote === '"' || $quote === "'") {
                $value = str_replace($quote.$quote, $quote, substr($raw, 1, -1));
                $value = preg_replace_callback('~\\\\(.)~s', static fn(array $m): string => match ($m[1]) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", '\\', '"', "'" => $m[1],
                    default => $m[0], // LIKE escapes such as \_ must survive.
                }, $value);
                $raw = "'" . str_replace("'", "''", $value) . "'";
            }
            $token = "\x1f" . count($protected) . "\x1f";
            $protected[$token] = $raw;
            return $token;
        }, $sql);
        $sql = preg_replace('~\btable_schema\s*=\s*DATABASE\(\)~i', 'table_schema=current_schema()', $sql);
        $sql = self::functions($sql);
        $sql = preg_replace_callback('/\bAS\s+([a-z][A-Za-z0-9_]*[A-Z][A-Za-z0-9_]*)\b/', static fn(array $m): string => 'AS "'.$m[1].'"', $sql);
        // User names are optional display data. Lock the actual section lock rows only.
        if (preg_match('/\bFROM page_builder_section_locks l LEFT JOIN users u\b/i', $sql)) {
            $sql = preg_replace('/\bFOR UPDATE\s*$/i', 'FOR UPDATE OF l', $sql);
        }
        // Process each social post once, oldest pending target first.
        if (preg_match('/^SELECT DISTINCT t\.post_id FROM social_post_targets t\b/i', $sql)
            && str_contains($sql, 'ORDER BY t.updated_at LIMIT')) {
            $sql = str_replace(['SELECT DISTINCT t.post_id','ORDER BY t.updated_at LIMIT'],
                ['SELECT t.post_id','GROUP BY t.post_id ORDER BY MIN(t.updated_at),t.post_id LIMIT'], $sql);
        }
        $sql = preg_replace('~\bREGEXP\b~i', '~', $sql);
        $sql = preg_replace('~\bINTERVAL\s+(\d+|\?)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b~i', "($1 * INTERVAL '1 $2')", $sql);
        if (preg_match('~^\s*INSERT\s+IGNORE\s+INTO\b~i', $sql)) {
            $sql = preg_replace('~\bINSERT\s+IGNORE\b~i', 'INSERT', $sql, 1);
            $sql = rtrim($sql, " ;\n\r\t") . ' ON CONFLICT DO NOTHING';
        }
        if (preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+/i', $sql, $marker, PREG_OFFSET_CAPTURE)) {
            $sql = self::upsert($sql, $marker[0][0], $marker[0][1]);
        }
        if (preg_match('/^\s*DELETE\s+(\w+)\s+FROM\s+(\w+)\s+\1\s+INNER JOIN\s+(\w+ \w+)\s+ON\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $m)) {
            $sql = "DELETE FROM {$m[2]} {$m[1]} USING {$m[3]} WHERE ({$m[4]}) AND ({$m[5]})";
        }
        if (preg_match('/^\s*UPDATE\s+(\w+)\s+(\w+)\s+(?:INNER )?JOIN\s+(\w+ \w+)\s+ON\s+([^?]+?)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $m)) {
            $assignments = preg_replace('/(^|,\s*)'.$m[2].'\.(\w+)\s*=/', '$1$2=', $m[5]);
            $sql = "UPDATE {$m[1]} {$m[2]} SET {$assignments} FROM {$m[3]} WHERE ({$m[4]}) AND ({$m[6]})";
        }
        if (preg_match('/^(DELETE FROM|UPDATE)\s+(\w+)\s+((?:SET\s+.+?\s+)?)WHERE\s+(.+)\s+LIMIT\s+(\d+|\?)\s*;?$/is', $sql, $m)) {
            $sql = "{$m[1]} {$m[2]} {$m[3]}WHERE ctid IN (SELECT ctid FROM {$m[2]} WHERE {$m[4]} LIMIT {$m[5]})";
        }
        return strtr($sql, $protected);
    }

    private static function upsert(string $sql, string $marker, int $at): string
    {
        if (!preg_match('/^\s*INSERT\s+INTO\s+(\w+)\s*\(([^)]+)\)/i', $sql, $insert)
            || !isset(self::CONFLICT_KEYS[$table = strtolower($insert[1])])) {
            throw new \RuntimeException('No PostgreSQL conflict target defined for this package statement.');
        }
        $updates = self::arguments(substr($sql, $at + strlen($marker)));
        $fields = array_map('trim', explode(',', $insert[2]));
        foreach ($updates as $assignment) $fields[] = trim(explode('=', $assignment, 2)[0]);
        $fields = array_filter(array_unique($fields), static fn($name) => preg_match('/^\w+$/D', $name));
        foreach ($updates as &$assignment) {
            [$field, $expression] = explode('=', $assignment, 2);
            $expression = preg_replace('/\bVALUES\(\s*(\w+|\x1f\d+\x1f)\s*\)/i', 'EXCLUDED.$1', $expression);
            // PostgreSQL requires an explicit target when both rows contain the field.
            if ($fields) $expression = preg_replace('/(?<![\w.])(' . implode('|', $fields) . ')(?![\w(])/i', $table.'.$1', $expression);
            $assignment = $field . '=' . $expression;
        }
        unset($assignment);
        return substr($sql, 0, $at) . ' ON CONFLICT (' . self::CONFLICT_KEYS[$table]
            . ') DO UPDATE SET ' . implode(', ', $updates);
    }

    private static function functions(string $sql): string
    {
        // Walk balanced parentheses so commas in subqueries and nested calls stay inside their argument.
        $out = '';
        $offset = 0;
        while (preg_match('/\b([A-Za-z_][A-Za-z_0-9]*)\s*\(/', $sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            [$prefix, $start] = $match[0];
            $open = $start + strlen($prefix);
            $depth = 1;
            for ($end = $open; $end < strlen($sql) && $depth > 0; $end++) {
                if ($sql[$end] === '(') $depth++;
                if ($sql[$end] === ')') $depth--;
            }
            if ($depth !== 0) break;
            $body = self::functions(substr($sql, $open, $end - $open - 1));
            $out .= substr($sql, $offset, $start - $offset)
                . self::call(strtoupper($match[1][0]), $prefix, $body);
            $offset = $end;
        }
        return $out . substr($sql, $offset);
    }

    private static function arguments(string $body): array
    {
        $args = []; $depth = 0; $start = 0;
        for ($i = 0; $i < strlen($body); $i++) {
            if ($body[$i] === '(') $depth++;
            if ($body[$i] === ')') $depth--;
            if ($body[$i] === ',' && $depth === 0) {
                $args[] = trim(substr($body, $start, $i - $start)); $start = $i + 1;
            }
        }
        $args[] = trim(substr($body, $start));
        return $args;
    }

    private static function call(string $name, string $prefix, string $body): string
    {
        if ($name === 'GROUP_CONCAT') {
            if (preg_match('/^(DISTINCT\s+)?(.+?)(?:\s+ORDER\s+BY\s+(.+?))?(?:\s+SEPARATOR\s+(\x1f\d+\x1f))?$/is', trim($body), $m)) {
                $order = !empty($m[3]) ? ' ORDER BY ' . $m[3] : '';
                $values = 'array_agg((' . $m[2] . ')::text' . $order . ')';
                if (!empty($m[1])) $values = 'sense_unique_text(' . $values . ')';
                return 'array_to_string(' . $values . ',' . ($m[4] ?? "','") . ')';
            }
        }
        if ($name === 'SUM' && preg_match('/^[\w.]+\s*(?:=|<>|IS\b|IN\b)/i', trim($body))) {
            return 'SUM((' . $body . ')::int)';
        }
        if (!in_array($name, ['IF','DATE_SUB','DATE_ADD','FIELD','CURDATE','UTC_TIMESTAMP','UNIX_TIMESTAMP','DATABASE','TIMESTAMPDIFF','TIMESTAMPADD','JSON_OBJECT'], true)) {
            return $prefix . $body . ')';
        }
        $a = self::arguments($body);
        return match (true) {
            $name === 'IF' && count($a) === 3 => '(CASE WHEN ' . $a[0] . ' THEN ' . $a[1] . ' ELSE ' . $a[2] . ' END)',
            $name === 'FIELD' && count($a) > 1 => 'sense_field(' . $body . ')',
            $name === 'CURDATE' => 'CURRENT_DATE',
            $name === 'DATABASE' => 'current_database()',
            $name === 'UNIX_TIMESTAMP' => 'floor(extract(epoch FROM ' . ($body === '' ? 'statement_timestamp()' : $body) . '))::bigint',
            $name === 'UTC_TIMESTAMP' => "(statement_timestamp() AT TIME ZONE 'UTC')",
            $name === 'DATE_ADD' && count($a) === 2 => '(' . $a[0] . ' + ' . $a[1] . ')',
            $name === 'DATE_SUB' && count($a) === 2 => '(' . $a[0] . ' - ' . $a[1] . ')',
            $name === 'TIMESTAMPDIFF' && count($a) === 3 && strtoupper($a[0]) === 'SECOND' =>
                'trunc(extract(epoch FROM (' . $a[2] . ' - ' . $a[1] . ')))::bigint',
            $name === 'TIMESTAMPADD' && count($a) === 3 && strtoupper($a[0]) === 'SECOND' =>
                '('.$a[2].' + ('.$a[1].") * INTERVAL '1 second')",
            $name === 'JSON_OBJECT' && $body === '' => "'{}'",
            default => $prefix . $body . ')',
        };
    }
}
