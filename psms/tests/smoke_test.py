"""End-to-end smoke test for PSMS. Usage: python3 tests/smoke_test.py http://127.0.0.1:8099
Run against a FRESH install (the test installs the system with demo data first)."""
import re, sys
import requests

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8099'
results = []
def check(name, cond, detail=''):
    results.append((name, bool(cond)))
    print(('PASS ' if cond else 'FAIL ') + name + (f'  [{detail}]' if detail and not cond else ''))

def csrf(html):
    m = re.search(r'name="_csrf" value="([0-9a-f]+)"', html); return m.group(1) if m else ''
def new_session(): return requests.Session()

s = new_session()
PW = 'Test#Pass123'

# ---- installer ----
r = s.get(BASE + '/install.php'); check('installer page loads', r.status_code == 200 and 'Install PSMS' in r.text)
r = s.post(BASE + '/install.php', data={'_csrf': csrf(r.text), 'full_name': 'Head Admin', 'username': 'admin', 'password': 'short', 'password2': 'short'})
check('installer rejects weak password', 'at least 8 characters' in r.text)
r = s.get(BASE + '/install.php')
r = s.post(BASE + '/install.php', data={'_csrf': csrf(r.text), 'full_name': 'Head Admin', 'username': 'admin', 'password': PW, 'password2': PW, 'demo': '1'})
check('installer completes with demo data', 'Installation complete' in r.text, re.sub(r'<[^>]+>', ' ', r.text)[:300])
r = s.get(BASE + '/install.php'); check('installer locks after install', 'already installed' in r.text)

# ---- authentication ----
a = new_session()
for p in ['dashboard.php', 'students.php', 'marks.php', 'fees.php', 'users.php', 'report_card.php?exam_id=1&class_id=1']:
    r = a.get(BASE + '/' + p, allow_redirects=False)
    check(f'anonymous blocked: {p}', r.status_code == 302 and 'login.php' in r.headers.get('Location', ''))
r = a.get(BASE + '/login.php')
r2 = a.post(BASE + '/login.php', data={'_csrf': csrf(r.text), 'username': 'admin', 'password': 'wrong'})
check('invalid login rejected', 'Incorrect username or password' in r2.text)
r2 = a.post(BASE + '/login.php', data={'username': 'admin', 'password': PW})
check('login without CSRF token rejected', r2.status_code == 419)
r = a.get(BASE + '/login.php')
r2 = a.post(BASE + '/login.php', data={'_csrf': csrf(r.text), 'username': 'admin', 'password': PW})
check('admin login succeeds', 'Dashboard' in r2.text and 'Pupils per class' in r2.text)
dash = r2.text
check('dashboard shows 48 active students', '>48<' in dash)
check('dashboard shows fee total (admin)', 'Fees collected' in dash)

def page(path, sess=a):
    return sess.get(BASE + '/' + path)
def tok(path, sess=a): return csrf(page(path, sess).text)

# ---- every module list page renders ----
for p in ['students.php', 'parents.php', 'teachers.php', 'classes.php', 'subjects.php', 'assignments.php', 'exams.php', 'fees.php', 'users.php', 'settings.php', 'attendance.php', 'marks.php', 'reports.php']:
    r = page(p); check(f'page renders: {p}', r.status_code == 200 and 'Fatal error' not in r.text and 'Warning:' not in r.text and 'Notice:' not in r.text, r.text[:200])

