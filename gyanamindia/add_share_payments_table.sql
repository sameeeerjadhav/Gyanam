-- ============================================================
-- Gyanam Portal: Share Payments Table Migration
-- Run this on Hostinger MySQL after uploading the updated code
-- Transaction fee is ₹15 per batch (updated from ₹20)
-- ============================================================

CREATE TABLE IF NOT EXISTS share_payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    atc_id              INT NOT NULL,
    student_ids         JSON NOT NULL          COMMENT 'Array of admission IDs included in this batch',
    total_share_amount  DECIMAL(10,2) NOT NULL  COMMENT 'Sum of individual student HO share amounts',
    transaction_fee     DECIMAL(10,2) NOT NULL DEFAULT 15.00 COMMENT 'Fixed ₹15 per batch',
    total_amount        DECIMAL(10,2) NOT NULL  COMMENT 'total_share_amount + transaction_fee',
    status              ENUM('Pending','Completed','Failed') DEFAULT 'Pending',
    razorpay_payment_id VARCHAR(100)  NULL,
    razorpay_order_id   VARCHAR(100)  NULL,
    razorpay_signature  VARCHAR(255)  NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    paid_at             DATETIME NULL,
    INDEX idx_atc_id    (atc_id),
    INDEX idx_status    (status),
    INDEX idx_created_at(created_at),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ATC-to-HeadOffice share payment batches via Razorpay';

-- If the table already exists with old default (20.00), update the default:
ALTER TABLE share_payments
    MODIFY COLUMN transaction_fee DECIMAL(10,2) NOT NULL DEFAULT 15.00
        COMMENT 'Fixed ₹15 per batch — updated from ₹20';
