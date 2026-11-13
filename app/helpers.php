<?php

/* ---------- output & routing ---------- */

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function url(string $path = ''): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    $root = preg_replace(
        '~/(admin|auth|employee)/[^/]+$~',
        '',
        $script
    );

    if ($root === null) {
        $root = '';
    }

    if ($root === $script) {
        $root = rtrim(dirname($script), '/\\');
    }

    if ($root === '/' || $root === '.') {
        $root = '';
    }

    return rtrim($root, '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}


/* ---------- request input ---------- */

function post_str(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function post_raw(string $key): string
{
    // Passwords and PINs are never trimmed.
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

function get_str(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function old(string $key, string $default = ''): string
{
    return e(
        isset($_POST[$key])
            ? post_str($key)
            : $default
    );
}


/* ---------- CSRF & flash ---------- */

function csrf_token(): string
{
    return $_SESSION['_csrf'] ?? '';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="'
        . e(csrf_token())
        . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';

    if (
        is_string($sent)
        && $sent !== ''
        && hash_equals(csrf_token(), $sent)
    ) {
        return;
    }

    flash(
        'error',
        'That page had expired, so nothing was saved. Please try again.'
    );

    header(
        'Location: ' . ($_SERVER['SCRIPT_NAME'] ?? url('index.php')),
        true,
        303
    );

    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][$type] = $message;
}

function get_flash(string $type): ?string
{
    $message = $_SESSION['_flash'][$type] ?? null;

    unset($_SESSION['_flash'][$type]);

    return is_string($message) ? $message : null;
}


/* ---------- time ---------- */

function now_string(): string
{
    return (
        new DateTimeImmutable(
            'now',
            new DateTimeZone(APP_TIMEZONE)
        )
    )->format('Y-m-d H:i:s');
}

function today_string(): string
{
    return substr(now_string(), 0, 10);
}

function valid_date(string $date): bool
{
    if (
        !preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})$/',
            $date,
            $matches
        )
    ) {
        return false;
    }

    return checkdate(
        (int) $matches[2],
        (int) $matches[3],
        (int) $matches[1]
    );
}

function fmt_time(?string $datetime): string
{
    return $datetime
        ? date('h:i A', strtotime($datetime))
        : '—';
}

function fmt_date(string $datetime): string
{
    return date('d M Y', strtotime($datetime));
}

function hours_between(?string $in, ?string $out): ?float
{
    if (!$in || !$out) {
        return null;
    }

    return max(
        0,
        strtotime($out) - strtotime($in)
    ) / 3600;
}

function format_hours(?string $in, ?string $out): string
{
    $hours = hours_between($in, $out);

    return $hours === null
        ? '—'
        : number_format($hours, 2) . ' hrs';
}


/* ---------- validation ---------- */

function valid_person_name(string $value): bool
{
    return (bool) preg_match(
        "/^[\p{L}][\p{L} .'-]{1,59}$/u",
        trim($value)
    );
}

function valid_phone(string $value): bool
{
    return (bool) preg_match(
        '/^[0-9]{10,15}$/',
        $value
    );
}

function valid_employee_id(string $value): bool
{
    return (bool) preg_match(
        '/^[A-Za-z0-9-]{3,20}$/',
        $value
    );
}

function valid_pin(string $value): bool
{
    return (bool) preg_match(
        '/^[0-9]{4,8}$/',
        $value
    );
}


/* ---------- access control ---------- */

function require_admin(): void
{
    if (!Auth::isAdmin()) {
        if (!isset($_SESSION['_flash']['error'])) {
            flash(
                'error',
                'Please sign in with an administrator account.'
            );
        }

        redirect('auth/login.php');
    }
}


/* ---------- display helpers ---------- */

function company_name(): string
{
    static $name = null;

    if ($name === null) {
        require_once __DIR__ . '/SettingsRepository.php';

        $settings = SettingsRepository::get();

        $name = trim(
            (string) ($settings['company_name'] ?? '')
        ) ?: 'Northstar Company';
    }

    return $name;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];

    $output = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $output .= mb_strtoupper(
            mb_substr($part, 0, 1)
        );
    }

    return $output !== '' ? $output : '?';
}

function is_missed_checkout(array $row): bool
{
    return $row['check_out'] === null
        && substr((string) $row['check_in'], 0, 10) < today_string();
}

/**
 * Check-out cell:
 * Shows the time, "In progress", or "Missed".
 */
function checkout_cell(array $row): string
{
    if ($row['check_out'] !== null) {
        return e(fmt_time($row['check_out']));
    }

    return is_missed_checkout($row)
        ? '<span class="status missed">Missed</span>'
        : '<span class="muted">In progress</span>';
}
