<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/demo.php';

$lock = __DIR__ . '/install.lock';
$errors = []; $done = false; $demo = false; $v = ['full_name' => '', 'username' => 'admin'];
$c = cfg('db');

function install_page(string $title, callable $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · PSMS</title><link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head><body class="login-body"><main class="login-card wide">';
    echo '<div class="brand login-brand"><span class="mark" aria-hidden="true">P</span><div><strong>PSMS installer</strong><small>Primary School Management System</small></div></div>';
    $body();
    echo '</main></body></html>';
}

if (file_exists($lock)) {
    install_page('Already installed', function () {
        echo '<div class="alert warn">PSMS is already installed. For safety the installer is locked.</div><p><a class="btn primary" href="' . e(url('login.php')) . '">Go to sign in</a></p><p class="muted small">To reinstall from scratch, drop the database tables and delete <code>install.lock</code>.</p>';
    });
    exit;
}

if (is_post()) {
    csrf_verify();
    $v = ['full_name' => trim((string)($_POST['full_name'] ?? '')), 'username' => trim((string)($_POST['username'] ?? ''))];
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
    $demo = !empty($_POST['demo']);
    if ($v['full_name'] === '') $errors[] = 'Enter the administrator\'s full name.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v['username'])) $errors[] = 'Username must be 3–50 letters, numbers, dots, dashes or underscores.';
    if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        try {
            if ($c['driver'] === 'mysql') {
                $root = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $schemaFile = __DIR__ . '/database/schema.sql';
            } else {
                $schemaFile = __DIR__ . '/tests/schema_sqlite.sql';   // local testing only
            }
            $pdo = db();
            $exists = false;
            try { $pdo->query('SELECT 1 FROM users LIMIT 1'); $exists = true; } catch (Throwable $e) {}
            if ($exists) throw new RuntimeException('Tables already exist in database "' . $c['name'] . '". Installation stopped so no data is overwritten.');
            $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($schemaFile));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);

            foreach (['school_name' => 'Atalabara West Primary School', 'academic_year' => date('Y'), 'current_term' => '1', 'currency' => 'SSP', 'pass_mark' => '50'] as $k => $val)
                q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$k, $val]);
            q('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (?, ?, ?, ?, 1)', [$v['username'], password_hash($pw, PASSWORD_DEFAULT), $v['full_name'], 'admin']);
            if ($demo) load_demo_data(password_hash($pw, PASSWORD_DEFAULT));
            file_put_contents($lock, 'Installed ' . date('c') . "\n");
            $done = true;
        } catch (Throwable $e) {
            $errors[] = $e instanceof PDOException ? 'Database error: ' . $e->getMessage() . ' — check the settings in config.php and that MySQL is running.' : $e->getMessage();
        }
    }
}

install_page('Install', function () use ($errors, $done, $demo, $v, $c) {
    if ($done) {
        echo '<div class="alert good">Installation complete.</div><p>Sign in as <strong>' . e($v['username']) . '</strong> with the password you just chose.' . ($demo ? ' Demo data was loaded, including a teacher account <strong>teacher1</strong> (same password).' : '') . '</p>';
        echo '<p><a class="btn primary" href="' . e(url('login.php')) . '">Go to sign in</a></p><p class="muted small">For security, delete <code>install.php</code> from the folder once you are done.</p>';
        return;
    }
    echo '<p class="muted">This creates the <code>' . e($c['driver'] === 'mysql' ? $c['name'] : 'sqlite') . '</code> database, its tables, and your administrator account. Database connection settings come from <code>config.php</code> (default XAMPP: user <code>root</code>, no password).</p>';
    foreach ($errors as $er) echo '<div class="alert bad" role="alert">' . e($er) . '</div>';
    echo '<form method="post" class="stack" novalidate>' . csrf_field();
    echo '<div class="field"><label for="full_name">Administrator full name</label><input id="full_name" name="full_name" required value="' . e($v['full_name']) . '"></div>';
    echo '<div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username" value="' . e($v['username']) . '"></div>';
    echo '<div class="field"><label for="password">Password <small class="hint">at least 8 characters</small></label><input id="password" type="password" name="password" required autocomplete="new-password"></div>';
    echo '<div class="field"><label for="password2">Confirm password</label><input id="password2" type="password" name="password2" required autocomplete="new-password"></div>';
    echo '<label class="check"><input type="checkbox" name="demo" value="1"> Load fictional demo data (8 classes, 48 pupils, teachers, marks, attendance, fees)</label>';
    echo '<button class="btn primary wide" type="submit">Install PSMS</button></form>';
});
