-- ============================================
-- Gyanam India Portal
-- Registration ID & Roll No Redesign Migration
-- ============================================
-- Run this ONCE on the Hostinger database via phpMyAdmin
-- ============================================

-- STEP 1: Add registration_id column to admissions
ALTER TABLE admissions
    ADD COLUMN IF NOT EXISTS registration_id VARCHAR(30) NULL AFTER roll_no;

-- STEP 2: Create a unique index on registration_id (global across ATCs)
-- (Use ignore so re-run doesn't break)
ALTER TABLE admissions
    ADD UNIQUE INDEX IF NOT EXISTS uq_registration_id (registration_id);

-- STEP 3: Add center_type_abbr column to atc_centers (computed from center_type)
-- This is optional, the PHP can compute it on the fly

-- STEP 4: Backfill roll_no for existing admissions
-- Reset roll_no to simple sequential per ATC (1, 2, 3...)
-- We use a user variable trick in MySQL
SET @prev_atc_id = NULL;
SET @rn = 0;

UPDATE admissions a
JOIN (
    SELECT id,
           @rn := IF(@prev_atc_id = atc_id, @rn + 1, 1) AS new_roll,
           @prev_atc_id := atc_id AS dummy
    FROM admissions
    ORDER BY atc_id ASC, id ASC
) t ON a.id = t.id
SET a.roll_no = CAST(t.new_roll AS CHAR);

-- STEP 5: Backfill registration_id for existing admissions
-- Format: gi + center_type_abbreviation + global_sequence
-- We join admissions with atc_centers to get center_type
SET @gseq = 0;

UPDATE admissions a
JOIN atc_centers c ON a.atc_id = c.id
JOIN (
    SELECT adm.id,
           @gseq := @gseq + 1 AS gseq,
           LOWER(CONCAT('gi',
               CASE
                   WHEN LOWER(c.center_type) LIKE '%abacus%'  THEN 'a'
                   WHEN LOWER(c.center_type) LIKE '%vedic%'   THEN 'vm'
                   WHEN LOWER(c.center_type) LIKE '%it%'      THEN 'it'
                   WHEN LOWER(c.center_type) LIKE '%dlp%'     THEN 'dlp'
                   WHEN LOWER(c.center_type) LIKE '%tally%'   THEN 'tal'
                   WHEN LOWER(c.center_type) LIKE '%english%' THEN 'eng'
                   WHEN LOWER(c.center_type) LIKE '%multi%'   THEN 'multi'
                   ELSE 'x'
               END,
               @gseq
           )) AS new_reg_id
    FROM admissions adm
    JOIN atc_centers c ON adm.atc_id = c.id
    ORDER BY adm.id ASC
) t ON a.id = t.id
SET a.registration_id = t.new_reg_id
WHERE a.registration_id IS NULL OR a.registration_id = '';

-- STEP 6: Verify results
SELECT a.id, a.roll_no, a.registration_id, c.name AS atc_name, c.center_type
FROM admissions a
JOIN atc_centers c ON a.atc_id = c.id
ORDER BY a.id ASC
LIMIT 20;
