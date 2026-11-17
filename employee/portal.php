<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$employee = $_SESSION['employee_portal'] ?? null;
$error = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['employee_portal']);
    session_regenerate_id(true);
    redirect('employee/portal.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action') === 'logout') {
    verify_csrf();
    unset($_SESSION['employee_portal']);
    session_regenerate_id(true);
    redirect('employee/portal.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action') === 'login') {
    verify_csrf();
    $code = strtoupper(post_str('employee_id'));
    $pin = post_raw('pin');

    if (!valid_employee_id($code) || !valid_pin($pin)) {
        $error = 'Enter a valid Employee ID and your 4–8 digit PIN.';
    } else {
        $key = Throttle::key('employee-portal', $code . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (($wait = Throttle::blockedFor($key)) > 0) {
            $error = Throttle::message($wait);
        } elseif ($verified = Auth::verifyEmployee($code, $pin)) {
            Throttle::clear($key);
            session_regenerate_id(true);
            $_SESSION['employee_portal'] = [
                'id' => (int) $verified['id'],
                'employee_id' => $verified['employee_id'],
                'first_name' => $verified['first_name'],
                'last_name' => $verified['last_name'],
                'department' => $verified['department'],
                'logged_in_at' => time()
            ];
            redirect('employee/portal.php');
        } else {
            Throttle::fail($key);
            $error = 'Employee ID or PIN is incorrect, or the account is inactive.';
        }
    }
}

$employee = $_SESSION['employee_portal'] ?? null;

// End the employee portal session after 20 minutes of inactivity.
if (is_array($employee) && time() - (int)($employee['logged_in_at'] ?? 0) > APP_SESSION_LIFETIME) {
    unset($_SESSION['employee_portal']);
    session_regenerate_id(true);
    $employee = null;
    $error = 'Your session expired. Please sign in again.';
}
if (is_array($employee)) {
    $_SESSION['employee_portal']['logged_in_at'] = time();

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $stmt = Database::connection()->prepare("
            SELECT attendance_date, check_in, check_out, status, notes
            FROM attendance
            WHERE employee_id = :employee_id
            ORDER BY attendance_date DESC, check_in DESC
        ");
        $stmt->execute(['employee_id' => $employee['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-attendance.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Check In', 'Check Out', 'Status', 'Notes']);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    $stmt = Database::connection()->prepare("
        SELECT attendance_date, check_in, check_out, status, notes
        FROM attendance
        WHERE employee_id = :employee_id
        ORDER BY attendance_date DESC, check_in DESC
        LIMIT 100
    ");
    $stmt->execute(['employee_id' => $employee['id']]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRecords = count($records);
} else {
    $records = [];
    $totalRecords = 0;
}

$pageTitle = 'Employee self-service portal';
require APP_ROOT . '/includes/header.php';
?>
<style>
.portal-wrap{max-width:1100px;margin:40px auto;padding:0 22px}.portal-top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.portal-card{background:#fff;border:1px solid #e7eaf0;border-radius:16px;padding:26px;box-shadow:0 8px 28px rgba(20,35,60,.05)}.portal-login{max-width:460px;margin:55px auto}.portal-table{width:100%;border-collapse:collapse}.portal-table th,.portal-table td{padding:13px 12px;text-align:left;border-bottom:1px solid #edf0f4;font-size:14px}.portal-table th{color:#687386;font-size:11px;letter-spacing:.06em}.portal-actions{display:flex;gap:10px;flex-wrap:wrap}.portal-muted{color:#758094}.portal-title{margin:0 0 6px}.portal-table-wrap{overflow-x:auto}@media(max-width:650px){.portal-top{align-items:flex-start;flex-direction:column}.portal-card{padding:18px}.portal-table{min-width:650px}}
</style>
<div class="portal-wrap">
<?php if (!$employee): ?>
    <section class="portal-card portal-login">
        <span class="eyebrow">EMPLOYEE SELF-SERVICE</span>
        <h1 class="portal-title">Employee sign in</h1>
        <p class="portal-muted">Sign in to view your personal attendance history.</p>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <label>Employee ID
                <input name="employee_id" required maxlength="20" autocomplete="username" placeholder="e.g. EMP001" value="<?= e(strtoupper(post_str('employee_id'))) ?>">
            </label>
            <label>Personal PIN
                <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required autocomplete="current-password" placeholder="Enter your PIN">
            </label>
            <button class="btn primary full">Sign in</button>
        </form>
        <p class="portal-muted" style="margin-top:20px"><a href="<?= e(url('employee/kiosk.php')) ?>">← Return to attendance kiosk</a></p>
    </section>
<?php else: ?>
    <div class="portal-top">
        <div>
            <span class="eyebrow">EMPLOYEE SELF-SERVICE</span>
            <h1 class="portal-title">Welcome, <?= e($employee['first_name']) ?></h1>
            <p class="portal-muted"><?= e($employee['employee_id']) ?> · <?= e($employee['department'] ?? 'No department') ?></p>
        </div>
        <div class="portal-actions">
            <a class="btn outline" href="<?= e(url('employee/portal.php?export=csv')) ?>">Download my records (CSV)</a>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="logout">
                <button class="btn primary">Sign out</button>
            </form>
        </div>
    </div>
    <section class="portal-card" style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
        <div><h2 class="portal-title">Leave management</h2><p class="portal-muted">Check your annual leave balance, submit a request and follow its status.</p></div>
        <a class="btn primary" href="<?= e(url('employee/leave.php')) ?>">Manage my leave →</a>
    </section>
    <section class="portal-card">
        <h2 class="portal-title">My attendance history</h2>
        <p class="portal-muted">Your latest <?= $totalRecords ?> attendance record(s). Only your own records are shown here.</p>
        <div class="portal-table-wrap">
        <table class="portal-table">
            <thead><tr><th>DATE</th><th>CHECK IN</th><th>CHECK OUT</th><th>STATUS</th><th>NOTES</th></tr></thead>
            <tbody>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime($record['attendance_date']))) ?></td>
                    <td><?= e(fmt_time($record['check_in'])) ?></td>
                    <td><?= e(fmt_time($record['check_out'])) ?></td>
                    <td><?= e(ucfirst((string)$record['status'])) ?></td>
                    <td><?= e($record['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$records): ?><tr><td colspan="5" class="portal-muted">No attendance records found yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <p class="portal-muted" style="margin-top:18px"><a href="<?= e(url('employee/kiosk.php')) ?>">Open attendance kiosk</a></p>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/end.php'; ?>
