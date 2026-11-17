<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$db = Database::connection();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $requestId = filter_var(post_str('request_id'), FILTER_VALIDATE_INT);
    if (!$requestId || !in_array($action,['approve','reject','entitlement'],true)) {
        $error = 'Invalid leave management action.';
    } elseif ($action === 'entitlement') {
        $employeeId = filter_var(post_str('employee_id'),FILTER_VALIDATE_INT);
        $days = filter_var(post_str('entitlement_days'),FILTER_VALIDATE_INT);
        if (!$employeeId || $days === false || $days < 0 || $days > 365) {
            $error = 'Enter a valid employee and annual entitlement between 0 and 365 days.';
        } else {
            $stmt=$db->prepare("INSERT INTO leave_balances(employee_id,leave_year,entitlement_days) VALUES(:employee_id,:leave_year,:days) ON CONFLICT(employee_id,leave_year) DO UPDATE SET entitlement_days=EXCLUDED.entitlement_days,updated_at=CURRENT_TIMESTAMP");
            $stmt->execute(['employee_id'=>$employeeId,'leave_year'=>(int)date('Y'),'days'=>$days]);
            flash('success','Annual leave entitlement updated.');
            redirect('admin/leaves.php');
        }
    } else {
        $note = trim(post_str('admin_note'));
        if (mb_strlen($note)>1000) $error='Administrator note cannot exceed 1,000 characters.';
        else {
            try {
                $db->beginTransaction();
                $stmt=$db->prepare("SELECT * FROM leave_requests WHERE leave_request_id=:id FOR UPDATE");
                $stmt->execute(['id'=>$requestId]);
                $request=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$request || $request['status']!=='pending') {
                    $db->rollBack();
                    $error='This request is no longer pending.';
                } elseif ($action==='approve') {
                    $year=(int)date('Y');
                    $balance=$db->prepare("SELECT entitlement_days FROM leave_balances WHERE employee_id=:employee_id AND leave_year=:leave_year FOR UPDATE");
                    $balance->execute(['employee_id'=>$request['employee_id'],'leave_year'=>$year]);
                    $entitlement=$balance->fetchColumn();
                    if ($entitlement===false) {
                        $db->prepare("INSERT INTO leave_balances(employee_id,leave_year,entitlement_days) VALUES(:employee_id,:leave_year,15) ON CONFLICT(employee_id,leave_year) DO NOTHING")->execute(['employee_id'=>$request['employee_id'],'leave_year'=>$year]);
                        $balance->execute(['employee_id'=>$request['employee_id'],'leave_year'=>$year]);
                        $entitlement=$balance->fetchColumn();
                    }
                    $used=$db->prepare("SELECT COALESCE(SUM(working_days),0) FROM leave_requests WHERE employee_id=:employee_id AND status='approved' AND EXTRACT(YEAR FROM start_date)=:leave_year");
                    $used->execute(['employee_id'=>$request['employee_id'],'leave_year'=>$year]);
                    if ((int)$used->fetchColumn()+(int)$request['working_days']>(int)$entitlement) {
                        $db->rollBack();
                        $error='Cannot approve this request because it exceeds the employee’s remaining annual leave balance.';
                    } else {
                        $update=$db->prepare("UPDATE leave_requests SET status='approved',admin_note=:note,reviewed_by=:admin_id,reviewed_at=CURRENT_TIMESTAMP WHERE leave_request_id=:id");
                        $update->execute(['note'=>$note ?: null,'admin_id'=>Auth::user()['id'],'id'=>$requestId]);
                        $db->commit();
                        flash('success','Leave request approved.');
                        redirect('admin/leaves.php');
                    }
                } else {
                    $update=$db->prepare("UPDATE leave_requests SET status='rejected',admin_note=:note,reviewed_by=:admin_id,reviewed_at=CURRENT_TIMESTAMP WHERE leave_request_id=:id");
                    $update->execute(['note'=>$note ?: null,'admin_id'=>Auth::user()['id'],'id'=>$requestId]);
                    $db->commit();
                    flash('success','Leave request rejected.');
                    redirect('admin/leaves.php');
                }
            } catch (Throwable $e) {
                if($db->inTransaction()) $db->rollBack();
                error_log('LEAVE REVIEW ERROR: '.$e->getMessage());
                $error='Unable to process this leave request. Please try again.';
            }
        }
    }
}

