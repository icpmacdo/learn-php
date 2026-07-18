-- Runs once on first MySQL container start (docker-entrypoint-initdb.d).
-- The `app` database is created by the MYSQL_DATABASE env var; this adds the
-- separate test database so Codeception never touches dev data.
CREATE DATABASE IF NOT EXISTS `app_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `app_test`.* TO 'app'@'%';
FLUSH PRIVILEGES;
