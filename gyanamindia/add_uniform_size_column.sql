-- Add uniform size column to admissions table
-- This is for tracking t-shirt sizes for Abacus course students

ALTER TABLE admissions 
ADD COLUMN uniform_size VARCHAR(10) NULL AFTER course,
ADD INDEX idx_uniform_size (uniform_size);

-- Update existing records to NULL (no size recorded)
UPDATE admissions SET uniform_size = NULL WHERE uniform_size IS NULL;

-- Add comment to column
ALTER TABLE admissions 
MODIFY COLUMN uniform_size VARCHAR(10) NULL COMMENT 'T-shirt size for Abacus course students (XS, S, M, L, XL, XXL, XXXL)';
