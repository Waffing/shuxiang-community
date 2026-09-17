<?php
declare(strict_types=1);

use App\Auth;
use App\Database;
use App\DownloadToken;
use App\Http;

require dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user(true);
$sourceId = Http::positiveInt($_GET['source'] ?? null, 'source');
$token = (string) ($_GET['token'] ?? '');
if (!DownloadToken::verify($token, $sourceId, (int) $user['id'])) {
    Http::json(['error' => 'invalid_or_expired_download_token'], 403);
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($referer !== '' && parse_url($referer, PHP_URL_HOST) !== $requestHost) {
    Http::json(['error' => 'invalid_referer'], 403);
}

$statement = Database::connection()->prepare(
    'SELECT ds.file_path, ds.filename, ds.mime_type, s.id software_id
     FROM download_sources ds JOIN software s ON s.id = ds.software_id
     WHERE ds.id = ? AND ds.type = "direct" AND ds.enabled = 1 AND s.status = "published"'
);
$statement->execute([$sourceId]);
$source = $statement->fetch();
if (!$source) {
    Http::json(['error' => 'not_found'], 404);
}

$filePath = (string) $source['file_path'];
if ($filePath === '' || preg_match('#(^/|\\\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$))#', $filePath)) {
    Http::json(['error' => 'invalid_download_path'], 404);
}

header('Content-Type: ' . ($source['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($source['filename']));
header('Cache-Control: private, no-store');
header('X-Accel-Redirect: /protected-downloads/' . implode('/', array_map('rawurlencode', explode('/', $filePath))));
exit;
