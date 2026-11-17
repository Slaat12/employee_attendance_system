<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Keep authenticated administrators on their dashboard.
// Everyone else starts at the shared employee attendance kiosk.
if (Auth::isAdmin()) {
    redirect('admin/dashboard.php');
}

redirect('employee/kiosk.php');
