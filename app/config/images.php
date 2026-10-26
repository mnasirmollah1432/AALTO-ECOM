<?php

use App\Core\Env;

/**
 * Image standards: the single source of truth for every image slot.
 *
 * Used by the upload pipeline (App\Core\ImageUpload), the admin help text and
 * live previews (partials/image-spec.php + admin.js), and storefront <img> sizes.
 * Change a standard here and every part of the site follows.
 *
 * Per type:
 *   width / height  output size, and the size we recommend uploading. Every upload is
 *                   centre-cropped to this ratio (never stretched) and scaled down to
 *                   fit. Smaller sources are never scaled up.
 *   min_width/height  smallest usable source *after* cropping; anything smaller would
 *                   look blurred where the image is shown, so it is refused.
 *   target_kb       optimisation goal. WebP quality is lowered step by step until the
 *                   file fits, but never below quality[1]: detail beats a few KB.
 *   quality         [start, minimum] WebP quality (0-100).
 *   fit             'cover' = centre-crop to the ratio; 'contain' = keep the whole image
 *                   (logos), scaled to fit inside width x height.
 *   formats         output formats shown in the admin help text.
 *   thumb           optional smaller "-sm" copy [width, height] for listings (srcset).
 *   upload          false = standard reserved for a future upload field; there is no such
 *                   field in the admin today (e.g. the homepage hero is built with CSS).
 *   used_for        where the image appears; the reason for the chosen size.
 */
return [
    // Largest file accepted from the browser. The stored result is optimised far below
    // this; the limit only protects the server. 8 MB fits normal phone photos.
    'max_upload_kb' => (int) Env::get('UPLOAD_IMAGE_MAX_KB', 8192),

    // Sources larger than this (width x height) are refused: decoding needs ~4 bytes/pixel.
    'max_pixels' => 16_000_000,

    // A source within 2% of the target ratio counts as "correct ratio" (no visible crop).
    'ratio_tolerance' => 0.02,

    'types' => [
        'category' => [
            'label'      => 'Category image',
            'folder'     => 'categories',
            'width'      => 320,
            'height'     => 320,
            'ratio'      => '1:1',
            'min_width'  => 160,
            'min_height' => 160,
            'target_kb'  => 20,
            'quality'    => [82, 62],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => null,
            'upload'     => true,
            'used_for'   => 'Homepage category cards (about 150-220 px wide, 1:1) and the category page header (64 px circle).',
        ],
        'product' => [
            'label'      => 'Product main image',
            'folder'     => 'products',
            'width'      => 800,
            'height'     => 1000,
            'ratio'      => '4:5',
            'min_width'  => 400,
            'min_height' => 500,
            'target_kb'  => 100,
            'quality'    => [85, 70],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => [480, 600],
            'upload'     => true,
            'used_for'   => 'Product cards (4:5, up to ~300 px wide) and the product page gallery (4:5, up to ~600 px wide; 800 px covers high-density screens).',
        ],
        'gallery' => [
            'label'      => 'Product gallery image',
            'folder'     => 'products',
            'width'      => 800,
            'height'     => 1000,
            'ratio'      => '4:5',
            'min_width'  => 400,
            'min_height' => 500,
            'target_kb'  => 100,
            'quality'    => [85, 70],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => [480, 600],
            'upload'     => true,
            'used_for'   => 'Product page gallery (same frame as the main image) and gallery thumbnails.',
        ],
        'hero' => [
            'label'      => 'Homepage hero / banner',
            'folder'     => 'banners',
            'width'      => 1920,
            'height'     => 700,
            'ratio'      => '1920:700',
            'min_width'  => 1280,
            'min_height' => 467,
            'target_kb'  => 150,
            'quality'    => [82, 68],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => [960, 350],
            'upload'     => false,
            'used_for'   => 'Full-width homepage banner (reserved: the current hero is text + CSS tiles, no image upload).',
        ],
        'slide' => [
            'label'      => 'Homepage slider image',
            'folder'     => 'banners',
            'width'      => 1500,
            'height'     => 600,
            'ratio'      => '5:2',
            'min_width'  => 1000,
            'min_height' => 400,
            'target_kb'  => 140,
            'quality'    => [84, 70],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => [750, 300],
            'upload'     => true,
            'used_for'   => 'Homepage slider (Admin -> Home slider): full width on phones, two thirds of the width on large screens. Keep text and faces away from the edges.',
        ],
        'side_banner' => [
            'label'      => 'Homepage side banner',
            'folder'     => 'banners',
            'width'      => 750,
            'height'     => 600,
            'ratio'      => '5:4',
            'min_width'  => 500,
            'min_height' => 400,
            'target_kb'  => 90,
            'quality'    => [84, 70],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => null,
            'upload'     => true,
            'used_for'   => 'Banner beside the slider on large screens (same height as the slider); hidden on phones.',
        ],
        'promo' => [
            'label'      => 'Promotional banner',
            'folder'     => 'banners',
            'width'      => 1200,
            'height'     => 500,
            'ratio'      => '12:5',
            'min_width'  => 800,
            'min_height' => 333,
            'target_kb'  => 100,
            'quality'    => [82, 68],
            'fit'        => 'cover',
            'formats'    => 'WebP',
            'thumb'      => null,
            'upload'     => false,
            'used_for'   => 'Promotion strip on the homepage (reserved: the current promos are CSS, no image upload).',
        ],
        'logo' => [
            'label'      => 'Logo',
            'folder'     => 'branding',
            'width'      => 400,
            'height'     => 120,
            'ratio'      => '10:3',
            'min_width'  => 200,
            'min_height' => 60,
            'target_kb'  => 50,
            'quality'    => [90, 80],
            'fit'        => 'contain',
            'formats'    => 'WebP or PNG',
            'thumb'      => null,
            'upload'     => false,
            'used_for'   => 'Header logo (reserved: the current logo is a text wordmark, no image upload).',
        ],
    ],
];
