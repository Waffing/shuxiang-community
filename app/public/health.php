<?php
declare(strict_types=1);

use App\Cache;
use App\Database;
use App\Http;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    Database::connection()->query('SELECT 1');
    $database = true;
} catch (\Throwable) {
    $database = false;
}
$redis = Cache::healthy();
Http::json(['status' => $database && $redis ? 'ok' : 'degraded', 'database' => $database, 'redis' => $redis], $database && $redis ? 200 : 503);
