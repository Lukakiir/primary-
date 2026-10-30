<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('dashboard.php');
if (!file_exists(__DIR__ . '/install.lock') && !is_post()) {
    try { $installed = (int)val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable $e) { $installed = false; }
    if (!$installed) redirect('install.php');
}

$error = '';
if (is_post()) {
    csrf_verify();
    $now = time();
    if (($_SESSION['lock_until'] ?? 0) > $now) {
        $error = 'Too many failed attempts. Please wait a minute and try again.';
    } elseif (attempt_login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
        unset($_SESSION['fails'], $_SESSION['lock_until']);
        redirect('dashboard.php');
    } else {
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = $now + 60; $_SESSION['fails'] = 0; }
        $error = 'Incorrect username or password.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · PSMS</title><link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>"></head>
<body class="login-body"><main class="login-card">
<div class="brand login-brand"><span class="mark" aria-hidden="true">P</span><div><strong>Primary School Management System</strong><small><?= e(school_name()) ?></small></div></div>
<?php foreach (flash() as [$t, $m]): ?><div class="alert <?= e($t) ?>" role="status"><?= e($m) ?></div><?php endforeach; ?>
<?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack" novalidate>
  <?= csrf_field() ?>
  <div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></div>
  <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required></div>
  <button class="btn primary wide" type="submit">Sign in</button>
</form>
<p class="muted small center">Authorised school staff only.</p>
</main></body></html>
