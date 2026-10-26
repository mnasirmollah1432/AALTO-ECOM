<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Money;
use App\Models\Cart;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tracking;

/**
 * Public product page: /product/{slug}
 */
final class ProductController
{
    public function show(string $slug): string
    {
        $product = Product::findActiveBySlug($slug);
        if ($product === null) {
            abort(404);
        }
        // The DB collation is case-insensitive; keep one URL per product
        if ($product['slug'] !== $slug) {
            redirect('product/' . rawurlencode($product['slug']), 301);
        }

        $images = Product::images((int) $product['id']);
        $url = url('product/' . $product['slug']);
        // Real photos only (not the placeholder) for search engines and social previews
        $photos = array_values(array_filter(array_column($images, 'image_path'), 'is_photo'));
        $mainImage = $photos ? absolute_url(image_url($photos[0])) : null;
        $inStock = (int) $product['stock_quantity'] > 0;

        $description = $product['short_description']
            ?: str_limit((string) ($product['description'] ?: $product['name'] . ' at ' . config('app.name')), 155);

        // schema.org structured data (rich results in search engines)
        $currency = (string) config('app.store.currency', 'BDT');
        $country = (string) config('app.store.country', 'BD');
        $onSale = Money::isOnSale($product['regular_price'], $product['sale_price']);
        $jsonLd = [
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $product['name'],
                'url'         => absolute_url($url),
                'sku'         => $product['sku'],
                'description' => $description,
                // Google needs at least one image for product results; omit rather than send []
                'image'       => array_map(static fn ($p) => absolute_url(image_url($p)), $photos) ?: null,
                'category'    => $product['category_name'],
                'offers'      => array_filter([
                    '@type'              => 'Offer',
                    'url'                => absolute_url($url),
                    'priceCurrency'      => $currency,
                    'price'              => $product['price'], // DECIMAL string, e.g. "2499.00"
                    // On sale: the regular price is shown struck through
                    'priceSpecification' => $onSale ? [
                        '@type'         => 'UnitPriceSpecification',
                        'priceType'     => 'https://schema.org/StrikethroughPrice',
                        'price'         => $product['regular_price'],
                        'priceCurrency' => $currency,
                    ] : null,
                    'availability'       => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'itemCondition'      => 'https://schema.org/NewCondition',
                    'seller'             => ['@type' => 'Organization', 'name' => (string) config('app.name')],
                    'shippingDetails'    => [
                        '@type'               => 'OfferShippingDetails',
                        'shippingRate'        => [
                            '@type'    => 'MonetaryAmount',
                            // same rule as checkout: free from the threshold, else the cheapest zone charge
                            'value'    => Money::fromCents(Cart::deliveryFor(Money::toCents($product['price']), Money::toCents(Location::chargeRange()[0]))),
                            'currency' => $currency,
                        ],
                        'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type'                => 'MerchantReturnPolicy',
                        'applicableCountry'    => $country,
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays'   => (int) config('app.store.return_days', 7),
                    ],
                ], static fn ($v) => $v !== null),
            ],
            breadcrumb_json_ld([
                ['Home', url('')],
                [$product['category_name'], url('category/' . $product['category_slug'])],
                [$product['name'], $url],
            ]),
        ];
        $jsonLd[0] = array_filter($jsonLd[0], static fn ($v) => $v !== null);

        // Facebook Pixel ViewContent / GTM view_item
        Tracking::add(Tracking::event('view_item', [[
            'id' => $product['sku'], 'name' => $product['name'], 'price_cents' => Money::toCents($product['price']),
            'quantity' => 1, 'category' => $product['category_name'],
        ]], Money::toCents($product['price'])));

        return view('pages/product', [
            'product'  => $product,
            'images'   => $images,
            'options'  => Product::options($product),
            'related'  => Product::related((int) $product['id'], (int) $product['category_id'], 4),
            'onSale'   => $onSale,
            'discount' => Money::discountPercent($product['regular_price'], $product['sale_price']),
            'meta'     => [
                'title'       => $product['name'],
                'description' => $description,
                'canonical'   => $url,
                'image'       => $mainImage,
                'image_alt'   => $product['name'],
                'type'        => 'product',
                'json_ld'     => $jsonLd,
                'product'     => [
                    'price'        => $product['price'],
                    'currency'     => (string) config('app.store.currency', 'BDT'),
                    'availability' => $inStock ? 'in stock' : 'out of stock',
                ],
            ],
        ], 'main');
    }
}
