-- =============================================================================
--  Secure Student Information & Access Management System (SIMS)
--  Schema + seed data for XAMPP / phpMyAdmin.
--
--  HOW TO IMPORT:
--    1. Start XAMPP (Apache + MySQL).
--    2. Open http://localhost/phpmyadmin  ->  Import  ->  choose this file  ->  Go.
--    3. Creates the `secure_sims` database with all tables + sample accounts.
--
--  SECURITY NOTE:
--    Passwords are the students'/teachers' school ID (e.g. 2026-00200) but are
--    stored ONLY as bcrypt hashes (password_hash). The plain school ID is never
--    saved, so the credential cannot be recovered from the database.
--
--  SEED LOGINS (username / password = school ID unless noted):
--    admin             : admin          / admin2026
--    teachers          : B_Delossantos  / 2026-00200  (BSIT-Block1)
--                        C_Reyes        / 2026-00300  (BSIT-Block2)
--                        D_Garcia       / 2026-00400  (BSIT-Block3)
--                        E_Lopez        / 2026-00500  (BSIT-Block4)
--                        F_Ramos        / 2026-00600  (BSIT-Block5)
--                        G_Torres       / 2026-00700  (BSIT-Block6)
--                        H_Villanueva   / 2026-00800  (BSIT-Block7)
--    students          : 10 per block (70 total). After import, run:
--                        php scripts/seed_teachers_students.php
--                      to create/refresh all teacher + student accounts.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `secure_sims`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `secure_sims`;

DROP TABLE IF EXISTS `grade_reports`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `grades`;
DROP TABLE IF EXISTS `subject_offerings`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `grading_scale`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `blocks`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `app_settings`;

-- Users (admin / teacher / student) ------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role`          ENUM('admin','teacher','student') NOT NULL,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `school_id`     VARCHAR(20)  NULL,
  `first_name`    VARCHAR(50)  NOT NULL,
  `last_name`     VARCHAR(50)  NOT NULL,
  `middle_initial` VARCHAR(10) NULL DEFAULT '',
  `email`         VARCHAR(120) NULL,
  `phone`         VARCHAR(11)  NULL,
  `age`           INT UNSIGNED NULL,
  `address`       VARCHAR(255) NULL DEFAULT '',
  `emergency_name` VARCHAR(120) NULL DEFAULT '',
  `emergency_relation` VARCHAR(20) NULL DEFAULT '',
  `emergency_address` VARCHAR(255) NULL DEFAULT '',
  `emergency_phone` VARCHAR(11) NULL DEFAULT '',
  `block_id`      INT UNSIGNED NULL,
  `program_id`    INT UNSIGNED NULL,
  `department_id` INT UNSIGNED NULL,
  `theme`         ENUM('dark','light') NOT NULL DEFAULT 'dark',
  `avatar`        VARCHAR(120) NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_program` (`program_id`),
  KEY `idx_users_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blocks (class sections: one teacher, many students, tied to dept + course) -
CREATE TABLE `blocks` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(60) NOT NULL,
  `teacher_id`    INT UNSIGNED NULL,
  `department_id` INT UNSIGNED NULL,
  `course_id`     INT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blocks_name` (`name`),
  KEY `idx_blocks_department` (`department_id`),
  KEY `idx_blocks_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Departments & courses (academic catalog) -----------------------------------
CREATE TABLE `departments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(20)  NOT NULL,
  `name`        VARCHAR(160) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `courses` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED NOT NULL,
  `code`          VARCHAR(40)  NOT NULL,
  `name`          VARCHAR(200) NOT NULL,
  `description`   VARCHAR(255) NOT NULL DEFAULT '',
  `units`         DECIMAL(3,1) NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courses_code` (`code`),
  KEY `idx_courses_dept` (`department_id`),
  CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `subjects` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(40)  NOT NULL,
  `name`          VARCHAR(160) NOT NULL,
  `department_id` INT UNSIGNED NULL,
  `units`         DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  `description`   VARCHAR(255) NOT NULL DEFAULT '',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code` (`code`),
  KEY `idx_subjects_dept` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `subject_offerings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_id` INT UNSIGNED NOT NULL,
  `teacher_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(80)  NOT NULL DEFAULT '',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offerings_subject` (`subject_id`),
  KEY `idx_offerings_teacher` (`teacher_id`),
  CONSTRAINT `fk_offerings_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_offerings_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `enrollments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`  INT UNSIGNED NOT NULL,
  `offering_id` INT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enrollment_student_offering` (`student_id`, `offering_id`),
  KEY `idx_enroll_student` (`student_id`),
  KEY `idx_enroll_offering` (`offering_id`),
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enroll_offering` FOREIGN KEY (`offering_id`) REFERENCES `subject_offerings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `grade_reports` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id`    INT UNSIGNED NOT NULL,
  `offering_id`   INT UNSIGNED NOT NULL,
  `title`         VARCHAR(160) NOT NULL DEFAULT '',
  `status`        VARCHAR(20)  NOT NULL DEFAULT 'submitted',
  `snapshot_json` LONGTEXT     NOT NULL,
  `note`          VARCHAR(255) NOT NULL DEFAULT '',
  `submitted_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_greports_teacher` (`teacher_id`),
  KEY `idx_greports_offering` (`offering_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grades (computed by the teacher, viewed by the student) --------------------
