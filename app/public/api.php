<?php
declare(strict_types=1);

use App\Auth;
use App\Cache;
use App\ForumService;
use App\Http;
use App\IconService;

require dirname(__DIR__) . '/src/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    Http::requireSameOriginForCookieWrite();
}

$action = (string) ($_GET['action'] ?? 'list');
$client = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$limit = match ($action) {
    'login', 'register', 'publish', 'update_resource' => 10,
    'report', 'dmca' => 6,
    'change_password' => 5,
    'reply' => 30,
    'search', 'list' => 60,
    default => 120,
};
if (!Cache::allow($action, $client, $limit, 60)) {
    Http::json(['error' => 'rate_limit_exceeded'], 429);
}
$service = new ForumService();

switch ($action) {
    case 'list':
    case 'search':
        Http::requireMethod('GET');
        Http::json($service->list($_GET));
    case 'detail':
        Http::requireMethod('GET');
        Http::json($service->detail(Http::positiveInt($_GET['id'] ?? null, 'id'), Auth::user()));
    case 'rankings':
        Http::requireMethod('GET');
        Http::json($service->rankings());
    case 'register':
        Http::requireMethod('POST');
        $input = Http::input();
        Http::json(Auth::register(trim((string) ($input['username'] ?? '')), (string) ($input['password'] ?? '')), 201);
    case 'login':
        Http::requireMethod('POST');
        $input = Http::input();
        Http::json(Auth::login(trim((string) ($input['username'] ?? '')), (string) ($input['password'] ?? '')));
    case 'me':
        Http::requireMethod('GET');
        Http::json(['user' => Auth::user(true)]);
    case 'logout':
        Http::requireMethod('POST');
        Auth::logout();
        Http::json(['logged_out' => true]);
    case 'change_password':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        Http::json(Auth::changePassword($user, (string) ($input['current_password'] ?? ''), (string) ($input['new_password'] ?? '')));
    case 'reply':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        Http::json($service->reply(Http::positiveInt($input['software_id'] ?? null, 'software_id'), $user, (string) ($input['content'] ?? '')), 201);
    case 'checkin':
        Http::requireMethod('POST');
        Http::json($service->checkin(Auth::user(true)));
    case 'publish':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        Http::json($service->publish($user, Http::input()), 201);
    case 'update_resource':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        Http::json($service->updateResource(Http::positiveInt($input['id'] ?? null, 'id'), $user, $input));
    case 'my_resources':
        Http::requireMethod('GET');
        Http::json($service->myResources(Auth::user(true), $_GET));
    case 'favorite_toggle':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        Http::json($service->toggleFavorite(Http::positiveInt($input['software_id'] ?? null, 'software_id'), $user));
    case 'favorite_status':
        Http::requireMethod('GET');
        $user = Auth::user(true);
        Http::json($service->favoriteStatus(Http::positiveInt($_GET['software_id'] ?? null, 'software_id'), $user));
    case 'favorites':
        Http::requireMethod('GET');
        Http::json($service->favorites(Auth::user(true), $_GET));
    case 'profile_dashboard':
        Http::requireMethod('GET');
        Http::json($service->profileDashboard(Auth::user(true)));
    case 'admin_queue':
        Http::requireMethod('GET');
        Http::json($service->adminQueue(Auth::admin(), $_GET));
    case 'admin_resolve':
        Http::requireMethod('POST');
        $user = Auth::admin();
        Http::json($service->resolveAdminQueue($user, Http::input()));
    case 'upload_icon':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $softwareId = Http::positiveInt($_POST['software_id'] ?? null, 'software_id');
        Http::json((new IconService())->upload($softwareId, $user, $_FILES['icon'] ?? []));
    case 'report':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        $reason = (string) ($input['reason'] ?? '');
        if (trim((string) ($input['details'] ?? '')) !== '') {
            $reason .= ': ' . trim((string) $input['details']);
        }
        Http::json($service->report(Http::positiveInt($input['software_id'] ?? null, 'software_id'), $user, $reason), 201);
    case 'dmca':
        Http::requireMethod('POST');
        Http::json($service->dmca(Http::input()), 201);
    case 'agreement':
        Http::requireMethod('GET', 'POST');
        Http::json(['accepted' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST', 'version' => '2026-07-01', 'title' => '使用与下载声明', 'content' => '本站仅提供软件信息与用户提交资源的索引服务。下载、安装和使用前请核验授权、来源与文件哈希；用户应遵守适用法律及软件许可。']);
    case 'download_token':
        Http::requireMethod('POST');
        $user = Auth::user(true);
        $input = Http::input();
        Http::json($service->issueDownload(Http::positiveInt($input['source_id'] ?? null, 'source_id'), $user));
    default:
        Http::json(['error' => 'unknown_action'], 404);
}
