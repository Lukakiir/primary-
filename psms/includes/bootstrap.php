<?php
declare(strict_types=1);

/* ---------------------------------------------------------------
 * PSMS bootstrap: config, session, database, helpers, auth, layout
 * ------------------------------------------------------------- */

define('PSMS_ROOT', dirname(__DIR__));
$GLOBALS['psms_config'] = require PSMS_ROOT . '/config.php';

session_name('psms_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/* ---------- configuration ---------- */
function cfg(string $key, $default = null)
{
    $v = $GLOBALS['psms_config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) return $default;
        $v = $v[$part];
    }
    return $v;
}

/* ---------- database (PDO, prepared statements only) ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if ($c['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts + [PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function row(string $sql, array $params = []): ?array  { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $params = [])          { $r = q($sql, $params)->fetch(PDO::FETCH_NUM); return $r === false ? null : $r[0]; }

/* ---------- settings (stored in the database) ---------- */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (rows('SELECT setting_key, setting_value FROM settings') as $r) $cache[$r['setting_key']] = $r['setting_value']; }
        catch (Throwable $e) { /* not installed yet */ }
    }
    return $cache[$key] ?? $default;
}
function school_name(): string   { return setting('school_name', 'Atalabara West Primary School'); }
function current_year(): string  { return setting('academic_year', date('Y')); }
function current_term(): int     { return max(1, min(3, (int)setting('current_term', '1'))); }
function currency(): string      { return setting('currency', 'SSP'); }
function pass_mark(): float      { return (float)setting('pass_mark', '50'); }

/* ---------- output / request helpers ---------- */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_path(): string { $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); return $d; }
function url(string $path = ''): string { return base_path() . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function money($n): string { return currency() . ' ' . number_format((float)$n, 2); }
function fmt_date(?string $d): string { if (!$d) return '—'; $t = strtotime($d); return $t ? date('d M Y', $t) : e($d); }
function full_name(array $r): string { return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); }
function valid_date(string $d): bool { $p = explode('-', $d); return count($p) === 3 && ctype_digit(implode('', $p)) && checkdate((int)$p[1], (int)$p[2], (int)$p[0]) && strlen($p[0]) === 4; }

function flash(?string $type = null, ?string $msg = null): array
{
    if ($type !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired or the form was invalid. Go back, reload the page and try again.');
    }
}

/* ---------- grading ---------- */
function grade_scale(): array
{
    return [[80, 'A', 'Excellent'], [70, 'B', 'Very good'], [60, 'C', 'Good'], [50, 'D', 'Fair'], [0, 'E', 'Needs improvement']];
}
function grade_for(float $score): array
{
    foreach (grade_scale() as [$min, $letter, $remark]) if ($score >= $min) return [$letter, $remark];
    return ['E', 'Needs improvement'];
}

/* ---------- authentication & roles ---------- */
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }

function require_login(): array
{
    $u = current_user();
    $idle = (int)cfg('session_idle_seconds', 1800);
    if ($u && isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('warn', 'You were signed out after a period of inactivity.');
        $u = null;
    }
    if (!$u) redirect('login.php');
    $_SESSION['last_seen'] = time();
    return $u;
}
function forbidden(): never
{
    http_response_code(403);
    page_start('Access denied');
    echo '<div class="card"><h2>Access denied</h2><p>Your account does not have permission to do that.</p><p><a class="btn" href="' . e(url('dashboard.php')) . '">Back to dashboard</a></p></div>';
    page_end();
    exit;
}
function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) forbidden();
    return $u;
}
function attempt_login(string $username, string $password): bool
{
    $u = row('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
    // Always run a hash comparison so response time doesn't reveal whether a username exists.
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidinvalidinvalidinvalidinva';
    $ok = password_verify($password, $hash) && $u;
    if (!$ok) return false;
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'teacher_id' => $u['teacher_id'] ? (int)$u['teacher_id'] : null];
    $_SESSION['last_seen'] = time();
    return true;
}
function do_logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);   // old session id is discarded; a fresh anonymous session starts
}

/** Classes the current teacher may work with (null = unrestricted: admins and unlinked accounts). */
function allowed_class_ids(): ?array
{
    $u = current_user();
    if (!$u || $u['role'] === 'admin' || !$u['teacher_id']) return null;
    $ids = array_column(rows('SELECT DISTINCT class_id FROM teacher_subjects WHERE teacher_id = ?', [$u['teacher_id']]), 'class_id');
    $ids = array_merge($ids, array_column(rows('SELECT id FROM classes WHERE class_teacher_id = ?', [$u['teacher_id']]), 'id'));
    return array_values(array_unique(array_map('intval', $ids)));
}
/** Subjects the current teacher teaches in a class (null = unrestricted). */
function allowed_subject_ids(int $classId): ?array
{
    $u = current_user();
    if (!$u || $u['role'] === 'admin' || !$u['teacher_id']) return null;
    return array_map('intval', array_column(rows('SELECT subject_id FROM teacher_subjects WHERE teacher_id = ? AND class_id = ?', [$u['teacher_id'], $classId]), 'subject_id'));
}