$requests=$db->query("SELECT lr.*,e.employee_code,e.first_name,e.last_name,d.department_name FROM leave_requests lr JOIN employees e ON e.employee_id=lr.employee_id LEFT JOIN departments d ON d.department_id=e.department_id ORDER BY CASE lr.status WHEN 'pending' THEN 0 ELSE 1 END,lr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$employees=$db->query("SELECT employee_id,employee_code,first_name,last_name FROM employees WHERE status='active' ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_ASSOC);
$balances=$db->query("SELECT lb.employee_id,lb.entitlement_days,COALESCE(SUM(CASE WHEN lr.status='approved' AND EXTRACT(YEAR FROM lr.start_date)=lb.leave_year THEN lr.working_days ELSE 0 END),0) AS used_days FROM leave_balances lb LEFT JOIN leave_requests lr ON lr.employee_id=lb.employee_id WHERE lb.leave_year=".(int)date('Y')." GROUP BY lb.employee_id,lb.entitlement_days ORDER BY lb.employee_id")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle='Leave management';
$active='leaves';
require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';
?>
<div class="section-heading"><div><h2>Leave requests</h2><p class="muted">Review employee leave applications and manage annual entitlements.</p></div></div>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<section class="panel">
<h3>Annual leave entitlement</h3><p class="muted">Set the annual working-day allowance for each active employee. The default is 15 days.</p>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="entitlement"><input type="hidden" name="request_id" value="1">
<label>Employee<select name="employee_id" required><option value="">Choose employee</option><?php foreach($employees as $emp):?><option value="<?=(int)$emp['employee_id']?>"><?=e($emp['employee_code'].' · '.$emp['first_name'].' '.$emp['last_name'])?></option><?php endforeach;?></select></label>
<label>Annual entitlement (working days)<input type="number" name="entitlement_days" min="0" max="365" value="15" required></label>
<div class="form-actions"><button class="btn primary">Save entitlement</button></div></form>
</section>
<div class="section-heading" style="margin-top:28px"><div><h2>All leave applications</h2><p class="muted"><?=count($requests)?> request(s) recorded</p></div></div>
<?php foreach($requests as $req):?>
<section class="panel" style="margin-bottom:14px">
<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><h3><?=e($req['first_name'].' '.$req['last_name'])?> <small class="muted"><?=e($req['employee_code'])?></small></h3><p class="muted"><?=e($req['department_name']??'No department')?> · <?=e(date('d M Y',strtotime($req['start_date'])))?> to <?=e(date('d M Y',strtotime($req['end_date'])))?> · <?= (int)$req['working_days']?> working day(s)</p></div><span class="status <?= $req['status']==='approved'?'present':($req['status']==='pending'?'late':'inactive')?>"><?=e(ucfirst($req['status']))?></span></div>
<p><?=nl2br(e($req['reason']))?></p>
<?php if($req['admin_note']):?><p class="muted">Administrator note: <?=e($req['admin_note'])?></p><?php endif;?>
<?php if($req['status']==='pending'):?><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="request_id" value="<?=(int)$req['leave_request_id']?>"><label class="span-two">Note to employee<textarea name="admin_note" rows="2" maxlength="1000"></textarea></label><div class="form-actions"><button class="btn primary" name="action" value="approve">Approve</button><button class="btn outline" name="action" value="reject">Reject</button></div></form><?php endif;?>
</section>
<?php endforeach;?>
<?php if(!$requests):?><div class="panel empty">No leave requests have been submitted yet.</div><?php endif;?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
