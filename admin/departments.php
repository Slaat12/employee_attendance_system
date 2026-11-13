
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = preg_replace('/\s+/u', ' ', trim(post_str('name')));

    if (!preg_match("/^\p{L}[\p{L} &'-]{1,49}$/u", $name)) {
        $error = 'Enter a valid department name (letters, spaces, &, apostrophes and hyphens; 2–50 characters).';
    } else {
        try {
            $db = Database::connection();

            $stmt = $db->prepare(
                'INSERT INTO departments (department_name)
                 VALUES (:name)'
            );

            $stmt->execute(['name' => $name]);

            flash('success', 'Department added.');
            redirect('admin/departments.php');

        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $error = 'That department already exists.';
            } else {
                error_log('DEPARTMENT SAVE ERROR: ' . $e->getMessage());
                $error = 'Unable to add the department. Please try again.';
            }
        }
    }
}

$db = Database::connection();

$stmt = $db->query(
    "SELECT
        d.department_name,
        COUNT(e.employee_id) AS employee_count
     FROM departments d
     LEFT JOIN employees e
        ON e.department_id = d.department_id
     GROUP BY d.department_id, d.department_name
     ORDER BY d.department_name ASC"
);

$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Departments';
$active = 'departments';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';
?>

<div class="section-heading">
    <div>
        <h2>Company departments</h2>
        <p class="muted">Organize employees by department.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<div class="two-column">

    <section class="panel">
        <h3>Add department</h3>

        <form method="post" class="form-stack">
            <?= csrf_field() ?>

            <label>
                Department name
                <input
                    name="name"
                    maxlength="50"
                    required
                    placeholder="e.g. Marketing"
                    autocomplete="off"
                    value="<?= $error ? e(old('name')) : '' ?>"
                >
            </label>

            <button class="btn primary" type="submit">
                Add department
            </button>
        </form>
    </section>

    <section class="panel">
        <h3>Department list</h3>

        <?php if (empty($departments)): ?>
            <p class="muted">No departments have been added yet.</p>
        <?php else: ?>

            <?php foreach ($departments as $department): ?>
                <?php $count = (int) $department['employee_count']; ?>

                <div class="department-row">
                    <span>
                        ▤ <?= e($department['department_name']) ?>
                    </span>

                    <span class="muted">
                        <?= $count ?>
                        employee<?= $count === 1 ? '' : 's' ?>
                    </span>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>

</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
