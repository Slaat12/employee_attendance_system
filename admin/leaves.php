<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$db = Database::connection();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'holiday_add') {
        $holidayDate = post_str('holiday_date');
        $holidayName = trim(post_str('holiday_name'));
        if (!valid_date($holidayDate) || mb_strlen($holidayName) < 2 || mb_strlen($holidayName) > 150) {
            $error = 'Enter a valid holiday date and a name between 2 and 150 characters.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO public_holidays (holiday_date, holiday_name) VALUES (:holiday_date, :holiday_name) ON CONFLICT (holiday_date) DO UPDATE SET holiday_name = EXCLUDED.holiday_name");
                $stmt->execute(['holiday_date' => $holidayDate, 'holiday_name' => $holidayName]);
                refresh_leave_request_days($db);
                flash('success', 'Public holiday saved. Leave-day totals have been recalculated.');
                redirect('admin/leaves.php');
            } catch (Throwable $e) {
                error_log('HOLIDAY SAVE ERROR: ' . $e->getMessage());
                $error = 'Unable to save the public holiday. Please try again.';
            }
        }
    } elseif ($action === 'holiday_delete') {
        $holidayId = filter_var(post_str('holiday_id'), FILTER_VALIDATE_INT);
        if (!$holidayId) {
            $error = 'Invalid public holiday.';
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM public_holidays WHERE holiday_id = :holiday_id");
                $stmt->execute(['holiday_id' => $holidayId]);
                refresh_leave_request_days($db);
                flash('success', 'Public holiday removed. Leave-day totals have been recalculated.');
                redirect('admin/leaves.php');
            } catch (Throwable $e) {
                error_log('HOLIDAY DELETE ERROR: ' . $e->getMessage());
                $error = 'Unable to remove the public holiday. Please try again.';
            }
        }
    } elseif ($action === 'entitlement') {
        $employeeId = filter_var(post_str('employee_id'), FILTER_VALIDATE_INT);
        $days = filter_var(post_str('entitlement_days'), FILTER_VALIDATE_INT);
        if (!$employeeId || $days === false || $days < 0 || $days > 365) {
            $error = 'Enter a valid employee and annual entitlement between 0 and 365 days.';
        } else {
            $stmt = $db->prepare("INSERT INTO leave_balances(employee_id,leave_year,entitlement_days) VALUES(:employee_id,:leave_year,:days) ON CONFLICT(employee_id,leave_year) DO UPDATE SET entitlement_days=EXCLUDED.entitlement_days,updated_at=CURRENT_TIMESTAMP");
            $stmt->execute(['employee_id' => $employeeId, 'leave_year' => (int) date('Y'), 'days' => $days]);
            flash('success', 'Annual leave entitlement updated.');
            redirect('admin/leaves.php');
        }
    } elseif (in_array($action, ['approve', 'reject'], true)) {
        $requestId = filter_var(post_str('request_id'), FILTER_VALIDATE_INT);
        $note = trim(post_str('admin_note'));
        if (!$requestId) {
            $error = 'Invalid leave request.';
        } elseif (mb_strlen($note) > 1000) {
            $error = 'Administrator note cannot exceed 1,000 characters.';
        } else {
            $reviewStage = 'starting approval';
            try {
                $reviewStage = 'starting database transaction';
                $db->beginTransaction();
                $reviewStage = 'locking leave request';
                $stmt = $db->prepare("SELECT * FROM leave_requests WHERE leave_id = :id FOR UPDATE");
                $stmt->execute(['id' => $requestId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$request || $request['status'] !== 'pending') {
                    $db->rollBack();
                    $error = 'This request is no longer pending.';
                } elseif ($action === 'approve') {
                    $year = (int) date('Y');
                    $reviewStage = 'calculating leave days against public holidays';
                    $requestDays = leave_working_days($db, (string) $request['start_date'], (string) $request['end_date']);
                    if ($requestDays < 1) {
                        $db->rollBack();
                        $error = 'This request contains no working days after excluding weekends and public holidays.';
                    } else {
                        $reviewStage = 'checking annual leave balance';
                        $balance = $db->prepare("SELECT entitlement_days FROM leave_balances WHERE employee_id = :employee_id AND leave_year = :leave_year FOR UPDATE");
                        $balance->execute(['employee_id' => $request['employee_id'], 'leave_year' => $year]);
                        $entitlement = $balance->fetchColumn();

                        if ($entitlement === false) {
                            $reviewStage = 'creating default annual leave balance';
                            $db->prepare("INSERT INTO leave_balances(employee_id,leave_year,entitlement_days) VALUES(:employee_id,:leave_year,15) ON CONFLICT(employee_id,leave_year) DO NOTHING")
                                ->execute(['employee_id' => $request['employee_id'], 'leave_year' => $year]);
                            $balance->execute(['employee_id' => $request['employee_id'], 'leave_year' => $year]);
                            $entitlement = $balance->fetchColumn();
                        }

                        $reviewStage = 'calculating previously approved leave';
                        $used = $db->prepare("SELECT COALESCE(SUM(working_days),0) FROM leave_requests WHERE employee_id = :employee_id AND status = 'approved' AND EXTRACT(YEAR FROM start_date) = :leave_year AND leave_id <> :leave_id");
                        $used->execute(['employee_id' => $request['employee_id'], 'leave_year' => $year, 'leave_id' => $requestId]);

                        if ((int) $used->fetchColumn() + $requestDays > (int) $entitlement) {
                            $db->rollBack();
                            $error = 'Cannot approve this request because it exceeds the employee’s remaining annual leave balance.';
                        } else {
                            $reviewStage = 'saving approved leave request';
                            $update = $db->prepare("UPDATE leave_requests SET status = 'approved', working_days = :working_days, admin_note = :note WHERE leave_id = :id");
                            $update->execute(['working_days' => $requestDays, 'note' => $note ?: null, 'id' => $requestId]);
                            $db->commit();
                            flash('success', 'Leave request approved.');
                            redirect('admin/leaves.php');
                        }
                    }
                } else {
                    $reviewStage = 'saving rejected leave request';
                    $update = $db->prepare("UPDATE leave_requests SET status = 'rejected', admin_note = :note WHERE leave_id = :id");
                    $update->execute(['note' => $note ?: null, 'id' => $requestId]);
                    $db->commit();
                    flash('success', 'Leave request rejected.');
                    redirect('admin/leaves.php');
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('LEAVE REVIEW ERROR at ' . ($reviewStage ?? 'unknown stage') . ': ' . $e->getMessage());
                $error = 'Unable to process this leave request. Please try again.';
            }
        }
    } else {
        $error = 'Invalid leave management action.';
    }
}

$requests = $db->query("SELECT lr.*, e.employee_code, e.first_name, e.last_name, d.department_name FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id LEFT JOIN departments d ON d.department_id = e.department_id ORDER BY CASE lr.status WHEN 'pending' THEN 0 ELSE 1 END, lr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$employees = $db->query("SELECT employee_id, employee_code, first_name, last_name FROM employees WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
$holidays = $db->query("SELECT holiday_id, holiday_date, holiday_name FROM public_holidays ORDER BY holiday_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Leave management';
$active = 'leaves';
require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';
?>
<div class="section-heading"><div><h2>Leave requests</h2><p class="muted">Review employee leave applications, manage annual entitlements and maintain the Ghana public holiday calendar.</p></div></div>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h3>Ghana public holidays</h3>
    <p class="muted">Add official public holidays when they are announced. Saturdays, Sundays and dates listed here will not count as leave days. Adding or removing a holiday recalculates existing leave requests.</p>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="holiday_add">
        <label>Holiday date<input type="date" name="holiday_date" required></label>
        <label>Holiday name<input type="text" name="holiday_name" maxlength="150" placeholder="e.g. Independence Day" required></label>
        <div class="form-actions"><button class="btn primary">Add public holiday</button></div>
    </form>
    <div style="overflow-x:auto;margin-top:18px">
        <table class="data-table">
            <thead><tr><th>DATE</th><th>HOLIDAY</th><th>ACTION</th></tr></thead>
            <tbody>
            <?php foreach ($holidays as $holiday): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime($holiday['holiday_date']))) ?></td>
                    <td><?= e($holiday['holiday_name']) ?></td>
                    <td><form method="post" onsubmit="return confirm('Remove this public holiday from the calendar?')"><?= csrf_field() ?><input type="hidden" name="action" value="holiday_delete"><input type="hidden" name="holiday_id" value="<?= (int) $holiday['holiday_id'] ?>"><button class="btn outline" type="submit">Remove</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$holidays): ?><tr><td colspan="3" class="muted">No public holidays have been added yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" style="margin-top:20px">
    <h3>Annual leave entitlement</h3><p class="muted">Set the annual working-day allowance for each active employee. The default is 15 days.</p>
    <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="entitlement"><input type="hidden" name="request_id" value="1">
        <label>Employee<select name="employee_id" required><option value="">Choose employee</option><?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['employee_id'] ?>"><?= e($emp['employee_code'] . ' · ' . $emp['first_name'] . ' ' . $emp['last_name']) ?></option><?php endforeach; ?></select></label>
        <label>Annual entitlement (working days)<input type="number" name="entitlement_days" min="0" max="365" value="15" required></label>
        <div class="form-actions"><button class="btn primary">Save entitlement</button></div>
    </form>
