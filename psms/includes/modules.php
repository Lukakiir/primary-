<?php
declare(strict_types=1);

/** Configuration for each CRUD-driven module. */

function gender_opts(): array { return ['Male' => 'Male', 'Female' => 'Female']; }
function term_opts(): array   { return ['1' => 'Term 1', '2' => 'Term 2', '3' => 'Term 3']; }
function status_opts(array $v): array { $o = []; foreach ($v as $s) $o[$s] = ucfirst($s); return $o; }
function class_opts(): array {
    $o = [];
    for ($i = 1; $i <= 8; $i++) { $o['P' . $i] = 'P' . $i; }
    return $o;
}

/** Return database class IDs for the P1–P8 student registration dropdown. */
function registration_class_opts(): array
{
    $classes = rows('SELECT id, name FROM classes');
    $classIds = [];
    $classNames = [];
    foreach ($classes as $class) {
        // Demo/older installs use names such as "Primary 1"; normalize them to P1.
        if (preg_match('/^(?:P|Primary\\s*)([1-8])$/i', trim($class['name']), $match)) {
            $grade = (int)$match[1];
            // Prefer a canonical P1 record when both naming styles exist.
            if (!isset($classIds[$grade]) || strcasecmp($class['name'], 'P' . $grade) === 0) {
                $classIds[$grade] = (int)$class['id'];
                $classNames[$grade] = trim($class['name']);
            }
        }
    }

    // Make all eight choices available on a fresh install too. These class rows
    // use real IDs, as required by students.class_id's foreign key.
    for ($grade = 1; $grade <= 8; $grade++) {
        if (!isset($classIds[$grade])) {
            q('INSERT INTO classes (name, academic_year) VALUES (?, ?)', ['P' . $grade, current_year()]);
            $classIds[$grade] = (int)db()->lastInsertId();
        } elseif (strcasecmp($classNames[$grade], 'P' . $grade) !== 0) {
            // Renaming a class preserves its ID and all linked student/attendance records.
            q('UPDATE classes SET name = ? WHERE id = ?', ['P' . $grade, $classIds[$grade]]);
        }
    }

    $options = [];
    for ($grade = 1; $grade <= 8; $grade++) {
        $options[(string)$classIds[$grade]] = 'P' . $grade;
    }
    return $options;
}
function module(string $key): array
{
    $fn = 'module_' . $key;
    return $fn();
}

function module_students(): array
{
    return [
        'key' => 'students', 'title' => 'Students', 'singular' => 'Student', 'table' => 'students',
        'sql' => "SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.class_id, s.parent_id, s.date_of_birth, s.enrollment_date, s.address, c.name AS class_name,
         p.full_name AS guardian, s.status
                  FROM students s JOIN classes c ON c.id = s.class_id LEFT JOIN parents p ON p.id = s.parent_id",
        'search' => ['admission_no', 'first_name', 'last_name', 'guardian'],
        'filters' => [['name' => 'class_id', 'label' => 'Class', 'options' => fn() => opt_classes()], ['name' => 'status', 'label' => 'Status', 'options' => status_opts(['active', 'transferred', 'graduated', 'withdrawn'])]],
        'order' => 'last_name, first_name',
        'computed' => ['full_name' => fn($r) => full_name($r)],
        'columns' => ['admission_no' => 'Adm. no.', 'full_name' => 'Name', 'gender' => 'Gender', 'class_name' => 
        'Class', 'guardian' => 'Parent / guardian', 'status' => 'Status'],
        'format' => ['status' => fn($v) => status_badge($v), 'guardian' => fn($v) => $v ? e($v) : '<span class="muted">—</span>'],
        'fields' => [
            ['name' => 'admission_no', 'label' => 'Admission number', 'required' => true, 'unique' => true, 'max_length' => 30],
            ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'max_length' => 60],
            ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'max_length' => 60],
            ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'options' => gender_opts(), 'required' => true],
            ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date'],
            ['name' => 'class_id', 'label' => 'Class', 'type' => 'select', 'options' => fn() => registration_class_opts(), 'required' => true],
        
            ['name' => 'parent_name', 'label' => ' parent / guardian name', 'max_length' => 120,
             'hint' => 'Enter a name to add and link to parent.'],
            ['name' => 'enrollment_date', 'label' => 'Enrollment date', 'type' => 'date'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => status_opts(['active', 'transferred', 'graduated', 'withdrawn']), 'required' => true],
            ['name' => 'address', 'label' => 'Home address', 'wide' => true],
        ],
        'defaults' => ['status' => 'active', 'enrollment_date' => date('Y-m-d')],
        'before_save' => function (array &$d, ?int $id) {
            $parentName = trim((string)($d['parent_name'] ?? ''));
            unset($d['parent_name']);
            if ($parentName !== '') {
                q('INSERT INTO parents (full_name, relationship) VALUES (?, ?)', [$parentName, 'Guardian']);
                $d['parent_id'] = (int)db()->lastInsertId();
            }
        },
        'delete_blocked' => 'This student has fee payments on record and cannot be deleted. Set the status to "withdrawn" or "transferred" instead.',
        'view' => 'student_profile',
    ];
}

