-- ============================================================
-- Gyanam India Portal - Scheme Module Migration
-- Run once on Hostinger via phpMyAdmin
-- ============================================================

-- 1. Schemes table (defined by Head Office)
CREATE TABLE IF NOT EXISTS schemes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    scheme_type     ENUM('Admission Count','Revenue Target','Custom') NOT NULL DEFAULT 'Admission Count',
    trigger_count   INT NOT NULL DEFAULT 10 COMMENT 'e.g. every 10 admissions',
    benefit_type    ENUM('Free Share','Discount','Cash Incentive') NOT NULL DEFAULT 'Free Share',
    benefit_value   INT NOT NULL DEFAULT 1 COMMENT 'e.g. 1 free share per trigger',
    applicable_to   ENUM('All ATCs','Specific ATC','ATC Type') NOT NULL DEFAULT 'All ATCs',
    atc_type_filter VARCHAR(50) DEFAULT NULL COMMENT 'Abacus / IT / Other — used if applicable_to=ATC Type',
    description     TEXT DEFAULT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('Active','Inactive','Expired') NOT NULL DEFAULT 'Active',
    created_by      INT DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status    (status),
    INDEX idx_start_date(start_date),
    INDEX idx_end_date  (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Scheme assignments (which ATCs get which scheme)
CREATE TABLE IF NOT EXISTS scheme_assignments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    scheme_id   INT NOT NULL,
    atc_id      INT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scheme_atc (scheme_id, atc_id),
    INDEX idx_scheme_id (scheme_id),
    INDEX idx_atc_id    (atc_id),
    FOREIGN KEY (scheme_id) REFERENCES schemes(id) ON DELETE CASCADE,
    FOREIGN KEY (atc_id)    REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Scheme progress per ATC per scheme cycle
CREATE TABLE IF NOT EXISTS scheme_progress (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    scheme_id        INT NOT NULL,
    atc_id           INT NOT NULL,
    current_count    INT NOT NULL DEFAULT 0 COMMENT 'admissions/revenue counted so far',
    benefit_unlocked INT NOT NULL DEFAULT 0 COMMENT 'number of times benefit was unlocked',
    unlocked_at      DATETIME DEFAULT NULL,
    notes            TEXT DEFAULT NULL,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_progress (scheme_id, atc_id),
    INDEX idx_scheme_id (scheme_id),
    INDEX idx_atc_id    (atc_id),
    FOREIGN KEY (scheme_id) REFERENCES schemes(id) ON DELETE CASCADE,
    FOREIGN KEY (atc_id)    REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
