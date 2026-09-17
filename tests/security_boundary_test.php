<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';

use App\Auth;
use App\Http;

$_SERVER['HTTP_HOST'] = 'forum.example.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_COOKIE['forum_token'] = 'cookie-token';

assert(Auth::usesCookieToken());
assert(Http::isSameOrigin('https://forum.example.com'));
assert(Http::isSameOrigin('HTTPS://FORUM.EXAMPLE.COM'));
assert(!Http::isSameOrigin(null));
assert(!Http::isSameOrigin('https://other.example'));

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';
assert(!Auth::usesCookieToken());

$icon = file_get_contents(dirname(__DIR__) . '/app/src/IconService.php');
$imageInfoPosition = strpos($icon, 'getimagesize');
$pixelLimitPosition = strpos($icon, '$width * $height');
$decodePosition = strpos($icon, "'image/jpeg' => imagecreatefromjpeg");
assert($imageInfoPosition !== false && $pixelLimitPosition !== false && $decodePosition !== false);
assert($imageInfoPosition < $pixelLimitPosition && $pixelLimitPosition < $decodePosition);

$forum = file_get_contents(dirname(__DIR__) . '/app/src/ForumService.php');
$replyPosition = strpos($forum, 'public function reply');
$lockPosition = strpos($forum, 'SELECT id FROM users WHERE id = ? FOR UPDATE', $replyPosition);
$rewardPosition = strpos($forum, 'SELECT 1 FROM replies WHERE software_id = ? AND user_id = ? LIMIT 1', $replyPosition);
assert($replyPosition !== false && $lockPosition !== false && $rewardPosition !== false);
assert($lockPosition < $rewardPosition);

foreach (['entrypoint.sh', 'forum.example.com.conf'] as $name) {
    $path = $name === 'entrypoint.sh'
        ? dirname(__DIR__) . '/infra/nginx/entrypoint.sh'
        : dirname(__DIR__) . '/infra/nginx/forum.example.com.conf';
    $nginx = file_get_contents($path);
    assert(str_contains($nginx, 'HTTP_AUTHORIZATION'));
    assert(str_contains($nginx, 'fastcgi_param HTTPS'));
    assert(str_contains($nginx, 'limit_req_zone'));
    assert(str_contains($nginx, 'login'));
    assert(str_contains($nginx, 'register'));
    assert(str_contains($nginx, 'publish'));
    assert(str_contains($nginx, 'report'));
    assert(str_contains($nginx, 'dmca'));
}

echo "Security boundary tests passed\n";