</section>

<div class="section-heading" style="margin-top:28px"><div><h2>All leave applications</h2><p class="muted"><?= count($requests) ?> request(s) recorded</p></div></div>
<?php foreach ($requests as $req): ?>
<section class="panel" style="margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div><h3><?= e($req['first_name'] . ' ' . $req['last_name']) ?> <small class="muted"><?= e($req['employee_code']) ?></small></h3>
        <p class="muted"><?= e($req['department_name'] ?? 'No department') ?> · <?= e(date('d M Y', strtotime($req['start_date']))) ?> to <?= e(date('d M Y', strtotime($req['end_date']))) ?> · <?= (int) $req['working_days'] ?> working day(s)</p></div>
        <span class="status <?= $req['status'] === 'approved' ? 'present' : ($req['status'] === 'pending' ? 'late' : 'inactive') ?>"><?= e(ucfirst($req['status'])) ?></span>
    </div>
    <p><?= nl2br(e($req['reason'])) ?></p>
    <?php if ($req['admin_note']): ?><p class="muted">Administrator note: <?= e($req['admin_note']) ?></p><?php endif; ?>
    <?php if ($req['status'] === 'pending'): ?>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $req['leave_id'] ?>">
        <label class="span-two">Note to employee<textarea name="admin_note" rows="2" maxlength="1000"></textarea></label>
        <div class="form-actions"><button class="btn primary" name="action" value="approve">Approve</button><button class="btn outline" name="action" value="reject">Reject</button></div>
    </form>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (!$requests): ?><div class="panel empty">No leave requests have been submitted yet.</div><?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
