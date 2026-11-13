
<?php

require_once __DIR__ . '/ThrottleRepository.php';

final class Throttle
{
    private const MAX_FAILS = 5;
    private const WINDOW = 300;
    private const LOCK = 300;

    public static function key(string $scope, string $id): string
    {
        return hash(
            'sha256',
            $scope . '|' . strtolower($id)
        );
    }

    /**
     * Return the remaining lockout time.
     * PostgreSQL stores locked_until as a Unix timestamp.
     */
    public static function blockedFor(string $key): int
    {
        $row = ThrottleRepository::find($key);

        if ($row === null || $row['locked_until'] === null) {
            return 0;
        }

        $lockTime = (int) $row['locked_until'];

        return max(0, $lockTime - time());
    }

    public static function fail(string $key): void
    {
        ThrottleRepository::recordFailure(
            $key,
            self::MAX_FAILS,
            self::WINDOW,
            self::LOCK
        );

        ThrottleRepository::cleanup();
    }

    public static function clear(string $key): void
    {
        ThrottleRepository::clear($key);
    }

    public static function message(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return 'Too many incorrect attempts. Please try again in '
            . $minutes
            . ' minute'
            . ($minutes === 1 ? '' : 's')
            . '.';
    }
}
