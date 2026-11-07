-- Remove only the untouched demo summons text; preserve any wording edited by staff.
UPDATE settings
SET setting_value = NULL
WHERE setting_key = 'summons_wording'
  AND setting_value LIKE 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL BARANGAY SUMMONS.%';
