<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

if (Auth::check()) {
    redirect(Auth::user()['role'] === 'admin' ? 'admin/dashboard.php' : 'employee/kiosk.php');
}
redirect('auth/login.php');
