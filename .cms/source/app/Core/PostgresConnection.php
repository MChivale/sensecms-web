<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Keeps the query interface of installed Core and signed package versions. */
final class PostgresConnection extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(PostgresQuery::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = PostgresQuery::translate($query);
        return $fetchMode === null ? parent::query($sql) : parent::query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(PostgresQuery::translate($statement));
    }
}
