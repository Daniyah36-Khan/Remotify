-- ==========================================================
-- SLOTSMART — Complete Database Schema
-- Import this file fresh in phpMyAdmin to set up the database.
-- ==========================================================

DROP DATABASE IF EXISTS slotsmart_db;
CREATE DATABASE slotsmart_db;
USE slotsmart_db;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:00";

-- ──────────────────────────────────────────────────────────
-- 1. DEPARTMENTS
-- ──────────────────────────────────────────────────────────
CREATE TABLE `departments` (
  `department_id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `department_name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 2. PROGRAMS
-- ──────────────────────────────────────────────────────────
CREATE TABLE `programs` (
  `program_id`    INT(11)      NOT NULL AUTO_INCREMENT,
  `program_name`  VARCHAR(100) NOT NULL,
  `department_id` INT(11)      NOT NULL,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`program_id`),
  KEY `fk_prog_dept` (`department_id`),
  CONSTRAINT `fk_prog_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 3. USERS
-- ──────────────────────────────────────────────────────────
CREATE TABLE `users` (
  `user_id`       INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `role`          ENUM('admin','user') NOT NULL DEFAULT 'user',
  `email`         VARCHAR(100) NOT NULL UNIQUE,
  `password`      VARCHAR(255) NOT NULL,
  `is_demo`       TINYINT(1)   NOT NULL DEFAULT 1,
  `department_id` INT(11)      DEFAULT NULL,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 4. FACULTY
-- ──────────────────────────────────────────────────────────
CREATE TABLE `faculty` (
  `faculty_id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `faculty_name`       VARCHAR(100) NOT NULL,
  `email`              VARCHAR(100) DEFAULT NULL UNIQUE,
  `phone_number`       VARCHAR(20)  DEFAULT NULL,
  `department_id`      INT(11)      NOT NULL,
  `max_hours_per_week` INT(11)      NOT NULL DEFAULT 20,
  `api_token`             VARCHAR(64)  DEFAULT NULL,
  `created_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`faculty_id`),
  KEY `fk_fac_dept` (`department_id`),
  CONSTRAINT `fk_fac_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 5. COURSES
-- credit_hours format: 'T-P-C' e.g. '3-1-3' (Theory-Practical-Credit)
-- requires_equipment: JSON list of names e.g. ["PC","AC","Light"]
-- ──────────────────────────────────────────────────────────
CREATE TABLE `courses` (
  `course_id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `course_name`        VARCHAR(100) NOT NULL,
  `course_code`        VARCHAR(20)  NOT NULL UNIQUE,
  `credit_hours`       VARCHAR(10)  NOT NULL DEFAULT '0-0-0',
  `requires_equipment` TEXT         DEFAULT NULL COMMENT 'JSON list of equipment names, e.g. [\"PC\",\"AC\"]',
  `department_id`      INT(11)      NOT NULL,
  `program_id`         INT(11)      DEFAULT NULL,
  `created_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`course_id`),
  KEY `fk_course_dept` (`department_id`),
  CONSTRAINT `fk_course_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 6. ROOMS
-- ──────────────────────────────────────────────────────────
CREATE TABLE `rooms` (
  `room_id`     INT(11)     NOT NULL AUTO_INCREMENT,
  `room_number` VARCHAR(50) NOT NULL UNIQUE,
  `floor_number` INT(11)    DEFAULT 1,
  `capacity`    INT(11)     NOT NULL,
  `room_type`   ENUM('Lecture Hall','Lab') DEFAULT 'Lecture Hall',
  `equipment`   TEXT         DEFAULT NULL COMMENT 'JSON per item: {\"AC 1\":1,\"AC 2\":0}; 1=working 0=faulty',
  `created_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 7. TIME SLOTS
-- ──────────────────────────────────────────────────────────
CREATE TABLE `time_slots` (
  `slot_id`    INT(11) NOT NULL AUTO_INCREMENT,
  `day`        ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `start_time` TIME    NOT NULL,
  `end_time`   TIME    NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`slot_id`),
  UNIQUE KEY `unique_slot` (`day`, `start_time`, `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 8. ALLOCATIONS (faculty assigned to course per session)
-- unique_alloc prevents duplicate: same course in same session
-- ──────────────────────────────────────────────────────────
CREATE TABLE `allocations` (
  `allocation_id` INT(11)     NOT NULL AUTO_INCREMENT,
  `faculty_id`    INT(11)     NOT NULL,
  `course_id`     INT(11)     NOT NULL,
  `session_name`  VARCHAR(50) DEFAULT 'Fall 2025',
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allocation_id`),
  UNIQUE KEY `unique_alloc` (`course_id`, `session_name`),
  KEY `fk_alloc_fac`    (`faculty_id`),
  KEY `fk_alloc_course` (`course_id`),
  CONSTRAINT `fk_alloc_course` FOREIGN KEY (`course_id`)  REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alloc_fac`    FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`faculty_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 9. STUDENTS
-- api_token: issued by api.php login (students/faculty log in with email or registration number, no password)
-- ──────────────────────────────────────────────────────────
CREATE TABLE `students` (
  `student_id`          INT(11)     NOT NULL AUTO_INCREMENT,
  `student_name`        VARCHAR(100) NOT NULL,
  `email`               VARCHAR(100) DEFAULT NULL UNIQUE,
  `registration_number` VARCHAR(50)  DEFAULT NULL UNIQUE,
  `phone_number`        VARCHAR(20)  DEFAULT NULL,
  `dob`                 DATE         DEFAULT NULL,
  `gender`              ENUM('Male','Female','Other') DEFAULT 'Male',
  `student_type`        ENUM('regular','transfer')   NOT NULL DEFAULT 'regular',
  `api_token`             VARCHAR(64)  DEFAULT NULL,
  `department_id`         INT(11)      DEFAULT NULL,
  `program_id`            INT(11)      DEFAULT NULL,
  `created_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`),
  KEY `fk_std_dept` (`department_id`),
  KEY `fk_std_prog` (`program_id`),
  CONSTRAINT `fk_std_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_std_prog` FOREIGN KEY (`program_id`)    REFERENCES `programs`    (`program_id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 10. ENROLLMENTS
-- batch_year + semester define the scheduling group (e.g. batch 2023, Semester 3).
-- The GA uses (batch_year, semester) pairs to ensure no two courses in the
-- same group share a time slot, independently of individual student records.
-- ──────────────────────────────────────────────────────────
CREATE TABLE `enrollments` (
  `enrollment_id` INT(11)     NOT NULL AUTO_INCREMENT,
  `student_id`    INT(11)     NOT NULL,
  `course_id`     INT(11)     NOT NULL,
  `batch_year`    VARCHAR(10) DEFAULT NULL,
  `semester`      VARCHAR(20) DEFAULT NULL,
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `unique_enrollment` (`student_id`, `course_id`),
  KEY `idx_enroll_course_student` (`course_id`, `student_id`),
  KEY `fk_enroll_course`   (`course_id`),
  KEY `fk_enroll_student`  (`student_id`),
  CONSTRAINT `fk_enroll_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses`  (`course_id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 11. TIMETABLE RUNS (one row per GA generation event)
-- Allows saving multiple runs per session; admin picks which to keep.
-- ──────────────────────────────────────────────────────────
CREATE TABLE `timetable_runs` (
  `run_id`        INT          NOT NULL AUTO_INCREMENT,
  `base_session`  VARCHAR(50)  NOT NULL,
  `run_label`     VARCHAR(120) NOT NULL UNIQUE,
  `fitness_score` INT          DEFAULT 0,
  `classes_count` INT          DEFAULT 0,
  `generated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `unplaced_json` TEXT         DEFAULT NULL COMMENT 'class sessions the generator could not place (blocks publishing)',
  `published_at`  DATETIME     DEFAULT NULL COMMENT 'set when the admin publishes this version; only one per session',
  PRIMARY KEY (`run_id`),
  INDEX `idx_base_session` (`base_session`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 11b. GA JOBS (background timetable generation queue; also auto-created by ga_handler.php)
-- ──────────────────────────────────────────────────────────
CREATE TABLE `ga_jobs` (
  `job_id`        INT          NOT NULL AUTO_INCREMENT,
  `session_name`  VARCHAR(50)  NOT NULL,
  `status`        ENUM('queued','running','complete','failed') DEFAULT 'queued',
  `progress`      VARCHAR(255) DEFAULT 'Queued...',
  `dept_done`     INT          DEFAULT 0,
  `dept_total`    INT          DEFAULT 0,
  `fitness_score` INT          DEFAULT 0,
  `classes_count` INT          DEFAULT 0,
  `run_id`        INT          DEFAULT NULL,
  `run_label`     VARCHAR(120) DEFAULT NULL,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────
-- 12. GENERATED TIMETABLE
-- run_id links to timetable_runs; NULL means a legacy row with no run record.
-- ──────────────────────────────────────────────────────────
CREATE TABLE `generated_timetable` (
  `timetable_id`   INT(11)     NOT NULL AUTO_INCREMENT,
  `run_id`         INT         DEFAULT NULL,
  `session_name`   VARCHAR(50) NOT NULL,
  `course_id`      INT(11)     NOT NULL,
  `faculty_id`     INT(11)     NOT NULL,
  `room_id`        INT(11)     NOT NULL,
  `slot_id`        INT(11)     NOT NULL,
  `component_type` ENUM('Theory','Lab') DEFAULT 'Theory',
  `display_label`  VARCHAR(100) DEFAULT NULL,
  `change_log`     TEXT        DEFAULT NULL COMMENT 'JSON history of room/time changes (required after publishing)',
  `version`        INT         NOT NULL DEFAULT 0 COMMENT 'optimistic lock: +1 on every edit',
  `created_at`     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`timetable_id`),
  UNIQUE KEY `uq_run_slot_room`    (`run_id`, `slot_id`, `room_id`),
  UNIQUE KEY `uq_run_slot_faculty` (`run_id`, `slot_id`, `faculty_id`),
  KEY `idx_run_id`    (`run_id`),
  KEY `fk_tt_course`  (`course_id`),
  KEY `fk_tt_faculty` (`faculty_id`),
  KEY `fk_tt_room`    (`room_id`),
  KEY `fk_tt_slot`    (`slot_id`),
  CONSTRAINT `fk_tt_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses`     (`course_id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_tt_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty`     (`faculty_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_room`    FOREIGN KEY (`room_id`)    REFERENCES `rooms`       (`room_id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_tt_slot`    FOREIGN KEY (`slot_id`)    REFERENCES `time_slots`  (`slot_id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



SET FOREIGN_KEY_CHECKS = 1;

-- ──────────────────────────────────────────────────────────
-- FIXED ADMIN ACCOUNT
-- email: admin@slotsmart.com  |  password: admin123
-- ──────────────────────────────────────────────────────────
INSERT INTO `users` (`name`, `role`, `email`, `password`, `is_demo`) VALUES
('SlotSmart Admin', 'admin', 'admin@slotsmart.com', '$2y$10$D359RdPJRlrxzdIxbmFTd.3F9GRJz1oeNPhwSod9nHVPILe/droUC', 0);


