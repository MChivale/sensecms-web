<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function run(PDO $db, string $directory): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        if ($files === []) throw new RuntimeException('No database migrations were found.');
        $applied = [];
        foreach ($files as $path) {
            $name = basename($path);
            if ($this->applied($db, $name)) continue;
            $sql = file_get_contents($path);
            if (!is_string($sql)) throw new RuntimeException("Migration {$name} could not be read.");
            foreach ($this->statements($sql) as $statement) $db->exec($statement);
            $record = $db->prepare('INSERT IGNORE INTO migrations (name) VALUES (?)');
            $record->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    private function applied(PDO $db, string $name): bool
    {
        try {
            $exists = $db->query("SHOW TABLES LIKE 'migrations'")->fetchColumn();
            if (!$exists) return false;
            $statement = $db->prepare('SELECT 1 FROM migrations WHERE name=? LIMIT 1');
            $statement->execute([$name]);
            return (bool) $statement->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function statements(string $sql): array
    {
        $statements = []; $buffer = ''; $quote = null; $escaped = false; $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index]; $next = $sql[$index + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\') { $escaped = true; continue; }
                if ($char === $quote) {
                    if ($next === $quote && $quote !== '`') { $buffer .= $next; $index++; continue; }
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $buffer .= $char; continue; }
            if ($char === '-' && $next === '-' && (($sql[$index + 2] ?? '') === ' ' || ($sql[$index + 2] ?? '') === "\t")) {
                while ($index < $length && $sql[$index] !== "\n") $index++;
                $buffer .= "\n"; continue;
            }
            if ($char === '#') { while ($index < $length && $sql[$index] !== "\n") $index++; $buffer .= "\n"; continue; }
            if ($char === '/' && $next === '*') {
                $index += 2;
                while ($index < $length - 1 && !($sql[$index] === '*' && $sql[$index + 1] === '/')) $index++;
                $index++; $buffer .= ' '; continue;
            }
            if ($char === ';') { $statement = trim($buffer); if ($statement !== '') $statements[] = $statement; $buffer = ''; continue; }
            $buffer .= $char;
        }
        $tail = trim($buffer); if ($tail !== '') $statements[] = $tail;
        return $statements;
    }
}
