<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static ?array $fileValues = null;

    public static function get(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }
        if (self::$fileValues === null) {
            $file = dirname(__DIR__, 2) . '/.env';
            self::$fileValues = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
        }
        return array_key_exists($name, self::$fileValues) ? (string) self::$fileValues[$name] : (string) $default;
    }

    public static function require(string $name): string
    {
        $value = self::get($name);
        if ($value === '') {
            throw new \RuntimeException("Missing environment variable: {$name}");
        }
        return $value;
    }
}
