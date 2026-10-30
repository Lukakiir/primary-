<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/results.php';
$user = require_login();

$examId = (int)($_GET['exam_id'] ?? 0);
$exam = row('SELECT * FROM exams WHERE id = ?', [$examId]);
if (!$exam) { flash('bad', 'Examination not found.'); redirect('reports.php'); }

$studentId = (int)($_GET['student_id'] ?? 0);
$classId   = (int)($_GET['class_id'] ?? 0);
if ($studentId) {
    $st = row('SELECT class_id FROM students WHERE id = ?', [$studentId]);
    if (!$st) { flash('bad', 'Student not found.'); redirect('reports.php'); }
    $classId = (int)$st['class_id'];
}
$allowed = allowed_class_ids();
if (!$classId || ($allowed !== null && !in_array($classId, $allowed, true))) forbidden();
$class = row('SELECT c.*, t.first_name AS tf, t.last_name AS tl FROM classes c LEFT JOIN teachers t ON t.id = c.class_teacher_id WHERE c.id = ?', [$classId]);
$res = class_results($examId, $classId);
$cards = array_values(array_filter($res['rows'], fn($r) => $r['total'] !== null && (!$studentId || (int)$r['student']['id'] === $studentId)));
usort($cards, fn($a, $b) => strcmp($a['student']['last_name'] . $a['student']['first_name'], $b['student']['last_name'] . $b['student']['first_name']));

page_start('Report card', 'reports');
echo '<div class="noprint toolbar"><a class="link" href="' . e(url('reports.php?tab=performance&exam_id=' . $examId . '&class_id=' . $classId)) . '">← Back to results</a><button class="btn primary" type="button" data-print>Print</button></div>';
if (!$cards) echo '<div class="card"><p class="muted">No marks have been recorded for this pupil in this examination.</p></div>';
foreach ($cards as $r) {
    $s = $r['student'];
    $att = null;
    if ($exam['start_date'] && $exam['end_date']) {
        $a = array_column(rows('SELECT status, COUNT(*) n FROM attendance WHERE student_id = ? AND att_date BETWEEN ? AND ? GROUP BY status', [$s['id'], $exam['start_date'], $exam['end_date']]), 'n', 'status');
        if (array_sum($a)) $att = $a;
    }
    echo '<section class="sheet"><header class="sheet-head"><div class="crest" aria-hidden="true">P</div><div><h2>' . e(school_name()) . '</h2><p>STUDENT REPORT CARD</p></div></header>';
    echo '<dl class="sheet-info"><div><dt>Pupil</dt><dd>' . e(full_name($s)) . '</dd></div><div><dt>Admission no.</dt><dd>' . e($s['admission_no']) . '</dd></div><div><dt>Class</dt><dd>' . e($class['name']) . '</dd></div>';
    echo '<div><dt>Examination</dt><dd>' . e($exam['name']) . '</dd></div><div><dt>Term / year</dt><dd>Term ' . (int)$exam['term'] . ', ' . e($exam['academic_year']) . '</dd></div><div><dt>Class teacher</dt><dd>' . e(trim(($class['tf'] ?? '') . ' ' . ($class['tl'] ?? '')) ?: '—') . '</dd></div></dl>';
    echo '<table class="sheet-table"><thead><tr><th>Subject</th><th class="num">Score</th><th class="num">Out of</th><th>Grade</th><th class="num">Class avg</th><th>Remark</th></tr></thead><tbody>';
    foreach ($res['subjects'] as $sid => $sub) {
        $v = $r['scores'][$sid] ?? null; [$g, $rm] = $v !== null ? grade_for($v) : ['—', 'Not examined'];
        echo '<tr><td>' . e($sub['name']) . '</td><td class="num">' . ($v !== null ? e(rtrim(rtrim(number_format($v, 2), '0'), '.')) : '—') . '</td><td class="num">100</td><td>' . e($g) . '</td><td class="num">' . ($res['subject_avg'][$sid] !== null ? number_format($res['subject_avg'][$sid], 1) : '—') . '</td><td>' . e($rm) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td>Total</td><td class="num">' . e(rtrim(rtrim(number_format($r['total'], 2), '0'), '.')) . '</td><td class="num">' . (count($res['subjects']) * 100) . '</td><td colspan="3"></td></tr></tfoot></table>';
    echo '<div class="sheet-summary"><div><span>Average</span><strong>' . number_format($r['average'], 1) . '</strong></div><div><span>Grade</span><strong>' . e($r['grade']) . '</strong></div><div><span>Position</span><strong>' . (int)$r['position'] . ' of ' . (int)$res['ranked_count'] . '</strong></div>';
    if ($att) { $d = array_sum($att); echo '<div><span>Attendance (exam period)</span><strong>' . round((($att['present'] ?? 0) + ($att['late'] ?? 0)) / $d * 100) . '%</strong></div>'; }
    echo '</div><p class="grade-key"><strong>Grading:</strong> ' . e(implode(' · ', array_map(fn($g) => $g[1] . ' ' . $g[0] . '+ (' . $g[2] . ')', grade_scale()))) . '</p>';
    echo '<div class="sheet-sign"><div>Class teacher\'s comment:<span></span></div><div>Head teacher\'s comment:<span></span></div><div>Parent / guardian signature:<span></span></div></div></section>';
}
page_end();
