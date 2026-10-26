<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Slide;
use App\Models\Tracking;

final class HomeController
{
    public function index(): string
    {
        $site = (string) config('app.name');
        $categories = Category::allActive();

        // "Shop Fashion, Electronics and Home & Kitchen at Aalto Mart. ..." from the live categories
        $names = array_column(array_slice($categories, 0, 4), 'name');
        $shopWhat = count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names)
            : ($names[0] ?? 'everyday essentials');
        $description = "Shop {$shopWhat} at {$site}. Cash on delivery"
            . (($freeFrom = free_delivery_from()) !== null ? ', free delivery over ' . format_price($freeFrom) : ', delivery all over Bangladesh')
            . ' and ' . (int) config('app.store.return_days', 7) . '-day easy returns.';

        // Site name and store details for search engines
        $store = array_filter([
            '@context'  => 'https://schema.org',
            '@type'     => 'OnlineStore',
            'name'      => $site,
            'url'       => absolute_url(url('')),
            'logo'      => absolute_url(asset('images/logo-512.png')),
            'email'     => config('app.store.email') ?: null,
            'telephone' => config('app.store.phone') ?: null,
        ]);

        $featured = Product::featured(8);
        $newArrivals = Product::newArrivals(8);
        // Facebook Pixel ViewCategory / GTM view_item_list for the products on the home page
        Tracking::listView('Home', array_values(array_column(array_merge($featured, $newArrivals), null, 'id')));

        return view('pages/home', [
            'categories'  => $categories,
            'banners'     => Slide::forHome(),
            'featured'    => $featured,
            'newArrivals' => $newArrivals,
            'maxDiscount' => Product::maxDiscountPercent(),
            'meta'        => [
                'full_title'  => $site . ' - Everyday Style, Thoughtfully Priced',
                'description' => $description,
                'canonical'   => url(''),
                'json_ld'     => [
                    ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $site, 'url' => absolute_url(url(''))],
                    $store,
                ],
            ],
        ], 'main');
    }
}
