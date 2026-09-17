-- DEVELOPMENT/TEST EXAMPLE DATA ONLY. Never mount this file in production.

INSERT INTO users (id, username, password_hash, role, points, level, vip_until, status)
VALUES (1, 'system', '!', 'user', 0, 1, NULL, 'suspended')
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO software
    (id, author_id, name, slug, version, summary, description, changelog, category, category_color,
     archive_password, md5, sha256, is_tested, is_vip, reply_required, points_required, download_count, status)
VALUES
    (1, 1, '星云笔记', 'nebula-notes', 'v2.4.1', '轻巧、离线优先的跨平台知识管理工具。',
     '支持 Markdown、全文检索与端到端加密同步，适合个人知识库与项目记录。',
     JSON_ARRAY('优化大文档渲染性能', '新增 Android 平板布局', '修复离线同步冲突'),
     '效率', '#6C63FF', 'forum.example', 'd41d8cd98f00b204e9800998ecf8427e',
     'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 1, 0, 0, 0, 5821, 'published'),
    (2, 1, '像素工坊', 'pixel-studio', 'v1.8.0', '为创作者准备的本地图片批处理工作台。',
     '批量裁剪、压缩与格式转换，所有处理均在本地完成。',
     JSON_ARRAY('新增 WebP/AVIF 批量导出', '改进色彩管理'),
     '设计', '#FF6B8A', NULL, NULL, NULL, 1, 1, 0, 100, 3240, 'published'),
    (3, 1, '终端工具箱', 'terminal-kit', 'v3.1.2', '面向开发者的命令片段与环境诊断集合。',
     '内置常用网络、Git 与容器诊断命令，可离线检索。',
     JSON_ARRAY('新增 Linux ARM64 支持', '修复主题切换闪烁'),
     '开发', '#00BFA6', 'tk2026', '71b192c02627d5a23099ccdf83ac787f',
     'b0e20359bc3b4c1dd5b1649f0248180379d6bdcb45a25c0d04b6a55d0a9d682f', 1, 0, 1, 0, 9910, 'published')
ON DUPLICATE KEY UPDATE version = VALUES(version), summary = VALUES(summary), updated_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO software_platforms (software_id, platform_id)
SELECT 1, id FROM platforms WHERE slug IN ('windows', 'macos', 'android', 'ios', 'linux');
INSERT IGNORE INTO software_platforms (software_id, platform_id)
SELECT 2, id FROM platforms WHERE slug IN ('windows', 'macos');
INSERT IGNORE INTO software_platforms (software_id, platform_id)
SELECT 3, id FROM platforms WHERE slug IN ('windows', 'macos', 'linux');

INSERT INTO download_sources (id, software_id, type, label, url, file_path, filename, mime_type, priority)
VALUES
    (1, 1, 'external', '蓝奏云', 'https://example.com/download/nebula', NULL, NULL, NULL, 10),
    (2, 2, 'external', '夸克网盘', 'https://example.com/download/pixel', NULL, NULL, NULL, 10),
    (3, 3, 'direct', '本站高速下载', NULL, 'terminal-kit-v3.1.2.zip', 'terminal-kit-v3.1.2.zip', 'application/zip', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO daily_download_stats (software_id, stat_date, count)
VALUES (1, CURDATE(), 182), (2, CURDATE(), 96), (3, CURDATE(), 241)
ON DUPLICATE KEY UPDATE count = VALUES(count);
