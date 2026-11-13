
<?php

require_once __DIR__ . '/AttendanceRepository.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/AuditRepository.php';

/**
 * Handles employee attendance operations using PostgreSQL.
 *
 * Each employee can have only one attendance record per day.
 * An open record from a previous day does not prevent a new check-in.
 */
final class AttendanceService
{
    /**
     * Convert a database attendance row into the application's
     * standard attendance format.
     */
    private static function formatRow(array $row): array
    {
        return [
            'id' => (int) ($row['attendance_id'] ?? $row['id'] ?? 0),
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'employee_code' => $row['employee_code'] ?? '',
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'department_name' => $row['department_name'] ?? '',
            'check_in' => $row['check_in'] ?? null,
            'check_out' => $row['check_out'] ?? null,
            'status' => $row['status'] ?? 'Present',
            'notes' => $row['notes'] ?? '',
        ];
    }

    /**
     * Retrieve attendance records for a specific date.
     */
    public static function rowsForDate(string $date): array
    {
        $rows = AttendanceRepository::rowsForDate($date);

        $formatted = array_map(
            fn($row) => self::formatRow($row),
            $rows
        );

        usort($formatted, function ($a, $b) {
            return strcmp(
                (string) $b['check_in'],
                (string) $a['check_in']
            );
        });

        return $formatted;
    }

    /**
     * Retrieve attendance records within a date range.
     */
    public static function rowsBetween(string $from, string $to): array
    {
        $rows = AttendanceRepository::rowsBetween($from, $to);

        $formatted = array_map(
            fn($row) => self::formatRow($row),
            $rows
        );

        usort($formatted, function ($a, $b) {
            return strcmp(
                (string) $b['check_in'],
                (string) $a['check_in']
            );
        });

        return $formatted;
    }

    /**
     * Retrieve today's attendance records.
     */
    public static function todayRows(): array
    {
        return self::rowsForDate(today_string());
    }

    /**
     * Record an employee check-in.
     *
     * @return array{0:bool,1:string}
     */
    public static function checkIn(int $employeeId): array
    {
        $now = now_string();
        $today = today_string();

        // Check whether the employee already has a record today.
        $existing = AttendanceRepository::findForEmployee(
            $employeeId,
            $today
        );

        if ($existing !== null) {
            if (empty($existing['check_out'])) {
                return [
                    false,
                    'You are already checked in.'
                ];
            }

            return [
                false,
                'You have already completed your attendance for today.'
            ];
        }

        // Get the company's attendance settings.
        $settings = SettingsRepository::get();

        $startTime = $settings['start_time'] ?? '08:00';
        $graceMinutes = (int) ($settings['grace_minutes'] ?? 0);

        $lateAfter = strtotime(
            $today . ' ' . $startTime
        ) + ($graceMinutes * 60);

        $status = strtotime($now) > $lateAfter
            ? 'Late'
            : 'Present';

        // Save the attendance record in PostgreSQL.
        $saved = AttendanceRepository::checkIn(
            $employeeId,
            $status
        );

        if (!$saved) {
            return [
                false,
                'Unable to record your check-in. Please try again.'
            ];
        }

        // Record the successful check-in in the audit log.
        AuditRepository::record(
            'employee_check_in',
            'employee',
            $employeeId,
            'Employee checked in. Attendance status: ' . $status
        );

        $message = 'Check-in recorded at '
            . date('h:i:s A', strtotime($now))
            . '.';

        if ($status === 'Late') {
            $message .= ' You have been marked Late.';
        }

        return [true, $message];
    }

    /**
     * Record an employee check-out.
     *
     * @return array{0:bool,1:string}
     */
    public static function checkOut(int $employeeId): array
    {
        $today = today_string();

        // Find the employee's attendance record for today.
        $record = AttendanceRepository::findForEmployee(
            $employeeId,
            $today
        );

        if ($record === null) {
            return [
                false,
                'No check-in found for today. Please check in first.'
            ];
        }

        if (!empty($record['check_out'])) {
            return [
                false,
                'You have already checked out today.'
            ];
        }

        $checkInTime = $record['check_in'] ?? null;

        if (empty($checkInTime)) {
            return [
                false,
                'Your check-in record could not be found.'
            ];
        }

        // Update the attendance record in PostgreSQL.
        $saved = AttendanceRepository::checkOut($employeeId);

        if (!$saved) {
            return [
                false,
                'Unable to record your check-out. Please try again.'
            ];
        }

        // Record the successful check-out in the audit log.
        AuditRepository::record(
            'employee_check_out',
            'employee',
            $employeeId,
            'Employee checked out successfully.'
        );

        $now = now_string();

        $hours = hours_between(
            $checkInTime,
            $now
        );

        $formattedHours = number_format(
            $hours ?? 0,
            2
        );

        $message = 'Check-out recorded at '
            . date('h:i:s A', strtotime($now))
            . '. Time worked today: '
            . $formattedHours
            . ' hrs.';

        return [true, $message];
    }
}
