-- Rollback for 001_core.sql. Only for working/test databases (tools/migrate.php down).
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS login_failures;
DROP TABLE IF EXISTS users;
