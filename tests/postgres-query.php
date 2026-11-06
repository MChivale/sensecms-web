<?php
declare(strict_types=1);

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
use App\Core\PostgresQuery;

$cases = [
    ['SELECT `key`, "O\'Brien", \'a LIKE b REGEXP c\' FROM settings WHERE `key`=?',
     'SELECT "key", \'O\'\'Brien\', \'a LIKE b REGEXP c\' FROM settings WHERE "key"=?'],
    ['SELECT DATE_SUB(NOW(),INTERVAL ? DAY), IF(a=1, CONCAT(b,c), ?) FROM t',
     "SELECT (NOW() - (? * INTERVAL '1 DAY')), (CASE WHEN a=1 THEN CONCAT(b,c) ELSE ? END) FROM t"],
    ['SELECT GROUP_CONCAT(DISTINCT name ORDER BY id SEPARATOR ", ") FROM t',
     "SELECT array_to_string(sense_unique_text(array_agg((name)::text ORDER BY id)),', ') FROM t"],
    ['INSERT INTO live_chat_reads (conversation_id,user_id,last_read_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id))',
     'INSERT INTO live_chat_reads (conversation_id,user_id,last_read_message_id) VALUES (?,?,?) ON CONFLICT (conversation_id,user_id) DO UPDATE SET last_read_message_id=GREATEST(live_chat_reads.last_read_message_id,EXCLUDED.last_read_message_id)'],
    ['DELETE c FROM chunks c INNER JOIN documents d ON d.id=c.document_id WHERE d.id=?',
     'DELETE FROM chunks c USING documents d WHERE (d.id=c.document_id) AND (d.id=?)'],
    ['UPDATE records SET status=? WHERE expires_at<? LIMIT 50',
     'UPDATE records SET status=? WHERE ctid IN (SELECT ctid FROM records WHERE expires_at<? LIMIT 50)'],
];
foreach ($cases as [$input, $expected]) {
    $actual = PostgresQuery::translate($input);
    if ($actual !== $expected) throw new RuntimeException("Unexpected translation:\n".$actual);
}
echo count($cases) . " PostgreSQL statement checks passed.\n";
