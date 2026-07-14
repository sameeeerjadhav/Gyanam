-- Duplicate Certificate Requests table
-- Run this once on your MySQL database

CREATE TABLE IF NOT EXISTS `duplicate_cert_requests` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `atc_id`         INT NOT NULL,
    `admission_id`   INT NOT NULL,
    `student_name`   VARCHAR(200) NOT NULL,
    `roll_no`        VARCHAR(50)  DEFAULT NULL,
    `course`         VARCHAR(200) DEFAULT NULL,
    `cert_type`      ENUM('Course Completion Certificate','Exam Certificate') NOT NULL,
    `reason`         ENUM('Name Correction','Misplaced by Student','Damaged') NOT NULL,
    `remarks`        TEXT DEFAULT NULL,
    `status`         ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `admin_note`     TEXT DEFAULT NULL,
    `requested_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at`    DATETIME DEFAULT NULL,
    `reviewed_by`    INT DEFAULT NULL,
    INDEX `idx_atc`       (`atc_id`),
    INDEX `idx_admission` (`admission_id`),
    INDEX `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
