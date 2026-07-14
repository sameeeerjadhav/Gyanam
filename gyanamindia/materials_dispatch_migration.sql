-- Materials Dispatch Module Migration
-- Run this on your database before using the module

-- Ensure admissions table has material columns
ALTER TABLE admissions
    ADD COLUMN IF NOT EXISTS material_type ENUM('With Material','Without Material') DEFAULT 'Without Material',
    ADD COLUMN IF NOT EXISTS material_language ENUM('Marathi','English','Hindi') NULL;

-- Main dispatch batches table
CREATE TABLE IF NOT EXISTS material_dispatches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id     VARCHAR(30)  NOT NULL UNIQUE COMMENT 'e.g. DISP-20240315-001',
    atc_id          INT          NOT NULL,
    postal_service  VARCHAR(100) NOT NULL,
    tracking_id     VARCHAR(100) DEFAULT NULL,
    dispatch_date   DATE         NOT NULL,
    notes           TEXT         DEFAULT NULL,
    status          ENUM('Pending','Dispatched','Delivered') DEFAULT 'Pending',
    created_by      INT          DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (atc_id) REFERENCES atc_centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Maps students (admissions) to their dispatch batch
CREATE TABLE IF NOT EXISTS material_dispatch_students (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id     INT NOT NULL,
    admission_id    INT NOT NULL,
    UNIQUE KEY uq_disp_adm (dispatch_id, admission_id),
    FOREIGN KEY (dispatch_id)  REFERENCES material_dispatches(id) ON DELETE CASCADE,
    FOREIGN KEY (admission_id) REFERENCES admissions(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
