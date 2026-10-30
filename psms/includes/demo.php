<?php
declare(strict_types=1);

/** Fictional demo data (no real people). Loaded only if the installer box is ticked. */
function load_demo_data(string $teacherPasswordHash): void
{
    mt_srand(2027);
    $year = current_year();
    $pdo = db();

    $tNames = [['Achol', 'Garang', 'Female'], ['Deng', 'Majok', 'Male'], ['Nyandeng', 'Bol', 'Female'], ['Lado', 'Wani', 'Male'], ['Rose', 'Modi', 'Female'], ['Samuel', 'Kenyi', 'Male'], ['Grace', 'Duku', 'Female'], ['Peter', 'Loro', 'Male']];
    $teacherIds = [];
    foreach ($tNames as $i => [$f, $l, $g]) {
        q('INSERT INTO teachers (staff_no, first_name, last_name, gender, phone, qualification, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ['T' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), $f, $l, $g, '+211 9' . mt_rand(10000000, 99999999), $i % 2 ? 'Diploma in Education' : 'Bachelor of Education', ($year - mt_rand(1, 9)) . '-0' . mt_rand(1, 9) . '-15', 'active']);
        $teacherIds[] = (int)$pdo->lastInsertId();
    }

    $subs = [['ENG', 'English'], ['MAT', 'Mathematics'], ['SCI', 'Science'], ['SST', 'Social Studies'], ['CRE', 'Religious Education'], ['CAR', 'Creative Arts']];
    $subjectIds = [];
    foreach ($subs as [$c, $n]) { q('INSERT INTO subjects (code, name) VALUES (?, ?)', [$c, $n]); $subjectIds[] = (int)$pdo->lastInsertId(); }

    $classIds = [];
    for ($i = 1; $i <= 8; $i++) {
        q('INSERT INTO classes (name, academic_year, class_teacher_id) VALUES (?, ?, ?)', ['P' . $i, $year, $teacherIds[$i - 1]]);
        $classIds[$i] = (int)$pdo->lastInsertId();
    }
    // Each teacher teaches 3 subjects in their own class and covers one subject in the next class up.
    foreach ($teacherIds as $i => $tid) {
        $cls = $classIds[$i + 1];
        foreach (array_slice($subjectIds, 0, 3) as $sid) q('INSERT INTO teacher_subjects (teacher_id, subject_id, class_id) VALUES (?, ?, ?)', [$tid, $sid, $cls]);
        foreach (array_slice($subjectIds, 3) as $sid) q('INSERT INTO teacher_subjects (teacher_id, subject_id, class_id) VALUES (?, ?, ?)', [$teacherIds[($i + 1) % 8], $sid, $cls]);
    }

    $first = [['Akol', 'Female'], ['Mayen', 'Male'], ['Nyibol', 'Female'], ['Kuol', 'Male'], ['Ayen', 'Female'], ['Majak', 'Male'], ['Awut', 'Female'], ['Chol', 'Male'], ['Adut', 'Female'], ['Jok', 'Male'], ['Yar', 'Female'], ['Bak', 'Male'], ['Amer', 'Female'], ['Thon', 'Male'], ['Lual', 'Male'], ['Abuk', 'Female']];
    $last = ['Mading', 'Akech', 'Ruot', 'Piol', 'Atem', 'Ajak', 'Machar', 'Kiir', 'Aluong', 'Biar', 'Dau', 'Marial'];
    $pFirst = ['John', 'Mary', 'James', 'Sarah', 'Joseph', 'Rebecca', 'Daniel', 'Esther'];
    $studentIds = []; $adm = 1; $parentId = null;
    foreach ($classIds as $lvl => $cid) {
        for ($k = 0; $k < 6; $k++) {
            [$fn, $g] = $first[($adm * 7 + $k) % count($first)];
            $ln = $last[($adm * 5 + $lvl) % count($last)];
            if ($adm % 5 !== 0 || !$parentId) {
                q('INSERT INTO parents (full_name, relationship, phone, occupation) VALUES (?, ?, ?, ?)', [$pFirst[$adm % count($pFirst)] . ' ' . $ln, $adm % 2 ? 'Father' : 'Mother', '+211 9' . mt_rand(10000000, 99999999), ['Trader', 'Farmer', 'Driver', 'Nurse', 'Teacher'][$adm % 5]]);
                $parentId = (int)$pdo->lastInsertId();
            }
            q('INSERT INTO students (admission_no, first_name, last_name, gender, date_of_birth, class_id, parent_id, address, enrollment_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [sprintf('AWPS-%s-%03d', $year, $adm), $fn, $ln, $g, ($year - 5 - $lvl) . '-' . str_pad((string)mt_rand(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)mt_rand(1, 28), 2, '0', STR_PAD_LEFT), $cid, $parentId, 'Atalabara, Juba', $year . '-02-01', 'active']);
            $studentIds[] = ['id' => (int)$pdo->lastInsertId(), 'class' => $cid];
            $adm++;
        }
    }

    $adminId = (int)val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    q('INSERT INTO users (username, password_hash, full_name, role, teacher_id, active) VALUES (?, ?, ?, ?, ?, 1)', ['teacher1', $teacherPasswordHash, 'Achol Garang', 'teacher', $teacherIds[0]]);

    q('INSERT INTO exams (name, term, academic_year, start_date, end_date, description) VALUES (?, ?, ?, ?, ?, ?)', ['Mid-Term 1 Examination', 1, $year, $year . '-03-10', $year . '-03-14', 'Demonstration examination']);
    $examId = (int)$pdo->lastInsertId();
    foreach ($studentIds as $s) {
        $ability = mt_rand(45, 85);
        foreach ($subjectIds as $sid) q('INSERT INTO marks (exam_id, student_id, subject_id, score, recorded_by) VALUES (?, ?, ?, ?, ?)', [$examId, $s['id'], $sid, max(18, min(99, $ability + mt_rand(-18, 14))), $adminId]);
    }

    $days = [];
    for ($d = 1; count($days) < 5; $d++) { $t = strtotime("-$d day"); if ((int)date('N', $t) < 6) $days[] = date('Y-m-d', $t); }
    $days[] = $year . '-03-11'; $days[] = $year . '-03-12';
    foreach ($days as $day) foreach ($studentIds as $s) {
        $r = mt_rand(1, 100); $st = $r <= 86 ? 'present' : ($r <= 93 ? 'late' : ($r <= 97 ? 'absent' : 'excused'));
        if (!row('SELECT id FROM attendance WHERE student_id = ? AND att_date = ?', [$s['id'], $day])) q('INSERT INTO attendance (student_id, class_id, att_date, status, recorded_by) VALUES (?, ?, ?, ?, ?)', [$s['id'], $s['class'], $day, $st, $adminId]);
    }

    $n = 1;
    foreach ($studentIds as $s) {
        if (mt_rand(1, 100) > 70) continue;
        q('INSERT INTO fee_payments (student_id, amount, paid_on, term, academic_year, method, receipt_no, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$s['id'], [5000, 7500, 10000, 15000][mt_rand(0, 3)], $year . '-02-' . str_pad((string)mt_rand(1, 28), 2, '0', STR_PAD_LEFT), 1, $year, ['Cash', 'Mobile money', 'Bank transfer'][mt_rand(0, 2)], sprintf('RCT-%s-%05d', $year, $n++), $adminId]);
    }
}
