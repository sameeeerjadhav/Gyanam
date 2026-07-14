-- Birthday Management Table
CREATE TABLE IF NOT EXISTS birthdays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15),
    birth_date DATE NOT NULL,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_birth_date (birth_date),
    INDEX idx_status (status),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
