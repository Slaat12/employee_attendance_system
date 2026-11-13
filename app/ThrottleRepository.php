
<?php

require_once __DIR__ . '/Database.php';

class ThrottleRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    public static function find(string $attemptKey): ?array
    {
        $stmt = self::db()->prepare(
            "SELECT
                key_hash,
                fail_count,
                first_attempt,
                locked_until,
                updated_at
             FROM login_attempts
             WHERE key_hash = :key_hash"
        );

        $stmt->execute(['key_hash' => $attemptKey]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'attempt_key' => trim($row['key_hash']),
            'attempt_count' => (int) $row['fail_count'],
            'first_attempt' => (int) $row['first_attempt'],
            'locked_until' => $row['locked_until'] === null
                ? null
                : (int) $row['locked_until'],
            'updated_at' => $row['updated_at']
        ];
    }

    public static function recordFailure(
        string $attemptKey,
        int $maxFails,
        int $windowSeconds,
        int $lockSeconds
    ): array {

        $db = self::db();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO login_attempts
                    (key_hash, fail_count, first_attempt, locked_until, updated_at)
                 VALUES
                    (:key_hash, 0, :first_attempt, NULL, CURRENT_TIMESTAMP)
                 ON CONFLICT (key_hash) DO NOTHING"
            );

            $now = time();

            $stmt->execute([
                'key_hash' => $attemptKey,
                'first_attempt' => $now
            ]);

            $stmt = $db->prepare(
                "SELECT *
                 FROM login_attempts
                 WHERE key_hash = :key_hash
                 FOR UPDATE"
            );

            $stmt->execute(['key_hash' => $attemptKey]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $count = (int) $row['fail_count'];
            $firstAttempt = (int) $row['first_attempt'];
            $lockedUntil = $row['locked_until'] === null
                ? null
                : (int) $row['locked_until'];

            if ($lockedUntil !== null && $lockedUntil > $now) {
                $db->commit();

                return [
                    'attempt_count' => $count,
                    'locked_until' => $lockedUntil
                ];
            }

            if (($now - $firstAttempt) > $windowSeconds ||
                ($lockedUntil !== null && $lockedUntil <= $now)) {

                $count = 0;
                $firstAttempt = $now;
                $lockedUntil = null;
            }

            $count++;

            if ($count >= $maxFails) {
                $lockedUntil = $now + $lockSeconds;
            }

            $update = $db->prepare(
                "UPDATE login_attempts
                 SET fail_count = :fail_count,
                     first_attempt = :first_attempt,
                     locked_until = :locked_until,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE key_hash = :key_hash"
            );

            $update->execute([
                'fail_count' => $count,
                'first_attempt' => $firstAttempt,
                'locked_until' => $lockedUntil,
                'key_hash' => $attemptKey
            ]);

            $db->commit();

            return [
                'attempt_count' => $count,
                'locked_until' => $lockedUntil
            ];

        } catch (Throwable $error) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $error;
        }
    }

    public static function clear(string $attemptKey): void
    {
        $stmt = self::db()->prepare(
            "DELETE FROM login_attempts
             WHERE key_hash = :key_hash"
        );

        $stmt->execute(['key_hash' => $attemptKey]);
    }

    public static function cleanup(int $olderThanSeconds = 86400): void
    {
        $cutoff = date(
            'Y-m-d H:i:s',
            time() - $olderThanSeconds
        );

        $stmt = self::db()->prepare(
            "DELETE FROM login_attempts
             WHERE updated_at < :cutoff"
        );

        $stmt->execute(['cutoff' => $cutoff]);
    }
}
