-- ============================================
-- Gyanam India Portal - Complete Database Schema
-- ============================================
-- Version: 1.0
-- Date: March 4, 2026
-- Description: Complete database schema for the Gyanam Portal
-- ============================================

-- Set character set and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Create database (skip on Hostinger — already created via cPanel)
-- CREATE DATABASE IF NOT EXISTS u587292075_gyanam_db_1 
-- CHARACTER SET utf8mb4 
-- COLLATE utf8mb4_unicode_ci;

-- USE u587292075_gyanam_db_1;

-- ============================================
-- 1. USERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'DLC Office', 'ATC CENTER') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    mobile VARCHAR(15),
    dlc_id INT,
    atc_id INT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_dlc_id (dlc_id),
    INDEX idx_atc_id (atc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. DLC OFFICES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS dlc_offices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    district VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    atc_count INT DEFAULT 0,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_district (district),
    INDEX idx_state (state),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. ATC CENTERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS atc_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    district VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    dlc_id INT NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    mobile VARCHAR(15),
    address TEXT,
    courses TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_dlc_id (dlc_id),
    INDEX idx_district (district),
    INDEX idx_status (status),
    FOREIGN KEY (dlc_id) REFERENCES dlc_offices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. COURSES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    course_name VARCHAR(150) NOT NULL,
    duration VARCHAR(50),
    fees DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_atc_id (atc_id),
    INDEX idx_course_name (course_name),
    INDEX idx_status (status),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. ADMISSIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    enrollment_no VARCHAR(50) UNIQUE NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    father_name VARCHAR(100),
    mother_name VARCHAR(100),
    dob DATE,
    gender ENUM('Male', 'Female', 'Other'),
    category ENUM('General', 'OBC', 'SC', 'ST', 'Other'),
    mobile VARCHAR(15),
    email VARCHAR(100),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    pincode VARCHAR(10),
    course_id INT,
    course_name VARCHAR(150),
    course_fees DECIMAL(10, 2) DEFAULT 0.00,
    discount_amount DECIMAL(10, 2) DEFAULT 0.00,
    fees_paid DECIMAL(10, 2) DEFAULT 0.00,
    fees_pending DECIMAL(10, 2) DEFAULT 0.00,
    photo_path VARCHAR(255),
    admission_date DATE,
    status ENUM('Active', 'Inactive', 'Completed') DEFAULT 'Active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_atc_id (atc_id),
    INDEX idx_enrollment_no (enrollment_no),
    INDEX idx_student_name (student_name),
    INDEX idx_mobile (mobile),
    INDEX idx_status (status),
    INDEX idx_course_id (course_id),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. FEE PAYMENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS fee_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_mode ENUM('Cash', 'Online', 'Cheque', 'DD', 'Card') NOT NULL,
    payment_date DATE NOT NULL,
    transaction_ref VARCHAR(100),
    remarks TEXT,
    receipt_no VARCHAR(50),
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admission_id (admission_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_receipt_no (receipt_no),
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. FEE PAYMENT REMARKS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS fee_payment_remarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admission_id INT NOT NULL,
    payment_id INT NOT NULL,
    remarks TEXT NOT NULL,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admission_id (admission_id),
    INDEX idx_payment_id (payment_id),
    FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES fee_payments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. DOCUMENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    category VARCHAR(100),
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    uploaded_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_title (title),
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_uploaded_by (uploaded_by),
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. DISPATCHES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS dispatches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_no VARCHAR(50) UNIQUE NOT NULL,
    dlc_id INT NOT NULL,
    atc_id INT,
    material_type ENUM('Books', 'Certificates', 'Marksheets', 'Other') NOT NULL,
    quantity INT NOT NULL,
    description TEXT,
    tracking_number VARCHAR(100),
    courier_name VARCHAR(100),
    dispatch_date DATE,
    expected_delivery DATE,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Created', 'Sent to DLC', 'Forwarded to ATC', 'Delivered', 'Cancelled') DEFAULT 'Created',
    admin_remarks TEXT,
    dlc_remarks TEXT,
    dlc_forwarded_date DATETIME,
    delivery_date DATETIME,
    received_by VARCHAR(100),
    atc_remarks TEXT,
    INDEX idx_dlc_id (dlc_id),
    INDEX idx_atc_id (atc_id),
    INDEX idx_dispatch_no (dispatch_no),
    INDEX idx_status (status),
    INDEX idx_tracking_number (tracking_number),
    FOREIGN KEY (dlc_id) REFERENCES dlc_offices(id) ON DELETE CASCADE,
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. DISPATCH HISTORY TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS dispatch_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    remarks TEXT,
    updated_by INT NOT NULL,
    updated_by_role ENUM('Admin', 'DLC Office', 'ATC CENTER') NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispatch_id (dispatch_id),
    INDEX idx_status (status),
    FOREIGN KEY (dispatch_id) REFERENCES dispatches(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. DISPATCH REQUESTS TABLE (Future Feature)
-- ============================================
CREATE TABLE IF NOT EXISTS dispatch_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    material_type ENUM('Books', 'Certificates', 'Marksheets', 'Other') NOT NULL,
    quantity INT NOT NULL,
    description TEXT,
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending', 'Approved', 'Dispatched', 'Received', 'Rejected') DEFAULT 'Pending',
    admin_remarks TEXT,
    INDEX idx_atc_id (atc_id),
    INDEX idx_status (status),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. INQUIRIES TABLE (Optional)
-- ============================================
CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    inquiry_type ENUM('Walk-in', 'Telephonic', 'Online', 'Reference') DEFAULT 'Walk-in',
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15),
    email VARCHAR(100),
    course_interested VARCHAR(150),
    inquiry_date DATE,
    follow_up_date DATE,
    status ENUM('New', 'Contacted', 'Interested', 'Not Interested', 'Converted') DEFAULT 'New',
    remarks TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_atc_id (atc_id),
    INDEX idx_inquiry_type (inquiry_type),
    INDEX idx_status (status),
    INDEX idx_mobile (mobile),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- FOREIGN KEY CONSTRAINTS FOR USERS TABLE
-- ============================================
ALTER TABLE users
    ADD CONSTRAINT fk_users_dlc FOREIGN KEY (dlc_id) REFERENCES dlc_offices(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_atc FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE SET NULL;

-- ============================================
-- INSERT DEFAULT ADMIN USER
-- ============================================
-- Password: admin123 (Change this in production!)
INSERT INTO users (username, password, role, name, email, status) 
VALUES ('admin', 'admin123', 'Admin', 'System Administrator', 'admin@gyanam.in', 'Active')
ON DUPLICATE KEY UPDATE username=username;

-- ============================================
-- SAMPLE DATA (Optional - Remove in production)
-- ============================================

-- Sample DLC Office
INSERT INTO dlc_offices (name, district, state, contact_person, mobile, email, status)
VALUES 
('Pune DLC Office', 'Pune', 'Maharashtra', 'Rajesh Kumar', '9876543210', 'pune@gyanam.in', 'Active'),
('Mumbai DLC Office', 'Mumbai', 'Maharashtra', 'Priya Sharma', '9876543211', 'mumbai@gyanam.in', 'Active')
ON DUPLICATE KEY UPDATE name=name;

-- Sample DLC User
INSERT INTO users (username, password, role, name, email, mobile, dlc_id, status)
VALUES ('dlc_pune', 'dlc123', 'DLC Office', 'Pune DLC Manager', 'dlc.pune@gyanam.in', '9876543210', 1, 'Active')
ON DUPLICATE KEY UPDATE username=username;

-- Sample ATC Center
INSERT INTO atc_centers (name, district, state, dlc_id, contact_person, mobile, email, courses, status)
VALUES 
('Pune ATC Center 01', 'Pune', 'Maharashtra', 1, 'Amit Patil', '9876543220', 'atc.pune01@gyanam.in', 'DCA, ADCA, Tally, MS Office', 'Active'),
('Pune ATC Center 02', 'Pune', 'Maharashtra', 1, 'Sneha Desai', '9876543221', 'atc.pune02@gyanam.in', 'DCA, Web Design, Programming', 'Active')
ON DUPLICATE KEY UPDATE name=name;

-- Sample ATC User
INSERT INTO users (username, password, role, name, email, mobile, atc_id, status)
VALUES ('atc_pune_01', 'atc123', 'ATC CENTER', 'Pune ATC 01 Manager', 'atc.pune01@gyanam.in', '9876543220', 1, 'Active')
ON DUPLICATE KEY UPDATE username=username;

-- ============================================
-- VIEWS FOR REPORTING (Optional)
-- ============================================

-- View: ATC Centers with Statistics
CREATE OR REPLACE VIEW vw_atc_statistics AS
SELECT 
    atc.id,
    atc.name AS atc_name,
    atc.district,
    atc.state,
    dlc.name AS dlc_name,
    COUNT(DISTINCT adm.id) AS total_students,
    COALESCE(SUM(adm.course_fees), 0) AS total_fees,
    COALESCE(SUM(adm.fees_paid), 0) AS total_collected,
    COALESCE(SUM(adm.fees_pending), 0) AS total_pending,
    atc.status
FROM atc_centers atc
LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
LEFT JOIN admissions adm ON atc.id = adm.atc_id AND adm.status = 'Active'
GROUP BY atc.id;

-- View: Fee Collection Summary
CREATE OR REPLACE VIEW vw_fee_collection_summary AS
SELECT 
    atc.id AS atc_id,
    atc.name AS atc_name,
    DATE_FORMAT(fp.payment_date, '%Y-%m') AS month,
    COUNT(fp.id) AS payment_count,
    SUM(fp.amount) AS total_collected
FROM fee_payments fp
JOIN admissions adm ON fp.admission_id = adm.id
JOIN atc_centers atc ON adm.atc_id = atc.id
GROUP BY atc.id, DATE_FORMAT(fp.payment_date, '%Y-%m');

-- ============================================
-- END OF SCHEMA
-- ============================================

