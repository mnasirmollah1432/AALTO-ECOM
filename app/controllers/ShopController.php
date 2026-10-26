<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tracking;

/**
 * Public product listings: /shop and /category/{slug}.
 */
final class ShopController
{
    private const PER_PAGE = 12;

    /** GET /shop?q=&category=&sort=&sale=1&in_stock=1&new=1&page= */
    public function index(): string
    {
        $filters = self::filters($_GET);

        // ?category=slug on its own is better served by the clean category URL
        $category = $filters['category'] !== '' ? Category::findActiveBySlug($filters['category']) : null;
        if ($category !== null && self::onlyCategory($filters)) {
            $page = self::pageParam();
            redirect('category/' . $category['slug'] . ($page > 1 ? '?page=' . $page : ''), 301);
        }

        $result = Product::shop($filters + ['category_id' => $category['id'] ?? null], self::pageParam(), self::PER_PAGE);
        self::redirectIfPastLastPage($result, 'shop');

        $title = $filters['q'] !== '' ? 'Search results for "' . $filters['q'] . '"' : 'Shop all products';
        $isFiltered = $filters['q'] !== '' || $category !== null || $filters['sale'] || $filters['in_stock'] || $filters['new'];

        // Facebook Pixel Search / GTM search, else ViewCategory / view_item_list
        if ($filters['q'] !== '') {
            Tracking::search($filters['q'], $result['items']);
        } else {
            Tracking::listView($category['name'] ?? ($filters['sale'] ? 'Deals' : ($filters['new'] ? 'New arrivals' : 'Shop')), $result['items']);
        }

        return view('pages/shop', [
            'heading'    => $filters['q'] !== '' ? 'Results for "' . $filters['q'] . '"' : ($filters['sale'] ? 'On sale' : ($filters['new'] ? 'New arrivals' : 'Shop')),
            'category'   => $category,
            'filters'    => $filters,
            'result'     => $result,
            'basePath'   => 'shop',
            'categories' => Category::allActive(),
            'meta'       => [
                'title'       => $result['page'] > 1 ? "{$title} - Page {$result['page']}" : $title,
                'description' => 'Browse ' . number_format($result['total']) . ' products at ' . config('app.name') . '. Filter by category, find deals and sort by price.'
                    . ($result['page'] > 1 ? " Page {$result['page']}." : ''),
                'canonical'   => url_with_query('shop', ['page' => $result['page'] > 1 ? $result['page'] : null]),
                'json_ld'     => [breadcrumb_json_ld([['Home', url('')], ['Shop', url('shop')]])],
                // Search/filter combinations are not worth indexing separately
                'robots'      => $isFiltered || $filters['sort'] !== 'featured' ? 'noindex, follow' : 'index, follow',
            ],
        ], 'main');
    }

    /** GET /category/{slug} */
    public function category(string $slug): string
    {
        $category = Category::findActiveBySlug($slug);
        if ($category === null) {
            $lower = strtolower($slug);
            if ($lower !== $slug && Category::findActiveBySlug($lower) !== null) {
                redirect('category/' . rawurlencode($lower), 301);
            }
            abort(404);
        }

        $filters = self::filters($_GET);
        $result = Product::shop($filters + ['category_id' => $category['id']], self::pageParam(), self::PER_PAGE);
        self::redirectIfPastLastPage($result, 'category/' . $category['slug']);

        Tracking::listView($category['name'], $result['items']); // Pixel ViewCategory / GTM view_item_list

        $description = $category['description']
            ?: sprintf('Shop %s at %s: %d products at honest prices.', $category['name'], config('app.name'), $category['product_count']);

        return view('pages/shop', [
            'heading'    => $category['name'],
            'category'   => $category,
            'filters'    => $filters,
            'result'     => $result,
            'basePath'   => 'category/' . $category['slug'],
            'categories' => Category::allActive(),
            'meta'       => [
                'title'       => $result['page'] > 1 ? "{$category['name']} - Page {$result['page']}" : $category['name'],
                'description' => ($result['page'] > 1 ? "Page {$result['page']}: " : '') . $description,
                'canonical'   => url_with_query('category/' . $category['slug'], ['page' => $result['page'] > 1 ? $result['page'] : null]),
                'json_ld'     => [breadcrumb_json_ld([
                    ['Home', url('')],
                    ['Shop', url('shop')],
                    [$category['name'], url('category/' . $category['slug'])],
                ])],
                'image'       => is_photo($category['image_path']) ? absolute_url(image_url($category['image_path'])) : null,
                'robots'      => $filters['q'] !== '' || $filters['sale'] || $filters['in_stock'] || $filters['new'] || $filters['sort'] !== 'featured'
                    ? 'noindex, follow' : 'index, follow',
            ],
        ], 'main');
    }

    /** Only known query keys with valid values. */
    private static function filters(array $input): array
    {
        $str = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($str('q'), 'UTF-8') ? $str('q') : ''));
        $category = $str('category');

        return [
            'q'        => mb_substr($q, 0, 100),
            'category' => is_valid_slug($category) ? $category : '',
            'sort'     => isset(Product::SHOP_SORTS[$str('sort')]) ? $str('sort') : 'featured',
            'sale'     => $str('sale') === '1',
            'in_stock' => $str('in_stock') === '1',
            'new'      => $str('new') === '1',
        ];
    }

    private static function onlyCategory(array $f): bool
    {
        return $f['q'] === '' && !$f['sale'] && !$f['in_stock'] && !$f['new'] && $f['sort'] === 'featured';
    }

    /** ?page=999 on a 3-page listing: send the visitor to the last real page. */
    private static function redirectIfPastLastPage(array $result, string $path): void
    {
        if (self::pageParam() > $result['pages']) {
            $known = array_intersect_key($_GET, array_flip(['q', 'category', 'sort', 'sale', 'in_stock', 'new']));
            $query = array_merge($known, ['page' => $result['pages'] > 1 ? $result['pages'] : null]);
            $query = array_filter($query, static fn ($v) => is_string($v) && $v !== '' || is_int($v));
            redirect($path . ($query ? '?' . http_build_query($query) : ''), 302);
        }
    }

    private static function pageParam(): int
    {
        $page = $_GET['page'] ?? '1';
        return is_string($page) && ctype_digit($page) && strlen($page) < 7 ? max(1, (int) $page) : 1;
    }
}