# ---- students CRUD + validation ----
r = page('students.php?a=new'); t = csrf(r.text)
cls = re.search(r'<option value="(\d+)"[^>]*>Primary 1</option>', r.text).group(1)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': '', 'admission_no': '', 'first_name': '', 'last_name': 'X', 'gender': 'Male', 'class_id': cls, 'status': 'active'})
check('student: required fields validated', r.status_code == 422 and 'Admission number is required' in r.text and 'First name is required' in r.text)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': '', 'admission_no': 'AWPS-TEST-1', 'first_name': 'Test', 'last_name': 'Pupil', 'gender': 'Female', 'class_id': cls, 'status': 'active', 'date_of_birth': '2019-02-30'})
check('student: invalid date rejected', r.status_code == 422 and 'valid date' in r.text)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': '', 'admission_no': 'AWPS-TEST-1', 'first_name': 'Test', 'last_name': "O'Brien <b>", 'gender': 'Female', 'class_id': cls, 'status': 'active', 'date_of_birth': '2019-02-10'})
check('student: created', 'Student added' in r.text and 'AWPS-TEST-1' in page('students.php?q=AWPS-TEST-1').text)
check('student: output is HTML-escaped (XSS)', '&lt;b&gt;' in page('students.php?q=AWPS-TEST-1').text and '<b>' not in page('students.php?q=AWPS-TEST-1').text.split('<tbody>')[1])
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': '', 'admission_no': 'AWPS-TEST-1', 'first_name': 'Dup', 'last_name': 'Pupil', 'gender': 'Male', 'class_id': cls, 'status': 'active'})
check('student: duplicate admission no rejected', r.status_code == 422 and 'already in use' in r.text)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': '', 'admission_no': 'AWPS-TEST-2', 'first_name': 'Bad', 'last_name': 'Class', 'gender': 'Male', 'class_id': '9999', 'status': 'active'})
check('student: invalid class rejected', r.status_code == 422)
sid = re.search(r'students\.php\?a=edit&amp;id=(\d+)', page('students.php?q=AWPS-TEST-1').text).group(1)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'save', 'id': sid, 'admission_no': 'AWPS-TEST-1', 'first_name': 'Edited', 'last_name': 'Pupil', 'gender': 'Female', 'class_id': cls, 'status': 'active'})
check('student: edited', 'Student updated' in r.text and 'Edited' in page('students.php?q=AWPS-TEST-1').text)
check('student: profile view', 'Attendance' in page(f'students.php?a=view&id={sid}').text)
check('student: search + class filter', 'AWPS-2027' in page(f'students.php?class_id={cls}').text or 'AWPS-' in page(f'students.php?class_id={cls}').text)
r = a.post(BASE + '/students.php', data={'a': 'delete', 'id': sid}); check('student: delete without CSRF blocked', r.status_code == 419)
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'delete', 'id': sid}); check('student: deleted', 'Student deleted' in r.text and 'AWPS-TEST-1' not in page('students.php?q=AWPS-TEST-1').text.split('<tbody>')[1])

# ---- referential integrity ----
st1 = re.search(r'students\.php\?a=edit&amp;id=(\d+)', page('students.php?q=AWPS-').text).group(1)
# find a student that has fee payments
fees_html = page('fees.php').text
fee_student = re.search(r'name="id" value="(\d+)"', fees_html)
paid_student = None
for m in re.finditer(r'students\.php\?a=view&amp;id=(\d+)', page('students.php').text):
    pass
import sqlite3
con = sqlite3.connect('/tmp/testpsms.sqlite')
paid_student = con.execute('select student_id from fee_payments limit 1').fetchone()[0]
r = a.post(BASE + '/students.php', data={'_csrf': t, 'a': 'delete', 'id': str(paid_student)})
check('integrity: student with payments cannot be deleted', 'cannot be deleted' in r.text and con.execute('select count(*) from students where id=?', (paid_student,)).fetchone()[0] == 1)
r = a.post(BASE + '/classes.php', data={'_csrf': t, 'a': 'delete', 'id': cls})
check('integrity: class with pupils cannot be deleted', 'still has students' in r.text and con.execute('select count(*) from classes where id=?', (cls,)).fetchone()[0] == 1)

# ---- teachers, classes, subjects, parents ----
def crud_add(mod, data, ok):
    r = page(f'{mod}.php?a=new'); tt = csrf(r.text)
    r = a.post(f'{BASE}/{mod}.php', data={'_csrf': tt, 'a': 'save', 'id': '', **data})
    return r
