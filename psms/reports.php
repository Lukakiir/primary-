<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/results.php';
$user = require_login();

$tab = $_GET['tab'] ?? 'performance';
if (!in_array($tab, ['performance', 'attendance', 'fees'], true) || ($tab === 'fees' && !is_admin())) $tab = 'performance';
$classes = opt_classes(allowed_class_ids());
$classId = (int)($_GET['class_id'] ?? 0);

/* ---- CSV export of class results ---- */
if ($tab === 'performance' && ($_GET['export'] ?? '') === 'csv') {
    $examId = (int)($_GET['exam_id'] ?? 0);
    if (!isset($classes[$classId]) || !row('SELECT id FROM exams WHERE id = ?', [$examId])) { flash('bad', 'Choose an examination and class first.'); redirect('reports.php'); }
    $res = class_results($examId, $classId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_' . preg_replace('/\W+/', '_', $classes[$classId]) . '_exam' . $examId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['Position', 'Adm. no.', 'Name'], array_column($res['subjects'], 'name'), ['Total', 'Average', 'Grade']));
    foreach ($res['rows'] as $r) {
        $line = [$r['position'] ?? '', $r['student']['admission_no'], full_name($r['student'])];
        foreach ($res['subjects'] as $sid => $_) $line[] = $r['scores'][$sid] ?? '';
        fputcsv($out, array_merge($line, [$r['total'] ?? '', $r['average'] !== null ? round($r['average'], 1) : '', $r['grade'] ?? '']));
    }
    exit;
}

page_start('Reports & report cards', 'reports');
$tabs = ['performance' => 'Class performance', 'attendance' => 'Attendance summary'] + (is_admin() ? ['fees' => 'Fees summary'] : []);
echo '<div class="tabs" role="tablist">';
foreach ($tabs as $k => $l) echo '<a role="tab" href="' . e(url('reports.php?tab=' . $k)) . '"' . ($k === $tab ? ' class="on" aria-selected="true"' : '') . '>' . e($l) . '</a>';
echo '</div>';

/* ------------------------- performance ------------------------- */
if ($tab === 'performance') {
    $exams = opt_exams(); $examId = (int)($_GET['exam_id'] ?? 0);
    echo '<form method="get" class="card filters-card"><input type="hidden" name="tab" value="performance"><div class="form-grid three">';
    echo '<div class="field"><label for="exam_id">Examination</label>' . select_html('exam_id', $exams, $examId, 'Select…') . '</div>';
    echo '<div class="field"><label for="class_id">Class</label>' . select_html('class_id', $classes, $classId, 'Select…') . '</div>';
    echo '<div class="field align-end"><button class="btn primary" type="submit">Show results</button></div></div></form>';

    if (isset($exams[$examId], $classes[$classId])) {
        $res = class_results($examId, $classId);
        $qs = http_build_query(['exam_id' => $examId, 'class_id' => $classId]);
        if (!$res['subjects']) echo '<div class="card"><p class="muted">No marks have been entered for this class in this examination yet.</p></div>';
        else {
            $st = $res['stats'];
            echo '<div class="stats small-stats">';
            foreach ([['Class mean', $st['mean'] !== null ? number_format($st['mean'], 1) : '—'], ['Highest average', $st['high'] !== null ? number_format($st['high'], 1) : '—'], ['Lowest average', $st['low'] !== null ? number_format($st['low'], 1) : '—'], ['Pass rate (≥ ' . pass_mark() . ')', $st['pass_rate'] !== null ? round($st['pass_rate']) . '%' : '—']] as [$l, $n])
                echo '<div class="stat"><span class="n">' . e($n) . '</span><span class="l">' . e($l) . '</span></div>';
            echo '</div><div class="card flush"><div class="card-head"><h2>' . e($classes[$classId]) . ' · ' . e($exams[$examId]) . '</h2><span class="actions"><a class="btn small" href="' . e(url('reports.php?tab=performance&export=csv&' . $qs)) . '">Download CSV</a><a class="btn small" target="_blank" href="' . e(url('report_card.php?' . $qs)) . '">Print all report cards</a></span></div>';
            echo '<div class="table-wrap"><table class="results"><thead><tr><th>Pos.</th><th>Pupil</th>';
            foreach ($res['subjects'] as $s) echo '<th class="num" title="' . e($s['name']) . '">' . e($s['code']) . '</th>';
            echo '<th class="num">Total</th><th class="num">Avg</th><th>Grade</th><th></th></tr></thead><tbody>';
            foreach ($res['rows'] as $r) {
                $s = $r['student'];
                echo '<tr><td>' . ($r['position'] ?? '—') . '</td><td>' . e(full_name($s)) . ' <span class="muted">' . e($s['admission_no']) . '</span></td>';
                foreach ($res['subjects'] as $sid => $_) { $v = $r['scores'][$sid] ?? null; echo '<td class="num' . ($v !== null && $v < pass_mark() ? ' low' : '') . '">' . ($v !== null ? e(rtrim(rtrim(number_format($v, 2), '0'), '.')) : '—') . '</td>'; }
                echo '<td class="num">' . ($r['total'] !== null ? e(rtrim(rtrim(number_format($r['total'], 2), '0'), '.')) : '—') . '</td><td class="num">' . ($r['average'] !== null ? number_format($r['average'], 1) : '—') . '</td><td>' . e($r['grade'] ?? '—') . '</td>';
                echo '<td class="right">' . ($r['total'] !== null ? '<a class="link" target="_blank" href="' . e(url('report_card.php?exam_id=' . $examId . '&student_id=' . $s['id'])) . '">Report card</a>' : '') . '</td></tr>';
            }
            echo '<tr class="foot-row"><td></td><td>Subject average</td>';
            foreach ($res['subjects'] as $sid => $_) echo '<td class="num">' . ($res['subject_avg'][$sid] !== null ? number_format($res['subject_avg'][$sid], 1) : '—') . '</td>';
            echo '<td colspan="4"></td></tr></tbody></table></div></div>';
            echo '<p class="muted small">Average = total ÷ number of subjects examined in this class. Subject codes: ';
            echo e(implode(', ', array_map(fn($s) => $s['code'] . ' = ' . $s['name'], $res['subjects']))) . '.</p>';
        }
    }
}

/* ------------------------- attendance ------------------------- */
if ($tab === 'attendance') {
    $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date('Y-m-d'));
    echo '<form method="get" class="card filters-card"><input type="hidden" name="tab" value="attendance"><div class="form-grid three">';
    echo '<div class="field"><label for="class_id">Class</label>' . select_html('class_id', $classes, $classId, 'Select…') . '</div>';
    echo '<div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="' . e($from) . '"></div>';
    echo '<div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="' . e($to) . '"></div>';
    echo '<div class="field align-end"><button class="btn primary" type="submit">Show summary</button></div></div></form>';
    if (isset($classes[$classId])) {
        if (!valid_date($from) || !valid_date($to) || $to < $from) echo '<div class="alert bad">Enter a valid date range.</div>';
        else {
            $data = rows("SELECT s.id, s.admission_no, s.first_name, s.last_name,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late,
                    SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) AS excused, SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent, COUNT(a.id) AS days
                FROM students s LEFT JOIN attendance a ON a.student_id = s.id AND a.att_date BETWEEN ? AND ?
                WHERE s.class_id = ? AND s.status = 'active' GROUP BY s.id, s.admission_no, s.first_name, s.last_name ORDER BY s.last_name, s.first_name", [$from, $to, $classId]);
            echo '<div class="card flush"><div class="card-head"><h2>' . e($classes[$classId]) . ' · ' . e(fmt_date($from)) . ' to ' . e(fmt_date($to)) . '</h2></div><div class="table-wrap"><table><thead><tr><th>Pupil</th><th class="num">Present</th><th class="num">Late</th><th class="num">Excused</th><th class="num">Absent</th><th class="num">Days</th><th class="num">Attendance</th></tr></thead><tbody>';
            foreach ($data as $r) {
                $d = (int)$r['days']; $pct = $d ? round(((int)$r['present'] + (int)$r['late']) / $d * 100) : null;
                echo '<tr><td>' . e(full_name($r)) . ' <span class="muted">' . e($r['admission_no']) . '</span></td><td class="num">' . (int)$r['present'] . '</td><td class="num">' . (int)$r['late'] . '</td><td class="num">' . (int)$r['excused'] . '</td><td class="num' . ($r['absent'] ? ' low' : '') . '">' . (int)$r['absent'] . '</td><td class="num">' . $d . '</td><td class="num">' . ($pct !== null ? $pct . '%' : '—') . '</td></tr>';
            }
            if (!$data) echo '<tr><td colspan="7" class="empty">No active students in this class.</td></tr>';
            echo '</tbody></table></div></div><p class="muted small">Attendance % counts present and late days as attended.</p>';
        }
    }
}

