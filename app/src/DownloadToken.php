<?php
declare(strict_types=1);

namespace App;

final class DownloadToken
{
    public static function issue(int $sourceId, int $userId, int $ttl = 300): string
    {
        $expires = time() + $ttl;
        $payload = $sourceId . ':' . $userId . ':' . $expires;
        $signature = hash_hmac('sha256', $payload, Config::require('APP_KEY'));
        return self::base64UrlEncode($payload . ':' . $signature);
    }

    public static function verify(string $token, int $sourceId, int $userId): bool
    {
        $decoded = self::base64UrlDecode($token);
        if ($decoded === false) {
            return false;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 4) {
            return false;
        }
        [$tokenSource, $tokenUser, $expires, $signature] = $parts;
        if ((int) $tokenSource !== $sourceId || (int) $tokenUser !== $userId || (int) $expires < time()) {
            return false;
        }
        $payload = $tokenSource . ':' . $tokenUser . ':' . $expires;
        return hash_equals(hash_hmac('sha256', $payload, Config::require('APP_KEY')), $signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