function student_profile(array $s): void
{
    $class  = row('SELECT name FROM classes WHERE id = ?', [$s['class_id']]);
    $parent = $s['parent_id'] ? row('SELECT * FROM parents WHERE id = ?', [$s['parent_id']]) : null;
    echo '<div class="grid two"><div class="card"><h2>' . e(full_name($s)) . ' ' . status_badge($s['status']) . '</h2><dl class="kv">';
    foreach ([['Admission no.', $s['admission_no']], ['Class', $class['name'] ?? '—'], ['Gender', $s['gender']], ['Date of birth', fmt_date($s['date_of_birth'])], ['Enrolled', fmt_date($s['enrollment_date'])], ['Address', $s['address'] ?: '—']] as [$k, $v]) echo '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    echo '</dl></div><div class="card"><h2>Parent / guardian</h2>';
    if ($parent) { echo '<dl class="kv">'; foreach ([['Name', $parent['full_name']], ['Relationship', $parent['relationship']], ['Phone', $parent['phone'] ?: '—'], ['Email', $parent['email'] ?: '—']] as [$k, $v]) echo '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>'; echo '</dl>'; }
    else echo '<p class="muted">No parent or guardian linked.</p>';
    echo '</div></div>';

    $att = rows('SELECT status, COUNT(*) n FROM attendance WHERE student_id = ? GROUP BY status', [$s['id']]);
    $cnt = array_column($att, 'n', 'status'); $tot = array_sum($cnt);
    echo '<div class="card"><h2>Attendance</h2>';
    if ($tot) {
        $attended = ($cnt['present'] ?? 0) + ($cnt['late'] ?? 0);
        echo '<p>' . round($attended / $tot * 100) . '% attended across ' . $tot . ' recorded days — ';
        foreach (['present', 'late', 'excused', 'absent'] as $k) echo e(ucfirst($k)) . ': <strong>' . (int)($cnt[$k] ?? 0) . '</strong> &nbsp;';
        echo '</p>';
    } else echo '<p class="muted">No attendance recorded yet.</p>';
    echo '</div>';

    $ex = rows('SELECT e.id, e.name, e.term, e.academic_year, COUNT(m.id) n, SUM(m.score) total FROM marks m JOIN exams e ON e.id = m.exam_id WHERE m.student_id = ? GROUP BY e.id, e.name, e.term, e.academic_year ORDER BY e.academic_year DESC, e.term DESC', [$s['id']]);
    echo '<div class="card"><h2>Results</h2>';
    if ($ex) {
        echo '<div class="table-wrap"><table><thead><tr><th>Exam</th><th>Term</th><th>Subjects</th><th>Average</th><th>Grade</th><th></th></tr></thead><tbody>';
        foreach ($ex as $x) { $avg = $x['total'] / $x['n']; echo '<tr><td>' . e($x['name']) . '</td><td>T' . (int)$x['term'] . ' ' . e($x['academic_year']) . '</td><td>' . (int)$x['n'] . '</td><td>' . number_format($avg, 1) . '</td><td>' . e(grade_for($avg)[0]) . '</td><td class="right"><a class="link" href="' . e(url('report_card.php?exam_id=' . $x['id'] . '&student_id=' . $s['id'])) . '">Report card</a></td></tr>'; }
        echo '</tbody></table></div>';
    } else echo '<p class="muted">No marks recorded yet.</p>';
    echo '</div>';

    if (is_admin()) {
        $paid = val('SELECT SUM(amount) FROM fee_payments WHERE student_id = ?', [$s['id']]);
        echo '<div class="card"><h2>Fees</h2><p>Total paid to date: <strong>' . e(money($paid ?? 0)) . '</strong> · <a class="link" href="' . e(url('fees.php?student_id=' . $s['id'])) . '">View payments</a></p></div>';
    }
}

