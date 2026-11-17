<?php

require_once __DIR__ . '/Database.php';

class EmployeeRepository
{
    private static function db(): PDO
    {
        return Database::connection();
    }

    /**
     * Convert a PostgreSQL employee row into
     * the format used by the existing application.
     */
    private static function formatEmployee(array $row): array
    {
        return [
            'id' => (int) $row['employee_id'],
            'employee_id' => $row['employee_code'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'department' => $row['department_name'],
            'pin_hash' => $row['pin_hash'],
            'status' => $row['status'],
            'created_at' => $row['created_at']
        ];
    }

    /**
     * Retrieve all employees.
     */
    public static function all(): array
    {
        $sql = "
            SELECT
                e.*,
                d.department_name
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.department_id
            ORDER BY e.employee_id DESC
        ";

        $stmt = self::db()->query($sql);

        return array_map(
            [self::class, 'formatEmployee'],
            $stmt->fetchAll()
        );
    }

    /**
     * Find an employee using the internal database ID.
     */
    public static function findById(int $id): ?array
    {
        $sql = "
            SELECT
                e.*,
                d.department_name
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.department_id
            WHERE e.employee_id = :id
            LIMIT 1
        ";

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row
            ? self::formatEmployee($row)
            : null;
    }

    /**
     * Find an employee using their employee code.
     */
    public static function findByCode(string $code): ?array
    {
        $sql = "
            SELECT
                e.*,
                d.department_name
            FROM employees e
            LEFT JOIN departments d
                ON e.department_id = d.department_id
            WHERE e.employee_code = :code
            LIMIT 1
        ";

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['code' => trim($code)]);

        $row = $stmt->fetch();

        return $row
            ? self::formatEmployee($row)
            : null;
    }

    /**
     * Save a new employee or update an existing employee.
     *
     * Returns null when successful, otherwise an error message.
     */
    public static function save(
        array $data,
        ?int $id = null,
        ?string $pinHash = null
    ): ?string {

        $db = self::db();

        $code = trim($data['employee_id'] ?? '');
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $department = trim($data['department'] ?? '');

        if (
            ($id !== null && $code === '') ||
            $firstName === '' ||
            $lastName === '' ||
            $department === ''
        ) {
            return 'Please complete all required employee fields.';
        }

        $saveStage = 'starting transaction';

        try {
            $saveStage = 'begin transaction';
            $db->beginTransaction();

            // Generate the next employee code for new employees.
            // A transaction-level advisory lock prevents two registrations
            // from choosing the same number at the same time.
            if ($id === null) {
                $saveStage = 'acquire employee-code lock';
                $db->query("SELECT pg_advisory_xact_lock(731902, 1)");

                $saveStage = 'generate next employee code';
                $nextNumber = (int) $db->query("
                    SELECT COALESCE(
                        MAX(
                            CASE
                                WHEN employee_code ~ '^EMP[0-9]+$'
                                THEN SUBSTRING(employee_code FROM 4)::INTEGER
                                ELSE 0
                            END
                        ),
                        0
                    )
                    FROM employees
                ")->fetchColumn() + 1;

                $code = 'EMP' . str_pad(
                    (string) $nextNumber,
                    3,
                    '0',
                    STR_PAD_LEFT
                );
            } elseif ($code === '') {
                $db->rollBack();
                return 'Employee ID is required.';
            }

            $saveStage = 'prepare department lookup';
            $deptStmt = $db->prepare("
                SELECT department_id
                FROM departments
                WHERE LOWER(department_name) = LOWER(:name)
                LIMIT 1
            ");

            $saveStage = 'execute department lookup';
            $deptStmt->execute(['name' => $department]);

            $departmentId = $deptStmt->fetchColumn();

            if (!$departmentId) {
                $db->rollBack();
                return 'The selected department does not exist.';
            }

            // Check whether another employee uses this code.
            $checkSql = "
                SELECT employee_id
                FROM employees
                WHERE LOWER(employee_code) = LOWER(:code)
            ";

            if ($id !== null) {
                $checkSql .= " AND employee_id <> :id";
            }

            $saveStage = 'prepare duplicate employee-code check';
            $check = $db->prepare($checkSql);

            $params = ['code' => $code];

            if ($id !== null) {
                $params['id'] = $id;
            }

            $saveStage = 'execute duplicate employee-code check';
            $check->execute($params);

            if ($check->fetch()) {
                $db->rollBack();
                return 'This employee ID is already in use.';
            }

            // Insert a new employee.
            if ($id === null) {

                if ($pinHash === null) {
                    $db->rollBack();
                    return 'A valid employee PIN is required.';
                }

                $sql = "
                    INSERT INTO employees (
                        employee_code,
                        first_name,
                        last_name,
                        email,
                        phone,
                        department_id,
                        pin_hash,
                        status
                    )
                    VALUES (
                        :code,
                        :first_name,
                        :last_name,
                        :email,
                        :phone,
                        :department_id,
                        :pin_hash,
                        'active'
                    )
                ";

                $saveStage = 'prepare employee insert';
                $stmt = $db->prepare($sql);

                $saveStage = 'execute employee insert';
                $stmt->execute([
                    'code' => $code,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'department_id' => $departmentId,
                    'pin_hash' => $pinHash
                ]);

            } else {

                // Update an existing employee.
                if ($pinHash !== null) {
                    $sql = "
                        UPDATE employees
                        SET
                            employee_code = :code,
                            first_name = :first_name,
                            last_name = :last_name,
                            email = :email,
                            phone = :phone,
                            department_id = :department_id,
                            pin_hash = :pin_hash
                        WHERE employee_id = :id
                    ";
                } else {
                    $sql = "
                        UPDATE employees
                        SET
                            employee_code = :code,
                            first_name = :first_name,
                            last_name = :last_name,
                            email = :email,
                            phone = :phone,
                            department_id = :department_id
                        WHERE employee_id = :id
                    ";
                }

                $params = [
                    'code' => $code,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'department_id' => $departmentId,
                    'id' => $id
                ];

                if ($pinHash !== null) {
                    $params['pin_hash'] = $pinHash;
                }

                $saveStage = 'prepare employee update';
                $stmt = $db->prepare($sql);
                $saveStage = 'execute employee update';
                $stmt->execute($params);
            }

            $saveStage = 'commit transaction';
            $db->commit();

            return null;

        } catch (PDOException $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(
                'EMPLOYEE SAVE ERROR at [' . $saveStage . ']: ' .
                $e->getMessage()
            );

            return 'Unable to save the employee. Please check the details and try again.';
        }
    }

    /**
     * Activate or deactivate an employee.
     */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            return false;
        }

        $stmt = self::db()->prepare("
            UPDATE employees
            SET status = :status
            WHERE employee_id = :id
        ");

        $stmt->execute([
            'status' => $status,
            'id' => $id
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Retrieve all department names.
     */
    public static function departments(): array
    {
        $stmt = self::db()->query("
            SELECT department_name
            FROM departments
            ORDER BY department_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Add a department.
     */
    public static function addDepartment(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return 'Department name is required.';
        }

        try {
            $stmt = self::db()->prepare("
                INSERT INTO departments (department_name)
                VALUES (:name)
            ");

            $stmt->execute(['name' => $name]);

            return null;

        } catch (PDOException $e) {

            error_log('Department creation error: ' . $e->getMessage());

            return 'Unable to add this department. It may already exist.';
        }
    }
}
