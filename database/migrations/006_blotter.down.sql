-- Rollback for 006_blotter.sql. Working/test databases only (tools/migrate.php down).
DROP TABLE IF EXISTS hearings;
DROP TABLE IF EXISTS blotter_parties;
DROP TABLE IF EXISTS blotters;
DELETE FROM settings WHERE setting_key = 'summons_wording';