/* ---------------------------- fees ----------------------------- */
if ($tab === 'fees') {
    $year = (string)($_GET['academic_year'] ?? current_year()); $term = (string)($_GET['term'] ?? '');
    echo '<form method="get" class="card filters-card"><input type="hidden" name="tab" value="fees"><div class="form-grid three">';
    echo '<div class="field"><label for="academic_year">Academic year</label>' . select_html('academic_year', year_opts(), $year) . '</div>';
    echo '<div class="field"><label for="term">Term</label>' . select_html('term', ['1' => 'Term 1', '2' => 'Term 2', '3' => 'Term 3'], $term, 'All terms') . '</div>';
    echo '<div class="field align-end"><button class="btn primary" type="submit">Show summary</button></div></div></form>';
    $p = [$year]; $tw = '';
    if (in_array($term, ['1', '2', '3'], true)) { $tw = ' AND f.term = ?'; $p[] = (int)$term; }
    $data = rows('SELECT c.name AS class_name, s.id, s.admission_no, s.first_name, s.last_name, SUM(f.amount) AS paid, COUNT(f.id) AS n FROM fee_payments f JOIN students s ON s.id = f.student_id JOIN classes c ON c.id = s.class_id WHERE f.academic_year = ?' . $tw . ' GROUP BY c.name, s.id, s.admission_no, s.first_name, s.last_name ORDER BY c.name, s.last_name, s.first_name', $p);
    $byClass = []; $grand = 0.0;
    foreach ($data as $r) { $byClass[$r['class_name']] = ($byClass[$r['class_name']] ?? 0) + (float)$r['paid']; $grand += (float)$r['paid']; }
    echo '<div class="stats small-stats"><div class="stat accent"><span class="n">' . e(money($grand)) . '</span><span class="l">Total collected</span></div><div class="stat"><span class="n">' . count($data) . '</span><span class="l">Students who paid</span></div></div>';
    echo '<div class="grid two"><div class="card flush"><div class="card-head"><h2>By class</h2></div><div class="table-wrap"><table><thead><tr><th>Class</th><th class="num">Collected</th></tr></thead><tbody>';
    foreach ($byClass as $cn => $amt) echo '<tr><td>' . e($cn) . '</td><td class="num">' . e(money($amt)) . '</td></tr>';
    if (!$byClass) echo '<tr><td colspan="2" class="empty">No payments in this period.</td></tr>';
    echo '</tbody></table></div></div><div class="card flush"><div class="card-head"><h2>By student</h2></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th class="num">Payments</th><th class="num">Paid</th></tr></thead><tbody>';
    foreach ($data as $r) echo '<tr><td>' . e(full_name($r)) . ' <span class="muted">' . e($r['admission_no']) . '</span></td><td>' . e($r['class_name']) . '</td><td class="num">' . (int)$r['n'] . '</td><td class="num">' . e(money($r['paid'])) . '</td></tr>';
    if (!$data) echo '<tr><td colspan="4" class="empty">No payments in this period.</td></tr>';
    echo '</tbody></table></div></div></div>';
}
page_end();
