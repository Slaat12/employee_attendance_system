
<?php

require_once __DIR__ . '/Database.php';

class AuditRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    public static function record(
        string $action,
        ?string $userType = null,
        ?int $userId = null,
        ?string $description = null
    ): bool {


        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

            if (
                $ipAddress !== null &&
                filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ) {
                $ipAddress = null;
            }

            $sql = "
                INSERT INTO audit_logs (
                    user_type,
                    user_id,
                    action,
                    description,
                    ip_address,
                    created_at
                )
                VALUES (
                    :user_type,
                    :user_id,
                    :action,
                    :description,
                    :ip_address,
                    CURRENT_TIMESTAMP
                )
            ";

            $stmt = self::db()->prepare($sql);

            $stmt->execute([
                'user_type' => $userType,
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $ipAddress
            ]);

            return true;

        } catch (Throwable $e) {
            error_log('AUDIT REPOSITORY FAILED: ' . $e->getMessage());

            return false;
        }
    }
}
