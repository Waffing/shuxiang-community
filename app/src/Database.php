<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', 'db'),
            Config::get('DB_PORT', '3306'),
            Config::get('DB_DATABASE', 'resource_forum')
        );

        self::$connection = new PDO($dsn, Config::require('DB_USERNAME'), Config::require('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
        ]);
        self::$connection->exec("SET time_zone = '+08:00'");
        return self::$connection;
    }
}
