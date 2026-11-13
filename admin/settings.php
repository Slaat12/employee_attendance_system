
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_admin();

$s = SettingsRepository::get();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $company = preg_replace('/\s+/u', ' ', trim(post_str('company_name')));
    $start = post_str('start_time');
    $end = post_str('end_time');
    $grace = filter_var(post_str('grace_minutes'), FILTER_VALIDATE_INT);

    $timeRx = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    if ($company === '' || mb_strlen($company) > 80) {
        $error = 'Enter a company name of 1–80 characters.';

    } elseif (
        !preg_match($timeRx, $start) ||
        !preg_match($timeRx, $end) ||
        $start >= $end
    ) {
        $error = 'Enter valid working hours. Closing time must be after opening time.';

    } elseif ($grace === false || $grace < 0 || $grace > 180) {
        $error = 'Grace period must be between 0 and 180 minutes.';

    } else {
        try {
            SettingsRepository::save([
                'company_name' => $company,
                'start_time' => $start,
                'end_time' => $end,
                'grace_minutes' => $grace,
                'work_days' => [1, 2, 3, 4, 5]
            ]);

            flash('success', 'Settings saved.');
            redirect('admin/settings.php');

        } catch (Throwable $e) {
            error_log('SETTINGS SAVE ERROR: ' . $e->getMessage());
            $error = 'Unable to save settings. Please try again.';
        }
    }

    // Keep the values entered by the administrator if validation fails.
    $s = [
        'company_name' => $company,
        'start_time' => $start,
        'end_time' => $end,
        'grace_minutes' => post_str('grace_minutes')
    ];
}

$pageTitle = 'Settings';
$active = 'settings';

require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/includes/sidebar.php';

?>

<div class="section-heading">
    <div>
        <h2>Company settings</h2>
        <p class="muted">Configure the attendance rules for your organization.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel settings-panel">

    <form method="post" class="form-grid">
        <?= csrf_field() ?>

        <label class="span-two">
            Company name
            <input
                name="company_name"
                maxlength="80"
                required
                value="<?= e($s['company_name'] ?? '') ?>"
            >
        </label>

        <label>
            Opening time
            <input
                type="time"
                name="start_time"
                required
                value="<?= e($s['start_time'] ?? '08:00') ?>"
            >
        </label>

        <label>
            Closing time
            <input
                type="time"
                name="end_time"
                required
                value="<?= e($s['end_time'] ?? '17:00') ?>"
            >
        </label>

        <label>
            Grace period (minutes)
            <input
                type="number"
                name="grace_minutes"
                min="0"
                max="180"
                step="1"
                required
                value="<?= e($s['grace_minutes'] ?? 15) ?>"
            >
        </label>

        <div class="form-actions span-two">
            <button class="btn primary" type="submit">
                Save settings
            </button>
        </div>

    </form>

    <div class="notice">
        <b>How lateness works</b>
        <p>
            An employee who checks in after the opening time plus the
            grace period is marked <b>Late</b>. Each employee has one
            attendance record per day. Company settings are stored in
            PostgreSQL.
        </p>
    </div>

</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
