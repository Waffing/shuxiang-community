<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';
restore_exception_handler();

use App\ForumService;
use App\Config;
use App\Database;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testDatabase(): PDO
{
    check(str_contains(Config::get('DB_DATABASE'), 'test'), 'Runtime checks require an isolated database with test in its name');
    return Database::connection();
}

if (($argv[1] ?? '') === '--case') {
    $db = testDatabase();
    $service = new ForumService();
    $query = $db->prepare('SELECT * FROM users WHERE id = ?');
    $query->execute([(int) $argv[4]]);
    $user = $query->fetch();
    register_shutdown_function(static function (): void {
        echo "\nHTTP_STATUS=" . (http_response_code() ?: 200) . "\n";
    });
    $id = (int) $argv[3];
    $result = match ($argv[2]) {
        'preview' => $service->detail($id, null),
        'edit' => $service->updateResource($id, $user, []),
        'reply' => $service->reply($id, $user, '这是有效的测试回复'),
        'report' => $service->report($id, $user, '测试资源链接失效'),
        'download' => $service->issueDownload($id, $user),
        'review' => $service->resolveAdminQueue($user, ['queue' => 'software', 'id' => $id, 'status' => 'published']),
        'bad-page' => $service->list(['page' => ['1']]),
        'bad-limit' => $service->list(['limit' => ['10']]),
        'long-query' => $service->list(['q' => str_repeat('中', 101)]),
        'bad-platform' => $service->list(['platform' => ['unsupported']]),
        'many-platforms' => $service->list(['platform' => array_fill(0, 6, 'android')]),
        'http-source' => (new ReflectionMethod(ForumService::class, 'validateDownloadSources'))->invoke($service, [['label' => '测试下载源', 'url' => 'http://downloads.example.org/test.zip']], null),
        default => throw new RuntimeException('Unknown test case'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}

function childCase(string $case, int $id, int $userId): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--case', $case, (string) $id, (string) $userId], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    check(is_resource($process), 'The child test process must start');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Child case failed: ' . $case . ' ' . $error);
    check(preg_match('/HTTP_STATUS=(\d+)/', $output, $match) === 1, 'Missing child response status');
    return [(int) $match[1], json_decode(trim(explode('HTTP_STATUS=', $output)[0]), true, 512, JSON_THROW_ON_ERROR)];
}

$reflection = new ReflectionClass(ForumService::class);
$service = $reflection->newInstanceWithoutConstructor();
$search = $reflection->getMethod('searchTerm');
check($search->invoke($service, '中文') === '%中文%', 'Chinese search must match literal substrings');
check($search->invoke($service, '100%_!') === '%100!%!_!!%', 'LIKE wildcards must be escaped');

$page = $reflection->getMethod('pageResult');
check($page->invoke($service, [], 2, 12, 24) === [
    'items' => [], 'page' => 2, 'limit' => 12, 'total' => 24, 'total_pages' => 2, 'has_more' => false,
], 'Exact final page must not advertise another page');
check($page->invoke($service, [], 1, 12, 0)['total_pages'] === 0, 'Empty results must have zero total pages');
check($page->invoke($service, [], 1, 12, 13)['has_more'] === true, 'A real next page must be advertised');

$source = file_get_contents(dirname(__DIR__) . '/app/src/ForumService.php');
check(!str_contains($source, 'MATCH(s.name'), 'Search must not depend on the CJK fulltext tokenizer');
check(str_contains($source, 'INTERVAL 6 DAY'), 'Weekly counts include today and the previous six dates');
check(str_contains($source, 'public function updateResource'), 'Authors need an update endpoint');
check(str_contains($source, 'public function myResources'), 'Authors need their moderation status');
check(str_contains($source, 'INSERT INTO moderation_audit'), 'Moderation changes need an audit record');
$schema = file_get_contents(dirname(__DIR__) . '/app/database/schema.sql');
$migration = file_get_contents(dirname(__DIR__) . '/app/database/migrations/20260917_content_moderation.sql');
foreach (['moderation_reason', 'reviewed_by', 'reviewed_at', 'moderation_audit'] as $field) {
    check(str_contains($schema, $field) && str_contains($migration, $field), 'Schema and upgrade must include ' . $field);
}
echo "Backend release focused checks passed\n";

