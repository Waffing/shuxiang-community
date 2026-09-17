SET NAMES utf8mb4;

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
