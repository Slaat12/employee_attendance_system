
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/EmployeeRepository.php';

require_admin();

$departments = EmployeeRepository::departments();

$error = null;
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = post_str('action');

    if ($action === 'save') {

        $id = (int) post_str('id');

        $f = [
            'id' => $id,
            'employee_id' => strtoupper(post_str('employee_id')),
            'first_name' => preg_replace('/\s+/u', ' ', post_str('first_name')),
            'last_name' => preg_replace('/\s+/u', ' ', post_str('last_name')),
            'email' => post_str('email'),
            'phone' => post_str('phone'),
            'department' => post_str('department'),
        ];

        $pin = post_raw('pin');
        $form = $f;

        if (!valid_employee_id($f['employee_id'])) {

            $error = 'Employee ID must be 3–20 letters, numbers or hyphens.';

        } elseif (
            !valid_person_name($f['first_name']) ||
            !valid_person_name($f['last_name'])
        ) {

            $error = 'Names must start with a letter and contain only letters, spaces, apostrophes, periods and hyphens.';

        } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {

            $error = 'Enter a valid email address.';

        } elseif (!valid_phone($f['phone'])) {

            $error = 'Phone number must contain 10–15 digits only.';

        } elseif (!in_array($f['department'], $departments, true)) {

            $error = 'Choose a valid department.';

        } elseif (!$id && !valid_pin($pin)) {

            $error = 'New employees need a 4–8 digit numeric PIN.';

        } elseif ($id && $pin !== '' && !valid_pin($pin)) {

            $error = 'PIN must contain 4–8 digits.';

        }

        if (!$error) {

            $pinHash = $pin !== ''
                ? password_hash($pin, PASSWORD_DEFAULT)
                : null;

            try {

                $saved = EmployeeRepository::save(
                    $f,
                    $id ?: null,
                    $pinHash
                );

                // save() returns null on success and an error string on failure.
                if ($saved !== null) {

                    $error = $saved;

                } else {

                    flash(
                        'success',
                        $id
                            ? 'Employee updated successfully.'
                            : 'Employee registered successfully.'
                    );

                    redirect('admin/employees.php');
                }

            } catch (Throwable $exception) {

                error_log('EMPLOYEE SAVE ERROR: ' . $exception->getMessage());

                $error = 'A database error occurred while saving the employee.';
            }
        }

    } elseif ($action === 'toggle') {

        $id = (int) post_str('id');

        try {

            $employee = EmployeeRepository::findById($id);

            if (!$employee) {

                flash('error', 'Employee not found.');

            } else {

                $newStatus = $employee['status'] === 'active'
                    ? 'inactive'
                    : 'active';

                EmployeeRepository::setStatus($id, $newStatus);

                flash('success', 'Employee status updated.');
            }

        } catch (Throwable $exception) {

            error_log($exception->getMessage());

            flash('error', 'Unable to update employee status.');
        }

        redirect('admin/employees.php');
    }
}

// Load employees from PostgreSQL.
$employees = EmployeeRepository::all();

$edit = null;

if (!$form && get_str('edit') !== '') {
    $edit = EmployeeRepository::findById((int) get_str('edit'));
}

$f = $form ?? $edit ?? [];

$showForm = $form !== null ||
            $edit !== null ||
            get_str('new') !== '';

$isEdit = !empty($f['id']);

$pageTitle = 'Employees';
$active = 'employees';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';

?>

<div class="section-heading">

    <div>
        <h2>Employee directory</h2>
        <p class="muted">Register and maintain company employee profiles.</p>
    </div>

    <button class="btn primary" type="button" data-toggle="employee-form">
        ＋ Add employee
    </button>

