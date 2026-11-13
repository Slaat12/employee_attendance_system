
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$date = get_str('date');

if (!valid_date($date)) {
    $date = today_string();
}

$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));

// Retrieve attendance records and employee details from PostgreSQL.
$rows = AttendanceService::rowsForDate($date);

$pageTitle = 'Attendance records';
$active = 'attendance';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';

?>

<div class="section-heading">
    <div>
        <h2>Attendance history</h2>
        <p class="muted">Review employee check-in and check-out activity.</p>
    </div>

    <form class="inline-filter" method="get">

        <a class="btn outline"
           href="?date=<?= e($prev) ?>"
           title="Previous day">‹</a>

        <label>
            Attendance date
            <input
                type="date"
                name="date"
                value="<?= e($date) ?>"
                max="<?= e(today_string()) ?>"
            >
        </label>

        <a class="btn outline"
           href="?date=<?= e($next) ?>"
           title="Next day">›</a>

        <button class="btn primary" type="submit">Filter</button>

        <a class="btn outline"
           href="?date=<?= e(today_string()) ?>">Today</a>

    </form>
</div>

<div class="table-card">

    <div class="table-toolbar">
        <b>
            <?= count($rows) ?>
            record<?= count($rows) === 1 ? '' : 's' ?>
            for <?= e(fmt_date($date)) ?>
        </b>

        <input
            class="table-search"
            data-table-search="attendance-table"
            placeholder="Search records..."
        >
    </div>

    <div class="table-scroll">

        <table id="attendance-table">

            <thead>
                <tr>
                    <th>EMPLOYEE</th>
                    <th>DATE</th>
                    <th>CHECK IN</th>
                    <th>CHECK OUT</th>
                    <th>HOURS WORKED</th>
                    <th>STATUS</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($rows as $r): ?>

                    <tr>

                        <td>
                            <b>
                                <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
                            </b>

                            <small class="block-muted">
                                <?= e($r['employee_id']) ?>
                            </small>
                        </td>

                        <td><?= e(fmt_date($r['check_in'])) ?></td>

                        <td><?= e(fmt_time($r['check_in'])) ?></td>

                        <td><?= checkout_cell($r) ?></td>

                        <td>
                            <?= e(format_hours($r['check_in'], $r['check_out'])) ?>
                        </td>

                        <td>
                            <span class="status <?= $r['status'] === 'Late' ? 'late' : 'present' ?>">
                                <?= e($r['status']) ?>
                            </span>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (!$rows): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No attendance records found for this date.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
