<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Seo;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /api.php\nDisallow: /download.php\nDisallow: /uploads/\nSitemap: " . Seo::origin() . "/sitemap.xml\n";
} elseif ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo Seo::sitemap();
} else {
    http_response_code(404);
}