r = crud_add('teachers', {'staff_no': 'T900', 'first_name': 'Neo', 'last_name': 'Teacher', 'gender': 'Male', 'email': 'not-an-email', 'status': 'active'}, '')
check('teacher: invalid email rejected', r.status_code == 422 and 'valid email' in r.text)
r = crud_add('teachers', {'staff_no': 'T900', 'first_name': 'Neo', 'last_name': 'Teacher', 'gender': 'Male', 'email': 'neo@example.org', 'phone': '+211 912345678', 'status': 'active'}, '')
check('teacher: created', 'Teacher added' in r.text)
r = crud_add('subjects', {'code': 'ICT', 'name': 'ICT Basics'}, ''); check('subject: created', 'Subject added' in r.text)
r = crud_add('subjects', {'code': 'ICT', 'name': 'Other'}, ''); check('subject: duplicate code rejected', r.status_code == 422)
r = crud_add('classes', {'name': 'Primary 9', 'academic_year': '2026'}, ''); check('class: created', 'Class added' in r.text)
r = crud_add('parents', {'full_name': 'Test Parent', 'relationship': 'Mother', 'phone': 'abc'}, ''); check('parent: bad phone rejected', r.status_code == 422)
r = crud_add('parents', {'full_name': 'Test Parent', 'relationship': 'Mother', 'phone': '+211 912 345 678'}, ''); check('parent: created', 'added' in r.text)

# ---- teacher-subject assignment ----
tid = con.execute("select id from teachers where staff_no='T900'").fetchone()[0]
subj = con.execute("select id from subjects where code='ICT'").fetchone()[0]
c9 = con.execute("select id from classes where name='Primary 9'").fetchone()[0]
r = crud_add('assignments', {'teacher_id': tid, 'subject_id': subj, 'class_id': c9}, ''); check('assignment: created', 'Assignment added' in r.text)
r = crud_add('assignments', {'teacher_id': tid, 'subject_id': subj, 'class_id': c9}, ''); check('assignment: duplicate rejected', r.status_code == 422 and 'already exists' in r.text)
check('assignment: listed with names', 'Neo Teacher' in page('assignments.php?q=ICT').text and 'ICT Basics' in page('assignments.php?q=ICT').text)

# ---- exams ----
r = crud_add('exams', {'name': 'Bad Dates', 'term': '1', 'academic_year': '2026', 'start_date': '2026-05-10', 'end_date': '2026-05-01'}, ''); check('exam: end before start rejected', r.status_code == 422)
r = crud_add('exams', {'name': 'End of Term 1', 'term': '1', 'academic_year': '2026', 'start_date': '2026-05-01', 'end_date': '2026-05-10'}, ''); check('exam: created', 'added' in r.text)

# ---- attendance ----
r = page(f'attendance.php?class_id={cls}&att_date=2026-10-28'); t2 = csrf(r.text)
sids = re.findall(r'name="status\[(\d+)\]" value="present"', r.text)
check('attendance: register lists pupils', len(sids) == 6, len(sids))
data = {'_csrf': t2, 'class_id': cls, 'att_date': '2026-10-28'}
for i, x in enumerate(sids): data[f'status[{x}]'] = 'absent' if i == 0 else 'present'
r = a.post(BASE + '/attendance.php', data=data); check('attendance: saved', 'Attendance saved' in r.text)
r = page(f'attendance.php?class_id={cls}&att_date=2026-10-28')
check('attendance: reload shows saved state', re.search(r'name="status\[%s\]" value="absent" checked' % sids[0], r.text) is not None)
data[f'status[{sids[0]}]'] = 'late'; r = a.post(BASE + '/attendance.php', data=data)
n = con.execute("select count(*) from attendance where att_date='2026-10-28' and class_id=?", (cls,)).fetchone()[0]
check('attendance: re-save updates (no duplicates)', n == 6 and 'Attendance saved' in r.text, n)
data['att_date'] = '2999-01-01'; r = a.post(BASE + '/attendance.php', data=data); check('attendance: future date rejected', 'not in the future' in r.text)

