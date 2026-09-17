<?php
declare(strict_types=1);

namespace App;

use PDO;

final class ForumService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function list(array $filters): array
    {
        $page = Http::positiveInt($filters['page'] ?? 1, 'page');
        $limit = min(30, Http::positiveInt($filters['limit'] ?? 12, 'limit'));
        if ($page > 100000) {
            Http::json(['error' => 'invalid_parameter', 'field' => 'page'], 422);
        }
        foreach (['q', 'category', 'access', 'sort'] as $field) {
            if (isset($filters[$field]) && !is_string($filters[$field])) {
                Http::json(['error' => 'invalid_parameter', 'field' => $field], 422);
            }
        }
        if (mb_strlen((string) ($filters['q'] ?? '')) > 100) {
            Http::json(['error' => 'invalid_parameter', 'field' => 'q'], 422);
        }
        $where = ['s.status = "published"'];
        $params = [];
        $platforms = (array) ($filters['platform'] ?? []);
        if (count($platforms) > 5 || array_filter($platforms, static fn (mixed $platform): bool =>
            !is_string($platform) || !in_array($platform, ['windows', 'macos', 'android', 'ios', 'linux'], true)) !== []) {
            Http::json(['error' => 'invalid_parameter', 'field' => 'platform'], 422);
        }
        $platforms = array_values(array_unique($platforms));
        if ($platforms !== []) {
            $placeholders = implode(',', array_fill(0, count($platforms), '?'));
            $where[] = "EXISTS (SELECT 1 FROM software_platforms sp JOIN platforms p ON p.id = sp.platform_id WHERE sp.software_id = s.id AND p.slug IN ({$placeholders}))";
            array_push($params, ...$platforms);
        }
        if (($filters['access'] ?? '') === 'free') {
            $where[] = 's.is_vip = 0';
        } elseif (($filters['access'] ?? '') === 'vip') {
            $where[] = 's.is_vip = 1';
        }
        $category = trim((string) ($filters['category'] ?? ''));
        if (mb_strlen($category) > 40) {
            Http::json(['error' => 'invalid_parameter', 'field' => 'category'], 422);
        }
        if ($category !== '') {
            $where[] = 's.category = ?';
            $params[] = $category;
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(s.name LIKE ? ESCAPE "!" OR s.summary LIKE ? ESCAPE "!" OR s.description LIKE ? ESCAPE "!")';
            $term = $this->searchTerm((string) $filters['q']);
            array_push($params, $term, $term, $term);
        }
        $updatedDays = filter_var($filters['updated'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($updatedDays !== false && $updatedDays !== null) {
            $where[] = 's.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $updatedDays;
        }
        $order = match ($filters['sort'] ?? 'updated') {
            'popular', 'downloads' => 's.download_count DESC, s.updated_at DESC, s.id DESC',
            'newest' => 's.created_at DESC, s.id DESC',
            default => 's.updated_at DESC, s.id DESC',
        };
        $key = 'software:list:' . hash('sha256', json_encode([$filters, $page, $limit]));
        return Cache::remember($key, 60, function () use ($where, $params, $order, $page, $limit): array {
            $count = $this->db->prepare('SELECT COUNT(*) FROM software s WHERE ' . implode(' AND ', $where));
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $sql = 'SELECT s.id, s.name, s.slug, s.version, s.summary, s.category, s.category_color, s.icon_path,
                           s.is_vip, s.download_count, s.updated_at,
                           (SELECT COUNT(*) FROM replies r WHERE r.software_id = s.id) reply_count,
                           (SELECT COUNT(*) FROM favorites f WHERE f.software_id = s.id) favorite_count,
                           CASE WHEN s.sha256 REGEXP "^[0-9A-Fa-f]{64}$"
                                     AND s.sha256 <> "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
                                     AND TRIM(rv.file_name) <> "" AND rv.file_size > 0
                                     AND TRIM(rv.source_domain) <> "" AND TRIM(rv.final_domain) <> ""
                                     AND rv.scan_result = "clean" AND TRIM(rv.scan_engine) <> "" AND rv.scanned_at IS NOT NULL
                                     AND (rv.verified_by IS NOT NULL OR TRIM(rv.verification_task) <> "")
                                     AND rv.source_checked_at IS NOT NULL AND rv.verified_at IS NOT NULL
                                THEN "verified" ELSE "unverified" END trust_status,
                           GROUP_CONCAT(DISTINCT p.slug ORDER BY p.id) platforms
                    FROM software s
                    LEFT JOIN software_platforms sp ON sp.software_id = s.id
                    LEFT JOIN platforms p ON p.id = sp.platform_id
                    LEFT JOIN resource_verifications rv ON rv.software_id = s.id
                    WHERE ' . implode(' AND ', $where) . '
                    GROUP BY s.id ORDER BY ' . $order . ' LIMIT ? OFFSET ?';
            $statement = $this->db->prepare($sql);
            $position = 1;
            foreach ($params as $value) {
                $statement->bindValue($position++, $value);
            }
            $statement->bindValue($position++, $limit, PDO::PARAM_INT);
            $statement->bindValue($position, ($page - 1) * $limit, PDO::PARAM_INT);
            $statement->execute();
            $items = array_map([$this, 'normalizeSummary'], $statement->fetchAll());
            return $this->pageResult($items, $page, $limit, $total);
        });
    }

    public function detail(int $id, ?array $user): array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, u.username author,
                    (SELECT COUNT(*) FROM replies r WHERE r.software_id = s.id) reply_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.software_id = s.id) favorite_count,
                    rv.file_name trust_file_name, rv.file_size trust_file_size,
                    rv.signature_publisher trust_signature_publisher,
                    rv.source_domain trust_source_domain, rv.final_domain trust_final_domain,
                    rv.scan_engine trust_scan_engine, rv.scan_result trust_scan_result,
                    rv.scanned_at trust_scanned_at, rv.verification_task trust_verification_task,
                    rv.verified_by trust_verified_by, verifier.username trust_verifier,
                    rv.verified_at trust_verified_at, rv.source_checked_at trust_source_checked_at,
                    GROUP_CONCAT(DISTINCT p.slug ORDER BY p.id) platforms
             FROM software s JOIN users u ON u.id = s.author_id
             LEFT JOIN software_platforms sp ON sp.software_id = s.id
             LEFT JOIN platforms p ON p.id = sp.platform_id
             LEFT JOIN resource_verifications rv ON rv.software_id = s.id
             LEFT JOIN users verifier ON verifier.id = rv.verified_by
             WHERE s.id = ? GROUP BY s.id'
        );
        $statement->execute([$id]);
        $software = $statement->fetch();
        $canEdit = $software && $user !== null &&
            ((int) $software['author_id'] === (int) $user['id'] || $user['role'] === 'admin');
        if (!$software || ($software['status'] !== 'published' && !$canEdit)) {
            Http::json(['error' => 'not_found'], 404);
        }
        $unlocked = $canEdit || $this->isUnlocked($software, $user);
        $sourceQuery = $this->db->prepare('SELECT id, type, label, url, priority, enabled FROM download_sources WHERE software_id = ? ORDER BY priority, id');
        $sourceQuery->execute([$id]);
        $editableSources = $sourceQuery->fetchAll();
        $sources = array_map(static function (array $source) use ($unlocked): array {
            return [
                'id' => (int) $source['id'],
                'type' => $source['type'],
                'label' => $source['label'],
                'locked' => !$unlocked,
                'url' => null,
            ];
        }, array_values(array_filter($editableSources, static fn (array $source): bool => (bool) $source['enabled'])));
        $software = $this->normalizeSummary($software);
        $software['trust'] = $this->normalizeTrust($software);
        $software['trust_status'] = $software['trust']['status'];
        $software['download_sources'] = $sources;
        $software['can_edit'] = (bool) $canEdit;
        if ($canEdit) {
            $software['edit_sources'] = $editableSources;
        }
        $replyQuery = $this->db->prepare(
            'SELECT r.id, u.username, r.content, r.created_at FROM replies r JOIN users u ON u.id = r.user_id
             WHERE r.software_id = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 50'
        );
        $replyQuery->execute([$id]);
        $software['replies'] = $replyQuery->fetchAll();
        $software['replies_count'] = $software['reply_count'];
        $software['unlocked'] = $unlocked;
        $software['archive_password'] = $unlocked ? $software['archive_password'] : null;
        $software['changelog'] = json_decode((string) $software['changelog'], true) ?: [];
        foreach (array_keys($software) as $field) {
            if (str_starts_with($field, 'trust_') && $field !== 'trust_status') {
                unset($software[$field]);
            }
        }
        unset($software['is_tested']);
        return $software;
    }

    public function rankings(): array
    {
        return Cache::remember('software:rankings', 300, function (): array {
            $weekly = $this->db->query(
                'SELECT s.id, s.name, s.slug, s.download_count, COALESCE(SUM(d.count), 0) downloads
                 FROM software s LEFT JOIN daily_download_stats d ON d.software_id = s.id AND d.stat_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
                 WHERE s.status = "published" GROUP BY s.id ORDER BY downloads DESC, s.updated_at DESC, s.id DESC LIMIT 8'
            )->fetchAll();
            $latest = $this->db->query(
                'SELECT id, name, slug, version, updated_at FROM software WHERE status = "published" ORDER BY updated_at DESC, id DESC LIMIT 8'
            )->fetchAll();
            return ['weekly' => $weekly, 'latest' => $latest];
        });
    }

    public function reply(int $softwareId, array $user, string $content): array
    {
        $content = trim($content);
        if (mb_strlen($content) < 2 || mb_strlen($content) > 1000) {
            Http::json(['error' => 'invalid_content'], 422);
        }
        $this->db->beginTransaction();
        try {
            $softwareLock = $this->db->prepare('SELECT id, is_vip, reply_required, points_required FROM software WHERE id = ? AND status = "published" LOCK IN SHARE MODE');
            $softwareLock->execute([$softwareId]);
            $software = $softwareLock->fetch();
            if (!$software) {
                $this->db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            $userLock = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $userLock->execute([$user['id']]);
            $rewardQuery = $this->db->prepare('SELECT 1 FROM replies WHERE software_id = ? AND user_id = ? LIMIT 1');
            $rewardQuery->execute([$softwareId, $user['id']]);
            $points = $rewardQuery->fetchColumn() ? 0 : 1;
            $this->db->prepare('INSERT INTO replies (software_id, user_id, content) VALUES (?, ?, ?)')->execute([$softwareId, $user['id'], $content]);
            if ($points > 0) {
                $this->db->prepare('UPDATE users SET points = points + 1 WHERE id = ?')->execute([$user['id']]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        Cache::forgetPrefix('software:');
        $user['points'] = (int) $user['points'] + $points;
        return ['unlocked' => $this->isUnlocked($software, $user), 'points_awarded' => $points];
    }

    public function checkin(array $user): array
    {
        try {
            $this->db->beginTransaction();
            $this->db->prepare('INSERT INTO checkins (user_id, checkin_date, points_awarded) VALUES (?, CURDATE(), 5)')->execute([$user['id']]);
            $this->db->prepare('UPDATE users SET points = points + 5 WHERE id = ?')->execute([$user['id']]);
            $this->db->commit();
            return ['checked_in' => true, 'points_awarded' => 5];
        } catch (\PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) $error->errorInfo[1] === 1062) {
                return ['checked_in' => false, 'points_awarded' => 0];
            }
            throw $error;
        }
    }

    public function report(int $softwareId, array $user, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            Http::json(['error' => 'invalid_reason'], 422);
        }
        $this->db->beginTransaction();
        try {
            $software = $this->db->prepare('SELECT id FROM software WHERE id = ? AND status = "published" FOR UPDATE');
            $software->execute([$softwareId]);
            if (!$software->fetchColumn()) {
                $this->db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            $this->db->prepare('INSERT INTO reports (software_id, user_id, reason) VALUES (?, ?, ?)')->execute([$softwareId, $user['id'], $reason]);
            $this->db->prepare('UPDATE software SET dead_link_reports = dead_link_reports + 1, updated_at = updated_at WHERE id = ?')->execute([$softwareId]);
            $this->db->prepare(
                'INSERT INTO admin_notifications (type, subject_id, message)
                 VALUES ("dead_link", ?, ?)
                 ON DUPLICATE KEY UPDATE message = VALUES(message), updated_at = CURRENT_TIMESTAMP, is_read = 0'
            )->execute([$softwareId, '软件 #' . $softwareId . ' 收到新的失效链接举报']);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return ['submitted' => true];
    }

    public function publish(array $user, array $input): array
    {
        $data = $this->resourceInput($input);
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) ($input['slug'] ?? $data['name'])), '-'));
        $slug = substr($slug, 0, 110);
        if ($slug === '') {
            $slug = 'software-' . bin2hex(random_bytes(5));
        }
        $slugQuery = $this->db->prepare('SELECT 1 FROM software WHERE slug = ? LIMIT 1');
        $slugQuery->execute([$slug]);
        if ($slugQuery->fetchColumn()) {
            $slug .= '-' . bin2hex(random_bytes(4));
        }
        $status = $user['role'] === 'admin' ? 'published' : 'draft';
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$user['id']]);
            $statement = $this->db->prepare(
                'INSERT INTO software
                 (author_id, name, slug, version, summary, description, changelog, category, category_color,
                  archive_password, md5, sha256, is_vip, reply_required, points_required, status, reviewed_by, reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $user['id'], $data['name'], $slug, $data['version'], $data['summary'], $data['description'],
                $data['changelog'], $data['category'], $data['category_color'], $data['archive_password'],
                $data['md5'], $data['sha256'], $user['role'] === 'admin' && !empty($input['is_vip']) ? 1 : 0,
                $data['reply_required'], $data['points_required'], $status,
                $status === 'published' ? $user['id'] : null, $status === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
            $softwareId = (int) $this->db->lastInsertId();
            $this->writeResourceLinks($softwareId, $data);
            $this->recordModeration($softwareId, (int) $user['id'], null, $status, $status === 'draft' ? '提交审核' : '管理员发布');
            $points = $status === 'published' ? $this->awardPublishPoints($softwareId, (int) $user['id']) : 0;
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        Cache::forgetPrefix('software:');
        return ['id' => $softwareId, 'slug' => $slug, 'status' => $status, 'points_awarded' => $points, 'download_sources' => count($data['sources'])];
    }

    public function updateResource(int $id, array $user, array $input): array
    {
        $query = $this->db->prepare('SELECT * FROM software WHERE id = ?');
        $query->execute([$id]);
        $existing = $query->fetch();
        if (!$existing) {
            Http::json(['error' => 'not_found'], 404);
        }
        if ((int) $existing['author_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
            Http::json(['error' => 'resource_owner_required'], 403);
        }
        $data = $this->resourceInput($input, $id);
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT status, is_vip FROM software WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $locked = $lock->fetch();
            if (!$locked) {
                $this->db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            $oldStatus = $locked['status'];
            $status = $user['role'] === 'admin' ? $oldStatus : 'draft';
            $statement = $this->db->prepare(
                'UPDATE software SET name = ?, version = ?, summary = ?, description = ?, changelog = ?,
                 category = ?, category_color = ?, archive_password = ?, md5 = ?, sha256 = ?,
                 is_vip = ?, reply_required = ?, points_required = ?, status = ?, is_tested = 0,
                 moderation_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([
                $data['name'], $data['version'], $data['summary'], $data['description'], $data['changelog'],
                $data['category'], $data['category_color'], $data['archive_password'], $data['md5'], $data['sha256'],
                $user['role'] === 'admin' ? (!empty($input['is_vip']) ? 1 : 0) : (int) $locked['is_vip'],
                $data['reply_required'], $data['points_required'], $status, $id,
            ]);
            $this->writeResourceLinks($id, $data);
            $this->db->prepare('DELETE FROM resource_verifications WHERE software_id = ?')->execute([$id]);
            $this->recordModeration($id, (int) $user['id'], $oldStatus, $status, '资源内容更新');
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        Cache::forgetPrefix('software:');
        return ['id' => $id, 'slug' => $existing['slug'], 'status' => $status, 'points_awarded' => 0, 'download_sources' => count($data['sources'])];
    }

    public function myResources(array $user, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(30, max(1, (int) ($filters['limit'] ?? 12)));
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && !in_array($status, ['draft', 'published', 'disabled'], true)) {
            Http::json(['error' => 'invalid_status'], 422);
        }
        $where = 's.author_id = ?' . ($status === '' ? '' : ' AND s.status = ?');
        $params = $status === '' ? [$user['id']] : [$user['id'], $status];
        $count = $this->db->prepare('SELECT COUNT(*) FROM software s WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->db->prepare(
            'SELECT s.id, s.name, s.slug, s.version, s.summary, s.category, s.category_color, s.icon_path,
                    s.is_vip, s.download_count, s.status, s.moderation_reason, s.reviewed_at, s.updated_at,
                    (SELECT COUNT(*) FROM replies r WHERE r.software_id = s.id) reply_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.software_id = s.id) favorite_count,
                    GROUP_CONCAT(DISTINCT p.slug ORDER BY p.id) platforms
             FROM software s LEFT JOIN software_platforms sp ON sp.software_id = s.id
             LEFT JOIN platforms p ON p.id = sp.platform_id WHERE ' . $where . '
             GROUP BY s.id ORDER BY s.updated_at DESC, s.id DESC LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($params as $value) {
            $statement->bindValue($position++, $value);
        }
        $statement->bindValue($position++, $limit, PDO::PARAM_INT);
        $statement->bindValue($position, ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        return $this->pageResult(array_map([$this, 'normalizeSummary'], $statement->fetchAll()), $page, $limit, $total);
    }

    public function toggleFavorite(int $softwareId, array $user): array
    {
        $this->db->beginTransaction();
        try {
            $software = $this->db->prepare('SELECT id FROM software WHERE id = ? AND status = "published" LOCK IN SHARE MODE');
            $software->execute([$softwareId]);
            if (!$software->fetchColumn()) {
                $this->db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            $lock = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$user['id']]);
            $delete = $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND software_id = ?');
            $delete->execute([$user['id'], $softwareId]);
            $favorited = $delete->rowCount() === 0;
            if ($favorited) {
                $this->db->prepare('INSERT INTO favorites (user_id, software_id) VALUES (?, ?)')
                    ->execute([$user['id'], $softwareId]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        Cache::forgetPrefix('software:');
        return ['software_id' => $softwareId, 'favorited' => $favorited];
    }

    public function favoriteStatus(int $softwareId, array $user): array
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS(SELECT 1 FROM software WHERE id = ? AND status = "published") existing,
                    EXISTS(SELECT 1 FROM favorites WHERE user_id = ? AND software_id = ?) favorited'
        );
        $statement->execute([$softwareId, $user['id'], $softwareId]);
        $result = $statement->fetch();
        if (!(bool) $result['existing']) {
            Http::json(['error' => 'not_found'], 404);
        }
        return ['software_id' => $softwareId, 'favorited' => (bool) $result['favorited']];
    }

    public function favorites(array $user, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(30, max(1, (int) ($filters['limit'] ?? 12)));
        $count = $this->db->prepare('SELECT COUNT(*) FROM favorites f JOIN software s ON s.id = f.software_id WHERE f.user_id = ? AND s.status = "published"');
        $count->execute([$user['id']]);
        $total = (int) $count->fetchColumn();
        $statement = $this->db->prepare(
            'SELECT s.id, s.name, s.slug, s.version, s.summary, s.category, s.category_color, s.icon_path,
                    s.is_vip, s.download_count, s.updated_at, f.created_at favorited_at,
                    (SELECT COUNT(*) FROM replies r WHERE r.software_id = s.id) reply_count,
                    (SELECT COUNT(*) FROM favorites favorite WHERE favorite.software_id = s.id) favorite_count,
                    GROUP_CONCAT(DISTINCT p.slug ORDER BY p.id) platforms
             FROM favorites f JOIN software s ON s.id = f.software_id
             LEFT JOIN software_platforms sp ON sp.software_id = s.id
             LEFT JOIN platforms p ON p.id = sp.platform_id
             WHERE f.user_id = ? AND s.status = "published"
             GROUP BY s.id, f.created_at ORDER BY f.created_at DESC, s.id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $user['id'], PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->bindValue(3, ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map([$this, 'normalizeSummary'], $statement->fetchAll());
        return $this->pageResult($items, $page, $limit, $total);
    }

    public function profileDashboard(array $user): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.username, u.role, u.points, u.level, u.vip_until, u.daily_downloads, u.download_date, u.created_at,
                    (SELECT COUNT(*) FROM software s WHERE s.author_id = u.id AND s.status = "published") published_count,
                    (SELECT COUNT(*) FROM software s WHERE s.author_id = u.id AND s.status = "draft") pending_count,
                    (SELECT COUNT(*) FROM replies r WHERE r.user_id = u.id) reply_count,
                    (SELECT COUNT(*) FROM favorites f JOIN software s ON s.id = f.software_id WHERE f.user_id = u.id AND s.status = "published") favorite_count,
                    EXISTS(SELECT 1 FROM checkins c WHERE c.user_id = u.id AND c.checkin_date = CURDATE()) checked_in_today
             FROM users u WHERE u.id = ?'
        );
        $statement->execute([$user['id']]);
        $profile = $statement->fetch();
        foreach (['id', 'points', 'level', 'daily_downloads', 'published_count', 'pending_count', 'reply_count', 'favorite_count'] as $field) {
            $profile[$field] = (int) $profile[$field];
        }
        $profile['checked_in_today'] = (bool) $profile['checked_in_today'];
        $profile['daily_download_limit'] = Auth::isVip($profile) ? null : 5;
        if ($profile['download_date'] !== date('Y-m-d')) {
            $profile['daily_downloads'] = 0;
        }
        return ['profile' => $profile];
    }

    public function adminQueue(array $user, array $filters): array
    {
        $this->assertAdmin($user);
        $queue = (string) ($filters['queue'] ?? 'reports');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 20)));
        $status = trim((string) ($filters['status'] ?? ''));
        $allowed = match ($queue) {
            'software' => ['draft', 'published', 'disabled'],
            'reports' => ['open', 'resolved', 'rejected'],
            'dmca' => ['open', 'processing', 'closed'],
            'notifications' => ['read', 'unread'],
            default => null,
        };
        if ($allowed === null) {
            Http::json(['error' => 'invalid_queue'], 422);
        }
        if ($status !== '' && !in_array($status, $allowed, true)) {
            Http::json(['error' => 'invalid_status'], 422);
        }
        $params = $status === '' ? [] : [$queue === 'notifications' ? ($status === 'read' ? 1 : 0) : $status];
        if ($queue === 'software') {
            $columns = 's.id, s.name, s.slug, s.version, s.summary, s.status, s.author_id, u.username author,
                        s.moderation_reason, s.reviewed_by, s.reviewed_at, s.created_at, s.updated_at';
            $from = 'FROM software s JOIN users u ON u.id = s.author_id' . ($status === '' ? '' : ' WHERE s.status = ?');
            $order = 's.updated_at DESC, s.id DESC';
        } elseif ($queue === 'reports') {
            $columns = 'r.id, r.software_id, s.name software_name, r.user_id, u.username, r.reason, r.status, r.created_at';
            $from = 'FROM reports r JOIN software s ON s.id = r.software_id JOIN users u ON u.id = r.user_id' . ($status === '' ? '' : ' WHERE r.status = ?');
            $order = 'r.created_at DESC, r.id DESC';
        } elseif ($queue === 'dmca') {
            $columns = 'd.id, d.claimant_name, d.email, d.resource_url, d.statement, d.status, d.created_at';
            $from = 'FROM dmca_requests d' . ($status === '' ? '' : ' WHERE d.status = ?');
            $order = 'd.created_at DESC, d.id DESC';
        } else {
            $columns = 'n.id, n.type, n.subject_id, n.message, n.is_read, n.created_at, n.updated_at';
            $from = 'FROM admin_notifications n' . ($status === '' ? '' : ' WHERE n.is_read = ?');
            $order = 'n.updated_at DESC, n.id DESC';
        }
        $count = $this->db->prepare('SELECT COUNT(*) ' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->db->prepare('SELECT ' . $columns . ' ' . $from . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        $position = 1;
        foreach ($params as $value) {
            $statement->bindValue($position++, $value);
        }
        $statement->bindValue($position++, $limit, PDO::PARAM_INT);
        $statement->bindValue($position, ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            if (array_key_exists('is_read', $item)) {
                $item['is_read'] = (bool) $item['is_read'];
            }
        }
        unset($item);
        return ['queue' => $queue] + $this->pageResult($items, $page, $limit, $total);
    }

    public function resolveAdminQueue(array $user, array $input): array
    {
        $this->assertAdmin($user);
        $queue = (string) ($input['queue'] ?? '');
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            Http::json(['error' => 'invalid_parameter', 'field' => 'id'], 422);
        }
        if ($queue === 'software') {
            $status = (string) ($input['status'] ?? '');
            $reason = trim((string) ($input['reason'] ?? ''));
            if (!in_array($status, ['draft', 'published', 'disabled'], true)) {
                Http::json(['error' => 'invalid_status'], 422);
            }
            if (mb_strlen($reason) > 500 || ($status !== 'published' && mb_strlen($reason) < 3)) {
                Http::json(['error' => 'invalid_reason'], 422);
            }
            $this->db->beginTransaction();
            try {
                $lock = $this->db->prepare('SELECT author_id, status, moderation_reason FROM software WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $software = $lock->fetch();
                if (!$software) {
                    $this->db->rollBack();
                    Http::json(['error' => 'not_found'], 404);
                }
                $changed = $software['status'] !== $status || (string) $software['moderation_reason'] !== $reason;
                $points = 0;
                if ($changed) {
                    $this->db->prepare(
                        'UPDATE software SET status = ?, moderation_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
                    )->execute([$status, $reason === '' ? null : $reason, $user['id'], $id]);
                    $this->recordModeration((int) $id, (int) $user['id'], $software['status'], $status, $reason);
                    if ($status === 'published') {
                        $points = $this->awardPublishPoints((int) $id, (int) $software['author_id']);
                    }
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
            Cache::forgetPrefix('software:');
            return ['queue' => $queue, 'id' => (int) $id, 'status' => $status, 'resolved' => true, 'points_awarded' => $points];
        } elseif ($queue === 'reports') {
            $status = (string) ($input['status'] ?? 'resolved');
            if (!in_array($status, ['resolved', 'rejected'], true)) {
                Http::json(['error' => 'invalid_status'], 422);
            }
            $statement = $this->db->prepare('UPDATE reports SET status = ? WHERE id = ?');
            $statement->execute([$status, $id]);
        } elseif ($queue === 'dmca') {
            $status = (string) ($input['status'] ?? 'closed');
            if (!in_array($status, ['processing', 'closed'], true)) {
                Http::json(['error' => 'invalid_status'], 422);
            }
            $statement = $this->db->prepare('UPDATE dmca_requests SET status = ? WHERE id = ?');
            $statement->execute([$status, $id]);
        } elseif ($queue === 'notifications') {
            $status = 'read';
            $statement = $this->db->prepare('UPDATE admin_notifications SET is_read = 1 WHERE id = ?');
            $statement->execute([$id]);
        } else {
            Http::json(['error' => 'invalid_queue'], 422);
        }
        $exists = $this->db->prepare(match ($queue) {
            'reports' => 'SELECT 1 FROM reports WHERE id = ?',
            'dmca' => 'SELECT 1 FROM dmca_requests WHERE id = ?',
            default => 'SELECT 1 FROM admin_notifications WHERE id = ?',
        });
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            Http::json(['error' => 'not_found'], 404);
        }
        return ['queue' => $queue, 'id' => (int) $id, 'status' => $status, 'resolved' => true];
    }

    public function dmca(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $url = filter_var($input['url'] ?? '', FILTER_VALIDATE_URL);
        $statement = trim((string) ($input['statement'] ?? ''));
        if ($name === '' || $email === false || $url === false || mb_strlen($statement) < 20 || mb_strlen($statement) > 5000) {
            Http::json(['error' => 'invalid_dmca_request'], 422);
        }
        $this->db->prepare('INSERT INTO dmca_requests (claimant_name, email, resource_url, statement) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, $url, $statement]);
        $requestId = (int) $this->db->lastInsertId();
        $this->db->prepare(
            'INSERT INTO admin_notifications (type, subject_id, message) VALUES ("dmca", ?, ?)'
        )->execute([$requestId, '收到新的版权下架申请 DMCA-' . str_pad((string) $requestId, 8, '0', STR_PAD_LEFT)]);
        return ['submitted' => true, 'reference' => 'DMCA-' . str_pad((string) $requestId, 8, '0', STR_PAD_LEFT)];
    }

    public function issueDownload(int $sourceId, array $user): array
    {
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'SELECT ds.*, s.id software_id, s.is_vip, s.reply_required, s.points_required
                 FROM download_sources ds JOIN software s ON s.id = ds.software_id
                 WHERE ds.id = ? AND ds.enabled = 1 AND s.status = "published" FOR UPDATE'
            );
            $query->execute([$sourceId]);
            $source = $query->fetch();
            if (!$source) {
                $this->db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            if (!$this->isUnlocked($source, $user)) {
                $this->db->rollBack();
                Http::json(['error' => 'resource_locked'], 403);
            }
            $this->enforceDownloadQuota($user);
            $this->db->prepare('UPDATE software SET download_count = download_count + 1, updated_at = updated_at WHERE id = ?')->execute([$source['software_id']]);
            $this->db->prepare(
                'INSERT INTO daily_download_stats (software_id, stat_date, count) VALUES (?, CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE count = count + 1'
            )->execute([$source['software_id']]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        Cache::forgetPrefix('software:');
        if ($source['type'] === 'external') {
            return ['type' => 'external', 'url' => $source['url']];
        }
        $token = DownloadToken::issue((int) $source['id'], (int) $user['id']);
        return ['type' => 'direct', 'url' => '/download.php?source=' . $source['id'] . '&token=' . rawurlencode($token), 'expires_in' => 300];
    }

    private function enforceDownloadQuota(array $user): void
    {
        if (Auth::isVip($user)) {
            $this->db->prepare(
                'UPDATE users SET daily_downloads = IF(download_date = CURDATE(), daily_downloads + 1, 1), download_date = CURDATE() WHERE id = ?'
            )->execute([$user['id']]);
            return;
        }
        $statement = $this->db->prepare(
            'UPDATE users
             SET daily_downloads = IF(download_date = CURDATE(), daily_downloads + 1, 1), download_date = CURDATE()
             WHERE id = ? AND (download_date IS NULL OR download_date < CURDATE() OR daily_downloads < 5)'
        );
        $statement->execute([$user['id']]);
        if ($statement->rowCount() !== 1) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Http::json(['error' => 'daily_download_limit'], 429);
        }
    }

    private function isUnlocked(array $software, ?array $user): bool
    {
        if ($user === null) {
            return !(bool) $software['reply_required'] && (int) $software['points_required'] === 0 && !(bool) $software['is_vip'];
        }
        if ((bool) $software['is_vip'] && !Auth::isVip($user)) {
            return false;
        }
        if ((int) $user['points'] < (int) $software['points_required']) {
            return false;
        }
        if (!(bool) $software['reply_required']) {
            return true;
        }
        $statement = $this->db->prepare('SELECT 1 FROM replies WHERE software_id = ? AND user_id = ? LIMIT 1');
        $statement->execute([$software['software_id'] ?? $software['id'], $user['id']]);
        return (bool) $statement->fetchColumn();
    }

    private function resourceInput(array $input, ?int $softwareId = null): array
    {
        foreach (['name', 'version', 'summary', 'description', 'category', 'category_color', 'archive_password', 'md5', 'sha256'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                Http::json(['error' => 'invalid_parameter', 'field' => $field], 422);
            }
        }
        $data = [];
        foreach (['name', 'version', 'summary', 'description', 'category'] as $field) {
            $data[$field] = trim((string) ($input[$field] ?? ''));
        }
        if (mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 100 ||
            $data['version'] === '' || mb_strlen($data['version']) > 40 ||
            mb_strlen($data['summary']) < 10 || mb_strlen($data['summary']) > 240 ||
            mb_strlen($data['description']) < 20 || mb_strlen($data['description']) > 20000 ||
            mb_strlen($data['category']) < 2 || mb_strlen($data['category']) > 40) {
            Http::json(['error' => 'invalid_software'], 422);
        }
        $platforms = array_values(array_unique(array_filter((array) ($input['platforms'] ?? []), 'is_string')));
        if ($platforms === [] || count($platforms) > 5) {
            Http::json(['error' => 'invalid_platform'], 422);
        }
        $placeholders = implode(',', array_fill(0, count($platforms), '?'));
        $platformQuery = $this->db->prepare("SELECT id FROM platforms WHERE slug IN ({$placeholders})");
        $platformQuery->execute($platforms);
        $data['platform_ids'] = $platformQuery->fetchAll(PDO::FETCH_COLUMN);
        if (count($data['platform_ids']) !== count($platforms)) {
            Http::json(['error' => 'invalid_platform'], 422);
        }
        $data['sources'] = $this->validateDownloadSources($input['download_sources'] ?? null, $softwareId);
        $changelog = array_values(array_map('trim', array_filter((array) ($input['changelog'] ?? []), static fn (mixed $item): bool => is_string($item) && trim($item) !== '')));
        if (count($changelog) > 50 || array_filter($changelog, static fn (string $item): bool => mb_strlen($item) > 500) !== []) {
            Http::json(['error' => 'invalid_changelog'], 422);
        }
        $data['changelog'] = json_encode($changelog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $data['category_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($input['category_color'] ?? '')) ? $input['category_color'] : '#536DFE';
        $password = trim((string) ($input['archive_password'] ?? ''));
        if (mb_strlen($password) > 100) {
            Http::json(['error' => 'invalid_archive_password'], 422);
        }
        $data['archive_password'] = $password === '' ? null : $password;
        foreach (['md5' => 32, 'sha256' => 64] as $field => $length) {
            $hash = strtolower(trim((string) ($input[$field] ?? '')));
            if ($hash !== '' && preg_match('/^[a-f0-9]{' . $length . '}$/', $hash) !== 1) {
                Http::json(['error' => 'invalid_hash', 'field' => $field], 422);
            }
            $data[$field] = $hash === '' ? null : $hash;
        }
        $data['reply_required'] = !empty($input['reply_required']) ? 1 : 0;
        $data['points_required'] = max(0, min(100000, (int) ($input['points_required'] ?? 0)));
        return $data;
    }

    private function validateDownloadSources(mixed $input, ?int $softwareId): array
    {
        if (!is_array($input) || $input === [] || count($input) > 10) {
            Http::json(['error' => 'invalid_download_sources'], 422);
        }
        $sources = [];
        foreach ($input as $index => $source) {
            if (!is_array($source) || !is_string($source['label'] ?? null)) {
                Http::json(['error' => 'invalid_download_source', 'index' => $index], 422);
            }
            $label = trim($source['label']);
            if (mb_strlen($label) < 2 || mb_strlen($label) > 50) {
                Http::json(['error' => 'invalid_download_source', 'index' => $index], 422);
            }
            $priority = max(1, min(1000, (int) ($source['priority'] ?? (($index + 1) * 10))));
            $type = $source['type'] ?? 'external';
            if ($type === 'direct' && $softwareId !== null) {
                $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $existing = $this->db->prepare('SELECT 1 FROM download_sources WHERE id = ? AND software_id = ? AND type = "direct"');
                $existing->execute([$id, $softwareId]);
                if (!$id || !$existing->fetchColumn()) {
                    Http::json(['error' => 'invalid_download_source', 'index' => $index], 422);
                }
                $sources[] = ['id' => $id, 'type' => 'direct', 'label' => $label, 'priority' => $priority];
                continue;
            }
            $url = is_string($source['url'] ?? null) ? trim($source['url']) : '';
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($type !== 'external' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false ||
                $scheme !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                Http::json(['error' => 'invalid_download_source', 'index' => $index], 422);
            }
            $sources[] = ['type' => 'external', 'label' => $label, 'url' => $url, 'priority' => $priority];
        }
        return $sources;
    }

    private function writeResourceLinks(int $softwareId, array $data): void
    {
        $this->db->prepare('DELETE FROM software_platforms WHERE software_id = ?')->execute([$softwareId]);
        $platformInsert = $this->db->prepare('INSERT INTO software_platforms (software_id, platform_id) VALUES (?, ?)');
        foreach ($data['platform_ids'] as $platformId) {
            $platformInsert->execute([$softwareId, $platformId]);
        }
        $this->db->prepare('DELETE FROM download_sources WHERE software_id = ? AND type = "external"')->execute([$softwareId]);
        $this->db->prepare('UPDATE download_sources SET enabled = 0 WHERE software_id = ? AND type = "direct"')->execute([$softwareId]);
        $sourceInsert = $this->db->prepare('INSERT INTO download_sources (software_id, type, label, url, priority) VALUES (?, "external", ?, ?, ?)');
        $sourceUpdate = $this->db->prepare('UPDATE download_sources SET label = ?, priority = ?, enabled = 1 WHERE id = ? AND software_id = ? AND type = "direct"');
        foreach ($data['sources'] as $source) {
            if ($source['type'] === 'direct') {
                $sourceUpdate->execute([$source['label'], $source['priority'], $source['id'], $softwareId]);
            } else {
                $sourceInsert->execute([$softwareId, $source['label'], $source['url'], $source['priority']]);
            }
        }
    }

    private function recordModeration(int $softwareId, int $actorId, ?string $fromStatus, string $toStatus, string $reason): void
    {
        $this->db->prepare(
            'INSERT INTO moderation_audit (software_id, actor_id, from_status, to_status, reason) VALUES (?, ?, ?, ?, ?)'
        )->execute([$softwareId, $actorId, $fromStatus, $toStatus, $reason]);
    }

    private function awardPublishPoints(int $softwareId, int $authorId): int
    {
        $lock = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$authorId]);
        $existing = $this->db->prepare('SELECT 1 FROM point_rewards WHERE event_type = "publish" AND subject_id = ?');
        $existing->execute([$softwareId]);
        if ($existing->fetchColumn()) {
            return 0;
        }
        $duplicate = $this->db->prepare(
            'SELECT 1 FROM point_rewards pr JOIN software s ON s.id = pr.subject_id
             WHERE pr.user_id = ? AND pr.event_type = "publish" AND pr.points > 0
             AND s.name = (SELECT name FROM software WHERE id = ?) LIMIT 1'
        );
        $duplicate->execute([$authorId, $softwareId]);
        $daily = $this->db->prepare('SELECT COUNT(*) FROM point_rewards WHERE user_id = ? AND event_type = "publish" AND points > 0 AND created_at >= CURDATE()');
        $daily->execute([$authorId]);
        $points = !$duplicate->fetchColumn() && (int) $daily->fetchColumn() < 3 ? 20 : 0;
        $this->db->prepare('INSERT INTO point_rewards (user_id, event_type, subject_id, points) VALUES (?, "publish", ?, ?)')
            ->execute([$authorId, $softwareId, $points]);
        if ($points > 0) {
            $this->db->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$points, $authorId]);
        }
        return $points;
    }

    private function assertAdmin(array $user): void
    {
        if (($user['role'] ?? null) !== 'admin') {
            Http::json(['error' => 'admin_required'], 403);
        }
    }

    private function normalizeTrust(array $software): array
    {
        $sha256 = strtolower((string) ($software['sha256'] ?? ''));
        $complete = preg_match('/^[a-f0-9]{64}$/', $sha256) === 1 &&
            $sha256 !== hash('sha256', '') &&
            trim((string) ($software['trust_file_name'] ?? '')) !== '' &&
            (int) ($software['trust_file_size'] ?? 0) > 0 &&
            trim((string) ($software['trust_source_domain'] ?? '')) !== '' &&
            trim((string) ($software['trust_final_domain'] ?? '')) !== '' &&
            ($software['trust_scan_result'] ?? '') === 'clean' &&
            trim((string) ($software['trust_scan_engine'] ?? '')) !== '' &&
            ($software['trust_scanned_at'] ?? null) !== null &&
            (($software['trust_verified_by'] ?? null) !== null || trim((string) ($software['trust_verification_task'] ?? '')) !== '') &&
            ($software['trust_source_checked_at'] ?? null) !== null &&
            ($software['trust_verified_at'] ?? null) !== null;
        return [
            'status' => $complete ? 'verified' : 'unverified',
            'file_name' => $software['trust_file_name'] ?? null,
            'file_size' => isset($software['trust_file_size']) ? (int) $software['trust_file_size'] : null,
            'md5' => $software['md5'] ?? null,
            'sha256' => $software['sha256'] ?? null,
            'signature_publisher' => $software['trust_signature_publisher'] ?? null,
            'source_domain' => $software['trust_source_domain'] ?? null,
            'final_domain' => $software['trust_final_domain'] ?? null,
            'scan_engine' => $software['trust_scan_engine'] ?? null,
            'scan_result' => $software['trust_scan_result'] ?? 'unknown',
            'scanned_at' => $software['trust_scanned_at'] ?? null,
            'verification_task' => $software['trust_verification_task'] ?? null,
            'verifier' => $software['trust_verifier'] ?? null,
            'verified_at' => $software['trust_verified_at'] ?? null,
            'source_checked_at' => $software['trust_source_checked_at'] ?? null,
        ];
    }

    private function normalizeSummary(array $row): array
    {
        foreach (['id', 'author_id', 'is_vip', 'download_count', 'points_required', 'reply_required', 'reply_count', 'favorite_count'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        $row['views'] = null;
        $row['platforms'] = isset($row['platforms']) && $row['platforms'] !== null ? explode(',', $row['platforms']) : [];
        return $row;
    }

    private function pageResult(array $items, int $page, int $limit, int $total): array
    {
        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
            'has_more' => $page * $limit < $total,
        ];
    }

    private function searchTerm(string $query): string
    {
        return '%' . strtr(mb_substr(trim($query), 0, 100), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
