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
<p class="kiosk-help">Your attendance time is recorded automatically. Do not share your PIN.</p></div>
<section class="employee-portal-promo">
    <div class="portal-promo-icon">↗</div>
    <div class="portal-promo-copy">
        <span class="eyebrow">EMPLOYEE SELF-SERVICE</span>
        <h2>View your attendance records</h2>
        <p>Sign in to your personal portal to check your attendance history and download your records.</p>
    </div>
    <a class="btn primary portal-promo-button" href="<?= e(url('employee/portal.php')) ?>">Open Employee Portal <span aria-hidden="true">→</span></a>
</section>
<style>
.employee-portal-promo{width:min(560px,calc(100% - 32px));margin:22px auto 0;padding:22px 24px;display:flex;align-items:center;gap:16px;background:#fff;border:1px solid #dfe5ee;border-radius:14px;box-shadow:0 8px 24px rgba(20,35,60,.06)}
.portal-promo-icon{width:46px;height:46px;flex:0 0 46px;display:grid;place-items:center;border-radius:12px;background:#eef2f8;color:#183153;font-size:24px;font-weight:700}
.portal-promo-copy{flex:1;min-width:0}.portal-promo-copy h2{margin:5px 0;font-size:18px;color:#17243a}.portal-promo-copy p{margin:0;color:#758094;font-size:13px;line-height:1.5}.portal-promo-button{white-space:nowrap;text-decoration:none}
@media(max-width:600px){.employee-portal-promo{align-items:flex-start;flex-wrap:wrap;padding:18px}.portal-promo-copy{flex:1 1 calc(100% - 64px)}.portal-promo-button{width:100%;justify-content:center;text-align:center}}
</style>
<div class="kiosk-bottom"><span><i class="live-dot"></i> Attendance terminal ready</span><span><?php if (Auth::isAdmin()): ?><a href="<?= e(url('admin/dashboard.php')) ?>">Administrator dashboard</a><?php else: ?><a href="<?= e(url('auth/login.php')) ?>">Administrator sign in</a><?php endif; ?></span></div></div>
<?php require APP_ROOT . '/includes/end.php'; ?>
