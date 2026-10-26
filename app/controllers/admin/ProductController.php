<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ImageUpload;
use App\Core\Money;
use App\Models\Product;
use PDOException;

/**
 * Admin product management: list/search/filter, create, edit,
 * active/featured/new toggles, images and (safe) delete.
 */
final class ProductController
{
    use GuardsPosts;

    private const PER_PAGE = 20;

    /** Optimisation reports of the images stored in this request (shown after saving). */
    private static array $imageReports = [];

    /** GET /admin/products */
    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        $filters = self::filters($_GET);

        return view('admin/products/index', [
            'title'      => 'Products',
            'admin'      => $admin,
            'filters'    => $filters,
            'result'     => Product::paginate($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE),
            'categories' => Product::categoryOptions(),
            'counts'     => db_one(
                'SELECT COUNT(*) AS total,
                        COALESCE(SUM(is_active = 1), 0) AS active,
                        COALESCE(SUM(stock_quantity = 0), 0) AS out_of_stock
                   FROM products'
            ),
        ], 'admin');
    }

    /** GET /admin/products/create */
    public function create(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/products/form', [
            'title'      => 'Add product',
            'admin'      => $admin,
            'product'    => null,
            'images'     => [],
            'variants'   => [],
            'categories' => Product::categoryOptions(),
            'errors'     => errors(),
        ], 'admin');
    }

    /** POST /admin/products/create */
    public function store(): never
    {
        require_admin();
        admin_headers();
        $formUrl = 'admin/products/create';
        self::guardPost($formUrl);

        [$data, $errors] = self::validate(null);
        if ($errors) {
            self::back($formUrl, $errors);
        }
        if ($data['sku'] === '') {
            $data['sku'] = Product::generateSku();
        }

        $main = self::uploadMain($formUrl, $data['slug']);
        $extras = self::uploadExtras($formUrl, 0, $main !== null ? [$main] : [], $data['slug']);
        $newFiles = array_merge($main !== null ? [$main] : [], $extras);

        try {
            $id = db_transaction(static function () use ($data, $main, $extras): int {
                $id = Product::create($data);
                self::saveOptions($id, $data['options']);
                $sort = 0;
                if ($main !== null) {
                    Product::addImage($id, $main, $data['name'], true, $sort++);
                }
                foreach ($extras as $i => $path) {
                    // Without a main image the first extra becomes the main one
                    Product::addImage($id, $path, $data['name'], $main === null && $i === 0, $sort++);
                }
                return $id;
            });
        } catch (PDOException $e) {
            array_map([ImageUpload::class, 'delete'], $newFiles);
            self::handleDuplicate($e, $formUrl);
        }

        flash('success', sprintf('Product "%s" was created.', $data['name']) . self::imageSummary());
        redirect('admin/products/edit/' . $id);
    }

    /** GET /admin/products/edit/{id} */
    public function edit(string $id): string
    {
        $admin = require_admin();
        admin_headers();
        $product = self::findOrBack($id);

        return view('admin/products/form', [
            'title'      => 'Edit product',
            'admin'      => $admin,
            'product'    => $product,
            'images'     => Product::images((int) $product['id']),
            'variants'   => Product::variants((int) $product['id']),
            'categories' => Product::categoryOptions(),
            'errors'     => errors(),
        ], 'admin');
    }

    /** POST /admin/products/edit/{id} */
    public function update(string $id): never
    {
        require_admin();
        admin_headers();
        $product = self::findOrBack($id);
        $id = (int) $product['id'];
        $formUrl = 'admin/products/edit/' . $id;
        self::guardPost($formUrl);

        [$data, $errors] = self::validate($id);
        if ($errors) {
            self::back($formUrl, $errors);
        }
        if ($data['sku'] === '') {
            $data['sku'] = $product['sku']; // blank keeps the current SKU
        }

        // Existing images, and which ones the admin asked to remove
        $images = Product::images($id);
        $byId = array_column($images, null, 'id');
        $remove = array_values(array_intersect(
            array_map('intval', (array) ($_POST['remove_images'] ?? [])),
            array_keys($byId)
        ));
        $currentMain = $images && $images[0]['is_primary'] ? (int) $images[0]['id'] : null;

        $main = self::uploadMain($formUrl, $data['slug']);
        if ($main !== null && $currentMain !== null && !in_array($currentMain, $remove, true)) {
            $remove[] = $currentMain; // a new main image replaces the old one
        }

        $remainingExtras = count(array_diff(array_keys($byId), $remove, [$currentMain]));
        $extras = self::uploadExtras($formUrl, $remainingExtras, $main !== null ? [$main] : [], $data['slug']);
        $newFiles = array_merge($main !== null ? [$main] : [], $extras);

        // "Replace" on an existing image: new file, same place in the gallery
        $replaced = self::uploadReplacements($formUrl, $byId, $remove, $newFiles, $data['slug'], $currentMain);
        $newFiles = array_merge($newFiles, array_values($replaced));

        $chosenMain = (int) ($_POST['primary_image'] ?? 0);
        $chosenMain = isset($byId[$chosenMain]) && !in_array($chosenMain, $remove, true) ? $chosenMain : null;

        try {
            db_transaction(static function () use ($id, $data, $remove, $main, $extras, $chosenMain, $images, $replaced): void {
                Product::update($id, $data);
                self::saveOptions($id, $data['options']);
                Product::deleteImages($id, $remove);
                foreach ($replaced as $imageId => $path) {
                    Product::replaceImagePath($id, (int) $imageId, $path);
                }

                $sort = $images ? max(array_column($images, 'sort_order')) + 1 : 0;
                $mainId = $main !== null ? Product::addImage($id, $main, $data['name'], false, 0) : null;
                foreach ($extras as $path) {
                    Product::addImage($id, $path, $data['name'], false, $sort++);
                }

                // New upload wins, then the admin's choice, else keep/promote
                Product::setPrimaryImage($id, $mainId ?? $chosenMain ?? self::keptPrimary($images, $remove));
            });
        } catch (PDOException $e) {
            array_map([ImageUpload::class, 'delete'], $newFiles);
            self::handleDuplicate($e, $formUrl);
        }

        // Files are removed only after the database no longer points at them
        foreach (array_merge($remove, array_keys($replaced)) as $imageId) {
            ImageUpload::delete($byId[$imageId]['image_path']);
        }

        flash('success', sprintf('Product "%s" was updated.', $data['name']) . self::imageSummary());
        redirect($formUrl);
    }

    /**
     * POST /admin/products/toggle/{id}  (flag = active | featured | new)
     * Answers JSON when asked (list page updates in place), else redirects.
     */
    public function toggle(string $id): never
    {
        require_admin();
        admin_headers();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $back = self::listUrl($_POST['return'] ?? '');

        $fail = static function (string $message, int $status) use ($wantsJson, $back): never {
            if ($wantsJson) {
                self::json(['ok' => false, 'message' => $message], $status);
            }
            flash('error', $message);
            redirect($back);
        };

        if (!csrf_valid()) {
            $fail('Your session expired or the form was out of date. Please refresh the page.', 403); // not 419: Apache turns unregistered codes into 500
        }

        $flag = (string) ($_POST['flag'] ?? '');
        if (!isset(Product::FLAGS[$flag])) {
            $fail('Unknown setting.', 422);
        }

        $productId = self::routeId($id);
        $product = $productId ? Product::find($productId) : null;
        if ($product === null) {
            $fail('That product no longer exists.', 404);
        }

        $column = Product::FLAGS[$flag];
        $on = !(bool) $product[$column];
        Product::setFlag($productId, $flag, $on);

        $labels = ['active' => ['active', 'inactive'], 'featured' => ['featured', 'not featured'], 'new' => ['marked as new', 'no longer marked as new']];
        $message = sprintf('"%s" is now %s.', $product['name'], $labels[$flag][$on ? 0 : 1]);

        if ($wantsJson) {
            self::json(['ok' => true, 'flag' => $flag, 'value' => $on, 'message' => $message]);
        }
        flash('success', $message);
        redirect($back);
    }

    /** POST /admin/products/delete/{id} */
    public function destroy(string $id): never
    {
        require_admin();
        admin_headers();
        $back = self::listUrl($_POST['return'] ?? '');
        self::guardPost($back);

        $product = self::findOrBack($id);
        $blocked = self::deleteBlockedReason($product);
        if ($blocked !== null) {
            flash('error', $blocked);
            redirect($back);
        }

        $images = Product::images((int) $product['id']);
        try {
            Product::delete((int) $product['id']);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', sprintf('"%s" is in use and cannot be deleted. Deactivate it instead.', $product['name']));
                redirect($back);
            }
            throw $e;
        }

        foreach ($images as $image) {
            ImageUpload::delete($image['image_path']);
        }

        flash('success', sprintf('Product "%s" was deleted.', $product['name']));
        redirect($back);
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    /** Why a product can't be deleted, or null when it's safe. */
    public static function deleteBlockedReason(array $product): ?string
    {
        $orders = (int) ($product['order_count'] ?? 0);
        if ($orders > 0) {
            return sprintf(
                '"%s" cannot be deleted because it appears in %d order line%s. Deactivate it instead to hide it from the store.',
                $product['name'],
                $orders,
                $orders === 1 ? '' : 's'
            );
        }
        return null;
    }

    /**
     * Validate all text/number fields.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    private static function validate(?int $id): array
    {
        $errors = self::invalidEncoding(['name', 'slug', 'sku', 'short_description', 'description', 'regular_price', 'sale_price', 'stock_quantity']);

        // Name
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if (isset($errors['name'])) {
            // already reported
        } elseif ($name === '') {
            $errors['name'] = 'Enter a product name.';
        } elseif (mb_strlen($name) > Product::NAME_MAX) {
            $errors['name'] = sprintf('Name must be %d characters or fewer.', Product::NAME_MAX);
        } elseif (!preg_match('/[\p{L}\p{N}]/u', $name) || preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
            $errors['name'] = 'Name must contain letters or numbers and no control characters.';
        }

        // Slug: optional; typed slugs are normalised, blank ones generated
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = '';
        if (isset($errors['slug'])) {
            // already reported
        } elseif ($slugInput === '') {
            if (!isset($errors['name'])) {
                $slug = Product::uniqueSlug(slugify($name, Product::SLUG_MAX), $id);
            }
        } else {
            $slug = slugify($slugInput, Product::SLUG_MAX);
            if ($slug === '' || !is_valid_slug($slug)) {
                $errors['slug'] = 'Use only letters, numbers and hyphens in the slug.';
            } elseif (Product::slugExists($slug, $id)) {
                $errors['slug'] = sprintf('The slug "%s" is already used by another product.', $slug);
            }
        }

        // SKU: optional (generated on create, kept on edit)
        $sku = trim((string) ($_POST['sku'] ?? ''));
        if (!isset($errors['sku']) && $sku !== '') {
            if (strlen($sku) > Product::SKU_MAX || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $sku)) {
                $errors['sku'] = sprintf('SKU may use letters, numbers, dots, dashes and underscores (max %d).', Product::SKU_MAX);
            } elseif (Product::skuExists($sku, $id)) {
                $errors['sku'] = sprintf('The SKU "%s" is already used by another product.', $sku);
            }
        }

        // Category
        $categoryId = (string) ($_POST['category_id'] ?? '');
        if ($categoryId === '' || !ctype_digit($categoryId) || !Product::categoryExists((int) $categoryId)) {
            $errors['category_id'] = 'Choose a category.';
        }

        // Descriptions
        $short = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['short_description'] ?? '')));
        if (!isset($errors['short_description']) && mb_strlen($short) > Product::SHORT_MAX) {
            $errors['short_description'] = sprintf('Short description must be %d characters or fewer (currently %d).', Product::SHORT_MAX, mb_strlen($short));
        }
        $description = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['description'] ?? '')));
        if (isset($errors['description'])) {
            // already reported
        } elseif (mb_strlen($description) > Product::DESCRIPTION_MAX) {
            $errors['description'] = sprintf('Description must be %s characters or fewer (currently %s).', number_format(Product::DESCRIPTION_MAX), number_format(mb_strlen($description)));
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)) {
            $errors['description'] = 'Description contains invalid characters.';
        }

        // Prices: parsed to exact DECIMAL strings, compared in integer cents
        $regularInput = trim((string) ($_POST['regular_price'] ?? ''));
        $regular = Money::parse($regularInput);
        if (isset($errors['regular_price'])) {
            // already reported
        } elseif ($regularInput === '') {
            $errors['regular_price'] = 'Enter the regular price.';
        } elseif (str_starts_with($regularInput, '-')) {
            $errors['regular_price'] = 'Price cannot be negative.';
        } elseif ($regular === null) {
            $errors['regular_price'] = 'Enter a price like 1299 or 1299.50 (up to 9,999,999.99).';
        } elseif (Money::toCents($regular) <= 0) {
            $errors['regular_price'] = 'Regular price must be more than 0.';
        }

        $saleInput = trim((string) ($_POST['sale_price'] ?? ''));
        $sale = null;
        if (!isset($errors['sale_price']) && $saleInput !== '') {
            $sale = Money::parse($saleInput);
            if (str_starts_with($saleInput, '-')) {
                $errors['sale_price'] = 'Sale price cannot be negative.';
            } elseif ($sale === null) {
                $errors['sale_price'] = 'Enter a sale price like 999 or 999.50, or leave it empty.';
            } elseif (Money::toCents($sale) === 0) {
                $sale = null; // 0 means "no sale"
            } elseif ($regular !== null && Money::toCents($sale) >= Money::toCents($regular)) {
                $errors['sale_price'] = 'Sale price must be lower than the regular price (or leave it empty).';
            }
        }

        // Size / colour options (stock per option replaces the single stock field)
        [$options, $optionErrors] = self::validateOptions();
        $errors += $optionErrors;
        $usesOptions = $options['has_sizes'] || $options['has_colours'];

        // Stock: whole number, never negative
        $stockInput = trim((string) ($_POST['stock_quantity'] ?? ''));
        if ($usesOptions) {
            unset($errors['stock_quantity']); // not used: the total of the option stock is stored
        } elseif (isset($errors['stock_quantity'])) {
            // already reported
        } elseif ($stockInput === '') {
            $errors['stock_quantity'] = 'Enter the stock quantity (0 if none).';
        } elseif (preg_match('/^-\s*\d/', $stockInput)) {
            $errors['stock_quantity'] = 'Stock cannot be negative.';
        } elseif (!ctype_digit($stockInput)) {
            $errors['stock_quantity'] = 'Stock must be a whole number.';
        } elseif (strlen(ltrim($stockInput, '0')) > 7 || (int) $stockInput > Product::STOCK_MAX) {
            $errors['stock_quantity'] = sprintf('Stock cannot be more than %s.', number_format(Product::STOCK_MAX));
        }

        $data = [
            'name'              => $name,
            'slug'              => $slug,
            'sku'               => $sku,
            'category_id'       => (int) $categoryId,
            'short_description' => $short === '' ? null : $short,
            'description'       => $description === '' ? null : $description,
            'regular_price'     => $regular,
            'sale_price'        => $sale,
            'stock_quantity'    => $usesOptions ? $options['total'] : (ctype_digit($stockInput) ? (int) $stockInput : 0),
            'is_active'         => !empty($_POST['is_active']) ? 1 : 0,
            'is_featured'       => !empty($_POST['is_featured']) ? 1 : 0,
            'is_new'            => !empty($_POST['is_new']) ? 1 : 0,
            'options'           => $options, // saved by saveOptions(), not a products column
        ];

        return [$data, $errors];
    }

    /**
     * Size / colour options from the form:
     *   has_sizes, has_colours      on/off switches
     *   sizes                       "S, M, L" (comma separated, in display order)
     *   colour_names[] + colour_hexes[]
     *   variant_stock[K1][K2]       K1 = bin2hex(size) or "_", K2 = bin2hex(colour) or "_"
     * Keys are the hex of the names, so stock follows its size/colour even
     * when the admin reorders or deletes other values.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    private static function validateOptions(): array
    {
        $errors = [];
        $hasSizes = !empty($_POST['has_sizes']);
        $hasColours = !empty($_POST['has_colours']);

        $clean = static function (mixed $value): ?string {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                return null;
            }
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        };
        $validName = static fn (string $v): bool => mb_strlen($v) <= Product::OPTION_MAX
            && preg_match('/[\p{L}\p{N}]/u', $v) && !preg_match('/[\p{Cc}\p{Cf}<>|]/u', $v);

        // Sizes
        $sizes = [];
        if ($hasSizes) {
            $sizesInput = $clean($_POST['sizes'] ?? '');
            if ($sizesInput === null) {
                $errors['sizes'] = 'Sizes contain invalid characters.';
            } else {
                foreach (explode(',', $sizesInput) as $size) {
                    $size = trim($size);
                    if ($size === '') {
                        continue;
                    }
                    if (!$validName($size)) {
                        $errors['sizes'] = sprintf('"%s" is not a valid size. Use letters or numbers, up to %d characters.', mb_substr($size, 0, 60), Product::OPTION_MAX);
                        break;
                    }
                    $sizes[mb_strtolower($size)] ??= $size; // no duplicates (case-insensitive)
                }
            }
            $sizes = array_values($sizes);
            if (!isset($errors['sizes']) && !$sizes) {
                $errors['sizes'] = 'Add at least one size, e.g. S, M, L, XL - or switch sizes off.';
            } elseif (count($sizes) > Product::OPTION_VALUES_MAX) {
                $errors['sizes'] = sprintf('A product can have up to %d sizes.', Product::OPTION_VALUES_MAX);
            }
        }

        // Colours
        $colours = [];
        if ($hasColours) {
            $names = array_values((array) ($_POST['colour_names'] ?? []));
            $hexes = array_values((array) ($_POST['colour_hexes'] ?? []));
            foreach ($names as $i => $name) {
                $name = $clean($name);
                if ($name === null) {
                    $errors['colours'] = 'Colours contain invalid characters.';
                    break;
                }
                if ($name === '') {
                    continue;
                }
                if (!$validName($name)) {
                    $errors['colours'] = sprintf('"%s" is not a valid colour name. Use letters or numbers, up to %d characters.', mb_substr($name, 0, 60), Product::OPTION_MAX);
                    break;
                }
                $hex = is_string($hexes[$i] ?? null) ? strtolower(trim($hexes[$i])) : '';
                $colours[mb_strtolower($name)] ??= ['name' => $name, 'hex' => preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : null];
            }
            $colours = array_values($colours);
            if (!isset($errors['colours']) && !$colours) {
                $errors['colours'] = 'Add at least one colour - or switch colours off.';
            } elseif (count($colours) > Product::OPTION_VALUES_MAX) {
                $errors['colours'] = sprintf('A product can have up to %d colours.', Product::OPTION_VALUES_MAX);
            }
        }

        // Stock of every combination (blank = 0)
        $stock = [];
        $total = 0;
        if ($hasSizes || $hasColours) {
            $grid = is_array($_POST['variant_stock'] ?? null) ? $_POST['variant_stock'] : [];
            foreach ($hasSizes ? $sizes : [''] as $size) {
                foreach ($hasColours ? array_column($colours, 'name') : [''] as $colour) {
                    $row = $grid[$size === '' ? '_' : bin2hex($size)] ?? [];
                    $raw = is_array($row) ? ($row[$colour === '' ? '_' : bin2hex($colour)] ?? '') : '';
                    $raw = is_string($raw) ? trim($raw) : 'x';
                    if ($raw !== '' && (!ctype_digit($raw) || strlen(ltrim($raw, '0')) > 7 || (int) $raw > Product::STOCK_MAX)) {
                        $label = implode(' / ', array_filter([$size, $colour], 'strlen'));
                        $errors['variant_stock'] ??= sprintf('Stock for "%s" must be a whole number from 0 to %s.', $label, number_format(Product::STOCK_MAX));
                        continue;
                    }
                    $stock[$size][$colour] = (int) $raw;
                    $total += (int) $raw;
                }
            }
            if ($total > Product::STOCK_MAX) {
                $errors['variant_stock'] ??= sprintf('Total stock cannot be more than %s.', number_format(Product::STOCK_MAX));
            }
        }

        return [[
            'has_sizes'   => $hasSizes,
            'has_colours' => $hasColours,
            'sizes'       => $sizes,
            'colours'     => $colours,
            'stock'       => $stock,
            'total'       => $total,
        ], $errors];
    }

    private static function saveOptions(int $productId, array $options): void
    {
        Product::saveVariants($productId, $options['has_sizes'], $options['has_colours'], $options['sizes'], $options['colours'], $options['stock']);
    }

    /** Store the main image if one was chosen (standard: images.types.product). Returns its path or null. */
    private static function uploadMain(string $formUrl, string $slug): ?string
    {
        if (!ImageUpload::hasFile($_FILES['main_image'] ?? null)) {
            return null;
        }
        $result = ImageUpload::store($_FILES['main_image'], 'product', 'product-' . $slug . '-main');
        if (isset($result['error'])) {
            self::back($formUrl, ['main_image' => $result['error']]);
        }
        self::$imageReports[] = $result['report'];
        return $result['path'];
    }

    /**
     * Store additional images, respecting the per-product limit.
     * On any failure every file stored in this request is removed.
     *
     * @param list<string> $alreadyStored files from this request to clean up on failure
     * @return list<string>
     */
    private static function uploadExtras(string $formUrl, int $existingExtras, array $alreadyStored, string $slug): array
    {
        $files = ImageUpload::normalizeMultiple($_FILES['images'] ?? null);
        if (!$files) {
            return [];
        }

        $room = Product::MAX_EXTRA_IMAGES - $existingExtras;
        if (count($files) > $room) {
            array_map([ImageUpload::class, 'delete'], $alreadyStored);
            self::back($formUrl, ['images' => sprintf(
                'A product can have up to %d additional images. You can add %d more.',
                Product::MAX_EXTRA_IMAGES,
                max(0, $room)
            )]);
        }

        $stored = [];
        foreach (array_values($files) as $i => $file) {
            // product-<slug>-01, -02 ... numbered after the images already there (standard: images.types.gallery)
            $stem = sprintf('product-%s-%02d', $slug, $existingExtras + $i + 1);
            $result = ImageUpload::store($file, 'gallery', $stem);
            if (isset($result['error'])) {
                array_map([ImageUpload::class, 'delete'], array_merge($alreadyStored, $stored));
                self::back($formUrl, ['images' => sprintf('"%s": %s', mb_substr((string) $file['name'], 0, 80), $result['error'])]);
            }
            self::$imageReports[] = $result['report'];
            $stored[] = $result['path'];
        }
        return $stored;
    }

    /**
     * Replacement files for existing images (name="replace_images[ID]"), processed
     * with the same standard as the image they replace. Images being removed are
     * skipped. On any failure every file stored in this request is removed.
     *
     * @return array<int, string> image id => new path
     */
    private static function uploadReplacements(string $formUrl, array $byId, array $remove, array $alreadyStored, string $slug, ?int $currentMain): array
    {
        $stored = [];
        foreach (ImageUpload::normalizeMultiple($_FILES['replace_images'] ?? null) as $imageId => $file) {
            $imageId = (int) $imageId;
            if (!isset($byId[$imageId]) || in_array($imageId, $remove, true)) {
                continue;
            }
            $isMain = $imageId === $currentMain;
            $stem = sprintf('product-%s-%s', $slug, $isMain ? 'main' : sprintf('%02d', max(1, (int) $byId[$imageId]['sort_order'])));
            $result = ImageUpload::store($file, $isMain ? 'product' : 'gallery', $stem);
            if (isset($result['error'])) {
                array_map([ImageUpload::class, 'delete'], array_merge($alreadyStored, array_values($stored)));
                self::back($formUrl, ['images' => sprintf('Replacement "%s": %s', mb_substr((string) $file['name'], 0, 80), $result['error'])]);
            }
            self::$imageReports[] = $result['report'];
            $stored[$imageId] = $result['path'];
        }
        return $stored;
    }

    /** " Images optimised: ..." for the success message, or ''. */
    private static function imageSummary(): string
    {
        return self::$imageReports
            ? ' Images optimised: ' . implode('; ', array_map([ImageUpload::class, 'summary'], self::$imageReports)) . '.'
            : '';
    }

    /** The current main image if it survives, else null (first remaining gets promoted). */
    private static function keptPrimary(array $images, array $removed): ?int
    {
        foreach ($images as $image) {
            if ($image['is_primary'] && !in_array((int) $image['id'], $removed, true)) {
                return (int) $image['id'];
            }
        }
        return null;
    }

    /** Redirect back to the form with errors and the submitted text. */
    private static function back(string $url, array $errors): never
    {
        $keep = [];
        foreach (['name', 'slug', 'sku', 'category_id', 'short_description', 'description', 'regular_price', 'sale_price', 'stock_quantity'] as $field) {
            $keep[$field] = mb_substr((string) (is_string($_POST[$field] ?? null) ? $_POST[$field] : ''), 0, Product::DESCRIPTION_MAX + 500);
        }
        foreach (['is_active', 'is_featured', 'is_new'] as $flag) {
            $keep[$flag] = !empty($_POST[$flag]) ? '1' : '0';
        }
        // Options as typed (the form rebuilds them from this JSON)
        $keep['has_sizes'] = !empty($_POST['has_sizes']) ? '1' : '0';
        $keep['has_colours'] = !empty($_POST['has_colours']) ? '1' : '0';
        $strings = static fn (mixed $list): array => array_slice(array_values(array_filter((array) $list, 'is_string')), 0, 50);
        $keep['options_json'] = (string) json_encode([
            'sizes' => is_string($_POST['sizes'] ?? null) ? mb_substr($_POST['sizes'], 0, 2000) : '',
            'names' => $strings($_POST['colour_names'] ?? []),
            'hexes' => $strings($_POST['colour_hexes'] ?? []),
            'stock' => is_array($_POST['variant_stock'] ?? null) ? $_POST['variant_stock'] : [],
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $keep['_submitted'] = '1';
        with_input($keep, $errors);

        $hadFiles = ImageUpload::hasFile($_FILES['main_image'] ?? null) || ImageUpload::normalizeMultiple($_FILES['images'] ?? null)
            || ImageUpload::normalizeMultiple($_FILES['replace_images'] ?? null);
        flash($hadFiles ? 'warning' : 'error', $hadFiles
            ? 'Please fix the errors below. Images were not saved - select them again.'
            : 'Please fix the errors below.');
        redirect($url);
    }

    private static function handleDuplicate(PDOException $e, string $url): never
    {
        $message = $e->getMessage();
        // CHECK violations report SQLSTATE HY000 (MySQL) / 23000 (MariaDB)
        if (str_contains($message, 'chk_products')) {
            self::back($url, ['stock_quantity' => 'Stock and prices cannot be negative.']);
        }
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        if (str_contains($message, 'uq_products_sku')) {
            self::back($url, ['sku' => 'This SKU is already used by another product.']);
        }
        if (str_contains($message, 'uq_products_slug')) {
            self::back($url, ['slug' => 'This slug is already used by another product.']);
        }
        throw $e;
    }

    private static function findOrBack(string $id): array
    {
        $productId = self::routeId($id);
        $product = $productId ? Product::find($productId) : null;
        if ($product === null) {
            flash('error', 'That product no longer exists.');
            redirect('admin/products');
        }
        return $product;
    }

    /** Only known filter keys with valid values. */
    private static function filters(array $input): array
    {
        $str = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $category = $str('category');

        return [
            'q'        => mb_substr($str('q'), 0, 100),
            'category' => ctype_digit($category) && (int) $category > 0 ? (int) $category : '',
            'status'   => in_array($str('status'), ['active', 'inactive'], true) ? $str('status') : '',
            'flag'     => in_array($str('flag'), ['featured', 'new', 'sale'], true) ? $str('flag') : '',
            'stock'    => in_array($str('stock'), ['in', 'low', 'out'], true) ? $str('stock') : '',
            'sort'     => isset(Product::SORTS[$str('sort')]) ? $str('sort') : 'newest',
        ];
    }

    /** List URL rebuilt from a submitted query string (whitelisted keys only). */
    private static function listUrl(mixed $return): string
    {
        parse_str(is_string($return) ? $return : '', $params);
        $query = self::filters($params);
        $query['page'] = max(1, (int) ($params['page'] ?? 1));
        $query = array_filter($query, static fn ($v, $k) => $v !== '' && !($k === 'sort' && $v === 'newest') && !($k === 'page' && $v === 1), ARRAY_FILTER_USE_BOTH);

        return 'admin/products' . ($query ? '?' . http_build_query($query) : '');
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
}
