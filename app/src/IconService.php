<?php
declare(strict_types=1);

namespace App;

final class IconService
{
    public function upload(int $softwareId, array $user, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            Http::json(['error' => 'invalid_upload'], 422);
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            Http::json(['error' => 'invalid_file_size'], 422);
        }

        $owner = Database::connection()->prepare('SELECT author_id FROM software WHERE id = ? LIMIT 1');
        $owner->execute([$softwareId]);
        $authorId = $owner->fetchColumn();
        if ($authorId === false) {
            Http::json(['error' => 'not_found'], 404);
        }
        if ((int) $authorId !== (int) $user['id'] && $user['role'] !== 'admin') {
            Http::json(['error' => 'forbidden'], 403);
        }

        $imageInfo = getimagesize((string) $file['tmp_name']);
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 32 || $height < 32 || $width > 8000 || $height > 8000 || $width * $height > 16000000) {
            Http::json(['error' => 'invalid_image_dimensions'], 422);
        }
        $mime = class_exists(\finfo::class)
            ? (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name'])
            : ($imageInfo['mime'] ?? null);
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg((string) $file['tmp_name']),
            'image/png' => imagecreatefrompng((string) $file['tmp_name']),
            'image/webp' => imagecreatefromwebp((string) $file['tmp_name']),
            default => false,
        };
        if ($source === false) {
            Http::json(['error' => 'unsupported_image'], 422);
        }

        $side = min($width, $height);
        $target = imagecreatetruecolor(256, 256);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefill($target, 0, 0, $transparent);
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), 256, 256, $side, $side);

        $directory = dirname(__DIR__) . '/public/uploads/icons';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($source);
            imagedestroy($target);
            throw new \RuntimeException('Unable to create icon directory');
        }
        $filename = 'software-' . $softwareId . '-' . bin2hex(random_bytes(6)) . '.webp';
        $absolutePath = $directory . '/' . $filename;
        if (!imagewebp($target, $absolutePath, 84)) {
            imagedestroy($source);
            imagedestroy($target);
            throw new \RuntimeException('Unable to encode WebP icon');
        }
        imagedestroy($source);
        imagedestroy($target);

        $publicPath = '/uploads/icons/' . $filename;
        $query = Database::connection()->prepare('SELECT icon_path, status FROM software WHERE id = ? FOR UPDATE');
        Database::connection()->beginTransaction();
        try {
            $query->execute([$softwareId]);
            $row = $query->fetch();
            $previous = $row['icon_path'];
            Database::connection()->prepare('UPDATE software SET icon_path = ? WHERE id = ?')->execute([$publicPath, $softwareId]);
            if ($user['role'] !== 'admin' && $row['status'] === 'published') {
                Database::connection()->prepare('UPDATE software SET status = "draft", moderation_reason = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?')->execute([$softwareId]);
                Database::connection()->prepare('INSERT INTO moderation_audit (software_id, actor_id, from_status, to_status, reason) VALUES (?, ?, "published", "draft", ?)')
                    ->execute([$softwareId, $user['id'], '作者更新图标，重新审核']);
            }
            Database::connection()->commit();
        } catch (\Throwable $error) {
            Database::connection()->rollBack();
            @unlink($absolutePath);
            throw $error;
        }
        if (is_string($previous) && preg_match('#^/uploads/icons/([a-zA-Z0-9.-]+\.webp)$#', $previous, $match) === 1) {
            @unlink($directory . '/' . $match[1]);
        }
        Cache::forgetPrefix('software:');
        return ['icon_path' => $publicPath, 'width' => 256, 'height' => 256, 'format' => 'webp'];
    }
}
