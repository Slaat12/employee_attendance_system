
<?php

require_once __DIR__ . '/AdminRepository.php';
require_once __DIR__ . '/EmployeeRepository.php';

final class Auth
{
    private const MFA_TIMEOUT = 300;

    public static function check(): bool
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return self::check() ? $_SESSION['user'] : null;
    }

    public static function isAdmin(): bool
    {
        return self::check()
            && ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private static function hashOk(mixed $hash, string $secret): bool
    {
        return is_string($hash)
            && $hash !== ''
            && password_verify($secret, $hash);
    }

    private static function burn(string $secret): void
    {
        password_verify(
            $secret,
            '$2y$10$B0L5nAelkFsP0Loc/Nr9o.YuVtqKFMsO3e/dHHT.An1CZNHDR/Vm'
        );
    }

    public static function verifyAdmin(
        string $email,
        string $password
    ): ?array {

        $admin = AdminRepository::findByEmail($email);

        if ($admin === null || !$admin['is_active']) {
            self::burn($password);
            return null;
        }

        if (!self::hashOk($admin['password_hash'] ?? null, $password)) {
            return null;
        }

        return $admin;
    }

    public static function verifyEmployee(
        string $employeeId,
        string $pin
    ): ?array {

        $employee = EmployeeRepository::findByCode($employeeId);

        if ($employee === null) {
            self::burn($pin);
            return null;
        }

        if (($employee['status'] ?? '') !== 'active') {
            self::burn($pin);
            return null;
        }

        if (!self::hashOk($employee['pin_hash'] ?? null, $pin)) {
            return null;
        }

        return $employee;
    }

    /**
     * Start the administrator's second authentication stage.
     * No authenticated user session is created here.
     */
    public static function beginMfaChallenge(array $admin): void
    {
        session_regenerate_id(true);

        unset($_SESSION['user']);

        $_SESSION['pending_admin_mfa'] = [
            'admin_id' => (int) $admin['id'],
            'started_at' => time(),
            'purpose' => !empty($admin['mfa_enabled'])
                ? 'verify'
                : 'setup'
        ];
    }

    /**
     * Retrieve the administrator awaiting MFA.
     */
    public static function pendingMfaAdmin(): ?array
    {
        $pending = $_SESSION['pending_admin_mfa'] ?? null;

        if (!is_array($pending)) {
            return null;
        }

        if (
            !isset($pending['admin_id'], $pending['started_at']) ||
            time() - (int) $pending['started_at'] > self::MFA_TIMEOUT
        ) {
            self::clearPendingMfa();
            return null;
        }

        $admin = AdminRepository::findById(
            (int) $pending['admin_id']
        );

        if ($admin === null || !$admin['is_active']) {
            self::clearPendingMfa();
            return null;
        }

        return $admin;
    }

    /**
     * Identify whether the pending stage is setup or verification.
     */
    public static function pendingMfaPurpose(): ?string
    {
        if (self::pendingMfaAdmin() === null) {
            return null;
        }

        return $_SESSION['pending_admin_mfa']['purpose'] ?? null;
    }

    /**
     * Clear an unfinished MFA authentication stage.
     */
    public static function clearPendingMfa(): void
    {
        unset(
            $_SESSION['pending_admin_mfa'],
            $_SESSION['pending_mfa_secret']
        );
    }

    /**
     * Complete login after successful MFA verification.
     */
    public static function completeMfaLogin(array $admin): void
    {
        self::clearPendingMfa();
        self::loginAdmin($admin);
    }

    public static function loginAdmin(array $admin): void
    {
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => 'admin'
        ];

        $_SESSION['_last_activity'] = time();
    }

    public static function logout(bool $redirectAfter = true): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax'
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if ($redirectAfter) {
            header('Location: ' . url('auth/login.php'));
            exit;
        }
    }
}
