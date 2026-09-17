<?php
declare(strict_types=1);

namespace App;

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function input(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode((string) file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return $_POST;
    }

    public static function requireMethod(string ...$methods): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, $methods, true)) {
            self::json(['error' => 'method_not_allowed'], 405);
        }
    }

    public static function requireSameOriginForCookieWrite(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ((Auth::usesCookieToken() || $origin !== null) && !self::isSameOrigin($origin)) {
            self::json(['error' => 'invalid_origin'], 403);
        }
    }

    public static function isSecure(): bool
    {
        return in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
    }

    public static function isSameOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '' || !isset($_SERVER['HTTP_HOST'])) {
            return false;
        }
        $scheme = self::isSecure() ? 'https' : 'http';
        $expected = parse_url($scheme . '://' . $_SERVER['HTTP_HOST']);
        $actual = parse_url($origin);
        if (!$expected || !$actual || !isset($actual['scheme'], $actual['host']) ||
            array_intersect(['user', 'pass', 'path', 'query', 'fragment'], array_keys($actual))) {
            return false;
        }
        return strtolower($actual['scheme']) === $scheme &&
            strtolower($actual['host']) === strtolower($expected['host'] ?? '') &&
            ($actual['port'] ?? ($scheme === 'https' ? 443 : 80)) ===
            ($expected['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    public static function positiveInt(mixed $value, string $name): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            self::json(['error' => 'invalid_parameter', 'field' => $name], 422);
        }
        return (int) $number;
    }
}
