-- Create share_payments table for ATC to Admin payments
CREATE TABLE IF NOT EXISTS share_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atc_id INT NOT NULL,
    student_ids JSON NOT NULL,
    total_share_amount DECIMAL(10, 2) NOT NULL,
    transaction_fee DECIMAL(10, 2) NOT NULL DEFAULT 20.00,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Pending',
    razorpay_payment_id VARCHAR(100),
    razorpay_order_id VARCHAR(100),
    razorpay_signature VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME,
    INDEX idx_atc_id (atc_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment
ALTER TABLE share_payments COMMENT = 'Stores share payment transactions from ATC to Admin via Razorpay';
