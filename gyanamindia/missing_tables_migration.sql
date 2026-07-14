-- ============================================
-- Gyanam India Portal - Missing Tables Migration
-- ============================================
-- Run this AFTER database_schema_complete.sql
-- This creates tables that are used by the application
-- but were not in the original schema.
-- ============================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================
-- TELEPHONIC INQUIRIES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS telephonic_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    caller_name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15),
    email VARCHAR(100),
    course_interested VARCHAR(150),
    source VARCHAR(100),
    inquiry_date DATE,
    follow_up_date DATE,
    status ENUM('New', 'Contacted', 'Interested', 'Not Interested', 'Converted') DEFAULT 'New',
    remarks TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_atc_id (atc_id),
    INDEX idx_status (status),
    INDEX idx_mobile (mobile),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    target_type ENUM('All', 'DLC', 'ATC', 'Specific') DEFAULT 'All',
    target_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender_id (sender_id),
    INDEX idx_target_type (target_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATION READS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS notification_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_id (notification_id),
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_read (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADD MISSING COLUMNS TO COURSES TABLE
-- ============================================
ALTER TABLE courses ADD COLUMN IF NOT EXISTS course_code VARCHAR(20) NULL AFTER course_name;

-- ============================================
-- ADD MISSING COLUMNS TO ADMISSIONS TABLE
-- ============================================
-- Add fees_total column if it doesn't exist (used by fees management)
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS fees_total DECIMAL(10, 2) DEFAULT 0.00 AFTER course_fees;

-- Add net_payable column if it doesn't exist
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS net_payable DECIMAL(10, 2) DEFAULT 0.00 AFTER fees_total;

-- Add course column (text) if missing
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS course VARCHAR(150) NULL AFTER course_name;

-- Add profile_photo column to users if missing
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL AFTER mobile;

-- Add upload_date column to documents if missing  
ALTER TABLE documents ADD COLUMN IF NOT EXISTS upload_date DATETIME DEFAULT CURRENT_TIMESTAMP AFTER file_type;

-- ============================================
-- ADD MISSING COLUMNS TO INQUIRIES TABLE
-- ============================================
-- The inquiries form uses an extended schema with name parts, fees, remarks, etc.
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS first_name VARCHAR(60) AFTER name;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) AFTER first_name;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS last_name VARCHAR(60) AFTER middle_name;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS father_name VARCHAR(100) AFTER last_name;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS gender ENUM('Male','Female','Other') DEFAULT 'Male' AFTER father_name;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS dob DATE AFTER gender;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS qualification VARCHAR(80) AFTER dob;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS interested_course VARCHAR(150) AFTER qualification;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS phone VARCHAR(15) AFTER mobile;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS address TEXT AFTER email;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS city VARCHAR(100) AFTER address;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS pin_code VARCHAR(10) AFTER city;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS course_fees DECIMAL(10, 2) DEFAULT 0.00 AFTER interested_course;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS quoted_fees DECIMAL(10, 2) DEFAULT 0.00 AFTER course_fees;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS next_inform_date DATE AFTER follow_up_date;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS next_inform_time TIME AFTER next_inform_date;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS referenced_by VARCHAR(100) AFTER next_inform_time;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS comment TEXT AFTER remarks;
ALTER TABLE inquiries ADD COLUMN IF NOT EXISTS is_converted TINYINT(1) DEFAULT 0 AFTER status;

-- ============================================
-- END OF MIGRATION
-- ============================================
