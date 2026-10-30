# Primary School Management System (PSMS)
Atalabara West Primary School · PHP + MySQL/MariaDB + HTML/CSS/JavaScript, run locally with XAMPP.

Built from the project proposal (scope and modules), Chapter 2 (core design principles: simple, user‑centred, reliable data) and Chapter 3 (PHP/MySQL/XAMPP stack, requirements, evaluation plan).

## Install on XAMPP (about 5 minutes)
1. Install XAMPP (PHP 8.1 or newer). Start **Apache** and **MySQL** in the XAMPP control panel.
2. Copy this whole `psms` folder into `C:\xampp\htdocs\` (so you have `C:\xampp\htdocs\psms\index.php`).
3. Open **http://localhost/psms/install.php**.
4. Choose your administrator name, username and password. Tick *demo data* if you want a ready-made fictional school to explore (recommended for the demonstration), then click **Install PSMS**.
5. Sign in at **http://localhost/psms/**. Afterwards delete `install.php` (it locks itself, but deleting it is safer).

Default database settings are in `config.php` (user `root`, empty password, database `psms_db`). Change them if your MySQL is set up differently. Prefer phpMyAdmin? Create an empty database `psms_db`, import `database/schema.sql`, then add an administrator row yourself, or just use the installer.

Demo data creates the account **teacher1** (same password as the admin you chose) so you can see the teacher role.

## What the system does
| Module (page) | What you can do | Source |
|---|---|---|
| Login / logout (`login.php`) | Password sign‑in, sessions, auto sign‑out after 30 min idle, lock‑out after 5 failed tries | Ch2 2.5.1, Ch3 3.3.3 |
| Dashboard | Counts, pupils per class, today's attendance, latest exams, fees (admin) | Ch2 2.5.2 |
| Students | Add / view / edit / delete, search, filter by class or status, profile page (attendance, results, fees) | Proposal 1.4.2, Ch2 2.5.3 |
| Parents / guardians | Manage guardians and link them to students | Proposal 1.4.2 |
| Teachers | Add / view / edit / delete teacher records | Ch2 2.5.4 |
| Classes | Class records, class teacher, pupil counts | Ch2 2.5.5 |
| Subjects | Subject records | Ch2 2.5.6 |
| Teacher–subject assignment | Which teacher teaches which subject in which class | Ch2 2.5.7 |
| Attendance | Daily register per class (present / late / excused / absent), editable | Proposal 1.4.2 |
| Examinations | Name, term, academic year, start/end dates, description | Proposal 1.4.2 |
| Marks entry | Score sheet per exam, class and subject; validated 0–100 | Proposal 1.4.2 |
| Reports & report cards | Class performance (totals, averages, grades, positions, pass rate), CSV export, attendance summary, fee summary, **printable report cards** (single pupil or whole class) | Proposal 1.4.2 |
| Fee payments | Amount, date, term, year, method, auto receipt numbers, remarks (admin only) | Proposal 1.4.2 |
| User accounts, School settings | Create admin/teacher logins; set school name, year, term, currency, pass mark | Ch3 3.3.3 |

**Scope note.** Chapters 2 and 3 describe attendance, examinations, marks, fees, parents and advanced reports as *later extensions* of a core system. The proposal lists them as in scope. Because you asked to combine all three, this build includes **both** the core (authentication, dashboard, students, teachers, classes, subjects, teacher–subject assignment, relational database) **and** the extended modules, with the core first in the menu and code. If your examiners want only the core, hide the extra pages by removing their entries from `nav_items()` in `includes/bootstrap.php`.

## Roles
* **Administrator** – everything.
* **Teacher** – read‑only access to records, plus attendance, marks and reports. A teacher account linked to a teacher record only sees the classes (and, for marks, the subjects) they are assigned to. No access to fees, users or settings.

## Database (11 tables, InnoDB, utf8mb4)
`users`, `teachers`, `classes`, `students`, `parents`, `subjects`, `teacher_subjects`, `attendance`, `exams`, `marks`, `fee_payments`, `settings`.
Primary and foreign keys throughout; unique constraints (admission no., staff no., subject code, one attendance row per pupil per day, one mark per pupil/exam/subject, receipt numbers); `CHECK` constraints on scores, amounts and terms; deleting a record that other records depend on is refused with a plain message (e.g. a student with fee payments).
Entity relationships: class 1‑N students · parent 1‑N students · teacher N‑M subject N‑M class via `teacher_subjects` · exam + student + subject → one mark · student 1‑N attendance / fee payments.

## Quality measures (mapped to Chapter 3 criteria / ISO 25010)
* **Security** – bcrypt password hashing, session ID regenerated on login, CSRF token on every form, prepared statements everywhere (no SQL injection), all output HTML‑escaped, role checks on every page *and* every POST, `includes/` and `database/` blocked from the web by `.htaccess`.
* **Data integrity** – server‑side validation (required, formats, dates, ranges, uniqueness) plus database constraints; marks and attendance are saved in transactions.
* **Usability** – one menu grouped by school task, plain error messages next to the field, mobile‑friendly layout, keyboard accessible.
* **Maintainability** – list/add/edit/delete modules are defined by short configuration arrays in `includes/modules.php`; one shared engine in `includes/crud.php`.

## Grading
A 80–100, B 70–79, C 60–69, D 50–59, E below 50 (edit `grade_scale()` in `includes/bootstrap.php`). Average = total ÷ number of subjects examined in the class; positions use standard ranking (ties share a position).

## Testing
`tests/smoke_test.py` is an automated end‑to‑end test (94 checks: login, access control, add/view/edit/delete for each module, validation, duplicates, integrity rules, attendance, marks, reports, report cards, fees, roles, CSRF, XSS escaping). Run it against a freshly installed copy:

    python3 tests/smoke_test.py http://localhost/psms

(It expects the installer's demo data and the SQLite test database configured in the script; see the comments at the top. For your own report, use it as a model for the test‑case table in Chapter 4: each `check(...)` line is a test case with an expected result.)

**Honest limitation.** In the build environment MySQL could not be installed, so the 94 checks ran against an SQLite copy of the same schema (`tests/schema_sqlite.sql`, generated from `database/schema.sql`). The SQL in the PHP code uses only portable statements. `database/schema.sql` itself has not been executed on a real MySQL/MariaDB server, so run the installer once on XAMPP and tell me if it reports any error.

## Folder map
```
config.php            database + session settings
install.php           first‑run installer (creates DB, tables, admin, optional demo data)
login.php logout.php dashboard.php
students.php parents.php teachers.php classes.php subjects.php assignments.php
attendance.php exams.php marks.php reports.php report_card.php fees.php users.php settings.php
includes/             bootstrap (helpers, auth, layout), crud engine, module configs, results logic, demo data
database/schema.sql   MySQL/MariaDB schema
assets/               style.css, app.js (no external libraries – works offline on a school LAN)
tests/                smoke test + SQLite test schema
```
