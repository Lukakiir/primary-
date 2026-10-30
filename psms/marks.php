<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$classes  = opt_classes(allowed_class_ids());
$exams    = opt_exams();
$examId   = (int)($_REQUEST['exam_id'] ?? 0);
$classId  = (int)($_REQUEST['class_id'] ?? 0);
$subjectId = (int)($_REQUEST['subject_id'] ?? 0);

$subjects = opt_subjects();
if ($classId) {
    $okSubjects = allowed_subject_ids($classId);
    if ($okSubjects !== null) $subjects = array_filter($subjects, fn($k) => in_array((int)$k, $okSubjects, true), ARRAY_FILTER_USE_KEY);
}
$ready = isset($exams[$examId], $classes[$classId], $subjects[$subjectId]);
$posted = null; $rowErrors = [];

if (is_post()) {
    csrf_verify();
    if (!$ready) { flash('bad', 'Choose an examination, class and subject you are allowed to enter marks for.'); redirect('marks.php'); }
    $students = rows("SELECT id FROM students WHERE class_id = ? AND status = 'active'", [$classId]);
    $clean = [];
    foreach ($students as $s) {
        $sid = (int)$s['id']; $v = trim((string)($_POST['score'][$sid] ?? ''));
        if ($v === '') { $clean[$sid] = null; continue; }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) { $rowErrors[$sid] = 'Enter a number from 0 to 100.'; continue; }
        $clean[$sid] = round((float)$v, 2);
    }
    if ($rowErrors) { $posted = $_POST['score']; flash('bad', 'Nothing was saved: ' . count($rowErrors) . ' score(s) need correcting.'); }
    else {
        $pdo = db(); $pdo->beginTransaction();
        try {
            foreach ($clean as $sid => $score) {
                $ex = row('SELECT id FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id = ?', [$examId, $sid, $subjectId]);
                if ($score === null) { if ($ex) q('DELETE FROM marks WHERE id = ?', [$ex['id']]); continue; }
                if ($ex) q('UPDATE marks SET score = ?, recorded_by = ? WHERE id = ?', [$score, $user['id'], $ex['id']]);
                else q('INSERT INTO marks (exam_id, student_id, subject_id, score, recorded_by) VALUES (?, ?, ?, ?, ?)', [$examId, $sid, $subjectId, $score, $user['id']]);
            }
            $pdo->commit();
            flash('good', 'Marks saved for ' . $subjects[$subjectId] . ' – ' . $classes[$classId] . '.');
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        redirect('marks.php?' . http_build_query(['exam_id' => $examId, 'class_id' => $classId, 'subject_id' => $subjectId]));
    }
}

page_start('Marks entry', 'marks');
echo '<form method="get" class="card filters-card"><div class="form-grid three">';
echo '<div class="field"><label for="exam_id">Examination</label>' . select_html('exam_id', $exams, $examId, 'Select…') . '</div>';
echo '<div class="field"><label for="class_id">Class</label>' . select_html('class_id', $classes, $classId, 'Select…') . '</div>';
echo '<div class="field"><label for="subject_id">Subject</label>' . select_html('subject_id', $subjects, $subjectId, 'Select…') . '</div>';
echo '<div class="field align-end"><button class="btn primary" type="submit">Load marks sheet</button></div></div>';
if ($classId && !$subjects) echo '<p class="muted small">You have no subjects assigned in this class. Ask an administrator to assign you under "Teacher–subject assignment".</p>';
echo '</form>';

if ($ready) {
    $students = rows("SELECT id, admission_no, first_name, last_name FROM students WHERE class_id = ? AND status = 'active' ORDER BY last_name, first_name", [$classId]);
    $cur = array_column(rows('SELECT student_id, score FROM marks WHERE exam_id = ? AND subject_id = ?', [$examId, $subjectId]), 'score', 'student_id');
    if (!$students) echo '<div class="card"><p class="muted">There are no active students in this class.</p></div>';
    else {
        echo '<form method="post" class="card flush">' . csrf_field() . '<input type="hidden" name="exam_id" value="' . $examId . '"><input type="hidden" name="class_id" value="' . $classId . '"><input type="hidden" name="subject_id" value="' . $subjectId . '">';
        echo '<div class="card-head"><h2>' . e($subjects[$subjectId]) . ' · ' . e($classes[$classId]) . '</h2><span class="muted">' . e($exams[$examId]) . ' · score out of 100</span></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>Adm. no.</th><th>Pupil</th><th style="width:160px">Score (0–100)</th><th>Grade</th></tr></thead><tbody>';
        foreach ($students as $s) {
            $sid = (int)$s['id'];
            $v = $posted !== null ? ($posted[$sid] ?? '') : (isset($cur[$sid]) ? rtrim(rtrim(number_format((float)$cur[$sid], 2, '.', ''), '0'), '.') : '');
            $err = $rowErrors[$sid] ?? null;
            echo '<tr' . ($err ? ' class="row-error"' : '') . '><td data-label="Adm. no.">' . e($s['admission_no']) . '</td><td data-label="Pupil">' . e(full_name($s)) . '</td>';
            echo '<td data-label="Score"><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="score[' . $sid . ']" value="' . e($v) . '" aria-label="Score for ' . e(full_name($s)) . '"' . ($err ? ' aria-invalid="true"' : '') . '>' . ($err ? '<small class="err">' . e($err) . '</small>' : '') . '</td>';
            echo '<td data-label="Grade">' . (is_numeric($v) && $v !== '' ? e(grade_for((float)$v)[0]) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div><div class="card-foot"><button class="btn primary" type="submit">Save marks</button><span class="muted small">Leave a box empty if the pupil did not sit the paper.</span></div></form>';
    }
}
page_end();
