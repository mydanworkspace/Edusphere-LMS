-- ============================================================
-- EduSphere Portal database (MySQL / MariaDB, XAMPP)
-- Import in phpMyAdmin: Import tab -> choose this file -> Go.
-- No default admin password is stored: open admin.php once to create the first admin.
-- ============================================================
CREATE DATABASE IF NOT EXISTS edusphere CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edusphere;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS activity_log, settings, messages, applications, fees, assessments, timetable, news, progress_revoked, progress, resources, units, courses, students, admins;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(30) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,            -- password_hash() output, never the password
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(30) NOT NULL UNIQUE,           -- case-insensitive (utf8mb4_unicode_ci)
  display VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  pw_version INT UNSIGNED NOT NULL DEFAULT 1,      -- raised when an admin resets the password or username: old logins stop working
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  last_active_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  title VARCHAR(120) NOT NULL,
  instructor VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL,
  phone VARCHAR(25) NOT NULL,
  credits TINYINT UNSIGNED NOT NULL DEFAULT 3,
  keywords VARCHAR(200) NOT NULL DEFAULT '',
  why TEXT NOT NULL,
  gain TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE resources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  url VARCHAR(500) NOT NULL,
  kind ENUM('Video','Notes') NOT NULL DEFAULT 'Notes',
  minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE progress (                             -- which resources each student has studied
  student_id INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  studied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, resource_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE progress_revoked (                     -- study marks an admin removed: the student cannot tick them again until an admin allows it
  student_id INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  reason VARCHAR(200) NOT NULL DEFAULT '',
  revoked_by VARCHAR(30) NOT NULL DEFAULT '',
  revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, resource_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE news (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  posted_on DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE timetable (                            -- 3 slots a day, Monday (1) to Friday (5)
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day TINYINT UNSIGNED NOT NULL,
  slot TINYINT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NULL,
  UNIQUE KEY uq_day_slot (day, slot),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE assessments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  week TINYINT UNSIGNED NOT NULL,
  weight TINYINT UNSIGNED NOT NULL,
  details VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE fees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL,
  module VARCHAR(120) NOT NULL,
  credits TINYINT UNSIGNED NOT NULL,
  fee INT UNSIGNED NOT NULL,
  status ENUM('Available','Full') NOT NULL DEFAULT 'Available',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL,
  phone VARCHAR(25) NOT NULL,
  programme VARCHAR(60) NOT NULL,
  statement TEXT NULL,
  status ENUM('new','reviewing','accepted','rejected') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip VARCHAR(45) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE messages (                             -- support / feedback form
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL,
  category VARCHAR(40) NOT NULL,
  comment TEXT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_log (                         -- every important action on the site
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type ENUM('student','admin','visitor') NOT NULL,
  actor_name VARCHAR(40) NOT NULL DEFAULT '',
  event VARCHAR(40) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  agent VARCHAR(200) NOT NULL DEFAULT '',
  KEY idx_created (created_at), KEY idx_event (event, ip, created_at), KEY idx_actor (actor_name)
) ENGINE=InnoDB;

CREATE TABLE settings (
  k VARCHAR(40) PRIMARY KEY,
  v VARCHAR(500) NOT NULL
) ENGINE=InnoDB;

-- ---------------- Sample data (taken from the portal's built-in content) ----------------
INSERT INTO settings (k, v) VALUES
 ('semester_start', '2026-10-19'),
 ('meet_url', 'https://meet.google.com/'),
 ('whatsapp', '254700000000'),
 ('slot_1', '08:00-10:00'),
 ('slot_2', '11:00-13:00'),
 ('slot_3', '14:00-16:00');

INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('CS101','Introduction to Programming','Dr. A. Mwangi','a.mwangi@edusphere.ac.ke','+254 700 000 101',3,'coding code python software algorithms computer','Programming is the base of every software, data and technology career. This course teaches you to think logically and turn ideas into working code.','writing and debugging simple programs, using variables, loops and functions, and breaking problems into steps.',1);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS101'),'Unit 1: Variables and data types',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=1),'Programming basics for beginners','https://www.youtube.com/results?search_query=programming%20basics%20for%20beginners%20full%20course','Video',90,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=1),'Variable (computer science)','https://en.wikipedia.org/w/index.php?search=variable%20computer%20science','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS101'),'Unit 2: Control flow and loops',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=2),'If statements and loops explained','https://www.youtube.com/results?search_query=loops%20and%20conditionals%20programming%20tutorial','Video',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=2),'Control flow','https://en.wikipedia.org/w/index.php?search=control%20flow%20programming','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS101'),'Unit 3: Functions',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=3),'Functions for beginners','https://www.youtube.com/results?search_query=functions%20programming%20beginners%20tutorial','Video',45,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=3),'Subroutine','https://en.wikipedia.org/w/index.php?search=subroutine%20function%20programming','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS101'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=4),'Python tutorial (W3Schools)','https://www.w3schools.com/python/','Notes',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=4),'The Python Tutorial (official docs)','https://docs.python.org/3/tutorial/','Notes',45,2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS101') AND sort_order=4),'freeCodeCamp videos on YouTube','https://www.youtube.com/@freecodecamp','Video',120,3);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('CS201','Data Structures','Prof. J. Otieno','j.otieno@edusphere.ac.ke','+254 700 000 102',3,'arrays lists trees graphs stacks queues algorithms','Choosing the right data structure decides whether software is fast or slow. This course gives you the toolkit used in every technical interview and large system.','storing and organising data efficiently, comparing algorithms, and implementing lists, stacks, queues and trees.',2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS201'),'Unit 1: Arrays and linked lists',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=1),'Data structures full course','https://www.youtube.com/results?search_query=data%20structures%20arrays%20linked%20lists%20course','Video',120,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=1),'Linked list','https://en.wikipedia.org/w/index.php?search=linked%20list','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS201'),'Unit 2: Stacks and queues',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=2),'Stacks and queues explained','https://www.youtube.com/results?search_query=stacks%20and%20queues%20data%20structures','Video',50,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=2),'Stack (abstract data type)','https://en.wikipedia.org/w/index.php?search=stack%20abstract%20data%20type','Notes',15,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS201'),'Unit 3: Trees and graphs',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=3),'Binary trees and graphs','https://www.youtube.com/results?search_query=binary%20trees%20graphs%20data%20structures','Video',90,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=3),'Tree (data structure)','https://en.wikipedia.org/w/index.php?search=tree%20data%20structure','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS201'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=4),'Visualise algorithms (VisuAlgo)','https://visualgo.net/en','Notes',40,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS201') AND sort_order=4),'Data structures notes (GeeksforGeeks)','https://www.geeksforgeeks.org/data-structures/','Notes',60,2);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('CS301','Databases','Dr. F. Abdi','f.abdi@edusphere.ac.ke','+254 700 000 107',4,'sql data tables queries normalisation transactions','Almost every app stores data. This course shows how to design reliable databases and ask them questions with SQL.','writing SQL queries, designing tables with keys and relationships, and understanding transactions and indexes.',3);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS301'),'Unit 1: SQL basics',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=1),'SQL tutorial for beginners','https://www.youtube.com/results?search_query=sql%20tutorial%20for%20beginners%20full%20course','Video',100,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=1),'SQL','https://en.wikipedia.org/w/index.php?search=SQL%20query%20language','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS301'),'Unit 2: Database design',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=2),'Database design and normalisation','https://www.youtube.com/results?search_query=database%20design%20normalization%20explained','Video',70,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=2),'Database normalization','https://en.wikipedia.org/w/index.php?search=database%20normalization','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS301'),'Unit 3: Transactions and indexes',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=3),'Transactions and indexes','https://www.youtube.com/results?search_query=database%20transactions%20indexes%20explained','Video',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=3),'ACID','https://en.wikipedia.org/w/index.php?search=ACID%20database%20transactions','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='CS301'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=4),'SQL tutorial (W3Schools)','https://www.w3schools.com/sql/','Notes',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='CS301') AND sort_order=4),'PostgreSQL tutorial (official docs)','https://www.postgresql.org/docs/current/tutorial.html','Notes',60,2);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('MA101','Calculus I','Dr. L. Wanjiru','l.wanjiru@edusphere.ac.ke','+254 700 000 103',3,'maths limits derivatives integrals differentiation','Calculus describes change and is the language of engineering, economics and science.','finding limits, differentiating and integrating functions, and applying them to rates and areas.',4);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA101'),'Unit 1: Limits',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=1),'Limits for beginners','https://www.youtube.com/results?search_query=calculus%20limits%20explained','Video',75,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=1),'Limit of a function','https://en.wikipedia.org/w/index.php?search=limit%20of%20a%20function','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA101'),'Unit 2: Derivatives',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=2),'Derivatives full lesson','https://www.youtube.com/results?search_query=calculus%20derivatives%20full%20lesson','Video',100,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=2),'Derivative','https://en.wikipedia.org/w/index.php?search=derivative%20mathematics','Notes',30,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA101'),'Unit 3: Integrals',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=3),'Introduction to integrals','https://www.youtube.com/results?search_query=calculus%20integrals%20introduction','Video',80,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=3),'Integral','https://en.wikipedia.org/w/index.php?search=integral%20calculus','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA101'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=4),'Calculus 1 (Khan Academy)','https://www.khanacademy.org/math/calculus-1','Notes',120,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA101') AND sort_order=4),'Calculus I notes (Paul''s Online Notes)','https://tutorial.math.lamar.edu/Classes/CalcI/CalcI.aspx','Notes',90,2);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('MA102','Linear Algebra','Dr. N. Chebet','n.chebet@edusphere.ac.ke','+254 700 000 108',3,'maths vectors matrices equations eigenvalues','Linear algebra powers graphics, machine learning and engineering models.','working with vectors and matrices, solving systems of equations, and finding eigenvalues.',5);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA102'),'Unit 1: Vectors and matrices',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=1),'Vectors and matrices intro','https://www.youtube.com/results?search_query=linear%20algebra%20vectors%20matrices%20introduction','Video',80,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=1),'Matrix (mathematics)','https://en.wikipedia.org/w/index.php?search=matrix%20mathematics','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA102'),'Unit 2: Systems of equations',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=2),'Gaussian elimination','https://www.youtube.com/results?search_query=gaussian%20elimination%20linear%20algebra','Video',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=2),'Gaussian elimination','https://en.wikipedia.org/w/index.php?search=gaussian%20elimination','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA102'),'Unit 3: Eigenvalues',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=3),'Eigenvalues and eigenvectors','https://www.youtube.com/results?search_query=eigenvalues%20eigenvectors%20explained','Video',70,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=3),'Eigenvalues and eigenvectors','https://en.wikipedia.org/w/index.php?search=eigenvalues%20and%20eigenvectors','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA102'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=4),'Linear algebra (Khan Academy)','https://www.khanacademy.org/math/linear-algebra','Notes',120,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA102') AND sort_order=4),'3Blue1Brown videos on YouTube','https://www.youtube.com/@3blue1brown','Video',90,2);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('MA201','Probability & Statistics','Dr. P. Kamau','p.kamau@edusphere.ac.ke','+254 700 000 104',3,'maths data mean variance distribution hypothesis','Statistics turns raw data into decisions in health, business and research.','summarising data, calculating probabilities, and reasoning with common distributions.',6);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA201'),'Unit 1: Descriptive statistics',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=1),'Statistics basics','https://www.youtube.com/results?search_query=statistics%20mean%20median%20mode%20variance','Video',70,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=1),'Descriptive statistics','https://en.wikipedia.org/w/index.php?search=descriptive%20statistics','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA201'),'Unit 2: Probability rules',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=2),'Probability explained','https://www.youtube.com/results?search_query=probability%20rules%20explained','Video',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=2),'Probability','https://en.wikipedia.org/w/index.php?search=probability%20theory','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA201'),'Unit 3: Distributions',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=3),'Normal distribution','https://www.youtube.com/results?search_query=normal%20distribution%20statistics%20lesson','Video',55,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=3),'Normal distribution','https://en.wikipedia.org/w/index.php?search=normal%20distribution','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='MA201'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=4),'Statistics and probability (Khan Academy)','https://www.khanacademy.org/math/statistics-probability','Notes',120,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='MA201') AND sort_order=4),'Seeing Theory (interactive)','https://seeing-theory.brown.edu/','Notes',40,2);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('BU101','Principles of Management','Ms. R. Achieng','r.achieng@edusphere.ac.ke','+254 700 000 105',3,'business leadership planning organisation teams','Every organisation needs people who can plan, organise and lead. This course introduces how managers make that work.','planning and setting goals, organising resources, motivating teams and making decisions.',7);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU101'),'Unit 1: What managers do',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=1),'Introduction to management','https://www.youtube.com/results?search_query=principles%20of%20management%20introduction','Video',60,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=1),'Management','https://en.wikipedia.org/w/index.php?search=management%20business','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU101'),'Unit 2: Planning and strategy',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=2),'Strategic planning basics','https://www.youtube.com/results?search_query=strategic%20planning%20for%20business%20basics','Video',50,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=2),'Strategic management','https://en.wikipedia.org/w/index.php?search=strategic%20management','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU101'),'Unit 3: Leading teams',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=3),'Leadership styles','https://www.youtube.com/results?search_query=leadership%20styles%20explained','Video',45,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=3),'Leadership','https://en.wikipedia.org/w/index.php?search=leadership','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU101'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU101') AND sort_order=4),'Principles of Management (OpenStax textbook)','https://openstax.org/details/books/principles-management','Notes',90,1);
INSERT INTO courses (code,title,instructor,email,phone,credits,keywords,why,gain,sort_order) VALUES ('BU205','Financial Accounting','Mr. D. Njoroge','d.njoroge@edusphere.ac.ke','+254 700 000 106',4,'business money accounts balance sheet ledger bookkeeping','Accounting is the language of business. You learn to record transactions and read the financial statements that investors and managers rely on.','recording transactions, preparing a balance sheet and income statement, and reading financial results.',8);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU205'),'Unit 1: The accounting equation',1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=1),'Accounting basics','https://www.youtube.com/results?search_query=accounting%20basics%20accounting%20equation','Video',80,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=1),'Accounting equation','https://en.wikipedia.org/w/index.php?search=accounting%20equation','Notes',15,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU205'),'Unit 2: Journals and ledgers',2);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=2),'Journal entries explained','https://www.youtube.com/results?search_query=journal%20entries%20and%20ledgers%20tutorial','Video',70,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=2),'General ledger','https://en.wikipedia.org/w/index.php?search=general%20ledger','Notes',20,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU205'),'Unit 3: Financial statements',3);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=3),'Balance sheet and income statement','https://www.youtube.com/results?search_query=balance%20sheet%20income%20statement%20explained','Video',90,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=3),'Financial statement','https://en.wikipedia.org/w/index.php?search=financial%20statement','Notes',25,2);
INSERT INTO units (course_id,title,sort_order) VALUES ((SELECT id FROM courses WHERE code='BU205'),'Extra: more notes and videos',4);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=4),'Principles of Financial Accounting (OpenStax textbook)','https://openstax.org/details/books/principles-financial-accounting','Notes',90,1);
INSERT INTO resources (unit_id,title,url,kind,minutes,sort_order) VALUES ((SELECT id FROM units WHERE course_id=(SELECT id FROM courses WHERE code='BU205') AND sort_order=4),'Core finance (Khan Academy)','https://www.khanacademy.org/economics-finance-domain/core-finance','Notes',90,2);
INSERT INTO timetable (day,slot,course_id) VALUES (1,1,(SELECT id FROM courses WHERE code='CS101'));
INSERT INTO timetable (day,slot,course_id) VALUES (1,2,(SELECT id FROM courses WHERE code='MA101'));
INSERT INTO timetable (day,slot,course_id) VALUES (1,3,(SELECT id FROM courses WHERE code='BU101'));
INSERT INTO timetable (day,slot,course_id) VALUES (2,1,(SELECT id FROM courses WHERE code='CS201'));
INSERT INTO timetable (day,slot,course_id) VALUES (2,2,(SELECT id FROM courses WHERE code='MA201'));
INSERT INTO timetable (day,slot,course_id) VALUES (2,3,NULL);
INSERT INTO timetable (day,slot,course_id) VALUES (3,1,(SELECT id FROM courses WHERE code='CS101'));
INSERT INTO timetable (day,slot,course_id) VALUES (3,2,(SELECT id FROM courses WHERE code='BU205'));
INSERT INTO timetable (day,slot,course_id) VALUES (3,3,(SELECT id FROM courses WHERE code='MA101'));
INSERT INTO timetable (day,slot,course_id) VALUES (4,1,(SELECT id FROM courses WHERE code='CS201'));
INSERT INTO timetable (day,slot,course_id) VALUES (4,2,(SELECT id FROM courses WHERE code='MA201'));
INSERT INTO timetable (day,slot,course_id) VALUES (4,3,NULL);
INSERT INTO timetable (day,slot,course_id) VALUES (5,1,(SELECT id FROM courses WHERE code='BU101'));
INSERT INTO timetable (day,slot,course_id) VALUES (5,2,NULL);
INSERT INTO timetable (day,slot,course_id) VALUES (5,3,NULL);
INSERT INTO assessments (name,week,weight,details) VALUES ('Assignment 1',2,5,'Practical task on Unit 1');
INSERT INTO assessments (name,week,weight,details) VALUES ('CAT 1',3,10,'1-hour test on Unit 1');
INSERT INTO assessments (name,week,weight,details) VALUES ('Assignment 2',4,5,'Problem set on Units 1 and 2');
INSERT INTO assessments (name,week,weight,details) VALUES ('CAT 2',6,10,'1-hour test on Units 1 and 2');
INSERT INTO assessments (name,week,weight,details) VALUES ('CAT 3',9,10,'1-hour test on Units 1 to 3');
INSERT INTO assessments (name,week,weight,details) VALUES ('Final exam',13,60,'3-hour exam on all units');
INSERT INTO news (title,body,posted_on,active) VALUES ('Vice Chancellor urges students to prepare for the upcoming exams','In his weekly address he asked every student to start revising early and to use the live revision classes.','2026-11-10',1);
INSERT INTO news (title,body,posted_on,active) VALUES ('Library extends opening hours during the exam period','The library will now open from 7:00 to 22:00 on weekdays until the end of the exams.','2026-11-09',1);
INSERT INTO news (title,body,posted_on,active) VALUES ('Semester registration closes on 30 November','Check the Finances page for fees and payment guidelines before you register.','2026-11-08',1);
INSERT INTO news (title,body,posted_on,active) VALUES ('Alumni career fair announced for January','More than 20 employers will meet final-year students on campus.','2026-11-06',1);
INSERT INTO news (title,body,posted_on,active) VALUES ('New live-class timetable is now available','Open the Dashboard to see which classes are live and join with one click.','2026-11-05',1);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Computer Science Core','CS101 Introduction to Programming',3,45000,'Available',1);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Computer Science Core','CS201 Data Structures',3,48000,'Available',2);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Computer Science Core','CS301 Databases',4,60000,'Full',3);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Mathematics','MA101 Calculus I',3,36000,'Available',4);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Mathematics','MA102 Linear Algebra',3,36000,'Available',5);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Mathematics','MA201 Probability & Statistics',3,39000,'Available',6);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Business','BU101 Principles of Management',3,33000,'Available',7);
INSERT INTO fees (category,module,credits,fee,status,sort_order) VALUES ('Business','BU205 Financial Accounting',4,44000,'Full',8);