CREATE TABLE `grades` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`     INT UNSIGNED NOT NULL,
  `teacher_id`     INT UNSIGNED NOT NULL,
  `offering_id`    INT UNSIGNED NULL,
  `subject`        VARCHAR(80)  NOT NULL,
  `quiz_scores`    LONGTEXT     NULL,   -- JSON array of percents e.g. [90,85,88]
  `activity_scores`LONGTEXT     NULL,   -- JSON array of percents e.g. [95,80]
  `midterm`        DECIMAL(7,2) NOT NULL DEFAULT 0,
  `midterm_max`    DECIMAL(7,2) NOT NULL DEFAULT 100.00,
  `final_exam`     DECIMAL(7,2) NOT NULL DEFAULT 0,
  `final_exam_max` DECIMAL(7,2) NOT NULL DEFAULT 100.00,
  `quiz_avg`       DECIMAL(5,2) NOT NULL DEFAULT 0,
  `activity_avg`   DECIMAL(5,2) NOT NULL DEFAULT 0,
  `final_grade`    DECIMAL(5,2) NOT NULL DEFAULT 0,
  `grade_point`    DECIMAL(4,2) NULL DEFAULT NULL,
  `remark`         VARCHAR(40)  NOT NULL DEFAULT 'N/A',
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_student_subject` (`student_id`, `subject`),
  KEY `idx_grades_offering` (`offering_id`),
  CONSTRAINT `fk_grade_student` FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grade_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles & permissions (admin-configurable RBAC matrix) -----------------------
CREATE TABLE `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(60)  NOT NULL,
  `code`        VARCHAR(20)  NOT NULL,
  `color`       VARCHAR(16)  NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL,
  `label`       VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `app_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grading scale (admin-configurable rating chart) ---------------------------
CREATE TABLE `grading_scale` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grade_point`  DECIMAL(4,2) NOT NULL,
  `min_percent`  DECIMAL(5,2) NOT NULL,
  `max_percent`  DECIMAL(5,2) NOT NULL,
  `description`  VARCHAR(40)  NOT NULL DEFAULT '',
  `sort_order`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log (security / activity events) -------------------------------------
CREATE TABLE `audit_log` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`       INT UNSIGNED NULL,
  `actor_role`     VARCHAR(20)  NOT NULL,
  `actor_username` VARCHAR(50)  NULL,
  `action`         VARCHAR(80)  NOT NULL,
  `target`         VARCHAR(160) NULL,
  `details`        VARCHAR(255) NULL,
  `ip_address`     VARCHAR(45)  NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed users -----------------------------------------------------------------
INSERT INTO `users` (`id`,`role`,`username`,`password_hash`,`first_name`,`last_name`,`email`,`phone`,`age`,`block_id`) VALUES
  (1,'admin','admin','$2y$10$OnJIDh0n7ZoGGOoyvrJ/AO345RCjHub8UoYjmBj8hAwqdTmjSGTUG','System','Admin','admin@gmail.com',NULL,NULL,NULL),
  (2,'teacher','B_Delossantos','$2y$10$tVdAmWD1epbWPK6R.j2FgubQ8ud7hK8w3Kgz4UjiuzVRokE.5Wm22','Bruno','Delossantos','brunodelossantos@gmail.com','09171234567',34,1),
  (3,'teacher','C_Reyes','$2y$10$vDlaNtFyO8kfK1rIm7ZzJ.90D8a1KA9eoVzFsTai.ex5/cH0jGU0G','Carla','Reyes','carlareyes@gmail.com','09181234567',29,2),
  (4,'student','A_Mendoza','$2y$10$7i1M/6bbQ.ZqODbTFCxGL.fUy4So8OkdfTjPUVwFCGwY7RuVLQ1Se','Alice','Mendoza','alicemendoza@gmail.com','09201234561',18,1),
  (5,'student','J_Santos','$2y$10$hY.m5YHniNFVyngd714uDeE36PdVfS4/f/MrPtfP7gurq025nE4Ia','John','Santos','johnsantos@gmail.com','09201234562',19,1),
  (6,'student','M_Cruz','$2y$10$EBtmEfX6uV.fVNf1x4C6iOm8UlzwdMmJa1nIL8AqlEuyte2WZW1QK','Maria','Cruz','mariacruz@gmail.com','09201234563',18,1),
  (7,'student','K_Tan','$2y$10$U9IVCeAZW6UXEPwx7eZuQu11vmmFDP4NdyU4VZ5Hqof4sy64ageLG','Kevin','Tan','kevintan@gmail.com','09201234564',20,2),
  (8,'student','L_Ong','$2y$10$80SPYlXE2qEDS04gl1Qcz.zwX0Ho2J5bxLFB8booRx57t6or2DCRG','Liza','Ong','lizaong@gmail.com','09201234565',19,2);