# ---- marks ----
exam = con.execute("select id from exams where name='Mid-Term 1 Examination'").fetchone()[0]
subj1 = con.execute("select id from subjects where code='ENG'").fetchone()[0]
r = page(f'marks.php?exam_id={exam}&class_id={cls}&subject_id={subj1}'); t3 = csrf(r.text)
msids = re.findall(r'name="score\[(\d+)\]"', r.text)
check('marks: sheet lists pupils with existing scores', len(msids) == 6 and 'value="' in r.text)
data = {'_csrf': t3, 'exam_id': exam, 'class_id': cls, 'subject_id': subj1}
for x in msids: data[f'score[{x}]'] = '70'
data[f'score[{msids[0]}]'] = '150'
r = a.post(BASE + '/marks.php', data=data); check('marks: score > 100 rejected, nothing saved', 'Nothing was saved' in r.text and con.execute('select count(*) from marks where exam_id=? and subject_id=? and score=70', (exam, subj1)).fetchone()[0] == 0)
data[f'score[{msids[0]}]'] = 'abc'; r = a.post(BASE + '/marks.php', data=data); check('marks: non-numeric rejected', 'Nothing was saved' in r.text)
data[f'score[{msids[0]}]'] = '95.5'; data[f'score[{msids[1]}]'] = ''
r = a.post(BASE + '/marks.php', data=data); check('marks: saved', 'Marks saved' in r.text)
cnt = con.execute('select count(*) from marks where exam_id=? and subject_id=? and student_id in (%s)' % ','.join(msids), (exam, subj1)).fetchone()[0]
check('marks: blank clears, others stored', cnt == 5 and con.execute('select score from marks where exam_id=? and subject_id=? and student_id=?', (exam, subj1, msids[0])).fetchone()[0] == 95.5, cnt)

# ---- reports ----
r = page(f'reports.php?tab=performance&exam_id={exam}&class_id={cls}')
check('report: class performance renders with stats', 'Pass rate' in r.text and 'Subject average' in r.text and 'Position' not in r.text or 'Pos.' in r.text)
top = re.findall(r'<tr><td>(\d+|—)</td><td>', r.text)
check('report: positions start at 1', top and top[0] == '1', top[:3])
r = page(f'reports.php?tab=performance&export=csv&exam_id={exam}&class_id={cls}')
check('report: CSV export', r.headers.get('Content-Type', '').startswith('text/csv') and r.text.splitlines()[0].startswith('Position,"Adm. no.",Name,'), r.text[:120])
check('report: attendance summary', 'Attendance' in page(f'reports.php?tab=attendance&class_id={cls}&from=2026-10-01&to=2026-10-31').text)
check('report: fee summary (admin)', 'Total collected' in page('reports.php?tab=fees&academic_year=2026').text)
r = page(f'report_card.php?exam_id={exam}&student_id={msids[0]}')
check('report card: single pupil', 'STUDENT REPORT CARD' in r.text and '95.5' in r.text and 'Position' in r.text and 'Class avg' in r.text)
r = page(f'report_card.php?exam_id={exam}&class_id={cls}')
check('report card: whole class prints', r.text.count('STUDENT REPORT CARD') == 6, r.text.count('STUDENT REPORT CARD'))

# ---- fees ----
r = page('fees.php?a=new'); t4 = csrf(r.text)
paid_id = str(paid_student)
base = {'_csrf': t4, 'a': 'save', 'id': '', 'student_id': paid_id, 'amount': '1000', 'paid_on': '2026-10-01', 'term': '3', 'academic_year': '2026', 'method': 'Cash'}
r = a.post(BASE + '/fees.php', data={**base, 'amount': '-5'}); check('fee: negative amount rejected', r.status_code == 422)
r = a.post(BASE + '/fees.php', data={**base, 'amount': 'lots'}); check('fee: non-numeric amount rejected', r.status_code == 422)
r = a.post(BASE + '/fees.php', data={**base, 'paid_on': '2999-01-01'}); check('fee: future date rejected', r.status_code == 422)
r = a.post(BASE + '/fees.php', data=base); check('fee: recorded, receipt auto-generated', 'Payment added' in r.text and 'RCT-2026-' in r.text)
r = a.post(BASE + '/fees.php', data={**base, 'receipt_no': 'RCT-2026-00001'}); check('fee: duplicate receipt rejected', r.status_code == 422)