function module_teachers(): array
{
    return [
        'key' => 'teachers', 'title' => 'Teachers', 'singular' => 'Teacher', 'table' => 'teachers',
        'sql' => "SELECT id, staff_no, first_name, last_name, gender, phone, email, qualification, status FROM teachers",
        'search' => ['staff_no', 'first_name', 'last_name', 'email', 'phone'],
        'filters' => [['name' => 'status', 'label' => 'Status', 'options' => status_opts(['active', 'inactive'])]],
        'order' => 'last_name, first_name',
        'computed' => ['full_name' => fn($r) => full_name($r)],
        'columns' => ['staff_no' => 'Staff no.', 'full_name' => 'Name', 'gender' => 'Gender', 'phone' => 'Phone', 'qualification' => 'Qualification', 'status' => 'Status'],
        'format' => ['status' => fn($v) => status_badge($v)],
        'fields' => [
            ['name' => 'staff_no', 'label' => 'Staff number', 'required' => true, 'unique' => true, 'max_length' => 30],
            ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'max_length' => 60],
            ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'max_length' => 60],
            ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'options' => gender_opts(), 'required' => true],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'max_length' => 30],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'max_length' => 120],
            ['name' => 'qualification', 'label' => 'Qualification', 'max_length' => 120],
            ['name' => 'hire_date', 'label' => 'Date hired', 'type' => 'date'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => status_opts(['active', 'inactive']), 'required' => true],
        ],
        'defaults' => ['status' => 'active'],
        'delete_blocked' => 'This teacher cannot be deleted right now. Set the status to "inactive" instead.',
    ];
}

function module_classes(): array
{
    return [
        'key' => 'classes', 'title' => 'Classes', 'singular' => 'Class', 'table' => 'classes',
        'sql' => "SELECT c.id, c.name, c.academic_year, t.first_name AS tf, t.last_name AS tl, (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') AS pupils FROM classes c LEFT JOIN teachers t ON t.id = c.class_teacher_id",
        'search' => ['name', 'tf', 'tl'],
        'order' => 'name',
        'computed' => ['class_teacher' => fn($r) => trim(($r['tf'] ?? '') . ' ' . ($r['tl'] ?? ''))],
        'columns' => ['name' => 'Class', 'academic_year' => 'Academic year', 'class_teacher' => 'Class teacher', 'pupils' => 'Active pupils'],
        'format' => ['class_teacher' => fn($v) => $v !== '' ? e($v) : '<span class="muted">—</span>'],
        'fields' => [
            ['name' => 'name', 'label' => 'Class name', 'type' => 'select', 'options' => class_opts(), 'required' => true, 'unique' => true],
            ['name' => 'academic_year', 'label' => 'Academic year', 'type' => 'select', 'options' => 'year_opts', 'required' => true],
            ['name' => 'class_teacher_id', 'label' => 'Class teacher', 'type' => 'select', 'options' => fn() => opt_teachers()],
        ],
        'defaults' => ['academic_year' => date('Y')],
        'delete_blocked' => 'This class still has students or attendance records. Move the students to another class first.',
    ];
}

