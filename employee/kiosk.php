<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

/*
 * Shared kiosk. Nobody is ever "signed in" here: every check-in/out re-checks
 * Employee ID + PIN, and the PIN is never stored. Works whether or not an
 * administrator is signed in on the same browser.
 */
$success = get_flash('kiosk_success');
$error = get_flash('error');
$prefillId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $employeeId = strtoupper(post_str('employee_id'));
    $pin = post_raw('pin');
    $action = post_str('action');
    $prefillId = $employeeId;

    if (!valid_employee_id($employeeId) || !valid_pin($pin)) {
        $error = 'Enter a valid Employee ID and a 4–8 digit PIN.';
    } elseif (!in_array($action, ['in', 'out'], true)) {
        $error = 'Choose Check in or Check out.';
    } else {
        $key = Throttle::key('kiosk', $employeeId);
        if (($wait = Throttle::blockedFor($key)) > 0) {
            $error = Throttle::message($wait);
        } elseif (!($emp = Auth::verifyEmployee($employeeId, $pin))) {
            Throttle::fail($key);
            $error = 'Employee ID or PIN is incorrect, or the account is inactive.';
        } else {
            Throttle::clear($key);
            [$ok, $message] = $action === 'in'
                ? AttendanceService::checkIn((int)$emp['id'])
                : AttendanceService::checkOut((int)$emp['id']);
            if ($ok) {
                // Redirect so a page refresh can never re-submit the check-in/out.
                flash('kiosk_success', $emp['first_name'] . ' ' . $emp['last_name'] . ' — ' . $message);
                redirect('employee/kiosk.php');
            }
            $error = $emp['first_name'] . ', ' . lcfirst($message);
        }
    }
}

$pageTitle = 'Employee attendance';
require APP_ROOT . '/includes/header.php';
?>
<div class="kiosk-wrap" data-kiosk><div class="kiosk-top"><a class="brand dark-brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark">N</span><span>NORTHSTAR<small>EMPLOYEE ATTENDANCE</small></span></a><div class="clock-block"><div id="live-clock"><?= e(date('h:i:s A', strtotime(now_string()))) ?></div><span id="live-date"><?= e(date('l, d F Y', strtotime(now_string()))) ?></span></div></div>
<div class="kiosk-card"><div class="kiosk-icon">◷</div><span class="eyebrow">EMPLOYEE TIME CLOCK</span><h1>Good day. Record your time.</h1><p class="muted">Enter your employee details to check in or check out.</p>
<?php if ($success): ?><div class="result success-result" data-autohide="9000"><span>✓</span><div><b>Attendance recorded</b><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-stack kiosk-form" id="kiosk-form"><?= csrf_field() ?>
<label>Employee ID<input name="employee_id" id="employee_id" required maxlength="20" pattern="[A-Za-z0-9\-]{3,20}" placeholder="e.g. EMP001" autocomplete="off" autocapitalize="characters" spellcheck="false" value="<?= e($prefillId) ?>" <?= $prefillId === '' ? 'autofocus' : '' ?>></label>
<label>Personal PIN<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required placeholder="Enter your 4–8 digit PIN" autocomplete="off" <?= $prefillId !== '' ? 'autofocus' : '' ?>></label>
<div class="kiosk-actions"><button class="btn primary" name="action" value="in">↗ Check in</button><button class="btn outline" name="action" value="out">↙ Check out</button></div></form>
<div class="employee-portal-promo">
    <div class="portal-promo-copy">
        <span class="eyebrow">EMPLOYEE SELF-SERVICE</span>
        <strong>Need to view your attendance history?</strong>
    </div>
    <a class="btn outline portal-promo-button" href="<?= e(url('employee/portal.php')) ?>">Employee Portal →</a>
</div>
<p class="kiosk-help">Your attendance time is recorded automatically. Do not share your PIN.</p></div>
<style>
.employee-portal-promo{margin:15px 0 0;padding:13px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;text-align:left;background:#f4f7fb;border:1px solid #dce5f0;border-radius:10px}
.portal-promo-copy{display:flex;flex-direction:column;gap:4px;min-width:0}.portal-promo-copy .eyebrow{font-size:9px;margin:0}.portal-promo-copy strong{font-size:12px;line-height:1.35;color:#20334d}.portal-promo-button{flex:0 0 auto;white-space:nowrap;text-decoration:none;font-size:11px;padding:10px 12px}
.kiosk-card{padding:28px 34px}.kiosk-icon{margin-bottom:12px}.kiosk-card>.muted{margin-bottom:16px}.kiosk-help{margin:12px 0 0}
@media(max-width:720px){.kiosk-card{padding:22px 17px}.employee-portal-promo{padding:11px 10px;gap:7px}.portal-promo-copy strong{font-size:11px}.portal-promo-button{font-size:10px;padding:9px 8px}.kiosk-icon{width:42px;height:42px;font-size:22px;margin-bottom:8px}.kiosk-card h1{margin:7px 0;font-size:19px}.kiosk-card>.muted{margin-bottom:12px}.kiosk-form label{margin-bottom:9px}.kiosk-form input{height:39px}.kiosk-actions .btn{padding:11px}.kiosk-help{margin-top:9px}}
</style>
<div class="kiosk-bottom"><span><i class="live-dot"></i> Attendance terminal ready</span><span><?php if (Auth::isAdmin()): ?><a href="<?= e(url('admin/dashboard.php')) ?>">Administrator dashboard</a><?php else: ?><a href="<?= e(url('auth/login.php')) ?>">Administrator sign in</a><?php endif; ?></span></div></div>
<?php require APP_ROOT . '/includes/end.php'; ?>
