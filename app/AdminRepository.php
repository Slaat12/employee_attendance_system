
<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MfaSecurity.php';

class AdminRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Convert a database administrator row into an application array.
     */
    private static function formatAdmin(array $admin): array
    {
        return [
            'id' => (int) $admin['admin_id'],
            'name' => $admin['full_name'],
            'email' => $admin['email'],
            'password_hash' => $admin['password_hash'],
            'role' => $admin['role'],
            'is_active' => (bool) $admin['is_active'],
            'created_at' => $admin['created_at'],
            'mfa_enabled' => (bool) $admin['mfa_enabled'],
            'mfa_secret' => $admin['mfa_secret']
        ];
    }

    /**
     * Find an administrator using their email address.
     */
    public static function findByEmail(string $email): ?array
    {
        $sql = "
            SELECT
                admin_id,
                full_name,
                email,
                password_hash,
                role,
                is_active,
                created_at,
                mfa_enabled,
                mfa_secret
            FROM administrators
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ";

        $stmt = self::db()->prepare($sql);

        $stmt->execute([
            'email' => trim($email)
        ]);

        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return null;
        }

        return self::formatAdmin($admin);
    }

    /**
     * Retrieve an administrator using the database ID.
     */
    public static function findById(int $id): ?array
    {
        $sql = "
            SELECT
                admin_id,
                full_name,
                email,
                password_hash,
                role,
                is_active,
                created_at,
                mfa_enabled,
                mfa_secret
            FROM administrators
            WHERE admin_id = :id
            LIMIT 1
        ";

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return null;
        }

        return self::formatAdmin($admin);
    }

    /**
     * Save an encrypted MFA secret and enable MFA.
     */
    public static function enableMfa(
        int $adminId,
        string $secret
    ): bool {

        $sql = "
            UPDATE administrators
            SET
                mfa_secret = :secret,
                mfa_enabled = TRUE,
                mfa_enabled_at = CURRENT_TIMESTAMP
            WHERE admin_id = :id
              AND is_active = TRUE
        ";

        $stmt = self::db()->prepare($sql);

        return $stmt->execute([
            'secret' => MfaSecurity::encrypt($secret),
            'id' => $adminId
        ]) && $stmt->rowCount() === 1;
    }

    /**
     * Disable MFA for an administrator.
     */
    public static function disableMfa(int $adminId): bool
    {
        $sql = "
            UPDATE administrators
            SET
                mfa_secret = NULL,
                mfa_enabled = FALSE,
                mfa_enabled_at = NULL
            WHERE admin_id = :id
        ";

        $stmt = self::db()->prepare($sql);

        return $stmt->execute(['id' => $adminId]);
    }

    /**
     * Decrypt an administrator's stored MFA secret.
     */
    public static function getMfaSecret(int $adminId): ?string
    {
        $sql = "
            SELECT mfa_secret
            FROM administrators
            WHERE admin_id = :id
              AND mfa_enabled = TRUE
              AND is_active = TRUE
            LIMIT 1
        ";

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['id' => $adminId]);

        $encryptedSecret = $stmt->fetchColumn();

        if (!is_string($encryptedSecret) || $encryptedSecret === '') {
            return null;
        }

        return MfaSecurity::decrypt($encryptedSecret);
    }

    /**
     * Create a new administrator account.
     */
    public static function create(
        string $name,
        string $email,
        string $passwordHash,
        string $role = 'admin'
    ): ?string {

        try {
            $sql = "
                INSERT INTO administrators (
                    full_name,
                    email,
                    password_hash,
                    role,
                    is_active
                )
                VALUES (
                    :name,
                    :email,
                    :password_hash,
                    :role,
                    TRUE
                )
            ";

            $stmt = self::db()->prepare($sql);

            $stmt->execute([
                'name' => trim($name),
                'email' => trim($email),
                'password_hash' => $passwordHash,
                'role' => $role
            ]);

            return null;

        } catch (PDOException $e) {

            error_log('Administrator creation error: ' . $e->getMessage());

            return 'Unable to create the administrator account. Check whether the email is already registered.';
        }
    }
}
