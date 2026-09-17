<?php
declare(strict_types=1);

namespace App;

final class Seo
{
    public static function origin(): string
    {
        return 'https://' . Config::get('DOMAIN', 'forum.example.com');
    }

    public static function page(string $uri): array
    {
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $page = ['title' => '数享社区 · 软件资源与使用交流', 'description' => '查找多平台软件，核对来源和验证记录，分享真实使用经验。',
            'canonical' => self::origin() . '/', 'resource' => null, 'platform' => '', 'category' => '', 'not_found' => false];
        if (preg_match('#^/software/([^/]+)$#u', $path, $match)) {
            $query = Database::connection()->prepare('SELECT id, name, slug, version, summary, description, updated_at FROM software WHERE slug = ? AND status = "published" LIMIT 1');
            $query->execute([$match[1]]);
            $resource = $query->fetch();
            if (!$resource) {
                $page['not_found'] = true;
                $page['title'] = '资源不存在 · 数享社区';
            } else {
                $page['resource'] = $resource;
                $page['title'] = $resource['name'] . ' ' . $resource['version'] . ' · 数享社区';
                $page['description'] = $resource['summary'];
                $page['canonical'] = self::origin() . '/software/' . rawurlencode($resource['slug']);
            }
        } elseif (preg_match('#^/(platform|category)/([^/]+)$#u', $path, $match)) {
            if ($match[1] === 'platform') {
                $query = Database::connection()->prepare('SELECT name FROM platforms WHERE slug = ?');
            } else {
                $query = Database::connection()->prepare('SELECT category FROM software WHERE category = ? AND status = "published" LIMIT 1');
            }
            $query->execute([$match[2]]);
            $label = $query->fetchColumn();
            if (!$label) {
                $page['not_found'] = true;
            } else {
                $page[$match[1]] = $match[2];
                $page['title'] = $label . ' 软件资源 · 数享社区';
                $page['description'] = '浏览 ' . $label . ' 软件资源、版本和来源验证记录。';
                $page['canonical'] = self::origin() . '/' . $match[1] . '/' . rawurlencode($match[2]);
            }
        } elseif (!in_array($path, ['/', '/index.php'], true)) {
            $page['not_found'] = true;
        }
        if ($page['not_found']) http_response_code(404);
        return $page;
    }

    public static function sitemap(): string
    {
        $urls = [['path' => '/', 'updated_at' => null]];
        foreach (Database::connection()->query('SELECT slug FROM platforms ORDER BY id') as $row) {
            $urls[] = ['path' => '/platform/' . rawurlencode($row['slug']), 'updated_at' => null];
        }
        foreach (Database::connection()->query('SELECT DISTINCT category FROM software WHERE status = "published" ORDER BY category') as $row) {
            $urls[] = ['path' => '/category/' . rawurlencode($row['category']), 'updated_at' => null];
        }
        foreach (Database::connection()->query('SELECT slug, updated_at FROM software WHERE status = "published" ORDER BY id LIMIT 45000') as $row) {
            $urls[] = ['path' => '/software/' . rawurlencode($row['slug']), 'updated_at' => $row['updated_at']];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>' . htmlspecialchars(self::origin() . $url['path'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if ($url['updated_at']) $xml .= '<lastmod>' . substr($url['updated_at'], 0, 10) . '</lastmod>';
            $xml .= '</url>';
        }
        return $xml . '</urlset>';
    }
}
