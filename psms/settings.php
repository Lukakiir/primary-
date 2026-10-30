<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role(['admin']);

$fields = [
    'school_name'   => ['School name', 'text'],
    'academic_year' => ['Current academic year', 'text'],
    'current_term'  => ['Current term', 'select'],
    'currency'      => ['Currency code', 'text'],
    'pass_mark'     => ['Pass mark (average, 0–100)', 'number'],
];
$errors = [];
if (is_post()) {
    csrf_verify();
    $in = [];
    foreach ($fields as $k => $_) $in[$k] = trim((string)($_POST[$k] ?? ''));
    if ($in['school_name'] === '' || mb_strlen($in['school_name']) > 120) $errors['school_name'] = 'Enter the school name (up to 120 characters).';
    if (!preg_match('/^\d{4}$/', $in['academic_year'])) $errors['academic_year'] = 'Enter a four-digit year, e.g. ' . date('Y') . '.';
    if (!in_array($in['current_term'], ['1', '2', '3'], true)) $errors['current_term'] = 'Choose a term.';
    if (!preg_match('/^[A-Za-z]{2,5}$/', $in['currency'])) $errors['currency'] = 'Enter a short currency code such as SSP or USD.';
    if (!is_numeric($in['pass_mark']) || $in['pass_mark'] < 0 || $in['pass_mark'] > 100) $errors['pass_mark'] = 'Enter a number from 0 to 100.';
    if (!$errors) {
        foreach ($in as $k => $v) {
            if (row('SELECT setting_key FROM settings WHERE setting_key = ?', [$k])) q('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]);
            else q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$k, $v]);
        }
        flash('good', 'Settings saved.');
        redirect('settings.php');
    }
}
$vals = $errors ? $_POST : ['school_name' => school_name(), 'academic_year' => current_year(), 'current_term' => (string)current_term(), 'currency' => currency(), 'pass_mark' => (string)pass_mark()];
page_start('School settings', 'settings');
echo '<div class="card"><form method="post" class="form-grid" novalidate>' . csrf_field();
foreach ($fields as $k => [$label, $type]) {
    echo '<div class="field' . ($k === 'school_name' ? ' full' : '') . (isset($errors[$k]) ? ' has-error' : '') . '"><label for="' . $k . '">' . e($label) . '</label>';
    if ($type === 'select') echo select_html($k, ['1' => 'Term 1', '2' => 'Term 2', '3' => 'Term 3'], $vals[$k] ?? '');
    else echo '<input id="' . $k . '" name="' . $k . '" type="' . $type . '" value="' . e($vals[$k] ?? '') . '"' . ($type === 'number' ? ' min="0" max="100" step="1"' : '') . '>';
    if (isset($errors[$k])) echo '<small class="err">' . e($errors[$k]) . '</small>';
    echo '</div>';
}
echo '<div class="form-actions full"><button class="btn primary" type="submit">Save settings</button></div></form></div>';
page_end();
