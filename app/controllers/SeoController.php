<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;

/**
 * robots.txt and sitemap.xml, generated so they always use APP_URL
 * and only list what is actually visible in the store.
 */
final class SeoController
{
    /** GET /robots.txt */
    public function robots(): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo "User-agent: *\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /cart\n";
        echo "Disallow: /checkout\n";
        echo "Disallow: /order-success\n";
        echo "Disallow: /wishlist\n";
        echo "Disallow: /health\n";
        echo "Disallow: /webhooks/\n";
        // Search results and re-sorted listings are noindex duplicates
        echo "Disallow: /shop?*q=\n";
        echo "Disallow: /*?*sort=\n";
        echo "\nSitemap: " . absolute_url(url('sitemap.xml')) . "\n";
        exit;
    }

    /** GET /sitemap.xml (with product images, Google image sitemap extension) */
    public function sitemap(): never
    {
        $products = db_all(
            'SELECT p.id, p.slug, p.category_id, p.updated_at
               FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1
              ORDER BY p.id'
        );

        $images = [];
        foreach (db_all(
            'SELECT pi.product_id, pi.image_path
               FROM product_images pi
               JOIN products p ON p.id = pi.product_id AND p.is_active = 1
              ORDER BY pi.product_id, pi.is_primary DESC, pi.sort_order, pi.id'
        ) as $row) {
            if (is_photo($row['image_path'])) {
                $images[(int) $row['product_id']][] = $row['image_path'];
            }
        }

        // lastmod: a listing changes when any product in it changes
        $latest = null;
        $latestByCategory = [];
        foreach ($products as $p) {
            $cat = (int) $p['category_id'];
            $latest = max($latest ?? $p['updated_at'], $p['updated_at']);
            $latestByCategory[$cat] = max($latestByCategory[$cat] ?? $p['updated_at'], $p['updated_at']);
        }

        $urls = [
            ['loc' => url(''), 'lastmod' => $latest],
            ['loc' => url('shop'), 'lastmod' => $latest],
            ['loc' => url('contact'), 'lastmod' => db_value("SELECT MAX(updated_at) FROM settings WHERE setting_key LIKE 'contact!_%' ESCAPE '!'") ?: $latest],
        ];
        foreach (Category::allActive() as $category) {
            $urls[] = ['loc' => url('category/' . $category['slug']), 'lastmod' => $latestByCategory[(int) $category['id']] ?? null];
        }
        foreach ($products as $p) {
            $urls[] = [
                'loc'     => url('product/' . $p['slug']),
                'lastmod' => $p['updated_at'],
                'images'  => $images[(int) $p['id']] ?? [],
            ];
        }

        $x = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $u) {
            echo '  <url><loc>' . $x(absolute_url($u['loc'])) . '</loc>';
            if (!empty($u['lastmod'])) {
                echo '<lastmod>' . date('Y-m-d', strtotime($u['lastmod'])) . '</lastmod>';
            }
            foreach ($u['images'] ?? [] as $path) {
                echo '<image:image><image:loc>' . $x(absolute_url(image_url($path))) . '</image:loc></image:image>';
            }
            echo "</url>\n";
        }
        echo "</urlset>\n";
        exit;
    }
}
