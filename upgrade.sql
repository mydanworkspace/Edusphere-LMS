-- ============================================================
-- EduSphere upgrade: run this ONLY if you already imported the first database.sql
-- (it keeps all your data). phpMyAdmin: select the "edusphere" database -> Import -> this file.
-- New installs do not need it: the new database.sql already includes these changes.
-- ============================================================
USE edusphere;

-- Lets an admin end a student's old logins when resetting a password or username
ALTER TABLE students ADD COLUMN IF NOT EXISTS pw_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

-- Study marks that an admin removed (the student cannot tick them again until allowed)
CREATE TABLE IF NOT EXISTS progress_revoked (
  student_id INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  reason VARCHAR(200) NOT NULL DEFAULT '',
  revoked_by VARCHAR(30) NOT NULL DEFAULT '',
  revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, resource_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Editable class times for the timetable
INSERT IGNORE INTO settings (k, v) VALUES ('slot_1', '08:00-10:00'), ('slot_2', '11:00-13:00'), ('slot_3', '14:00-16:00');