-- Seed blocks (teacher assignments) ------------------------------------------
INSERT INTO `blocks` (`id`,`name`,`teacher_id`,`department_id`,`course_id`) VALUES
  (1,'BSIT-Block1',2,NULL,NULL),
  (2,'BSIT-Block2',3,NULL,NULL);

-- Seed a couple of grades so the student view has data -----------------------
INSERT INTO `grades`
  (`student_id`,`teacher_id`,`subject`,`quiz_scores`,`activity_scores`,`midterm`,`midterm_max`,`final_exam`,`final_exam_max`,`quiz_avg`,`activity_avg`,`final_grade`,`grade_point`,`remark`) VALUES
  (4,2,'Programming 1','[]','[]',100.00,100.00,79.00,100.00,0.00,0.00,89.50,1.75,'Passed'),
  (4,2,'Data Structures','[]','[]',80.00,100.00,84.00,100.00,0.00,0.00,82.00,2.50,'Passed'),
  (5,2,'Programming 1','[]','[]',68.00,100.00,74.00,100.00,0.00,0.00,71.00,5.00,'Failed');

-- Seed roles / permissions / default matrix ----------------------------------
INSERT INTO `roles` (`id`,`name`,`code`,`color`,`description`) VALUES
  (1,'Administrator','admin','#ff4d5e','Full control: users, blocks, and system settings.'),
  (2,'Teacher / Staff','teacher','#4d9bff','Manages grades and reports for assigned blocks.'),
  (3,'Student','student','#43d17a','Views own profile and grades only.');

INSERT INTO `permissions` (`id`,`code`,`label`,`description`,`sort_order`) VALUES
  (1,'dashboard','Dashboard / Blocks','Admin landing: create and view class blocks.',1),
  (2,'profile','My Profile','Student profile page (own account only).',2),
  (3,'students','Students','Admin student accounts and teacher roster.',3),
  (4,'grades','Grades','Teacher grade computation and student grade view.',4),
  (5,'attendance','Attendance','Reserved module — also used by the 3D walkthrough.',5),
  (6,'reports','Reports','Teacher class reports and admin grade report inbox.',6),
  (7,'users','Teachers / Users','Admin teacher account management.',7),
  (8,'roles','Roles & Permissions','Admin Settings: manage system options and RBAC.',8),
  (9,'audit_log','Audit Log','View tamper-evident security and activity events.',9),
  (10,'academics','Departments & Courses','Manage academic departments and course catalog.',10);

INSERT INTO `role_permissions` (`role_id`,`permission_id`) VALUES
  (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),
  (2,1),(2,2),(2,3),(2,4),(2,5),(2,6),
  (3,1),(3,2),(3,4);

INSERT INTO `app_settings` (`setting_key`,`setting_value`) VALUES
  ('school_name','Secure SIMS'),
  ('school_year','2025-2026'),
  ('support_email','admin@gmail.com'),
  ('login_message','Sign in with your username and school ID.'),
  ('records_locked','0'),
  ('weight_quiz','0'),
  ('weight_activity','0'),
  ('weight_midterm','50'),
  ('weight_final','50'),
  ('passing_point','3.00');

INSERT INTO `grading_scale` (`grade_point`,`min_percent`,`max_percent`,`description`,`sort_order`) VALUES
  (1.00,98.00,100.00,'Excellent',1),
  (1.25,95.00,97.00,'Outstanding',2),
  (1.50,92.00,94.00,'Very Good',3),
  (1.75,89.00,91.00,'Above Average',4),
  (2.00,86.00,88.00,'Good',5),
  (2.25,83.00,85.00,'Fairly Good',6),
  (2.50,80.00,82.00,'Satisfactory',7),
  (2.75,76.00,79.00,'Fair',8),
  (3.00,74.50,75.99,'Passing',9),
  (5.00,0.00,74.49,'Failure',10);

