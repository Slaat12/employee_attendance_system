
<?php

// This migration must only be run from Command Prompt.
if (PHP_SAPI !== 'cli') {
    exit("Run this migration from Command Prompt.\n");
}

// Load only what the migration needs.
// Do not load the full application bootstrap.
define('APP_ROOT', __DIR__);
define('APP_TIMEZONE', 'Africa/Accra');

date_default_timezone_set(APP_TIMEZONE);

require_once __DIR__ . '/app/JsonStore.php';
require_once __DIR__ . '/app/Database.php';

$db = Database::connection();

function migrationTimestampValue(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    return date('Y-m-d H:i:s', strtotime($value));
}

function syncPostgresSequence(
    PDO $db,
    string $table,
    string $column
): void {
    $allowed = [
        'administrators' => 'admin_id',
        'employees' => 'employee_id',
        'attendance' => 'attendance_id',
        'leave_requests' => 'leave_id'
    ];

    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        throw new RuntimeException('Invalid sequence target.');
    }

    $sql = "SELECT setval(
        pg_get_serial_sequence('$table', '$column'),
        COALESCE(MAX($column), 1),
        MAX($column) IS NOT NULL
    ) FROM $table";

    $db->query($sql);
}

try {
    echo "Starting JSON to PostgreSQL migration...\n";

    // Read existing JSON records.
    $employees = JsonStore::read('employees');
    $admins = JsonStore::read('admins');
    $attendance = JsonStore::read('attendance');
    $departments = JsonStore::read('departments');
    $settings = JsonStore::read('settings');
    $leaves = JsonStore::read('leaves');

    // Refuse to overwrite existing database records.
    $tables = [
        'administrators',
        'employees',
        'departments',
        'attendance',
        'leave_requests',
        'company_settings'
    ];

    foreach ($tables as $table) {
        $count = (int) $db->query(
            "SELECT COUNT(*) FROM $table"
        )->fetchColumn();

        if ($count > 0) {
            throw new RuntimeException(
                "Table $table is not empty. Migration stopped."
            );
        }
    }

    $db->beginTransaction();

    // 1. Migrate departments.
    echo "Migrating departments...\n";

    $departmentNames = [];

    foreach ($departments as $department) {
        if (is_string($department) && trim($department) !== '') {
            $departmentNames[] = trim($department);
        }
    }

    foreach ($employees as $employee) {
        $name = trim((string) ($employee['department'] ?? ''));

        if ($name !== '') {
            $departmentNames[] = $name;
        }
    }

    $departmentNames = array_unique($departmentNames);

    $insertDepartment = $db->prepare(
        "INSERT INTO departments (department_name)
         VALUES (:name)
         ON CONFLICT DO NOTHING"
    );

    foreach ($departmentNames as $name) {
        $insertDepartment->execute(['name' => $name]);
    }

    $departmentRows = $db->query(
        "SELECT department_id, department_name FROM departments"
    )->fetchAll(PDO::FETCH_ASSOC);

    $departmentMap = [];

    foreach ($departmentRows as $row) {
        $departmentMap[strtolower($row['department_name'])] =
            (int) $row['department_id'];
    }

    // 2. Migrate administrators.
    echo "Migrating administrators...\n";

    $insertAdmin = $db->prepare(
        "INSERT INTO administrators
        (admin_id, full_name, email, password_hash, role, is_active, created_at)
        VALUES
        (:id, :name, :email, :password_hash, :role, :is_active, :created_at)"
    );

    foreach ($admins as $admin) {
        $hash = $admin['password_hash'] ?? null;

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException(
                'An administrator has no valid password hash.'
            );
        }

        $insertAdmin->execute([
            'id' => (int) ($admin['id'] ?? 1),
            'name' => $admin['name'] ?? 'System Administrator',
            'email' => $admin['email'] ?? '',
            'password_hash' => $hash,
            'role' => $admin['role'] ?? 'admin',
            'is_active' => $admin['is_active'] ?? true,
            'created_at' => migrationTimestampValue(
                $admin['created_at'] ?? null
            )
        ]);
    }

    // 3. Migrate employees.
    echo "Migrating employees...\n";

    $insertEmployee = $db->prepare(
        "INSERT INTO employees
        (employee_id, employee_code, first_name, last_name, email, phone,
         department_id, pin_hash, status, created_at)
        VALUES
        (:id, :code, :first_name, :last_name, :email, :phone,
         :department_id, :pin_hash, :status, :created_at)"
    );

    foreach ($employees as $employee) {
        $hash = $employee['pin_hash'] ?? null;

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException(
                'An employee has no valid PIN hash.'
            );
        }

        $departmentName = strtolower(trim(
            (string) ($employee['department'] ?? '')
        ));

        $insertEmployee->execute([
            'id' => (int) $employee['id'],
            'code' => $employee['employee_id'],
            'first_name' => $employee['first_name'],
            'last_name' => $employee['last_name'],
            'email' => $employee['email'] ?? null,
            'phone' => $employee['phone'] ?? null,
            'department_id' => $departmentMap[$departmentName] ?? null,
            'pin_hash' => $hash,
            'status' => $employee['status'] ?? 'active',
            'created_at' => migrationTimestampValue(
                $employee['created_at'] ?? null
            )
        ]);
    }

    // 4. Migrate attendance records.
    echo "Migrating attendance records...\n";

    $insertAttendance = $db->prepare(
        "INSERT INTO attendance
        (attendance_id, employee_id, check_in, check_out,
         attendance_date, status, notes, created_at)
        VALUES
        (:id, :employee_id, :check_in, :check_out,
         :attendance_date, :status, :notes, :created_at)"
    );

    foreach ($attendance as $record) {
        $checkIn = migrationTimestampValue(
            $record['check_in'] ?? null
        );

        if ($checkIn === null) {
            throw new RuntimeException(
                'An attendance record has no check-in time.'
            );
        }

        $insertAttendance->execute([
            'id' => (int) $record['id'],
            'employee_id' => (int) $record['employee_id'],
            'check_in' => $checkIn,
            'check_out' => migrationTimestampValue(
                $record['check_out'] ?? null
            ),
            'attendance_date' => date('Y-m-d', strtotime($checkIn)),
            'status' => $record['status'] ?? 'present',
            'notes' => $record['notes'] ?? null,
            'created_at' => migrationTimestampValue(
                $record['created_at'] ?? null
            )
        ]);
    }

    // 5. Migrate leave requests.
    echo "Migrating leave requests...\n";

    $insertLeave = $db->prepare(
        "INSERT INTO leave_requests
        (leave_id, employee_id, start_date, end_date, reason, status, created_at)
        VALUES
        (:id, :employee_id, :start_date, :end_date, :reason, :status, :created_at)"
    );

    foreach ($leaves as $leave) {
        $insertLeave->execute([
            'id' => (int) $leave['id'],
            'employee_id' => (int) $leave['employee_id'],
            'start_date' => $leave['start_date'],
            'end_date' => $leave['end_date'],
            'reason' => $leave['reason'] ?? null,
            'status' => $leave['status'] ?? 'pending',
            'created_at' => migrationTimestampValue(
                $leave['created_at'] ?? null
            )
        ]);
    }

    // 6. Migrate company settings.
    echo "Migrating company settings...\n";

    $workDays = $settings['work_days'] ?? [1, 2, 3, 4, 5];

    $workDays = array_values(
        array_filter(
            array_map('intval', $workDays),
            fn($day) => $day >= 1 && $day <= 7
        )
    );

    $workDaysSql = '{' . implode(',', $workDays) . '}';

    $insertSettings = $db->prepare(
        "INSERT INTO company_settings
        (setting_id, company_name, start_time, end_time, grace_minutes, work_days)
        VALUES
        (1, :company_name, :start_time, :end_time, :grace_minutes,
         CAST(:work_days AS SMALLINT[]))"
    );

    $insertSettings->execute([
        'company_name' => $settings['company_name'] ?? 'Northstar Company',
        'start_time' => $settings['start_time'] ?? '08:00',
        'end_time' => $settings['end_time'] ?? '17:00',
        'grace_minutes' => (int) ($settings['grace_minutes'] ?? 15),
        'work_days' => $workDaysSql
    ]);

    // 7. Synchronize database ID sequences.
    echo "Synchronizing database sequences...\n";

    syncPostgresSequence($db, 'administrators', 'admin_id');
    syncPostgresSequence($db, 'employees', 'employee_id');
    syncPostgresSequence($db, 'attendance', 'attendance_id');
    syncPostgresSequence($db, 'leave_requests', 'leave_id');

    $db->commit();

    echo "\nMigration completed successfully!\n";
    echo "Your original JSON files have NOT been deleted.\n";

} catch (Throwable $error) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    echo "\nMIGRATION FAILED:\n";
    echo $error->getMessage() . "\n";
    echo "No partial migration was committed.\n";

    exit(1);
}