/* ---------- option lists for dropdowns ---------- */
function opt_classes(?array $only = null): array
{
    $out = [];
    foreach (rows('SELECT id, name FROM classes ORDER BY name') as $r) if ($only === null || in_array((int)$r['id'], $only, true)) $out[$r['id']] = $r['name'];
    return $out;
}
function opt_teachers(): array  { $o = []; foreach (rows("SELECT id, first_name, last_name FROM teachers WHERE status = 'active' ORDER BY last_name, first_name") as $r) $o[$r['id']] = full_name($r); return $o; }
function opt_subjects(): array  { $o = []; foreach (rows('SELECT id, name FROM subjects ORDER BY name') as $r) $o[$r['id']] = $r['name']; return $o; }
function opt_parents(): array   { $o = []; foreach (rows('SELECT id, full_name, phone FROM parents ORDER BY full_name') as $r) $o[$r['id']] = $r['full_name'] . ($r['phone'] ? ' (' . $r['phone'] . ')' : ''); return $o; }
function opt_students(): array  { $o = []; foreach (rows("SELECT id, admission_no, first_name, last_name FROM students WHERE status = 'active' ORDER BY last_name, first_name") as $r) $o[$r['id']] = $r['admission_no'] . ' – ' . full_name($r); return $o; }
function opt_exams(): array     { $o = []; foreach (rows('SELECT id, name, term, academic_year FROM exams ORDER BY academic_year DESC, term DESC, id DESC') as $r) $o[$r['id']] = $r['name'] . ' (Term ' . $r['term'] . ', ' . $r['academic_year'] . ')'; return $o; }
function year_opts(): array { $y = (int)date('Y'); $o = []; for ($i = $y + 1; $i >= $y - 4; $i--) $o[(string)$i] = (string)$i; return $o; }
function select_html(string $name, array $options, $selected, string $placeholder = '', array $attrs = []): string
{
    $id = e($attrs['id'] ?? $name);
    unset($attrs['id']);
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . $k . '="' . e($v) . '"';
    $h = '<select name="' . e($name) . '" id="' . $id . '"' . $a . '>';
    if ($placeholder !== '') $h .= '<option value="">' . e($placeholder) . '</option>';
    foreach ($options as $k => $label) $h .= '<option value="' . e($k) . '"' . ((string)$selected === (string)$k ? ' selected' : '') . '>' . e($label) . '</option>';
    return $h . '</select>';
}

/* ---------- layout ---------- */
function nav_items(): array
{
    return [
        'Overview'  => [['dashboard', 'Dashboard', 'dashboard.php', ['admin', 'teacher']]],
        'Records'   => [
            ['students', 'Students', 'students.php', ['admin', 'teacher']],
            ['parents', 'Parents / guardians', 'parents.php', ['admin', 'teacher']],
            ['teachers', 'Teachers', 'teachers.php', ['admin', 'teacher']],
            ['classes', 'Classes', 'classes.php', ['admin', 'teacher']],
            ['subjects', 'Subjects', 'subjects.php', ['admin', 'teacher']],
            ['assignments', 'Teacher–subject assignment', 'assignments.php', ['admin', 'teacher']],
        ],
        'Academics' => [
            ['attendance', 'Attendance', 'attendance.php', ['admin', 'teacher']],
            ['exams', 'Examinations', 'exams.php', ['admin', 'teacher']],
            ['marks', 'Marks entry', 'marks.php', ['admin', 'teacher']],
            ['reports', 'Reports & report cards', 'reports.php', ['admin', 'teacher']],
        ],
        'Finance'   => [['fees', 'Fee payments', 'fees.php', ['admin']]],
        'System'    => [
            ['users', 'User accounts', 'users.php', ['admin']],
            ['settings', 'School settings', 'settings.php', ['admin']],
        ],
    ];
}

function page_start(string $title, string $active = ''): void
{
    $u = current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · PSMS</title><link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head><body>';
    echo '<a class="skip" href="#main">Skip to content</a><div class="shell">';
    echo '<aside class="side" id="side" aria-label="Main navigation"><div class="brand"><span class="mark" aria-hidden="true">P</span><div><strong>PSMS</strong><small>' . e(school_name()) . '</small></div></div><nav>';
    if ($u) {
        foreach (nav_items() as $group => $items) {
            $vis = array_filter($items, fn($i) => in_array($u['role'], $i[3], true));
            if (!$vis) continue;
            echo '<div class="nav-group">' . e($group) . '</div>';
            foreach ($vis as [$key, $label, $file]) {
                echo '<a href="' . e(url($file)) . '"' . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . e($label) . '</a>';
            }
        }
    }
    echo '</nav></aside><div class="main-col"><header class="top"><button class="menu-btn" type="button" id="menuBtn" aria-controls="side" aria-expanded="false">Menu</button>';
    echo '<h1 class="page-title">' . e($title) . '</h1><div class="who">';
    if ($u) {
        echo '<span>' . e($u['full_name']) . ' <em class="role">' . e($u['role']) . '</em></span>';
        echo '<form method="post" action="' . e(url('logout.php')) . '">' . csrf_field() . '<button class="btn ghost small" type="submit">Sign out</button></form>';
    }
    echo '</div></header><main id="main">';
    foreach (flash() as [$type, $msg]) echo '<div class="alert ' . e($type) . '" role="status">' . e($msg) . '</div>';
}
function page_end(): void
{
    echo '</main><footer class="foot">Primary School Management System · ' . e(school_name()) . '</footer></div></div><script src="' . e(url('assets/app.js')) . '"></script></body></html>';
}

/** Small HTML helpers */
function badge(string $text, string $tone = 'neutral'): string { return '<span class="badge ' . e($tone) . '">' . e($text) . '</span>'; }
function status_badge(?string $s): string
{
    $tone = ['active' => 'good', 'present' => 'good', 'inactive' => 'neutral', 'withdrawn' => 'bad', 'transferred' => 'warn', 'graduated' => 'info', 'absent' => 'bad', 'late' => 'warn', 'excused' => 'info'][$s ?? ''] ?? 'neutral';
    return badge(ucfirst((string)$s), $tone);
}
