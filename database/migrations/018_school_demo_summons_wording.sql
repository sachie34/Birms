-- Seed an explicitly non-official sample summons for school project demonstrations.
-- Preserve any wording already supplied by the Barangay.
INSERT INTO settings (setting_key, setting_value)
SELECT 'summons_wording',
       'SCHOOL PROJECT DEMO - NOT AN OFFICIAL BARANGAY SUMMONS.\n\nThis sample asks {{respondent_names}} to appear at {{venue}} on {{hearing_date}} at {{hearing_time}} regarding case {{case_no}}.\n\nComplainants: {{complainant_names}}\n\nBarangay: {{barangay}}, {{municipality}}, {{province}}\n\nThis sample is for interface demonstration only. It has no legal effect and must not be served as an official summons.'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'summons_wording');

UPDATE settings
SET setting_value = 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL BARANGAY SUMMONS.\n\nThis sample asks {{respondent_names}} to appear at {{venue}} on {{hearing_date}} at {{hearing_time}} regarding case {{case_no}}.\n\nComplainants: {{complainant_names}}\n\nBarangay: {{barangay}}, {{municipality}}, {{province}}\n\nThis sample is for interface demonstration only. It has no legal effect and must not be served as an official summons.'
WHERE setting_key = 'summons_wording'
  AND (setting_value IS NULL OR TRIM(setting_value) = '');
