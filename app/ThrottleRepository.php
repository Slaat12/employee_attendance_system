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

        /*
         * Record a failed login in one atomic PostgreSQL UPSERT.
         *
         * Keeping this as one statement avoids a multi-query transaction
         * becoming aborted between INSERT, SELECT FOR UPDATE and UPDATE.
         * PostgreSQL serializes conflicting updates to the same key_hash.
         */
        $now = time();
        $lockUntil = $now + $lockSeconds;

        $stmt = self::db()->prepare(
            "INSERT INTO login_attempts
                (key_hash, fail_count, first_attempt, locked_until, updated_at)
             VALUES
                (?, 1, ?, CASE WHEN ? <= 1 THEN ? ELSE NULL END, CURRENT_TIMESTAMP)
             ON CONFLICT (key_hash) DO UPDATE
             SET
                fail_count = CASE
                    WHEN login_attempts.locked_until IS NOT NULL
                         AND login_attempts.locked_until > EXCLUDED.first_attempt
                    THEN login_attempts.fail_count

                    WHEN (EXCLUDED.first_attempt - login_attempts.first_attempt) > ?
                         OR (
                             login_attempts.locked_until IS NOT NULL
                             AND login_attempts.locked_until <= EXCLUDED.first_attempt
                         )
                    THEN 1

                    ELSE login_attempts.fail_count + 1
                END,

                first_attempt = CASE
                    WHEN login_attempts.locked_until IS NOT NULL
                         AND login_attempts.locked_until > EXCLUDED.first_attempt
                    THEN login_attempts.first_attempt

                    WHEN (EXCLUDED.first_attempt - login_attempts.first_attempt) > ?
                         OR (
                             login_attempts.locked_until IS NOT NULL
                             AND login_attempts.locked_until <= EXCLUDED.first_attempt
                         )
                    THEN EXCLUDED.first_attempt

                    ELSE login_attempts.first_attempt
                END,

                locked_until = CASE
                    WHEN login_attempts.locked_until IS NOT NULL
                         AND login_attempts.locked_until > EXCLUDED.first_attempt
                    THEN login_attempts.locked_until

                    WHEN (EXCLUDED.first_attempt - login_attempts.first_attempt) > ?
                         OR (
                             login_attempts.locked_until IS NOT NULL
                             AND login_attempts.locked_until <= EXCLUDED.first_attempt
                         )
                    THEN NULL

                    WHEN login_attempts.fail_count + 1 >= ?
                    THEN EXCLUDED.first_attempt + ?

                    ELSE NULL
                END,

                updated_at = CURRENT_TIMESTAMP
             RETURNING fail_count, locked_until"
        );

        $stmt->execute([
            $attemptKey,
            $now,
            $maxFails,
            $lockUntil,
            $windowSeconds,
            $windowSeconds,
            $windowSeconds,
            $maxFails,
            $lockSeconds
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException(
                'The login attempt could not be recorded.'
            );
        }

        return [
            'attempt_count' => (int) $row['fail_count'],
            'locked_until' => $row['locked_until'] === null
                ? null
                : (int) $row['locked_until']
        ];
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