</div>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel form-panel" id="employee-form" <?= $showForm ? '' : 'hidden' ?>>

    <div class="panel-heading">

        <h3><?= $isEdit ? 'Edit employee' : 'Register new employee' ?></h3>

        <button class="icon-button" type="button"
                data-toggle="employee-form" aria-label="Close form">
            ×
        </button>

    </div>

    <form method="post" class="form-grid">

        <?= csrf_field() ?>

        <input type="hidden" name="action" value="save">

        <input type="hidden" name="id"
               value="<?= e($f['id'] ?? '') ?>">

        <label>
            Employee ID

            <input name="employee_id"
                   required
                   maxlength="20"
                   pattern="[A-Za-z0-9\-]{3,20}"
                   autocomplete="off"
                   value="<?= e($f['employee_id'] ?? '') ?>"
                   placeholder="EMP004">
        </label>

        <label>
            First name

            <input name="first_name"
                   required
                   maxlength="60"
                   autocomplete="off"
                   value="<?= e($f['first_name'] ?? '') ?>">
        </label>

        <label>
            Last name

            <input name="last_name"
                   required
                   maxlength="60"
                   autocomplete="off"
                   value="<?= e($f['last_name'] ?? '') ?>">
        </label>

        <label>
            Email address

            <input type="email"
                   name="email"
                   required
                   maxlength="120"
                   autocomplete="off"
                   value="<?= e($f['email'] ?? '') ?>">
        </label>

        <label>
            Phone number

            <input name="phone"
                   required
                   inputmode="numeric"
                   pattern="[0-9]{10,15}"
                   maxlength="15"
                   autocomplete="off"
                   value="<?= e($f['phone'] ?? '') ?>"
                   placeholder="0241234567">
        </label>

        <label>
            Department

            <select name="department" required>

                <option value="">Select department</option>

                <?php foreach ($departments as $d): ?>

                    <option
                        <?= ($f['department'] ?? '') === $d ? 'selected' : '' ?>
                        value="<?= e($d) ?>">

                        <?= e($d) ?>

                    </option>

                <?php endforeach; ?>

            </select>
        </label>

        <label>
            Numeric PIN <?= $isEdit ? '(leave blank to keep current)' : '' ?>

            <input name="pin"
                   type="password"
                   inputmode="numeric"
                   pattern="[0-9]{4,8}"
                   maxlength="8"
                   autocomplete="new-password"
                   <?= $isEdit ? '' : 'required' ?>>
        </label>

        <div class="form-actions">

            <button class="btn primary">Save employee</button>

            <a class="btn outline"
               href="<?= e(url('admin/employees.php')) ?>">
                Cancel
            </a>

        </div>

    </form>

</section>

<div class="table-card">

    <div class="table-toolbar">

        <div>
            <b>
                <?= count($employees) ?>
                employee<?= count($employees) === 1 ? '' : 's' ?>
            </b>

            <span class="muted"> · Company directory</span>
        </div>

        <input class="table-search"
               data-table-search="employees-table"
               placeholder="Search employees...">

    </div>

    <div class="table-scroll">

        <table id="employees-table">

            <thead>
                <tr>
                    <th>EMPLOYEE</th>
                    <th>DEPARTMENT</th>
                    <th>EMAIL</th>
                    <th>PHONE</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($employees as $x): ?>

                    <?php $isActive = $x['status'] === 'active'; ?>

                    <tr>

                        <td>

                            <div class="person-cell">

                                <span class="avatar">
                                    <?= e(initials($x['first_name'] . ' ' . $x['last_name'])) ?>
                                </span>

                                <span>

                                    <b>
                                        <?= e($x['first_name'] . ' ' . $x['last_name']) ?>
                                    </b>

                                    <small>
                                        <?= e($x['employee_id']) ?>
                                    </small>

                                </span>

                            </div>

                        </td>

                        <td><?= e($x['department']) ?></td>
                        <td><?= e($x['email']) ?></td>
                        <td><?= e($x['phone']) ?></td>

                        <td>

                            <span class="status <?= $isActive ? 'present' : 'inactive' ?>">
                                <?= e(ucfirst($x['status'])) ?>
                            </span>

                        </td>

                        <td>

                            <div class="actions">

                                <a class="small-link"
                                   href="?edit=<?= (int) $x['id'] ?>">
                                    Edit
                                </a>

                                <form method="post"
                                    <?= $isActive
                                        ? 'data-confirm="Deactivate ' .
                                            e($x['first_name'] . ' ' . $x['last_name']) .
                                            '? They will no longer be able to check in."'
                                        : '' ?>>

                                    <?= csrf_field() ?>

                                    <input type="hidden" name="action" value="toggle">

                                    <input type="hidden" name="id"
                                           value="<?= (int) $x['id'] ?>">

                                    <button class="small-link <?= $isActive ? 'danger-link' : '' ?>">
                                        <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (!$employees): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No employees have been added.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
