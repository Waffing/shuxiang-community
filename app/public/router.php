<?php
declare(strict_types=1);

// Development server routes; production routes are defined by Nginx.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/robots.txt', '/sitemap.xml'], true)) {
    require __DIR__ . '/seo.php';
    return true;
}
if ($path !== '/' && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';
