-- Persistent academic batches, student assignments and weekly routine slots.
CREATE TABLE IF NOT EXISTS batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  instructor_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  batch_code VARCHAR(40) NOT NULL,
  schedule VARCHAR(255) NOT NULL DEFAULT '',
  room_number VARCHAR(80) NOT NULL DEFAULT '',
  max_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('upcoming','ongoing','completed') NOT NULL DEFAULT 'upcoming',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_batches_branch_code (branch_id, batch_code),
  INDEX idx_batches_branch_status (branch_id, is_active, status, start_date),
  INDEX idx_batches_course (course_id, is_active),
  INDEX idx_batches_instructor (instructor_id, is_active),
  CONSTRAINT fk_batches_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_batches_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_batches_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_batches_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_batches_capacity CHECK (max_capacity > 0),
  CONSTRAINT chk_batches_date_range CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  student_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','completed','withdrawn') NOT NULL DEFAULT 'active',
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_batch_student (batch_id, student_user_id),
  INDEX idx_batch_students_status (batch_id, status),
  INDEX idx_batch_student_user (student_user_id, status),
  CONSTRAINT fk_batch_student_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_batch_student_user FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_routine_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  instructor_id BIGINT UNSIGNED NOT NULL,
  day_of_week ENUM('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  subject VARCHAR(180) NOT NULL,
  room_number VARCHAR(80) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_routine_batch_day (batch_id, day_of_week, start_time),
  INDEX idx_routine_instructor_day (instructor_id, day_of_week, start_time, end_time),
  INDEX idx_routine_branch_day (branch_id, day_of_week, start_time, end_time),
  CONSTRAINT fk_routine_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_routine_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_routine_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_routine_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_routine_time_range CHECK (end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES
('admin', 'batches.manage', 1), ('super_admin', 'batches.manage', 1),
('branch_manager', 'batches.manage', 1), ('teacher', 'batches.manage', 0),
('assistant_teacher', 'batches.manage', 0), ('accountant', 'batches.manage', 0),
('student', 'batches.manage', 0), ('guardian', 'batches.manage', 0);
