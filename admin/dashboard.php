
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

require_once APP_ROOT . '/app/EmployeeRepository.php';

// Retrieve employees from PostgreSQL.
$employees = EmployeeRepository::all();

// Retrieve today's attendance from PostgreSQL.
$today = AttendanceService::todayRows();

// Dashboard statistics.
$late = count(array_filter(
    $today,
    fn($r) => $r['status'] === 'Late'
));

$workingNow = count(array_filter(
    $today,
    fn($r) => $r['check_out'] === null
));

$activeStaff = count(array_filter(
    $employees,
    fn($x) => strtolower($x['status']) === 'active'
));

// Greeting based on Ghana time.
$hour = (int) date('G');

$greeting = $hour < 12
    ? 'morning'
    : ($hour < 17 ? 'afternoon' : 'evening');

$pageTitle = 'Dashboard';
$active = 'dashboard';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';
?>

<div class="welcome-row">
    <div>
        <h2>Good <?= $greeting ?>, <?= e(Auth::user()['name']) ?></h2>
        <p class="muted">
            Here is your workforce attendance overview for today.
        </p>
    </div>

    <a class="btn primary"
       href="<?= e(url('admin/employees.php?new=1')) ?>">
        ＋ Add employee
    </a>
</div>

<div class="stats-grid">

    <div class="stat-card">
        <span class="stat-icon navy">♙</span>
        <span class="stat-label">Total employees</span>
        <strong><?= $activeStaff ?></strong>
        <small>Active workforce</small>
    </div>

    <div class="stat-card">
        <span class="stat-icon green">✓</span>
        <span class="stat-label">Present today</span>
        <strong><?= count($today) ?></strong>
        <small>Checked in today</small>
    </div>

    <div class="stat-card">
        <span class="stat-icon amber">◷</span>
        <span class="stat-label">Late arrivals</span>
        <strong><?= $late ?></strong>
        <small>After grace period</small>
    </div>

    <div class="stat-card">
        <span class="stat-icon blue">⇄</span>
        <span class="stat-label">Currently working</span>
        <strong><?= $workingNow ?></strong>
        <small>Not checked out yet</small>
    </div>

</div>

<div class="section-heading">
    <div>
        <h2>Today's attendance</h2>
        <p class="muted">Latest check-in activity</p>
    </div>

    <a class="text-link"
       href="<?= e(url('admin/attendance.php')) ?>">
        View all attendance →
    </a>
</div>

<div class="table-card">
    <div class="table-scroll">

        <table>
            <thead>
                <tr>
                    <th>EMPLOYEE</th>
                    <th>CHECK IN</th>
                    <th>CHECK OUT</th>
                    <th>HOURS</th>
                    <th>STATUS</th>
                </tr>
            </thead>

            <tbody>

            <?php
            $shown = 0;

            foreach ($today as $row):

                if (++$shown > 8) {
                    break;
                }

                $fullName = trim(
                    $row['first_name'] . ' ' . $row['last_name']
                );
            ?>

                <tr>
                    <td>
                        <div class="person-cell">

                            <span class="avatar">
                                <?= e(initials($fullName)) ?>
                            </span>

                            <span>
                                <b><?= e($fullName) ?></b>
                                <small>
                                    <?= e($row['employee_code']) ?>
                                </small>
                            </span>

                        </div>
                    </td>

                    <td>
                        <?= e(fmt_time($row['check_in'])) ?>
                    </td>

                    <td>
                        <?= checkout_cell($row) ?>
                    </td>

                    <td>
                        <?= e(format_hours(
                            $row['check_in'],
                            $row['check_out']
                        )) ?>
                    </td>

                    <td>
                        <span class="status <?= $row['status'] === 'Late' ? 'late' : 'present' ?>">
                            <?= e($row['status']) ?>
                        </span>
                    </td>
                </tr>

            <?php endforeach; ?>

            <?php if ($shown === 0): ?>

                <tr>
                    <td colspan="5" class="empty">
                        No attendance has been recorded today.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>

    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
