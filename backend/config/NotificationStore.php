<?php

class NotificationStore
{
    private static array $columnCache = [];

    public static function timestampColumn(PDO $db): string
    {
        return self::hasColumn($db, 'sent_at') ? 'sent_at' : 'created_at';
    }

    public static function incidentIdExpression(PDO $db, string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        if (self::hasColumn($db, 'incident_id')) {
            return $prefix . 'incident_id';
        }

        if (self::hasColumn($db, 'data')) {
            return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$prefix}data, '$.incident_id')) AS UNSIGNED)";
        }

        return 'NULL';
    }

    public static function insert(
        PDO $db,
        int $userId,
        ?int $incidentId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        int $isRead = 0
    ): void {
        $columns = ['user_id', 'type', 'title', 'body'];
        $placeholders = ['?', '?', '?', '?'];
        $params = [$userId, $type, $title, $body];

        if (self::hasColumn($db, 'incident_id')) {
            $columns[] = 'incident_id';
            $placeholders[] = '?';
            $params[] = $incidentId;
        }

        if (self::hasColumn($db, 'data')) {
            $payload = $data;
            if ($incidentId !== null && $incidentId > 0 && !array_key_exists('incident_id', $payload)) {
                $payload['incident_id'] = $incidentId;
            }
            $columns[] = 'data';
            $placeholders[] = '?';
            $params[] = json_encode(
                $payload === [] ? new stdClass() : $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (self::hasColumn($db, 'is_read')) {
            $columns[] = 'is_read';
            $placeholders[] = '?';
            $params[] = $isRead;
        }

        $timestampColumn = self::timestampColumn($db);
        $columns[] = $timestampColumn;
        $placeholders[] = 'NOW()';

        $stmt = $db->prepare(sprintf(
            'INSERT INTO notifications (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        ));
        $stmt->execute($params);
    }

    public static function existsRecently(
        PDO $db,
        int $userId,
        ?int $incidentId,
        string $type,
        string $title,
        string $body,
        int $windowMinutes = 10
    ): bool {
        $conditions = [
            'user_id = ?',
            'type = ?',
            'title = ?',
            'body = ?',
            self::timestampColumn($db) . " >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)",
        ];
        $params = [$userId, $type, $title, $body];

        $incidentExpression = self::incidentIdExpression($db);
        if ($incidentExpression !== 'NULL') {
            $conditions[] = "COALESCE({$incidentExpression}, 0) = COALESCE(?, 0)";
            $params[] = $incidentId;
        }

        $stmt = $db->prepare(sprintf(
            'SELECT 1 FROM notifications WHERE %s LIMIT 1',
            implode(' AND ', $conditions)
        ));
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public static function hasColumn(PDO $db, string $columnName): bool
    {
        $cacheKey = spl_object_hash($db) . ':notifications';
        if (!isset(self::$columnCache[$cacheKey])) {
            $stmt = $db->query('SHOW COLUMNS FROM notifications');
            self::$columnCache[$cacheKey] = array_fill_keys(
                array_map(static fn(array $row): string => (string) $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
                true
            );
        }

        return isset(self::$columnCache[$cacheKey][$columnName]);
    }
}
