-- ==========================================================
-- SLOTSMART — Demo Database Schema Template
-- Auto-executed when a new demo user registers.
-- Contains all tables EXCEPT `users` (auth lives in slotsmart_db).
-- ==========================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE IF NOT EXISTS `departments` (
  `department_id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `department_name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `programs` (
  `program_id`    INT(11)      NOT NULL AUTO_INCREMENT,
  `program_name`  VARCHAR(100) NOT NULL,
  `department_id` INT(11)      NOT NULL,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`program_id`),
  KEY `fk_prog_dept` (`department_id`),
  CONSTRAINT `fk_prog_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `faculty` (
  `faculty_id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `faculty_name`          VARCHAR(100) NOT NULL,
  `email`                 VARCHAR(100) DEFAULT NULL UNIQUE,
  `phone_number`          VARCHAR(20)  DEFAULT NULL,
  `department_id`         INT(11)      NOT NULL,
  `max_hours_per_week`    INT(11)      NOT NULL DEFAULT 20,
  `api_token`             VARCHAR(64)  DEFAULT NULL,
  `created_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`faculty_id`),
  KEY `fk_fac_dept` (`department_id`),
  CONSTRAINT `fk_fac_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `courses` (
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

CREATE TABLE IF NOT EXISTS `rooms` (
  `room_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `room_number`  VARCHAR(50)  NOT NULL UNIQUE,
  `floor_number` INT(11)      DEFAULT 1,
  `capacity`     INT(11)      NOT NULL,
  `room_type`    ENUM('Lecture Hall','Lab') DEFAULT 'Lecture Hall',
  `equipment`    TEXT         DEFAULT NULL COMMENT 'JSON per item: {\"AC 1\":1,\"AC 2\":0}; 1=working 0=faulty',
  `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `time_slots` (
  `slot_id`    INT(11) NOT NULL AUTO_INCREMENT,
  `day`        ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `start_time` TIME    NOT NULL,
  `end_time`   TIME    NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`slot_id`),
  UNIQUE KEY `unique_slot` (`day`, `start_time`, `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `allocations` (
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

CREATE TABLE IF NOT EXISTS `students` (
  `student_id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `student_name`          VARCHAR(100) NOT NULL,
  `email`                 VARCHAR(100) DEFAULT NULL UNIQUE,
  `registration_number`   VARCHAR(50)  DEFAULT NULL UNIQUE,
  `phone_number`          VARCHAR(20)  DEFAULT NULL,
  `dob`                   DATE         DEFAULT NULL,
  `gender`                ENUM('Male','Female','Other') DEFAULT 'Male',
  `student_type`          ENUM('regular','transfer')   NOT NULL DEFAULT 'regular',
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

CREATE TABLE IF NOT EXISTS `enrollments` (
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
  KEY `fk_enroll_course`  (`course_id`),
  KEY `fk_enroll_student` (`student_id`),
  CONSTRAINT `fk_enroll_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses`  (`course_id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `timetable_runs` (
  `run_id`        INT          NOT NULL AUTO_INCREMENT,
  `base_session`  VARCHAR(50)  NOT NULL,
  `run_label`     VARCHAR(120) NOT NULL UNIQUE,
  `fitness_score` INT          DEFAULT 0,
  `classes_count` INT          DEFAULT 0,
  `generated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `unplaced_json` TEXT         DEFAULT NULL COMMENT 'class sessions the generator could not place (blocks publishing)',
  `published_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  INDEX `idx_base_session` (`base_session`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `generated_timetable` (
  `timetable_id`   INT(11)      NOT NULL AUTO_INCREMENT,
  `run_id`         INT          DEFAULT NULL,
  `session_name`   VARCHAR(50)  NOT NULL,
  `course_id`      INT(11)      NOT NULL,
  `faculty_id`     INT(11)      NOT NULL,
  `room_id`        INT(11)      NOT NULL,
  `slot_id`        INT(11)      NOT NULL,
  `component_type` ENUM('Theory','Lab') DEFAULT 'Theory',
  `display_label`  VARCHAR(100) DEFAULT NULL,
  `change_log`     TEXT         DEFAULT NULL,
  `version`        INT          NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
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