function module_subjects(): array
{
    return [
        'key' => 'subjects', 'title' => 'Subjects', 'singular' => 'Subject', 'table' => 'subjects',
        'sql' => 'SELECT id, code, name, description FROM subjects',
        'search' => ['code', 'name'],
        'order' => 'name',
        'columns' => ['code' => 'Code', 'name' => 'Subject', 'description' => 'Description'],
        'fields' => [
            ['name' => 'code', 'label' => 'Subject code', 'required' => true, 'unique' => true, 'max_length' => 10],
            ['name' => 'name', 'label' => 'Subject name', 'required' => true, 'unique' => true, 'max_length' => 80],
            ['name' => 'description', 'label' => 'Description', 'wide' => true],
        ],
        'delete_blocked' => 'Marks have been recorded for this subject, so it cannot be deleted.',
    ];
}

function module_parents(): array
{
    return [
        'key' => 'parents', 'title' => 'Parents / guardians', 'singular' => 'Parent / guardian', 'table' => 'parents',
        'sql' => 'SELECT p.id, p.full_name, p.relationship, p.phone, p.email, (SELECT COUNT(*) FROM students s WHERE s.parent_id = p.id) AS children FROM parents p',
        'search' => ['full_name', 'phone', 'email'],
        'order' => 'full_name',
        'columns' => ['full_name' => 'Name', 'relationship' => 'Relationship', 'phone' => 'Phone', 'email' => 'Email', 'children' => 'Children enrolled'],
        'fields' => [
            ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'max_length' => 120],
            ['name' => 'relationship', 'label' => 'Relationship', 'type' => 'select', 'options' => ['Father' => 'Father', 'Mother' => 'Mother', 'Guardian' => 'Guardian', 'Other' => 'Other'], 'required' => true],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'max_length' => 30],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'max_length' => 120],
            ['name' => 'occupation', 'label' => 'Occupation', 'max_length' => 100],
            ['name' => 'address', 'label' => 'Address', 'wide' => true],
        ],
        'defaults' => ['relationship' => 'Guardian'],
    ];
}

function module_assignments(): array
{
    return [
        'key' => 'assignments', 'title' => 'Teacher–subject assignment', 'singular' => 'Assignment', 'table' => 'teacher_subjects',
        'sql' => "SELECT ts.id, ts.teacher_id, ts.subject_id, ts.class_id, t.first_name AS tf, t.last_name AS tl, s.name AS subject, c.name AS class_name
                  FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id JOIN subjects s ON s.id = ts.subject_id JOIN classes c ON c.id = ts.class_id",
        'search' => ['tf', 'tl', 'subject', 'class_name'],
        'computed' => ['teacher' => fn($r) => trim($r['tf'] . ' ' . $r['tl'])],
        'filters' => [['name' => 'teacher_id', 'label' => 'Teacher', 'options' => fn() => opt_teachers()], ['name' => 'class_id', 'label' => 'Class', 'options' => fn() => opt_classes()]],
        'order' => 'tl, tf, class_name, subject',
        'columns' => ['teacher' => 'Teacher', 'subject' => 'Subject', 'class_name' => 'Class'],
        'fields' => [
            ['name' => 'teacher_id', 'label' => 'Teacher', 'type' => 'select', 'options' => fn() => opt_teachers(), 'required' => true],
            ['name' => 'teacher_id', 'label' => 'Teacher', 'type' => 'select', 'options' => fn()
             => opt_teachers(), 'required' => true],
            ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'select', 'options' => fn() => opt_subjects(), 'required' => true],
            ['name' => 'class_id', 'label' => 'Class', 'type' => 'select', 'options' => fn() => opt_classes(), 'required' => true],
        ],
        'unique_together' => ['teacher_id', 'subject_id', 'class_id'],
    ];
}

