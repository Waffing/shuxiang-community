<?php
declare(strict_types=1);

namespace App;

final class Cache
{
    private static ?\Redis $redis = null;

    private static function connection(): ?\Redis
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        if (self::$redis !== null) {
            return self::$redis;
        }
        try {
            $redis = new \Redis();
            $redis->connect(Config::get('REDIS_HOST', 'redis'), (int) Config::get('REDIS_PORT', '6379'), 0.25);
            $password = Config::get('REDIS_PASSWORD');
            if ($password !== '') {
                $redis->auth($password);
            }
            self::$redis = $redis;
            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function remember(string $key, int $ttl, callable $loader): mixed
    {
        $redis = self::connection();
        if ($redis !== null) {
            try {
                $cached = $redis->get($key);
            } catch (\Throwable) {
                $cached = false;
                $redis = null;
            }
            if (is_string($cached)) {
                $value = json_decode($cached, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $value;
                }
            }
        }
        $value = $loader();
        if ($redis !== null) {
            try {
                $redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable) {
                error_log('Cache write failed');
            }
        }
        return $value;
    }

    public static function forgetPrefix(string $prefix): void
    {
        $redis = self::connection();
        if ($redis === null) {
            return;
        }
        $iterator = null;
        try {
            do {
                $keys = $redis->scan($iterator, $prefix . '*', 100);
                if (is_array($keys) && $keys !== []) {
                    $redis->del($keys);
                }
            } while ($iterator !== 0);
        } catch (\Throwable) {
            error_log('Cache invalidation failed');
        }
    }

    public static function healthy(): bool
    {
        try {
            $redis = self::connection();
            return $redis !== null && $redis->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function allow(string $bucket, string $identity, int $limit, int $windowSeconds): bool
    {
        $redis = self::connection();
        if ($redis === null) {
            return true;
        }
        $key = 'rate:' . $bucket . ':' . hash_hmac('sha256', $identity, Config::require('APP_KEY'));
        $script = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
LUA;
        try {
            $current = $redis->eval($script, [$key, $windowSeconds], 1);
            return is_int($current) && $current <= $limit;
        } catch (\Throwable) {
            return true;
        }
    }
}
