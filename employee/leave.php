<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$employee = $_SESSION['employee_portal'] ?? null;
if (!is_array($employee)) {
    flash('error', 'Please sign in to your employee portal first.');
    redirect('employee/portal.php');
}

$error = null;
$success = null;
$db = Database::connection();

function leave_working_days(string $start, string $end): int {
    $count = 0;
    $date = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);
    while ($date <= $last) {
        if ((int)$date->format('N') <= 5) $count++;
        $date = $date->modify('+1 day');
    }
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $start = post_str('start_date');
    $end = post_str('end_date');
    $reason = trim(post_str('reason'));

    if (!valid_date($start) || !valid_date($end) || $end < $start) {
        $error = 'Choose valid leave dates. The end date cannot be before the start date.';
    } elseif ($start < today_string()) {
        $error = 'Leave requests must start today or on a future date.';
    } elseif (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
        $error = 'Please provide a reason between 5 and 1,000 characters.';
    } else {
        $days = leave_working_days($start, $end);
        if ($days < 1) {
            $error = 'The selected dates contain no working days.';
        } else {
            $overlap = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = :employee_id AND status IN ('pending','approved') AND start_date <= :end_date AND end_date >= :start_date");
            $overlap->execute(['employee_id'=>$employee['id'], 'start_date'=>$start, 'end_date'=>$end]);
            if ((int)$overlap->fetchColumn() > 0) {
                $error = 'You already have a pending or approved request covering some of those dates.';
            } else {
                $stmt = $db->prepare("INSERT INTO leave_requests (employee_id,start_date,end_date,working_days,reason) VALUES (:employee_id,:start_date,:end_date,:working_days,:reason)");
                $stmt->execute(['employee_id'=>$employee['id'],'start_date'=>$start,'end_date'=>$end,'working_days'=>$days,'reason'=>$reason]);
                $success = 'Your leave request has been submitted for administrator review.';
            }
        }
    }
}

$year = (int)date('Y');
$balanceStmt = $db->prepare("SELECT entitlement_days FROM leave_balances WHERE employee_id=:employee_id AND leave_year=:leave_year");
$balanceStmt->execute(['employee_id'=>$employee['id'],'leave_year'=>$year]);
$entitlement = $balanceStmt->fetchColumn();
if ($entitlement === false) {
    $init = $db->prepare("INSERT INTO leave_balances (employee_id,leave_year,entitlement_days) VALUES (:employee_id,:leave_year,15) ON CONFLICT (employee_id,leave_year) DO NOTHING");
    $init->execute(['employee_id'=>$employee['id'],'leave_year'=>$year]);
    $balanceStmt->execute(['employee_id'=>$employee['id'],'leave_year'=>$year]);
    $entitlement = $balanceStmt->fetchColumn();
}
$usedStmt = $db->prepare("SELECT COALESCE(SUM(working_days),0) FROM leave_requests WHERE employee_id=:employee_id AND status='approved' AND EXTRACT(YEAR FROM start_date)=:leave_year");
$usedStmt->execute(['employee_id'=>$employee['id'],'leave_year'=>$year]);
$used = (int)$usedStmt->fetchColumn();
$remaining = max(0,(int)$entitlement-$used);

$list = $db->prepare("SELECT start_date,end_date,working_days,reason,status,admin_note,created_at FROM leave_requests WHERE employee_id=:employee_id ORDER BY created_at DESC");
$list->execute(['employee_id'=>$employee['id']]);
$requests = $list->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My leave';
require APP_ROOT . '/includes/header.php';
?>
<style>
.leave-wrap{max-width:1050px;margin:35px auto;padding:0 22px}.leave-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:20px 0}.leave-stat,.leave-panel{background:#fff;border:1px solid #e6eaf0;border-radius:14px;padding:22px}.leave-stat strong{display:block;font-size:30px;color:#172b4d}.leave-stat span,.leave-muted{color:#758094;font-size:13px}.leave-form{display:grid;grid-template-columns:1fr 1fr;gap:15px}.leave-form .wide{grid-column:1/-1}.leave-form input,.leave-form textarea{width:100%;margin-top:7px}.leave-table{width:100%;border-collapse:collapse}.leave-table th,.leave-table td{padding:12px;text-align:left;border-bottom:1px solid #edf0f4;font-size:13px}.leave-scroll{overflow-x:auto}.leave-table{min-width:650px}.leave-status{font-weight:700;text-transform:capitalize}.leave-status.approved{color:#16834a}.leave-status.rejected{color:#bd3434}.leave-status.pending{color:#a66b00}@media(max-width:650px){.leave-grid{grid-template-columns:1fr}.leave-form{grid-template-columns:1fr}.leave-form .wide{grid-column:auto}}
</style>
<div class="leave-wrap">
    <div class="section-heading"><div><h2>My leave</h2><p class="leave-muted">Submit leave requests and track administrator decisions.</p></div><a class="btn outline" href="<?= e(url('employee/portal.php')) ?>">Back to employee portal</a></div>
    <?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
    <?php if($success): ?><div class="alert success"><?=e($success)?></div><?php endif; ?>
    <div class="leave-grid">
        <div class="leave-stat"><span>Annual entitlement</span><strong><?= (int)$entitlement ?></strong><span>working days · <?= $year ?></span></div>
        <div class="leave-stat"><span>Approved leave used</span><strong><?= $used ?></strong><span>working days</span></div>
        <div class="leave-stat"><span>Available balance</span><strong><?= $remaining ?></strong><span>working days remaining</span></div>
    </div>
    <section class="leave-panel">
        <h2>Request leave</h2>
        <p class="leave-muted">Weekends are excluded from the working-day calculation. Requests are not approved until an administrator reviews them.</p>
        <form method="post" class="leave-form">
            <?=csrf_field()?>
            <label>Start date<input type="date" name="start_date" min="<?=e(today_string())?>" required value="<?=e(post_str('start_date'))?>"></label>
            <label>End date<input type="date" name="end_date" min="<?=e(today_string())?>" required value="<?=e(post_str('end_date'))?>"></label>
            <label class="wide">Reason<textarea name="reason" rows="3" maxlength="1000" required><?=e(post_str('reason'))?></textarea></label>
            <div class="wide"><button class="btn primary">Submit leave request</button></div>
        </form>
    </section>
    <section class="leave-panel" style="margin-top:20px">
        <h2>My requests</h2>
        <div class="leave-scroll"><table class="leave-table"><thead><tr><th>DATES</th><th>DAYS</th><th>REASON</th><th>STATUS</th><th>ADMIN NOTE</th></tr></thead><tbody>
        <?php foreach($requests as $request): ?><tr><td><?=e(date('d M Y',strtotime($request['start_date'])))?> to <?=e(date('d M Y',strtotime($request['end_date'])))?></td><td><?= (int)$request['working_days'] ?></td><td><?=e($request['reason'])?></td><td><span class="leave-status <?=e($request['status'])?>"><?=e($request['status'])?></span></td><td><?=e($request['admin_note'] ?: '—')?></td></tr><?php endforeach; ?>
        <?php if(!$requests): ?><tr><td colspan="5" class="leave-muted">You have not submitted any leave requests.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
