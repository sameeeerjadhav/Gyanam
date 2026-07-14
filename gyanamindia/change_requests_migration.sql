-- ============================================
-- Change Requests Migration
-- Run once on the database
-- ============================================
CREATE TABLE IF NOT EXISTS change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    atc_id INT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    old_value TEXT,
    new_value TEXT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    one_time_token VARCHAR(64) NULL,
    applied TINYINT(1) DEFAULT 0,
    reject_reason TEXT NULL,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX idx_admission_id (admission_id),
    INDEX idx_atc_id (atc_id),
    INDEX idx_status (status),
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
