-- ============================================================
-- Gyanam India Portal - Exam Schedules Table
-- Run once on Hostinger via phpMyAdmin
-- ============================================================
CREATE TABLE IF NOT EXISTS exam_schedules (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    admission_id  INT NOT NULL,
    atc_id        INT NOT NULL,
    exam_date     DATE NOT NULL,
    exam_time     TIME NOT NULL DEFAULT '10:00:00',
    exam_hall     VARCHAR(100) DEFAULT NULL,
    notes         TEXT DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_atc_id      (atc_id),
    INDEX idx_admission_id(admission_id),
    INDEX idx_exam_date   (exam_date),
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (atc_id)       REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
