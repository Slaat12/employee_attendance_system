
<?php

require_once __DIR__ . '/Database.php';

class SettingsRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Retrieve company settings.
     */
    public static function get(): array
    {
        $stmt = self::db()->query("
            SELECT
                setting_id,
                company_name,
                start_time,
                end_time,
                grace_minutes,
                work_days
            FROM company_settings
            ORDER BY setting_id ASC
            LIMIT 1
        ");

        $settings = $stmt->fetch();

        if (!$settings) {
            return [
                'company_name' => 'Northstar Employee Attendance',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'grace_minutes' => 15,
                'work_days' => [1, 2, 3, 4, 5]
            ];
        }

        // PostgreSQL returns SMALLINT[] as a string such as {1,2,3,4,5}.
        $workDays = $settings['work_days'] ?? null;

        if (is_string($workDays)) {
            $workDays = trim($workDays, '{}');

            $workDays = $workDays === ''
                ? []
                : array_map('intval', explode(',', $workDays));
        }

        return [
            'company_name' => $settings['company_name'],
            'start_time' => substr((string) $settings['start_time'], 0, 5),
            'end_time' => substr((string) $settings['end_time'], 0, 5),
            'grace_minutes' => (int) $settings['grace_minutes'],
            'work_days' => $workDays
        ];
    }

    /**
     * Save company settings.
     */
    public static function save(array $data): bool
    {
        $companyName = trim($data['company_name'] ?? '');
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        $graceMinutes = (int) ($data['grace_minutes'] ?? 15);
        $workDays = $data['work_days'] ?? [1, 2, 3, 4, 5];

        if (
            $companyName === '' ||
            !preg_match('/^\d{2}:\d{2}$/', $startTime) ||
            !preg_match('/^\d{2}:\d{2}$/', $endTime) ||
            $graceMinutes < 0 ||
            $graceMinutes > 180 ||
            !is_array($workDays)
        ) {
            return false;
        }

        $workDays = array_values(array_unique(array_map('intval', $workDays)));

        foreach ($workDays as $day) {
            if ($day < 1 || $day > 7) {
                return false;
            }
        }

        sort($workDays);

        // Convert the PHP array into PostgreSQL SMALLINT[] format.
        $postgresDays = '{' . implode(',', $workDays) . '}';

        try {
            $sql = "
                INSERT INTO company_settings (
                    setting_id,
                    company_name,
                    start_time,
                    end_time,
                    grace_minutes,
                    work_days
                )
                VALUES (
                    1,
                    :company_name,
                    :start_time,
                    :end_time,
                    :grace_minutes,
                    CAST(:work_days AS SMALLINT[])
                )
                ON CONFLICT (setting_id)
                DO UPDATE SET
                    company_name = EXCLUDED.company_name,
                    start_time = EXCLUDED.start_time,
                    end_time = EXCLUDED.end_time,
                    grace_minutes = EXCLUDED.grace_minutes,
                    work_days = EXCLUDED.work_days
            ";

            $stmt = self::db()->prepare($sql);

            return $stmt->execute([
                'company_name' => $companyName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'grace_minutes' => $graceMinutes,
                'work_days' => $postgresDays
            ]);

        } catch (PDOException $e) {
            error_log('Settings save error: ' . $e->getMessage());
            return false;
        }
    }
}
