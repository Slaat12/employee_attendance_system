<?php $nav = [
    'dashboard'   => ['admin/dashboard.php',   '▦', 'Dashboard'],
    'employees'   => ['admin/employees.php',   '♙', 'Employees'],
    'attendance'  => ['admin/attendance.php',  '◷', 'Attendance'],
    'departments' => ['admin/departments.php', '▤', 'Departments'],
    'reports'     => ['admin/reports.php',     '▥', 'Reports'],
    'settings'    => ['admin/settings.php',    '⚙', 'Settings'],
]; ?>
<aside class="sidebar">
  <a class="brand" href="<?= e(url('admin/dashboard.php')) ?>"><span class="brand-mark">N</span><span>Northstar<small>ATTENDANCE</small></span></a>
  <div class="side-label">WORKSPACE</div>
  <nav>
<?php foreach ($nav as $key => [$href, $icon, $label]): ?>
    <a class="<?= ($active ?? '') === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= $icon ?> <span><?= e($label) ?></span></a>
<?php endforeach; ?>
    <a href="<?= e(url('employee/kiosk.php')) ?>" target="_blank" rel="noopener">▣ <span>Open kiosk ↗</span></a>
  </nav>
  <div class="sidebar-bottom"><div class="user-mini"><span class="avatar"><?= e(initials($user['name'] ?? 'Administrator')) ?></span><span><b><?= e($user['name'] ?? 'Administrator') ?></b><small>Administrator</small></span></div><a class="logout-link" href="<?= e(url('auth/logout.php')) ?>">↪ Sign out</a></div>
</aside>
<main class="main">
<header class="topbar"><div><span class="eyebrow"><?= e(mb_strtoupper(company_name())) ?></span><h1><?= e($pageTitle) ?></h1></div><div class="top-actions"><span class="live-dot"></span><span>System online</span><span class="top-date"><?= date('D, d M Y') ?></span></div></header>
<div class="content">
<?php if ($m = get_flash('success')): ?><div class="alert success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = get_flash('error')): ?><div class="alert error"><?= e($m) ?></div><?php endif; ?>
