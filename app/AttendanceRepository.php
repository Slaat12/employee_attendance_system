
<?php

require_once __DIR__ . '/Database.php';

class AttendanceRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Retrieve attendance records for one date.
     */
    public static function rowsForDate(string $date): array
    {
        $sql = "
            SELECT
                a.attendance_id,
                a.employee_id,
                e.employee_code,
                e.first_name,
                e.last_name,
                d.department_name,
                a.check_in,
                a.check_out,
                a.attendance_date,
                a.status,
                a.notes
            FROM attendance a
            INNER JOIN employees e
                ON a.employee_id = e.employee_id
            LEFT JOIN departments d
                ON e.department_id = d.department_id
            WHERE a.attendance_date = :date
            ORDER BY a.check_in DESC
        ";

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['date' => $date]);

        return $stmt->fetchAll();
    }

    /**
     * Retrieve attendance records between two dates.
     */
    public static function rowsBetween(
        string $startDate,
        string $endDate
    ): array {

        $sql = "
            SELECT
                a.attendance_id,
                a.employee_id,
                e.employee_code,
                e.first_name,
                e.last_name,
                d.department_name,
                a.check_in,
                a.check_out,
                a.attendance_date,
                a.status,
                a.notes
            FROM attendance a
            INNER JOIN employees e
                ON a.employee_id = e.employee_id
            LEFT JOIN departments d
                ON e.department_id = d.department_id
            WHERE a.attendance_date BETWEEN :start_date AND :end_date
            ORDER BY a.attendance_date DESC, a.check_in DESC
        ";

        $stmt = self::db()->prepare($sql);

        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Retrieve today's attendance records.
     */
    public static function todayRows(): array
    {
        return self::rowsForDate(date('Y-m-d'));
    }

    /**
     * Find an employee's attendance record for a particular date.
     */
    public static function findForEmployee(
        int $employeeId,
        string $date
    ): ?array {

        $stmt = self::db()->prepare("
            SELECT *
            FROM attendance
            WHERE employee_id = :employee_id
              AND attendance_date = :attendance_date
            LIMIT 1
        ");

        $stmt->execute([
            'employee_id' => $employeeId,
            'attendance_date' => $date
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Record employee check-in.
     */
    public static function checkIn(
        int $employeeId,
        string $status
    ): bool {

        try {
            $stmt = self::db()->prepare("
                INSERT INTO attendance (
                    employee_id,
                    check_in,
                    attendance_date,
                    status
                )
                VALUES (
                    :employee_id,
                    CURRENT_TIMESTAMP,
                    CURRENT_DATE,
                    :status
                )
            ");

            $stmt->execute([
                'employee_id' => $employeeId,
                'status' => $status
            ]);

            return true;

        } catch (PDOException $e) {

            error_log('Attendance check-in error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Record employee check-out.
     */
    public static function checkOut(int $employeeId): bool
    {
        $stmt = self::db()->prepare("
            UPDATE attendance
            SET check_out = CURRENT_TIMESTAMP
            WHERE employee_id = :employee_id
              AND attendance_date = CURRENT_DATE
              AND check_out IS NULL
        ");

        $stmt->execute([
            'employee_id' => $employeeId
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Count attendance records for a date.
     */
    public static function countForDate(string $date): int
    {
        $stmt = self::db()->prepare("
            SELECT COUNT(*)
            FROM attendance
            WHERE attendance_date = :date
        ");

        $stmt->execute(['date' => $date]);

        return (int) $stmt->fetchColumn();
    }
}
