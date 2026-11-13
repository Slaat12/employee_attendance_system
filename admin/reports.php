
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$from = get_str('from');
$to = get_str('to');

if (!valid_date($from)) {
    $from = date('Y-m-01');
}

if (!valid_date($to)) {
    $to = today_string();
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// Retrieve attendance and employee information from PostgreSQL.
$rows = AttendanceService::rowsBetween($from, $to);

// CSV export.
if (get_str('export') === 'csv') {

    header('Content-Type: text/csv; charset=utf-8');

    header(
        'Content-Disposition: attachment; filename="attendance-report-' .
        $from . '_to_' . $to . '.csv"'
    );

    $out = fopen('php://output', 'w');

    // BOM allows Microsoft Excel to display accented names correctly.
    fwrite($out, "\xEF\xBB\xBF");

    $csv = fn(array $fields) => fputcsv($out, $fields, ',', '"', '');

    $csv([
        'Employee ID',
        'Employee Name',
        'Department',
        'Date',
        'Check In',
        'Check Out',
        'Hours',
        'Status'
    ]);

    foreach ($rows as $r) {

        $hours = hours_between(
            $r['check_in'],
            $r['check_out']
        );

        $status = is_missed_checkout($r)
            ? $r['status'] . ' (missed check-out)'
            : $r['status'];

        $csv([
            $r['employee_code'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['department_name'],
            substr($r['check_in'], 0, 10),
            $r['check_in'],
            $r['check_out'] ?? '',
            $hours === null ? '' : number_format($hours, 2, '.', ''),
            $status
        ]);
    }

    fclose($out);
    exit;
}

// Calculate report summaries.
$totalHours = 0.0;
$missed = 0;
$lateCount = 0;

foreach ($rows as $r) {

    $totalHours += hours_between(
        $r['check_in'],
        $r['check_out']
    ) ?? 0;

    if (is_missed_checkout($r)) {
        $missed++;
    }

    if ($r['status'] === 'Late') {
        $lateCount++;
    }
}

$pageTitle = 'Reports';
$active = 'reports';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';

?>

<div class="section-heading">

    <div>
        <h2>Attendance reports</h2>
        <p class="muted">
            Analyze attendance over a selected period.
        </p>
    </div>

    <a class="btn outline"
       href="?from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;export=csv">
        ↓ Export CSV
    </a>

</div>

<form class="panel inline-filter report-filter" method="get">

    <label>
        From
        <input type="date" name="from" value="<?= e($from) ?>">
    </label>

    <label>
        To
        <input type="date" name="to" value="<?= e($to) ?>">
    </label>

    <button class="btn primary" type="submit">
        Generate report
    </button>

</form>

<div class="stats-grid three">

    <div class="stat-card">
        <span class="stat-label">Attendance records</span>
        <strong><?= count($rows) ?></strong>
        <small>
            <?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?>
        </small>
    </div>

    <div class="stat-card">
        <span class="stat-label">Late arrivals</span>
        <strong><?= $lateCount ?></strong>
        <small>Selected period</small>
    </div>

    <div class="stat-card">
        <span class="stat-label">Recorded work hours</span>
        <strong><?= number_format($totalHours, 1) ?></strong>
        <small>
            Completed shifts
            <?= $missed
                ? ' · ' . $missed . ' missed check-out' . ($missed === 1 ? '' : 's')
                : ''
            ?>
        </small>
    </div>

</div>

<div class="table-card">

    <div class="table-toolbar">
        <b>Report details</b>
    </div>

    <div class="table-scroll">

        <table>

            <thead>
                <tr>
                    <th>EMPLOYEE</th>
                    <th>DEPARTMENT</th>
                    <th>DATE</th>
                    <th>CHECK IN</th>
                    <th>CHECK OUT</th>
                    <th>HOURS</th>
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
                                <?= e($r['employee_code']) ?>
                            </small>
                        </td>

                        <td><?= e($r['department_name']) ?></td>

                        <td><?= e(substr($r['check_in'], 0, 10)) ?></td>

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
                        <td colspan="7" class="empty">
                            No records in this period.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
