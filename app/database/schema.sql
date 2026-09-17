SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(24) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'vip', 'admin') NOT NULL DEFAULT 'user',
    points INT UNSIGNED NOT NULL DEFAULT 0,
    level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    vip_until DATETIME NULL,
    daily_downloads SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    download_date DATE NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status_vip (status, vip_until)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tokens_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS software (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    version VARCHAR(40) NOT NULL,
    summary VARCHAR(240) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    changelog JSON NOT NULL,
    category VARCHAR(40) NOT NULL,
    category_color CHAR(7) NOT NULL DEFAULT '#536DFE',
    icon_path VARCHAR(255) NULL,
    archive_password VARCHAR(100) NULL,
    md5 CHAR(32) NULL,
    sha256 CHAR(64) NULL,
    is_tested TINYINT(1) NOT NULL DEFAULT 0,
    is_vip TINYINT(1) NOT NULL DEFAULT 0,
    reply_required TINYINT(1) NOT NULL DEFAULT 0,
    points_required INT UNSIGNED NOT NULL DEFAULT 0,
    download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    dead_link_reports INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'disabled') NOT NULL DEFAULT 'draft',
    moderation_reason VARCHAR(500) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_software_author FOREIGN KEY (author_id) REFERENCES users(id),
    FULLTEXT INDEX ft_software_search (name, summary, description),
    INDEX idx_software_listing (status, is_vip, updated_at),
    INDEX idx_software_popular (status, download_count)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS moderation_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    from_status ENUM('draft', 'published', 'disabled') NULL,
    to_status ENUM('draft', 'published', 'disabled') NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_moderation_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    CONSTRAINT fk_moderation_actor FOREIGN KEY (actor_id) REFERENCES users(id),
    INDEX idx_moderation_resource (software_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platforms (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(30) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS software_platforms (
    software_id BIGINT UNSIGNED NOT NULL,
    platform_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (software_id, platform_id),
    CONSTRAINT fk_sp_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    CONSTRAINT fk_sp_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS download_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_id BIGINT UNSIGNED NOT NULL,
    type ENUM('direct', 'external') NOT NULL,
    label VARCHAR(50) NOT NULL,
    url VARCHAR(1000) NULL,
    file_path VARCHAR(500) NULL,
    filename VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sources_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    INDEX idx_sources_software (software_id, enabled, priority)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS resource_verifications (
    software_id BIGINT UNSIGNED PRIMARY KEY,
    file_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NULL,
    signature_publisher VARCHAR(255) NULL,
    source_domain VARCHAR(253) NULL,
    final_domain VARCHAR(253) NULL,
    scan_engine VARCHAR(100) NULL,
    scan_result ENUM('unknown', 'clean', 'suspicious', 'malicious') NOT NULL DEFAULT 'unknown',
    scanned_at DATETIME NULL,
    verification_task VARCHAR(100) NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    source_checked_at DATETIME NULL,
    CONSTRAINT fk_verification_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    CONSTRAINT fk_verification_user FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_verification_status (scan_result, scanned_at),
    INDEX idx_verification_source_check (source_checked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    content VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_replies_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    CONSTRAINT fk_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_replies_unlock (software_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checkins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    checkin_date DATE NOT NULL,
    points_awarded SMALLINT UNSIGNED NOT NULL,
    CONSTRAINT fk_checkins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_daily_checkin (user_id, checkin_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
    user_id BIGINT UNSIGNED NOT NULL,
    software_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, software_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    INDEX idx_favorites_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS point_rewards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('publish') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    points SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_point_rewards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_point_rewards_software FOREIGN KEY (subject_id) REFERENCES software(id) ON DELETE CASCADE,
    UNIQUE KEY uq_point_reward_subject (event_type, subject_id),
    INDEX idx_point_rewards_daily (user_id, event_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('open', 'resolved', 'rejected') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reports_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reports_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dmca_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claimant_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    resource_url VARCHAR(1000) NOT NULL,
    statement TEXT NOT NULL,
    status ENUM('open', 'processing', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dmca_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('dead_link', 'dmca', 'system') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_subject (type, subject_id),
    INDEX idx_notifications_unread (is_read, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_download_stats (
    software_id BIGINT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (software_id, stat_date),
    CONSTRAINT fk_stats_software FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE,
    INDEX idx_stats_date (stat_date)
) ENGINE=InnoDB;

INSERT INTO platforms (slug, name) VALUES
    ('windows', 'Windows'), ('macos', 'macOS'), ('android', 'Android'),
    ('ios', 'iOS'), ('linux', 'Linux')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO schema_migrations (version) VALUES ('20260727_backend_enhancements');
INSERT IGNORE INTO schema_migrations (version) VALUES ('20260728_resource_verifications');
INSERT IGNORE INTO schema_migrations (version) VALUES ('20260917_content_moderation');
