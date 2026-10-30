<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$allowed = allowed_class_ids();
$classes = opt_classes($allowed);
$classId = (int)($_REQUEST['class_id'] ?? 0);
$date    = (string)($_REQUEST['att_date'] ?? date('Y-m-d'));
$statuses = ['present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent'];

if (is_post()) {
    csrf_verify();
    $err = null;
    if (!isset($classes[$classId])) $err = 'Choose one of your classes.';
    elseif (!valid_date($date) || $date > date('Y-m-d')) $err = 'Choose a valid date that is not in the future.';
    if (!$err) {
        $students = rows("SELECT id FROM students WHERE class_id = ? AND status = 'active'", [$classId]);
        $pdo = db(); $pdo->beginTransaction();
        try {
            foreach ($students as $s) {
                $sid = (int)$s['id'];
                $st = $_POST['status'][$sid] ?? '';
                if (!isset($statuses[$st])) continue;
                $rem = mb_substr(trim((string)($_POST['remarks'][$sid] ?? '')), 0, 255) ?: null;
                $ex = row('SELECT id FROM attendance WHERE student_id = ? AND att_date = ?', [$sid, $date]);
                if ($ex) q('UPDATE attendance SET status = ?, remarks = ?, class_id = ?, recorded_by = ? WHERE id = ?', [$st, $rem, $classId, $user['id'], $ex['id']]);
                else q('INSERT INTO attendance (student_id, class_id, att_date, status, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?)', [$sid, $classId, $date, $st, $rem, $user['id']]);
            }
            $pdo->commit();
            flash('good', 'Attendance saved for ' . $classes[$classId] . ' on ' . fmt_date($date) . '.');
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    } else flash('bad', $err);
    redirect('attendance.php?class_id=' . $classId . '&att_date=' . urlencode($date));
}

page_start('Attendance', 'attendance');
echo '<form method="get" class="card filters-card"><div class="form-grid three">';
echo '<div class="field"><label for="class_id">Class</label>' . select_html('class_id', $classes, $classId, 'Select a class…') . '</div>';
echo '<div class="field"><label for="att_date">Date</label><input type="date" id="att_date" name="att_date" value="' . e($date) . '" max="' . e(date('Y-m-d')) . '"></div>';
echo '<div class="field align-end"><button class="btn primary" type="submit">Load register</button></div></div></form>';

if ($classId && isset($classes[$classId]) && valid_date($date)) {
    $students = rows("SELECT id, admission_no, first_name, last_name FROM students WHERE class_id = ? AND status = 'active' ORDER BY last_name, first_name", [$classId]);
    $existing = [];
    foreach (rows('SELECT student_id, status, remarks FROM attendance WHERE att_date = ? AND class_id = ?', [$date, $classId]) as $r) $existing[(int)$r['student_id']] = $r;
    if (!$students) { echo '<div class="card"><p class="muted">There are no active students in this class.</p></div>'; }
    else {
        echo '<form method="post" class="card flush">' . csrf_field() . '<input type="hidden" name="class_id" value="' . $classId . '"><input type="hidden" name="att_date" value="' . e($date) . '">';
        echo '<div class="card-head"><h2>' . e($classes[$classId]) . ' · ' . e(fmt_date($date)) . '</h2>' . ($existing ? badge('Already recorded – editing', 'info') : badge('New register', 'neutral'));
        echo '<button type="button" class="btn small" data-mark-all="present">Mark all present</button></div>';
        echo '<div class="table-wrap"><table class="register"><thead><tr><th>Adm. no.</th><th>Pupil</th><th colspan="4" class="center">Status</th><th>Remarks</th></tr></thead><tbody>';
        foreach ($students as $s) {
            $sid = (int)$s['id']; $cur = $existing[$sid]['status'] ?? 'present';
            echo '<tr><td data-label="Adm. no.">' . e($s['admission_no']) . '</td><td data-label="Pupil">' . e(full_name($s)) . '</td>';
            foreach ($statuses as $k => $label) echo '<td class="center rd" data-label="' . e($label) . '"><label class="radio ' . $k . '"><input type="radio" name="status[' . $sid . ']" value="' . $k . '"' . ($cur === $k ? ' checked' : '') . '><span>' . e($label) . '</span></label></td>';
            echo '<td data-label="Remarks"><input type="text" name="remarks[' . $sid . ']" maxlength="255" value="' . e($existing[$sid]['remarks'] ?? '') . '" aria-label="Remarks for ' . e(full_name($s)) . '"></td></tr>';
        }
        echo '</tbody></table></div><div class="card-foot"><button class="btn primary" type="submit">Save attendance</button></div></form>';
    }
}
page_end();
