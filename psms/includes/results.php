<?php
declare(strict_types=1);

/**
 * Examination results for one class in one exam.
 * Average = total ÷ number of subjects examined in the class, so a missing mark counts as zero effort
 * rather than silently raising a pupil's average. Positions use standard competition ranking (ties share a rank).
 */
function class_results(int $examId, int $classId): array
{
    $students = rows("SELECT id, admission_no, first_name, last_name, gender FROM students WHERE class_id = ? AND status = 'active' ORDER BY last_name, first_name", [$classId]);
    $marks = rows('SELECT m.student_id, m.subject_id, m.score FROM marks m JOIN students s ON s.id = m.student_id WHERE m.exam_id = ? AND s.class_id = ?', [$examId, $classId]);

    $subjectIds = array_values(array_unique(array_map('intval', array_column($marks, 'subject_id'))));
    $subjects = [];
    foreach (rows('SELECT id, code, name FROM subjects ORDER BY name') as $s) if (in_array((int)$s['id'], $subjectIds, true)) $subjects[(int)$s['id']] = $s;

    $by = [];
    foreach ($marks as $m) $by[(int)$m['student_id']][(int)$m['subject_id']] = (float)$m['score'];

    $nSub = count($subjects);
    $out = [];
    foreach ($students as $s) {
        $scores = $by[(int)$s['id']] ?? [];
        $row = ['student' => $s, 'scores' => $scores, 'total' => null, 'average' => null, 'grade' => null, 'remark' => null, 'position' => null, 'entered' => count($scores)];
        if ($scores && $nSub) {
            $row['total'] = array_sum($scores);
            $row['average'] = $row['total'] / $nSub;
            [$row['grade'], $row['remark']] = grade_for($row['average']);
        }
        $out[] = $row;
    }
    $ranked = array_values(array_filter($out, fn($r) => $r['total'] !== null));
    usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']);
    $pos = []; $prev = null; $rank = 0;
    foreach ($ranked as $i => $r) {
        if ($prev === null || abs($r['total'] - $prev) > 0.0001) $rank = $i + 1;
        $pos[(int)$r['student']['id']] = $rank; $prev = $r['total'];
    }
    foreach ($out as &$r) $r['position'] = $pos[(int)$r['student']['id']] ?? null;
    unset($r);
    usort($out, fn($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX) ?: strcmp($a['student']['last_name'], $b['student']['last_name']));

    $avgs = array_filter(array_column($out, 'average'), fn($v) => $v !== null);
    $subjAvg = [];
    foreach ($subjects as $sid => $_) {
        $vals = array_filter(array_map(fn($r) => $r['scores'][$sid] ?? null, $out), fn($v) => $v !== null);
        $subjAvg[$sid] = $vals ? array_sum($vals) / count($vals) : null;
    }
    return [
        'subjects' => $subjects, 'rows' => $out, 'subject_avg' => $subjAvg, 'ranked_count' => count($ranked),
        'stats' => [
            'mean' => $avgs ? array_sum($avgs) / count($avgs) : null,
            'high' => $avgs ? max($avgs) : null,
            'low'  => $avgs ? min($avgs) : null,
            'pass_rate' => $avgs ? count(array_filter($avgs, fn($v) => $v >= pass_mark())) / count($avgs) * 100 : null,
        ],
    ];
}
