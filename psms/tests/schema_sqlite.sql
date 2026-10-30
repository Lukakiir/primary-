-- PSMS relational schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- Create an empty database first (the web installer does this for you), then import this file.

CREATE TABLE settings (
  setting_key   VARCHAR(50)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
);

CREATE TABLE parents (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name    VARCHAR(120) NOT NULL,
  relationship TEXT NOT NULL DEFAULT 'Guardian',
  phone        VARCHAR(30)  NULL,
  email        VARCHAR(120) NULL,
  occupation   VARCHAR(100) NULL,
  address      VARCHAR(255) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE teachers (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  staff_no      VARCHAR(30)  NOT NULL UNIQUE,
  first_name    VARCHAR(60)  NOT NULL,
  last_name     VARCHAR(60)  NOT NULL,
  gender        TEXT NOT NULL,
  phone         VARCHAR(30)  NULL,
  email         VARCHAR(120) NULL,
  qualification VARCHAR(120) NULL,
  hire_date     DATE NULL,
  status        TEXT NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE classes (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  name             VARCHAR(50) NOT NULL UNIQUE,
  academic_year    VARCHAR(9)  NOT NULL,
  class_teacher_id INT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (class_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

CREATE TABLE subjects (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  code        VARCHAR(10)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL
);

CREATE TABLE students (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  admission_no    VARCHAR(30) NOT NULL UNIQUE,
  first_name      VARCHAR(60) NOT NULL,
  last_name       VARCHAR(60) NOT NULL,
  gender          TEXT NOT NULL,
  date_of_birth   DATE NULL,
  class_id        INT NOT NULL,
  parent_id       INT NULL,
  address         VARCHAR(255) NULL,
  enrollment_date DATE NULL,
  status          TEXT NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_class  FOREIGN KEY (class_id)  REFERENCES classes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_students_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE SET NULL
);

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          TEXT NOT NULL DEFAULT 'teacher',
  teacher_id    INT NULL,
  active        TINYINT NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

CREATE TABLE teacher_subjects (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  class_id   INT NOT NULL,
  UNIQUE (teacher_id, subject_id, class_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_class   FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE
);

CREATE TABLE attendance (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id  INT NOT NULL,
  class_id    INT NOT NULL,
  att_date    DATE NOT NULL,
  status      TEXT NOT NULL DEFAULT 'present',
  remarks     VARCHAR(255) NULL,
  recorded_by INT NULL,
  UNIQUE (student_id, att_date),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_class   FOREIGN KEY (class_id)    REFERENCES classes(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_att_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
);

CREATE TABLE exams (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          VARCHAR(120) NOT NULL,
  term          TINYINT NOT NULL,
  academic_year VARCHAR(9)   NOT NULL,
  start_date    DATE NULL,
  end_date      DATE NULL,
  description   VARCHAR(255) NULL,
  CHECK (term BETWEEN 1 AND 3)
);

CREATE TABLE marks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  exam_id     INT NOT NULL,
  student_id  INT NOT NULL,
  subject_id  INT NOT NULL,
  score       DECIMAL(5,2) NOT NULL,
  recorded_by INT NULL,
  UNIQUE (exam_id, student_id, subject_id),
  CHECK (score >= 0 AND score <= 100),
  CONSTRAINT fk_marks_exam    FOREIGN KEY (exam_id)     REFERENCES exams(id)    ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id)  REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
);

CREATE TABLE fee_payments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id    INT NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  paid_on       DATE NOT NULL,
  term          TINYINT NOT NULL,
  academic_year VARCHAR(9) NOT NULL,
  method        TEXT NOT NULL DEFAULT 'Cash',
  receipt_no    VARCHAR(30) NOT NULL UNIQUE,
  remarks       VARCHAR(255) NULL,
  recorded_by   INT NULL,
  CHECK (amount > 0),
  CHECK (term BETWEEN 1 AND 3),
  CONSTRAINT fk_fee_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
);

CREATE INDEX idx_students_class ON students(class_id);
CREATE INDEX idx_students_parent ON students(parent_id);
CREATE INDEX idx_att_class_date ON attendance(class_id, att_date);
CREATE INDEX idx_marks_exam_student ON marks(exam_id, student_id);
CREATE INDEX idx_fee_student ON fee_payments(student_id);
CREATE INDEX idx_fee_period ON fee_payments(academic_year, term);
