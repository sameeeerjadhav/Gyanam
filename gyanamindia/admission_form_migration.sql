-- ============================================================
-- Admission Form Redesign — New Column Migration
-- Run this ONCE on Hostinger MySQL before uploading files
-- ============================================================

-- 1. Installments
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS installments INT DEFAULT 1 COMMENT 'No. of fee installments' AFTER discount_reason;

-- 2. Material type
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS material_type ENUM('With Material','Without Material') DEFAULT 'Without Material' AFTER installments;

-- 3. Material language
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS material_language ENUM('Marathi','English','Hindi') DEFAULT 'English' AFTER material_type;

-- 4. State (already exists in schema but may be missing in older DB)
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS state VARCHAR(100) NULL AFTER city;

-- 5. Category (may be missing in migrated DBs)
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS category ENUM('General','OBC','SC','ST','Other') DEFAULT 'General' AFTER gender;

-- 6. Father / Mother name (may be missing)
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS father_name VARCHAR(100) NULL AFTER last_name;
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS mother_name VARCHAR(100) NULL AFTER father_name;

-- 7. Confirm net_payable column exists
ALTER TABLE admissions ADD COLUMN IF NOT EXISTS net_payable DECIMAL(10,2) DEFAULT 0.00 AFTER discount_reason;

-- Done!
SELECT 'Migration complete' AS Status;