if (($argv[1] ?? '') !== '--database') {
    exit(0);
}

$db = testDatabase();
$service = new ForumService();
$prefix = '审核测试' . bin2hex(random_bytes(4));
$userIds = [];
$resourceIds = [];
try {
    foreach (['user', 'user', 'admin'] as $index => $role) {
        $insert = $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['test_' . bin2hex(random_bytes(5)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
        $userIds[] = (int) $db->lastInsertId();
    }
    $query = $db->prepare('SELECT * FROM users WHERE id = ?');
    $query->execute([$userIds[0]]);
    $author = $query->fetch();
    $query->execute([$userIds[1]]);
    $other = $query->fetch();
    $query->execute([$userIds[2]]);
    $admin = $query->fetch();
    $input = [
        'name' => $prefix . '中文100%_!', 'version' => '1.0.0', 'summary' => '这是用于隔离数据库测试的资源概要内容',
        'description' => '这是用于隔离数据库测试的完整说明内容，仅供自动测试使用，测试结束后会清理。',
        'category' => '开发工具', 'platforms' => ['windows', 'android'], 'changelog' => ['首次测试发布'],
        'download_sources' => [['label' => '测试下载源', 'url' => 'https://downloads.example.org/test.zip']],
        'archive_password' => 'local-fixture', 'md5' => hash('md5', 'fixture'), 'sha256' => hash('sha256', 'fixture'),
    ];
    $created = $service->publish($author, $input);
    $id = $resourceIds[] = $created['id'];
    check($created['status'] === 'draft' && $created['points_awarded'] === 0, 'User submissions must wait for approval without rewards');
    check($service->list(['q' => $prefix])['total'] === 0, 'Drafts must not enter public lists');
    $preview = $service->detail($id, $author);
    check($preview['can_edit'] && $preview['status'] === 'draft' && count($preview['edit_sources']) === 1, 'Author needs a complete editable preview');
    check(childCase('preview', $id, $userIds[0])[0] === 404, 'Anonymous draft preview must be 404');
    check(childCase('edit', $id, $userIds[1])[0] === 403, 'Other authors must be rejected before input validation');
    check(childCase('review', $id, $userIds[0])[0] === 403, 'Ordinary users must not moderate');
    check(childCase('reply', $id, $userIds[0])[0] === 404, 'Hidden resources must not generate replies or points');
    check(childCase('report', $id, $userIds[0])[0] === 404, 'Hidden resources must not accept reports');
    check($service->myResources($author, ['limit' => 1])['has_more'] === false, 'An exact final author page must end');
    $queue = $service->adminQueue($admin, ['queue' => 'software', 'status' => 'draft']);
    check(in_array($id, array_column($queue['items'], 'id'), true) && $queue['total'] >= 1, 'Draft must enter the moderation queue');

    try {
        $service->resolveAdminQueue(['id' => PHP_INT_MAX, 'role' => 'admin'], ['queue' => 'software', 'id' => $id, 'status' => 'published']);
        throw new RuntimeException('Invalid audit actor must fail the transaction');
    } catch (PDOException) {
        check($service->detail($id, $author)['status'] === 'draft', 'A failed audit insert must roll back moderation');
    }
    $approved = $service->resolveAdminQueue($admin, ['queue' => 'software', 'id' => $id, 'status' => 'published']);
    check($approved['points_awarded'] === 20, 'First successful approval awards publication points');
    check($service->resolveAdminQueue($admin, ['queue' => 'software', 'id' => $id, 'status' => 'published'])['points_awarded'] === 0, 'Repeated approval must not award twice');
    $list = $service->list(['q' => $prefix . '中文100%_!', 'limit' => 1]);
    check($list['total'] === 1 && $list['total_pages'] === 1 && !$list['has_more'], 'Chinese and literal wildcard search must return exact totals');
    check($service->list(['q' => $prefix, 'platform' => ['linux']])['total'] === 0, 'Platform filtering must agree with total');
    check($service->list(['q' => $prefix, 'category' => '开发工具'])['total'] === 1, 'Exact category filtering must find its resources');
    check($service->list(['q' => $prefix, 'category' => '开发'])['total'] === 0, 'Category filters must not match partial names');
    foreach (['bad-page', 'bad-limit', 'long-query', 'bad-platform', 'many-platforms'] as $case) {
        check(childCase($case, $id, $userIds[0])[0] === 422, 'Malformed search filters must be rejected: ' . $case);
    }
    check(childCase('http-source', $id, $userIds[0])[0] === 422, 'New and edited external sources must use HTTPS');
    $detail = $service->detail($id, null);
    check($detail['download_sources'][0]['url'] === null && !isset($detail['edit_sources']), 'Public detail must not expose the unmetered external URL');
    check($detail['views'] === null && $detail['trust_status'] === 'unverified', 'Missing measurement and verification must stay unknown');
    check($service->reply($id, $author, '有效的首次测试回复')['points_awarded'] === 1, 'First reply awards one point');
    check($service->reply($id, $author, '第二次测试不重复奖励')['points_awarded'] === 0, 'Repeated replies must not reward again');
    check($service->toggleFavorite($id, $author)['favorited'], 'Favorite creation must succeed');
    $detail = $service->detail($id, null);
    check($detail['reply_count'] === 2 && count($detail['replies']) === 2 && $detail['favorite_count'] === 1, 'Detail counts must come from stored records');
    check($service->favorites($author, ['limit' => 1])['total'] === 1 && !$service->favorites($author, ['limit' => 1])['has_more'], 'Favorite pagination must terminate accurately');
    $sourceId = (int) $detail['download_sources'][0]['id'];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        check($service->issueDownload($sourceId, $author)['type'] === 'external', 'Authorized external download must resolve');
    }
    check(childCase('download', $sourceId, $userIds[0])[0] === 429, 'Sixth ordinary download must exceed quota');
    $detail = $service->detail($id, null);
    check($detail['download_count'] === 5, 'Rejected downloads must not increment counters');
    $stats = $db->prepare('SELECT SUM(count) FROM daily_download_stats WHERE software_id = ?');
    $stats->execute([$id]);
    check((int) $stats->fetchColumn() === 5, 'Weekly and total downloads must share recorded events');

    $db->prepare('INSERT INTO resource_verifications (software_id, file_name, file_size, source_domain, final_domain, scan_engine, scan_result, scanned_at, verified_by, verified_at, source_checked_at) VALUES (?, "fixture.zip", 10, "example.org", "example.org", "test-engine", "clean", NOW(), ?, NOW(), NOW())')->execute([$id, $userIds[2]]);
    App\Cache::forgetPrefix('software:');
    check($service->detail($id, null)['trust_status'] === 'verified', 'Complete synthetic verification must be recognized');
    check($service->list(['q' => $prefix])['items'][0]['trust_status'] === 'verified', 'List and detail must agree on structured verification');
    $input['version'] = '1.0.1';
    $updated = $service->updateResource($id, $author, $input);
    check($updated['status'] === 'draft' && $updated['points_awarded'] === 0, 'Author edits must require review again');
    check($service->detail($id, $author)['trust_status'] === 'unverified', 'Edited files must invalidate old verification');
    check($service->list(['q' => $prefix])['total'] === 0, 'Edited drafts must leave public cached lists');
    check($service->resolveAdminQueue($admin, ['queue' => 'software', 'id' => $id, 'status' => 'published'])['points_awarded'] === 0, 'Reapproval of an edit must not award again');
    $service->resolveAdminQueue($admin, ['queue' => 'software', 'id' => $id, 'status' => 'disabled', 'reason' => '测试下架原因']);
    check($service->detail($id, $author)['moderation_reason'] === '测试下架原因', 'The author must see the moderation reason');
    check(childCase('preview', $id, $userIds[0])[0] === 404, 'Disabled resources must leave public detail');
    $audit = $db->prepare('SELECT COUNT(*) FROM moderation_audit WHERE software_id = ?');
    $audit->execute([$id]);
    check((int) $audit->fetchColumn() === 5, 'Publication, approval, update, reapproval, and removal must be audited exactly once');
    echo "Backend release database checks passed\n";
} finally {
    foreach ($resourceIds as $id) {
        $db->prepare('DELETE FROM software WHERE id = ?')->execute([$id]);
    }
    foreach ($userIds as $id) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    App\Cache::forgetPrefix('software:');
}
