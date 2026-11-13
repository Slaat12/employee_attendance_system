
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (Auth::check()) {
    redirect(Auth::isAdmin() ? 'admin/dashboard.php' : 'employee/kiosk.php');
}

$error = get_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = post_str('email');
    $password = post_raw('password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        $key = Throttle::key(
            'admin',
            $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')
        );

        if (($wait = Throttle::blockedFor($key)) > 0) {
            $error = Throttle::message($wait);

        } elseif ($admin = Auth::verifyAdmin($email, $password)) {
            Throttle::clear($key);

            // Require MFA setup or verification before granting access.
            Auth::beginMfaChallenge($admin);

            redirect('auth/mfa.php');

        } else {
            Throttle::fail($key);
            $error = 'The email or password is incorrect.';
        }
    }
}

$pageTitle = 'Administrator sign in';

require APP_ROOT . '/includes/header.php';
?>

<div class="auth-shell">

    <section class="auth-visual">
        <div class="visual-brand">
            <span class="brand-mark">N</span> NORTHSTAR
        </div>

        <div>
            <p class="eyebrow light">PEOPLE. TIME. ACCOUNTABILITY.</p>
            <h1>Make every<br>workday count.</h1>
            <p>A clear view of attendance, people and performance.</p>
        </div>

        <small>Employee Attendance Management</small>
    </section>

    <section class="auth-panel">

        <div class="auth-card">

            <div class="mobile-brand">
                <span class="brand-mark">N</span> NORTHSTAR
            </div>

            <span class="eyebrow">ADMINISTRATOR PORTAL</span>

            <h2>Welcome back</h2>

            <p class="muted">Sign in to manage your workforce.</p>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack" novalidate>

                <?= csrf_field() ?>

                <label>
                    Email address
                    <input
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        maxlength="120"
                        placeholder="admin@company.com"
                        value="<?= old('email') ?>"
                        autofocus
                    >
                </label>

                <label>
                    Password
                    <input
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        placeholder="Enter your password"
                    >
                </label>

                <button class="btn primary full">
                    Continue securely
                </button>

            </form>

            <div class="auth-switch">
                <span>Need to record attendance?</span>
                <a href="<?= e(url('employee/kiosk.php')) ?>">
                    Open employee kiosk →
                </a>
            </div>

            <?php if (APP_DEMO): ?>
                <div class="demo-note">
                    <b>Demo administrator</b><br>
                    admin@northstar.local<br>
                    Admin@12345
                </div>
            <?php endif; ?>

        </div>

    </section>

</div>

<?php require APP_ROOT . '/includes/end.php'; ?>