INSERT INTO `departments` (`id`,`code`,`name`,`description`) VALUES
  (1,'CBGG','College of Business and Good Governance',NULL),
  (2,'CTE','College of Teacher Education',NULL),
  (3,'CICT','College of Information and Communication Technology',NULL),
  (4,'CAF','College of Agriculture and Fisheries',NULL),
  (5,'CCJE','College of Criminal Justice Education',NULL),
  (6,'DCE','Department of Civil Engineering',NULL);

INSERT INTO `courses` (`department_id`,`code`,`name`,`description`,`units`) VALUES
  (1,'BPA','Bachelor of Public Administration',NULL,0),
  (1,'BSAIS','Bachelor of Science in Accounting Information System',NULL,0),
  (1,'BSHM','Bachelor of Science in Hospitality Management',NULL,0),
  (1,'BSTM','Bachelor of Science in Tourism Management',NULL,0),
  (1,'BSBA-MM','Bachelor of Science in Business Administration major in Marketing Management',NULL,0),
  (1,'BSSW','Bachelor of Science in Social Work',NULL,0),
  (2,'BEEd','Bachelor of Elementary Education',NULL,0),
  (2,'BECEd','Bachelor of Early Childhood Education',NULL,0),
  (2,'BSEd-English','Bachelor of Secondary Education major in English',NULL,0),
  (2,'BSEd-Filipino','Bachelor of Secondary Education major in Filipino',NULL,0),
  (2,'BSEd-Math','Bachelor of Secondary Education major in Mathematics',NULL,0),
  (2,'BSEd-Science','Bachelor of Secondary Education major in Science',NULL,0),
  (2,'BSEd-SocialStudies','Bachelor of Secondary Education major in Social Studies',NULL,0),
  (2,'BTLEd-ICT','Bachelor of Technology and Livelihood Education major in Information and Communication Technology',NULL,0),
  (3,'BSIT','Bachelor of Science in Information Technology',NULL,0),
  (3,'BSIT-BA','Bachelor of Science in Information Technology major in Business Analytics',NULL,0),
  (3,'ACT','Associate in Computer Technology',NULL,0),
  (4,'BSA-AnimalScience','Bachelor of Science in Agriculture major in Animal Science',NULL,0),
  (4,'BSA-CropScience','Bachelor of Science in Agriculture major in Crop Science',NULL,0),
  (4,'BSA-Horticulture','Bachelor of Science in Agriculture major in Horticulture',NULL,0),
  (4,'BSA-PlantBreeding','Bachelor of Science in Agriculture major in Plant Breeding',NULL,0),
  (4,'BSFisheries','Bachelor of Science in Fisheries',NULL,0),
  (5,'BSCrim','Bachelor of Science in Criminology',NULL,0),
  (6,'BSCE-Structural','Bachelor of Science in Civil Engineering major in Structural Engineering',NULL,0);

INSERT INTO `subjects` (`code`,`name`,`units`,`department_id`) VALUES
  ('IT101','Programming 1',3.0,3),
  ('IT102','Programming 2',3.0,3),
  ('IT201','Data Structures',3.0,3),
  ('IT202','Database Systems',3.0,3),
  ('IT301','Web Development',3.0,3);

-- Attach seeded blocks to CICT / BSIT -----------------------------------------
UPDATE `blocks` SET `department_id` = 3, `course_id` = (SELECT `id` FROM `courses` WHERE `code` = 'BSIT' LIMIT 1) WHERE `id` IN (1,2);

INSERT INTO `audit_log` (`actor_id`,`actor_role`,`actor_username`,`action`,`target`,`details`,`ip_address`,`created_at`) VALUES
  (1,'admin','admin','LOGIN_SUCCESS','session','Initial seed event','127.0.0.1','2026-06-01 08:02:11'),
  (2,'teacher','B_Delossantos','GRADE_UPDATED','student#4','Programming 1','127.0.0.1','2026-06-01 09:14:37'),
  (4,'student','A_Mendoza','ACCESS_DENIED','module:users','Student blocked from admin module','127.0.0.1','2026-06-01 09:20:05'),
  (1,'admin','admin','ROLE_MATRIX_UPDATED','roles','Default RBAC matrix active','127.0.0.1','2026-06-01 10:01:52'),
  (1,'admin','admin','SETTINGS_UPDATED','app_settings','System settings initialized','127.0.0.1','2026-06-01 10:45:19'),
  (1,'admin','admin','INTEGRITY_CHECK_OK','audit_log','Seed integrity check','127.0.0.1','2026-06-01 11:30:00');
