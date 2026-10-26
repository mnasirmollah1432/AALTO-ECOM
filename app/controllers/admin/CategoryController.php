<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ImageUpload;
use App\Models\Category;
use PDOException;

/**
 * Admin category management: list/search, create, edit,
 * activate/deactivate and (safe) delete.
 */
final class CategoryController
{
    use GuardsPosts;

    private const PER_PAGE = 15;

    /** GET /admin/categories */
    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        $filters = self::filters($_GET);
        $result = Category::paginate($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE);

        return view('admin/categories/index', [
            'title'   => 'Categories',
            'admin'   => $admin,
            'filters' => $filters,
            'result'  => $result,
            'counts'  => db_one(
                'SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active FROM categories'
            ),
        ], 'admin');
    }

    /** GET /admin/categories/create */
    public function create(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/categories/form', [
            'title'    => 'Add category',
            'admin'    => $admin,
            'category' => null,
            'errors'   => errors(),
        ], 'admin');
    }

    /** POST /admin/categories/create */
    public function store(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/categories/create');

        [$data, $errors] = self::validate(null);
        if ($errors) {
            self::back('admin/categories/create', $errors);
        }

        if (ImageUpload::hasFile($_FILES['image'] ?? null)) {
            $upload = ImageUpload::store($_FILES['image'], 'category', 'category-' . $data['slug']); // standard: images.types.category
            if (isset($upload['error'])) {
                self::back('admin/categories/create', ['image' => $upload['error']]);
            }
            $data['image_path'] = $upload['path'];
        }

        try {
            $id = Category::create($data);
        } catch (PDOException $e) {
            ImageUpload::delete($data['image_path'] ?? null);
            self::handleDuplicate($e, 'admin/categories/create');
        }

        flash('success', sprintf('Category "%s" was created.', $data['name'])
            . (isset($upload['report']) ? ' Image optimised: ' . ImageUpload::summary($upload['report']) . '.' : ''));
        redirect('admin/categories/edit/' . $id);
    }

    /** GET /admin/categories/edit/{id} */
    public function edit(string $id): string
    {
        $admin = require_admin();
        admin_headers();
        $category = self::findOrBack($id);

        return view('admin/categories/form', [
            'title'    => 'Edit category',
            'admin'    => $admin,
            'category' => $category,
            'errors'   => errors(),
        ], 'admin');
    }

    /** POST /admin/categories/edit/{id} */
    public function update(string $id): never
    {
        require_admin();
        admin_headers();
        $category = self::findOrBack($id);
        $id = (int) $category['id'];
        $formUrl = 'admin/categories/edit/' . $id;

        self::guardPost($formUrl);

        [$data, $errors] = self::validate($id);
        if ($errors) {
            self::back($formUrl, $errors);
        }

        $oldImage = $category['image_path'];
        $newImage = null;

        if (ImageUpload::hasFile($_FILES['image'] ?? null)) {
            $upload = ImageUpload::store($_FILES['image'], 'category', 'category-' . $data['slug']); // standard: images.types.category
            if (isset($upload['error'])) {
                self::back($formUrl, ['image' => $upload['error']]);
            }
            $data['image_path'] = $newImage = $upload['path'];
        } elseif (!empty($_POST['remove_image'])) {
            $data['image_path'] = null;
        }

        try {
            Category::update($id, $data);
        } catch (PDOException $e) {
            ImageUpload::delete($newImage);
            self::handleDuplicate($e, $formUrl);
        }

        // Old file is removed only after the database no longer points at it
        if (array_key_exists('image_path', $data) && $oldImage !== $data['image_path']) {
            ImageUpload::delete($oldImage);
        }

        flash('success', sprintf('Category "%s" was updated.', $data['name'])
            . (isset($upload['report']) ? ' Image optimised: ' . ImageUpload::summary($upload['report']) . '.' : ''));
        redirect($formUrl);
    }

    /** POST /admin/categories/toggle/{id} */
    public function toggle(string $id): never
    {
        require_admin();
        admin_headers();
        $back = self::listUrl($_POST['return'] ?? '');
        self::guardPost($back);

        $category = self::findOrBack($id);
        $active = !(bool) $category['is_active'];
        Category::setActive((int) $category['id'], $active);

        flash('success', $active
            ? sprintf('"%s" is now active and visible in the store.', $category['name'])
            : sprintf('"%s" is now inactive and hidden from the store.', $category['name']));
        redirect($back);
    }

    /** POST /admin/categories/delete/{id} */
    public function destroy(string $id): never
    {
        require_admin();
        admin_headers();
        $back = self::listUrl($_POST['return'] ?? '');
        self::guardPost($back);

        $category = self::findOrBack($id);

        $blocked = self::deleteBlockedReason($category);
        if ($blocked !== null) {
            flash('error', $blocked);
            redirect($back);
        }

        try {
            Category::delete((int) $category['id']);
        } catch (PDOException $e) {
            // A product was assigned between the check and the delete
            if ($e->getCode() === '23000') {
                flash('error', sprintf('"%s" is in use and cannot be deleted. Deactivate it instead.', $category['name']));
                redirect($back);
            }
            throw $e;
        }

        ImageUpload::delete($category['image_path']);

        flash('success', sprintf('Category "%s" was deleted.', $category['name']));
        redirect($back);
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    /** Why a category can't be deleted, or null when it's safe. */
    public static function deleteBlockedReason(array $category): ?string
    {
        $products = (int) $category['product_count'];
        if ($products > 0) {
            return sprintf(
                '"%s" cannot be deleted because it has %d product%s. Move or delete %s first, or deactivate the category instead.',
                $category['name'],
                $products,
                $products === 1 ? '' : 's',
                $products === 1 ? 'it' : 'them'
            );
        }
        if ((int) ($category['child_count'] ?? 0) > 0) {
            return sprintf('"%s" cannot be deleted because other categories are inside it.', $category['name']);
        }
        return null;
    }

    /**
     * Validate the text fields of the form.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    private static function validate(?int $id): array
    {
        $errors = self::invalidEncoding(['name', 'slug', 'description']);

        // Name: trimmed, inner whitespace collapsed
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if (isset($errors['name'])) {
            // already reported
        } elseif ($name === '') {
            $errors['name'] = 'Enter a category name.';
        } elseif (mb_strlen($name) > Category::NAME_MAX) {
            $errors['name'] = sprintf('Name must be %d characters or fewer.', Category::NAME_MAX);
        } elseif (!preg_match('/[\p{L}\p{N}]/u', $name) || preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
            $errors['name'] = 'Name must contain letters or numbers and no control characters.';
        } elseif (Category::nameExists($name, $id)) {
            $errors['name'] = 'A category with this name already exists.';
        }

        // Slug: optional; typed slugs are normalised, blank ones generated from the name
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        if (isset($errors['slug'])) {
            $slug = '';
        } elseif ($slugInput === '') {
            $slug = isset($errors['name']) ? '' : Category::uniqueSlug(slugify($name, Category::SLUG_MAX), $id);
        } else {
            $slug = slugify($slugInput, Category::SLUG_MAX);
            if ($slug === '' || !is_valid_slug($slug)) {
                $errors['slug'] = 'Use only letters, numbers and hyphens in the slug.';
            } elseif (Category::slugExists($slug, $id)) {
                $errors['slug'] = sprintf('The slug "%s" is already used by another category.', $slug);
            }
        }

        // Description: optional, length-limited, normalised line endings
        $description = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['description'] ?? '')));
        if (isset($errors['description'])) {
            // already reported
        } elseif (mb_strlen($description) > Category::DESCRIPTION_MAX) {
            $errors['description'] = sprintf(
                'Description must be %d characters or fewer (currently %d).',
                Category::DESCRIPTION_MAX,
                mb_strlen($description)
            );
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)) {
            $errors['description'] = 'Description contains invalid characters.';
        }

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Choose a status.';
        }

        $data = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description === '' ? null : $description,
            'is_active'   => $status === 'active' ? 1 : 0,
        ];

        return [$data, $errors];
    }

    /** Redirect back to the form with errors and the submitted text. */
    private static function back(string $url, array $errors): never
    {
        with_input([
            'name'         => mb_substr((string) ($_POST['name'] ?? ''), 0, 500),
            'slug'         => mb_substr((string) ($_POST['slug'] ?? ''), 0, 500),
            'description'  => mb_substr((string) ($_POST['description'] ?? ''), 0, Category::DESCRIPTION_MAX + 500),
            'status'       => (string) ($_POST['status'] ?? ''),
            'remove_image' => !empty($_POST['remove_image']) ? '1' : '',
        ], $errors);

        if (isset($errors['image']) || ImageUpload::hasFile($_FILES['image'] ?? null)) {
            flash('warning', 'Please fix the errors below. If you chose an image, select it again.');
        } else {
            flash('error', 'Please fix the errors below.');
        }
        redirect($url);
    }

    /** Unique-key race between our check and the write. */
    private static function handleDuplicate(PDOException $e, string $url): never
    {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        $field = str_contains($e->getMessage(), 'uq_categories_slug') ? 'slug' : 'name';
        self::back($url, [$field => $field === 'slug'
            ? 'This slug is already used by another category.'
            : 'A category with this name already exists.']);
    }

    private static function findOrBack(string $id): array
    {
        $category = ctype_digit($id) && (int) $id > 0 ? Category::find((int) $id) : null;
        if ($category === null) {
            flash('error', 'That category no longer exists.');
            redirect('admin/categories');
        }
        return $category;
    }

    /** Only known filter keys with valid values. */
    private static function filters(array $input): array
    {
        $q = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100) : '';
        $status = in_array($input['status'] ?? '', ['active', 'inactive'], true) ? $input['status'] : '';
        $sort = isset(Category::SORTS[$input['sort'] ?? '']) ? $input['sort'] : 'name';

        return ['q' => $q, 'status' => $status, 'sort' => $sort];
    }

    /** List URL rebuilt from a submitted query string (whitelisted keys only). */
    private static function listUrl(mixed $return): string
    {
        parse_str(is_string($return) ? $return : '', $params);
        $query = array_filter(self::filters($params) + [
            'page' => max(1, (int) ($params['page'] ?? 1)),
        ], static fn ($v) => $v !== '' && $v !== 'name' && $v !== 1);

        return 'admin/categories' . ($query ? '?' . http_build_query($query) : '');
    }
}
