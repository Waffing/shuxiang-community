SET NAMES utf8mb4;

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
