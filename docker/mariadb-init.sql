-- Create planning database and user (separate from Drupal database)
CREATE DATABASE IF NOT EXISTS planning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- planning_user: used by the planning Python service
CREATE USER IF NOT EXISTS 'planning_user'@'%' IDENTIFIED BY 'planning_pass_local';
GRANT ALL PRIVILEGES ON planning.* TO 'planning_user'@'%';

-- drupal_user also needs access to read/write planning tables from Drupal
GRANT ALL PRIVILEGES ON planning.* TO 'drupal_user'@'%';

FLUSH PRIVILEGES;