# ---- users, roles, teacher scope ----
r = crud_add('users', {'username': 'weak', 'full_name': 'Weak', 'role': 'teacher', 'active': '1', 'password': 'abc'}, ''); check('user: short password rejected', r.status_code == 422)
t_id = con.execute("select id from teachers where staff_no='T001'").fetchone()[0]
r = crud_add('users', {'username': 'teacher2', 'full_name': 'Second Teacher', 'role': 'teacher', 'teacher_id': t_id, 'active': '1', 'password': 'Another#Pass1'}, ''); check('user: created', 'User added' in r.text)
h = con.execute("select password_hash from users where username='teacher2'").fetchone()[0]
check('user: password stored hashed (bcrypt/argon)', h.startswith('$2y$') or h.startswith('$argon2'), h[:8])
me = con.execute("select id from users where username='admin'").fetchone()[0]
r = a.post(BASE + '/users.php', data={'_csrf': t, 'a': 'delete', 'id': me}); check('user: cannot delete own account', 'cannot delete your own' in r.text)

tch = new_session(); r = tch.get(BASE + '/login.php')
r = tch.post(BASE + '/login.php', data={'_csrf': csrf(r.text), 'username': 'teacher1', 'password': PW})
check('teacher login succeeds', 'Dashboard' in r.text and 'My teaching assignments' in r.text)
check('teacher: no fee data on dashboard', 'Fees collected' not in r.text)
for p in ['fees.php', 'users.php', 'settings.php']:
    r = tch.get(BASE + '/' + p); check(f'teacher forbidden: {p}', r.status_code == 403)
check('teacher: no fees/users in nav', 'fees.php' not in tch.get(BASE + '/students.php').text)
r = tch.get(BASE + '/students.php'); check('teacher: students read-only (no add button)', '+ Add student' not in r.text and 'Delete' not in r.text)
r = tch.post(BASE + '/students.php', data={'_csrf': csrf(r.text), 'a': 'delete', 'id': st1}); check('teacher: cannot delete via crafted POST', r.status_code == 403)
r = tch.get(BASE + '/attendance.php'); opts = re.findall(r'<option value="(\d+)"', r.text)
check('teacher: attendance limited to assigned classes', 0 < len(opts) <= 3, len(opts))
r = tch.get(BASE + '/report_card.php?exam_id=%s&class_id=%s' % (exam, con.execute("select id from classes where name='Primary 5'").fetchone()[0]))
check('teacher: report cards of unassigned class forbidden', r.status_code == 403)

# ---- settings + logout + session ----
r = page('settings.php'); t5 = csrf(r.text)
r = a.post(BASE + '/settings.php', data={'_csrf': t5, 'school_name': 'X', 'academic_year': 'abcd', 'current_term': '2', 'currency': 'SSP', 'pass_mark': '50'}); check('settings: invalid year rejected', 'four-digit year' in r.text)
r = a.post(BASE + '/settings.php', data={'_csrf': t5, 'school_name': 'Atalabara West Primary School', 'academic_year': '2026', 'current_term': '2', 'currency': 'SSP', 'pass_mark': '40'}); check('settings: saved', 'Settings saved' in r.text)
r = page('logout.php', a); 
r = a.post(BASE + '/logout.php', data={'_csrf': tok('dashboard.php')}); check('logout works', 'signed out' in r.text)
check('after logout dashboard blocked', a.get(BASE + '/dashboard.php', allow_redirects=False).status_code == 302)
b = requests.get(BASE + '/includes/bootstrap.php'); check('includes/ not publicly readable (php server: executes harmlessly)', 'DB_PASS' not in b.text and 'password' not in b.text.lower()[:50])

fails = [n for n, ok in results if not ok]
print(f'\n{len(results) - len(fails)}/{len(results)} checks passed')
sys.exit(1 if fails else 0)