function module_exams(): array
{
    return [
        'key' => 'exams', 'title' => 'Examinations', 'singular' => 'Examination', 'table' => 'exams',
        'sql' => 'SELECT id, name, term, academic_year, start_date, end_date, description FROM exams',
        'search' => ['name', 'academic_year'],
        'filters' => [['name' => 'term', 'label' => 'Term', 'options' => term_opts()], ['name' => 'academic_year', 'label' => 'Year', 'options' => 'year_opts']],
        'order' => 'academic_year DESC, term DESC, start_date DESC',
        'columns' => ['name' => 'Examination', 'term' => 'Term', 'academic_year' => 'Year', 'start_date' => 'Starts', 'end_date' => 'Ends'],
        'format' => ['term' => fn($v) => 'Term ' . (int)$v, 'start_date' => fn($v) => e(fmt_date($v)), 'end_date' => fn($v) => e(fmt_date($v))],
        'fields' => [
            ['name' => 'name', 'label' => 'Examination name', 'required' => true, 'max_length' => 120, 'wide' => true, 'hint' => 'For example: End of Term 1 Examination'],
            ['name' => 'term', 'label' => 'Term', 'type' => 'select', 'options' => term_opts(), 'required' => true],
            ['name' => 'academic_year', 'label' => 'Academic year', 'type' => 'select', 'options' => 'year_opts', 'required' => true],
            ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date'],
            ['name' => 'end_date', 'label' => 'End date', 'type' => 'date'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'wide' => true],
        ],
        'defaults' => ['term' => (string)current_term(), 'academic_year' => current_year()],
        'validate' => fn($d) => ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) ? ['end_date' => 'End date cannot be before the start date.'] : [],
        'delete_blocked' => 'This examination cannot be deleted.',
    ];
}

function module_fees(): array
{
    return [
        'key' => 'fees', 'title' => 'Fee payments', 'singular' => 'Payment', 'table' => 'fee_payments',
        'read' => ['admin'], 'write' => ['admin'],
        'sql' => "SELECT f.id, f.student_id, f.receipt_no, f.paid_on, f.amount, f.term, f.academic_year, f.method, f.remarks, s.first_name AS sf, s.last_name AS sl, s.admission_no
                  FROM fee_payments f JOIN students s ON s.id = f.student_id",
        'search' => ['receipt_no', 'sf', 'sl', 'admission_no'],
        'computed' => ['student' => fn($r) => trim($r['sf'] . ' ' . $r['sl'])],
        'filters' => [['name' => 'student_id', 'label' => 'Student', 'options' => fn() => opt_students()], ['name' => 'term', 'label' => 'Term', 'options' => term_opts()], ['name' => 'academic_year', 'label' => 'Year', 'options' => 'year_opts'], ['name' => 'method', 'label' => 'Method', 'options' => ['Cash' => 'Cash', 'Mobile money' => 'Mobile money', 'Bank transfer' => 'Bank transfer', 'Other' => 'Other']]],
        'order' => 'paid_on DESC, id DESC',
        'columns' => ['receipt_no' => 'Receipt', 'paid_on' => 'Date', 'student' => 'Student', 'amount' => 'Amount', 'term' => 'Term', 'academic_year' => 'Year', 'method' => 'Method'],
        'format' => ['paid_on' => fn($v) => e(fmt_date($v)), 'amount' => fn($v) => e(money($v)), 'term' => fn($v) => 'T' . (int)$v, 'student' => fn($v, $r) => e($v) . ' <span class="muted">' . e($r['admission_no']) . '</span>'],
        'fields' => [
            ['name' => 'student_id', 'label' => 'Student', 'type' => 'select', 'options' => fn() => opt_students(), 'required' => true, 'wide' => true],
            ['name' => 'amount', 'label' => 'Amount (' . currency() . ')', 'type' => 'number', 'step' => '0.01', 'min' => '0.01', 'max' => '99999999', 'required' => true],
            ['name' => 'paid_on', 'label' => 'Payment date', 'type' => 'date', 'required' => true],
            ['name' => 'term', 'label' => 'Term', 'type' => 'select', 'options' => term_opts(), 'required' => true],
            ['name' => 'academic_year', 'label' => 'Academic year', 'type' => 'select', 'options' => 'year_opts', 'required' => true],
            ['name' => 'method', 'label' => 'Payment method', 'type' => 'select', 'options' => ['Cash' => 'Cash', 'Mobile money' => 'Mobile money', 'Bank transfer' => 'Bank transfer', 'Other' => 'Other'], 'required' => true],
            ['name' => 'receipt_no', 'label' => 'Receipt number', 'unique' => true, 'max_length' => 30, 'hint' => 'Leave blank to generate one automatically.'],
            ['name' => 'remarks', 'label' => 'Remarks', 'wide' => true],
        ],
        'defaults' => ['paid_on' => date('Y-m-d'), 'term' => (string)current_term(), 'academic_year' => current_year(), 'method' => 'Cash'],
        'validate' => fn($d) => $d['paid_on'] > date('Y-m-d') ? ['paid_on' => 'Payment date cannot be in the future.'] : [],
        'before_save' => function (array &$d, ?int $id) {
            $d['recorded_by'] = current_user()['id'];
            if (empty($d['receipt_no'])) {
                $n = (int)val('SELECT COALESCE(MAX(id), 0) FROM fee_payments') + 1;
                do { $r = 'RCT-' . $d['academic_year'] . '-' . str_pad((string)$n++, 5, '0', STR_PAD_LEFT); } while (row('SELECT id FROM fee_payments WHERE receipt_no = ?', [$r]));
                $d['receipt_no'] = $r;
            }
        },
    ];
}

