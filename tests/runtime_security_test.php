<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/src/bootstrap.php';
restore_exception_handler();

use App\Http;
use App\Cache;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_SERVER['HTTP_HOST'] = 'localhost:8787';
$_SERVER['HTTPS'] = '';
check(Http::isSameOrigin('http://localhost:8787'), 'Empty HTTPS must represent HTTP');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'forum.example.com:443';
check(Http::isSameOrigin('https://forum.example.com'), 'Default HTTPS port must normalize');
check(!Http::isSameOrigin('https://forum.example.com.evil.test'), 'Suffix origin must be rejected');
check(!Http::isSameOrigin('https://forum.example.com/path'), 'Origins must not have a path');
check(!Http::isSameOrigin('null'), 'Opaque origins must be rejected');
check(!Http::isSameOrigin('http://forum.example.com'), 'Scheme mismatch must be rejected');
check(!Http::isSameOrigin('https://forum.example.com:8443'), 'Port mismatch must be rejected');

putenv('REDIS_HOST=127.0.0.1');
putenv('REDIS_PORT=1');
check(!Cache::healthy(), 'Disconnected Redis must report unhealthy');
echo "Runtime security tests passed\n";
