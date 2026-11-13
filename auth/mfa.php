
<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/Totp.php';

if (Auth::check()) {
    redirect('admin/dashboard.php');
}

$admin = Auth::pendingMfaAdmin();

if ($admin === null) {
    set_flash('error', 'Your authentication session expired. Please sign in again.');
    redirect('auth/login.php');
}

$purpose = Auth::pendingMfaPurpose();

if (!in_array($purpose, ['setup', 'verify'], true)) {
    Auth::clearPendingMfa();
    redirect('auth/login.php');
}

// Generate a temporary secret for first-time MFA setup.
if ($purpose === 'setup') {
    if (empty($_SESSION['pending_mfa_secret'])) {
        $_SESSION['pending_mfa_secret'] = Totp::generateSecret();
    }

    $secret = $_SESSION['pending_mfa_secret'];

    $issuer = 'Northstar Attendance';
    $account = $admin['email'];

    $authenticatorUri = 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

$error = null;
$success = null;

$key = Throttle::key(
    'admin_mfa',
    $admin['id'] . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($wait = Throttle::blockedFor($key)) > 0) {
        $error = Throttle::message($wait);

    } else {
        $code = post_str('code');

        if (!preg_match('/^\d{6}$/', trim($code))) {
            Throttle::fail($key);
            $error = 'Enter a valid six-digit authenticator code.';

        } elseif ($purpose === 'setup') {
            $secret = $_SESSION['pending_mfa_secret'] ?? '';

            if (
                !is_string($secret) ||
                $secret === '' ||
                !Totp::verify($secret, trim($code))
            ) {
                Throttle::fail($key);
                $error = 'The authenticator code is incorrect. Try again.';

            } elseif (!AdminRepository::enableMfa($admin['id'], $secret)) {
                $error = 'Unable to activate MFA. Please try again.';

            } else {
                Throttle::clear($key);

                unset($_SESSION['pending_mfa_secret']);

                $updatedAdmin = AdminRepository::findById($admin['id']);

                if ($updatedAdmin === null) {
                    Auth::clearPendingMfa();
                    redirect('auth/login.php');
                }

                Auth::completeMfaLogin($updatedAdmin);

                redirect('admin/dashboard.php');
            }

        } else {
            $storedSecret = AdminRepository::getMfaSecret($admin['id']);

            if (
                $storedSecret === null ||
                !Totp::verify($storedSecret, trim($code))
            ) {
                Throttle::fail($key);
                $error = 'The authenticator code is incorrect. Try again.';

            } else {
                Throttle::clear($key);

                Auth::completeMfaLogin($admin);

                redirect('admin/dashboard.php');
            }
        }
    }
}

$pageTitle = $purpose === 'setup'
    ? 'Set up two-factor authentication'
    : 'Verify authenticator code';

require APP_ROOT . '/includes/header.php';
?>

<div class="auth-shell">

    <section class="auth-visual">
        <div class="visual-brand">
            <span class="brand-mark">N</span> NORTHSTAR
        </div>

        <div>
            <p class="eyebrow light">ACCOUNT SECURITY</p>
            <h1>Protect your<br>workforce.</h1>
            <p>Verify your identity before accessing the administrator dashboard.</p>
        </div>

        <small>Two-factor authentication</small>
    </section>

    <section class="auth-panel">

        <div class="auth-card">

            <div class="mobile-brand">
                <span class="brand-mark">N</span> NORTHSTAR
            </div>

            <span class="eyebrow">ADMINISTRATOR SECURITY</span>

            <?php if ($purpose === 'setup'): ?>

                <h2>Set up your authenticator</h2>

                <p class="muted">
                    Add this account to Google Authenticator or Microsoft Authenticator.
                </p>

                <div class="demo-note">
                    <b>Step 1: Add your account</b>

                    <p>
                        Open your authenticator app, choose to add an account
                        manually, and enter the details below.
                    </p>

                    <p><b>Account:</b><br><?= e($admin['email']) ?></p>

                    <p><b>Secret key:</b></p>

                    <p style="word-break:break-all;">
                        <?= e($secret) ?>
                    </p>

                    <p><b>Account type:</b> Time-based</p>
                    <p><b>Digits:</b> 6</p>
                    <p><b>Time period:</b> 30 seconds</p>

                    <p>
                        Keep this secret private. Never share it with anyone.
                    </p>
                </div>

                <p class="muted">
                    <b>Step 2:</b> Enter the six-digit code shown in your
                    authenticator app to activate MFA.
                </p>

            <?php else: ?>

                <h2>Verify your identity</h2>

                <p class="muted">
                    Open your authenticator app and enter the six-digit
                    code for <?= e($admin['email']) ?>.
                </p>

            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack" novalidate>

                <?= csrf_field() ?>

                <label>
                    Authenticator code

                    <input
                        type="text"
                        name="code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        minlength="6"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        required
                        autofocus
                    >
                </label>

                <button class="btn primary full">
                    <?= $purpose === 'setup'
                        ? 'Activate MFA and continue'
                        : 'Verify and sign in' ?>
                </button>

            </form>

            <div class="auth-switch">
                <a href="<?= e(url('auth/logout.php')) ?>">
                    Cancel and return to sign in
                </a>
            </div>

        </div>

    </section>

</div>

<?php require APP_ROOT . '/includes/end.php'; ?>
