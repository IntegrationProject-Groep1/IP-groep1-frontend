-- Create planning database and user (separate from Drupal database)
CREATE DATABASE IF NOT EXISTS planning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- planning_user: used by the planning Python service
CREATE USER IF NOT EXISTS 'planning_user'@'%' IDENTIFIED BY 'planning_pass_local';
GRANT ALL PRIVILEGES ON planning.* TO 'planning_user'@'%';

-- drupal_user also needs access to read/write planning tables from Drupal
GRANT ALL PRIVILEGES ON planning.* TO 'drupal_user'@'%';

FLUSH PRIVILEGES;

-- Create tables in the planning database
USE planning;

CREATE TABLE IF NOT EXISTS planning_sessions (
    session_id        VARCHAR(255)   NOT NULL COMMENT 'Primary identifier.',
    title             TEXT           NOT NULL,
    start_datetime    VARCHAR(32)    NOT NULL,
    end_datetime      VARCHAR(32)    NOT NULL,
    location          TEXT           DEFAULT '',
    session_type      VARCHAR(50)    DEFAULT 'keynote',
    status            VARCHAR(50)    DEFAULT 'published',
    max_attendees     INT            NOT NULL DEFAULT 0,
    current_attendees INT            NOT NULL DEFAULT 0,
    price             DECIMAL(10,2)  NULL,
    is_deleted        TINYINT        NOT NULL DEFAULT 0,
    PRIMARY KEY (session_id),
    INDEX status     (status),
    INDEX is_deleted (is_deleted),
    INDEX start      (start_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Event sessions managed by the frontend team.';

CREATE TABLE IF NOT EXISTS planning_registrations (
    id            INT          NOT NULL AUTO_INCREMENT,
    session_id    VARCHAR(255) NOT NULL,
    master_uuid   VARCHAR(36)  NOT NULL COMMENT 'Identity Service UUID.',
    status        VARCHAR(50)  NOT NULL DEFAULT 'confirmed',
    registered_at VARCHAR(32)  NULL,
    ics_url       TEXT         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_registration (session_id, master_uuid),
    INDEX session (session_id),
    INDEX user    (master_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User <-> session enrollment records.';
