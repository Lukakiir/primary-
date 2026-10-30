<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$admin = $user['role'] === 'admin';
$year = current_year(); $term = current_term(); $today = date('Y-m-d');

$nStudents = (int)val("SELECT COUNT(*) FROM students WHERE status = 'active'");
$nTeachers = (int)val("SELECT COUNT(*) FROM teachers WHERE status = 'active'");
$nClasses  = (int)val('SELECT COUNT(*) FROM classes');
$nSubjects = (int)val('SELECT COUNT(*) FROM subjects');
$perClass  = rows("SELECT c.name, COUNT(s.id) n FROM classes c LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active' GROUP BY c.id, c.name ORDER BY c.name");
$attToday  = array_column(rows('SELECT status, COUNT(*) n FROM attendance WHERE att_date = ? GROUP BY status', [$today]), 'n', 'status');
$attTotal  = array_sum($attToday);
$exams     = rows('SELECT id, name, term, academic_year, start_date FROM exams ORDER BY academic_year DESC, term DESC, id DESC LIMIT 4');

page_start('Dashboard', 'dashboard');
echo '<p class="lead">Welcome, ' . e($user['full_name']) . '. ' . e(date('l, j F Y')) . ' · Academic year ' . e($year) . ', Term ' . $term . '.</p>';
echo '<div class="stats">';
foreach ([['Active students', $nStudents, 'students.php'], ['Active teachers', $nTeachers, 'teachers.php'], ['Classes', $nClasses, 'classes.php'], ['Subjects', $nSubjects, 'subjects.php']] as [$l, $n, $f])
    echo '<a class="stat" href="' . e(url($f)) . '"><span class="n">' . $n . '</span><span class="l">' . e($l) . '</span></a>';
if ($admin) {
    $paid = (float)val('SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE academic_year = ? AND term = ?', [$year, $term]);
    echo '<a class="stat accent" href="' . e(url('fees.php?academic_year=' . $year . '&term=' . $term)) . '"><span class="n">' . e(money($paid)) . '</span><span class="l">Fees collected, Term ' . $term . '</span></a>';
}
echo '</div><div class="grid two">';

echo '<div class="card"><h2>Pupils per class</h2>';
$max = max(1, ...array_map(fn($r) => (int)$r['n'], $perClass ?: [['n' => 1]]));
if (!$perClass) echo '<p class="muted">No classes yet. <a class="link" href="' . e(url('classes.php?a=new')) . '">Add a class</a>.</p>';
foreach ($perClass as $r) echo '<div class="bar-row"><span class="bar-l">' . e($r['name']) . '</span><span class="bar"><i style="width:' . round($r['n'] / $max * 100) . '%"></i></span><span class="bar-n">' . (int)$r['n'] . '</span></div>';
echo '</div>';

echo '<div class="card"><h2>Attendance today</h2>';
if ($attTotal) {
    $att = ($attToday['present'] ?? 0) + ($attToday['late'] ?? 0);
    echo '<p class="big">' . round($att / $attTotal * 100) . '%<small> attended (' . $att . ' of ' . $attTotal . ' marked)</small></p><p>';
    foreach (['present', 'late', 'excused', 'absent'] as $k) echo status_badge($k) . ' ' . (int)($attToday[$k] ?? 0) . ' &nbsp; ';
    echo '</p>';
} else echo '<p class="muted">No attendance has been recorded today.</p>';
echo '<p><a class="btn" href="' . e(url('attendance.php')) . '">Take attendance</a></p></div>';

echo '<div class="card"><h2>Recent examinations</h2>';
if ($exams) {
    echo '<ul class="plain">';
    foreach ($exams as $x) echo '<li><span>' . e($x['name']) . '</span><span class="muted">Term ' . (int)$x['term'] . ' · ' . e($x['academic_year']) . '</span></li>';
    echo '</ul>';
} else echo '<p class="muted">No examinations set up yet.</p>';
echo '<p><a class="btn" href="' . e(url('reports.php')) . '">View reports</a></p></div>';

if ($admin) {
    $recent = rows('SELECT f.receipt_no, f.amount, f.paid_on, s.first_name, s.last_name FROM fee_payments f JOIN students s ON s.id = f.student_id ORDER BY f.paid_on DESC, f.id DESC LIMIT 5');
    echo '<div class="card"><h2>Latest fee payments</h2>';
    if ($recent) { echo '<ul class="plain">'; foreach ($recent as $r) echo '<li><span>' . e(full_name($r)) . '<br><small class="muted">' . e($r['receipt_no']) . ' · ' . e(fmt_date($r['paid_on'])) . '</small></span><span class="nowrap">' . e(money($r['amount'])) . '</span></li>'; echo '</ul>'; }
    else echo '<p class="muted">No payments recorded yet.</p>';
    echo '<p><a class="btn" href="' . e(url('fees.php?a=new')) . '">Record payment</a></p></div>';
} else {
    $mine = $user['teacher_id'] ? rows('SELECT s.name subject, c.name class_name FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id JOIN classes c ON c.id = ts.class_id WHERE ts.teacher_id = ? ORDER BY c.name, s.name', [$user['teacher_id']]) : [];
    echo '<div class="card"><h2>My teaching assignments</h2>';
    if ($mine) { echo '<ul class="plain">'; foreach ($mine as $r) echo '<li><span>' . e($r['subject']) . '</span><span class="muted">' . e($r['class_name']) . '</span></li>'; echo '</ul>'; }
    else echo '<p class="muted">No assignments recorded for your account.</p>';
    echo '</div>';
}
echo '</div>';
page_end();
