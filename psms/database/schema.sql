-- PSMS relational schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- Compatibility version without CHECK clauses for editors or servers that do not recognize them.
-- Create an empty database first (the web installer does this for you), then import this file.

CREATE TABLE settings (
  setting_key   VARCHAR(50)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parents (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  full_name    VARCHAR(120) NOT NULL,
  relationship ENUM('Father','Mother','Guardian','Other') NOT NULL DEFAULT 'Guardian',
  phone        VARCHAR(30)  NULL,
  email        VARCHAR(120) NULL,
  occupation   VARCHAR(100) NULL,
  address      VARCHAR(255) NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE teachers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  staff_no      VARCHAR(30)  NOT NULL UNIQUE,
  first_name    VARCHAR(60)  NOT NULL,
  last_name     VARCHAR(60)  NOT NULL,
  gender        ENUM('Male','Female') NOT NULL,
  phone         VARCHAR(30)  NULL,
  email         VARCHAR(120) NULL,
  qualification VARCHAR(120) NULL,
  hire_date     DATE NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE classes (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(50) NOT NULL UNIQUE,
  academic_year    VARCHAR(9)  NOT NULL,
  class_teacher_id INT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (class_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(10)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE students (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  admission_no    VARCHAR(30) NOT NULL UNIQUE,
  first_name      VARCHAR(60) NOT NULL,
  last_name       VARCHAR(60) NOT NULL,
  gender          ENUM('Male','Female') NOT NULL,
  date_of_birth   DATE NULL,
  class_id        INT NOT NULL,
  parent_id       INT NULL,
  address         VARCHAR(255) NULL,
  enrollment_date DATE NULL,
  status          ENUM('active','transferred','graduated','withdrawn') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_class  FOREIGN KEY (class_id)  REFERENCES classes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_students_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  teacher_id    INT NULL,
  active        TINYINT NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE teacher_subjects (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  class_id   INT NOT NULL,
  UNIQUE (teacher_id, subject_id, class_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_class   FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  class_id    INT NOT NULL,
  att_date    DATE NOT NULL,
  status      ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  remarks     VARCHAR(255) NULL,
  recorded_by INT NULL,
  UNIQUE (student_id, att_date),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_class   FOREIGN KEY (class_id)    REFERENCES classes(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_att_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exams (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  term          TINYINT NOT NULL,
  academic_year VARCHAR(9)   NOT NULL,
  start_date    DATE NULL,
  end_date      DATE NULL,
  description   VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE marks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  exam_id     INT NOT NULL,
  student_id  INT NOT NULL,
  subject_id  INT NOT NULL,
  score       DECIMAL(5,2) NOT NULL,
  recorded_by INT NULL,
  UNIQUE (exam_id, student_id, subject_id),
  CONSTRAINT fk_marks_exam    FOREIGN KEY (exam_id)     REFERENCES exams(id)    ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id)  REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_marks_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fee_payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    INT NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  paid_on       DATE NOT NULL,
  term          TINYINT NOT NULL,
  academic_year VARCHAR(9) NOT NULL,
  method        ENUM('Cash','Mobile money','Bank transfer','Other') NOT NULL DEFAULT 'Cash',
  receipt_no    VARCHAR(30) NOT NULL UNIQUE,
  remarks       VARCHAR(255) NULL,
  recorded_by   INT NULL,
  CONSTRAINT fk_fee_student FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_user    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_students_class ON students(class_id);
CREATE INDEX idx_students_parent ON students(parent_id);
CREATE INDEX idx_att_class_date ON attendance(class_id, att_date);
CREATE INDEX idx_marks_exam_student ON marks(exam_id, student_id);
CREATE INDEX idx_fee_student ON fee_payments(student_id);
CREATE INDEX idx_fee_period ON fee_payments(academic_year, term);





