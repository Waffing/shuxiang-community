SET NAMES utf8mb4;

ALTER TABLE software
    ADD COLUMN IF NOT EXISTS moderation_reason VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;

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