function module_users(): array
{
    return [
        'key' => 'users', 'title' => 'User accounts', 'singular' => 'User', 'table' => 'users',
        'read' => ['admin'], 'write' => ['admin'],
        'sql' => "SELECT u.id, u.username, u.full_name, u.role, u.active, t.first_name AS tf, t.last_name AS tl FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id",
        'search' => ['username', 'full_name'],
        'order' => 'username',
        'computed' => ['teacher' => fn($r) => trim(($r['tf'] ?? '') . ' ' . ($r['tl'] ?? ''))],
        'columns' => ['username' => 'Username', 'full_name' => 'Name', 'role' => 'Role', 'teacher' => 'Linked teacher', 'active' => 'Status'],
        'format' => ['active' => fn($v) => $v ? badge('Active', 'good') : badge('Disabled', 'bad'), 'role' => fn($v) => e(ucfirst($v)), 'teacher' => fn($v) => $v !== '' ? e($v) : '<span class="muted">—</span>'],
        'fields' => [
            ['name' => 'username', 'label' => 'Username', 'required' => true, 'unique' => true, 'max_length' => 50],
            ['name' => 'full_name', 'label' => 'Full name', 'required' => true, 'max_length' => 120],
            ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => ['admin' => 'Administrator', 'teacher' => 'Teacher'], 'required' => true, 'hint' => 'Administrators manage everything. Teachers manage attendance and marks.'],
            ['name' => 'teacher_id', 'label' => 'Linked teacher record', 'type' => 'select', 'options' => fn() => opt_teachers(), 'hint' => 'Teacher accounts only see the classes and subjects they are assigned to.'],
            ['name' => 'active', 'label' => 'Account status', 'type' => 'select', 'options' => ['1' => 'Active', '0' => 'Disabled'], 'required' => true],
            ['name' => 'password', 'column' => 'password_hash', 'label' => 'Password', 'type' => 'password', 'hint' => 'At least 8 characters. When editing, leave blank to keep the current password.'],
        ],
        'defaults' => ['role' => 'teacher', 'active' => '1'],
        'validate' => function (array $d, ?int $id) {
            if ($id === (current_user()['id'] ?? 0) && ($d['active'] === '0' || $d['role'] !== 'admin')) return ['_' => ['You cannot disable or demote your own account.']];
            return [];
        },
        'before_delete' => fn(int $id) => $id === (current_user()['id'] ?? 0) ? 'You cannot delete your own account.' : null,
        'delete_blocked' => 'This account cannot be deleted. Disable it instead.',
    ];
}
