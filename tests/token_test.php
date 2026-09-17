<?php
declare(strict_types=1);

putenv('APP_KEY=test-secret-with-enough-entropy');
require dirname(__DIR__) . '/app/src/bootstrap.php';

use App\DownloadToken;

$token = DownloadToken::issue(12, 34, 60);
assert(DownloadToken::verify($token, 12, 34));
assert(!DownloadToken::verify($token, 13, 34));
assert(!DownloadToken::verify($token, 12, 35));
assert(!DownloadToken::verify($token . 'x', 12, 34));
echo "DownloadToken tests passed\n";
