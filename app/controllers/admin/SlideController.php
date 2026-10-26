<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ImageUpload;
use App\Models\Slide;

/**
 * Admin -> Home slider: slider images and the side banner of the homepage.
 * Images follow the "slide" / "side_banner" standards in app/config/images.php.
 */
final class SlideController
{
    use GuardsPosts;

    /** GET /admin/slides */
    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/slides/index', [
            'title'  => 'Home slider',
            'admin'  => $admin,
            'slides' => Slide::all(),
        ], 'admin');
    }

    /** GET /admin/slides/create?placement=slider|side */
    public function create(): string
    {
        $admin = require_admin();
        admin_headers();
        $placement = ($_GET['placement'] ?? '') === 'side' ? 'side' : 'slider';

        return view('admin/slides/form', [
            'title'     => $placement === 'side' ? 'Add side banner' : 'Add slide',
            'admin'     => $admin,
            'slide'     => null,
            'placement' => $placement,
            'errors'    => errors(),
        ], 'admin');
    }

    /** POST /admin/slides/create */
    public function store(): never
    {
        require_admin();
        admin_headers();
        $placement = ($_POST['placement'] ?? '') === 'side' ? 'side' : 'slider';
        $formUrl = 'admin/slides/create' . ($placement === 'side' ? '?placement=side' : '');
        self::guardPost($formUrl);

        [$data, $errors] = self::validate();
        if (!ImageUpload::hasFile($_FILES['image'] ?? null)) {
            $errors['image'] = 'Choose an image.';
        }
        if ($errors) {
            self::back($formUrl, $errors);
        }
        $upload = ImageUpload::store($_FILES['image'], self::imageType($placement), $placement === 'side' ? 'home-side-banner' : 'home-slide');
        if (isset($upload['error'])) {
            self::back($formUrl, ['image' => $upload['error']]);
        }

        Slide::create($data + ['placement' => $placement, 'image_path' => $upload['path']]);
        flash('success', ($placement === 'side' ? 'Side banner added.' : 'Slide added.') . ' Image optimised: ' . ImageUpload::summary($upload['report']) . '.');
        redirect('admin/slides');
    }

    /** GET /admin/slides/edit/{id} */
    public function edit(string $id): string
    {
        $admin = require_admin();
        admin_headers();
        $slide = self::findOrBack($id);

        return view('admin/slides/form', [
            'title'     => $slide['placement'] === 'side' ? 'Edit side banner' : 'Edit slide',
            'admin'     => $admin,
            'slide'     => $slide,
            'placement' => $slide['placement'],
            'errors'    => errors(),
        ], 'admin');
    }

    /** POST /admin/slides/edit/{id} */
    public function update(string $id): never
    {
        require_admin();
        admin_headers();
        $slide = self::findOrBack($id);
        $formUrl = 'admin/slides/edit/' . $slide['id'];
        self::guardPost($formUrl);

        [$data, $errors] = self::validate();
        if ($errors) {
            self::back($formUrl, $errors);
        }
        $report = '';
        if (ImageUpload::hasFile($_FILES['image'] ?? null)) {
            $upload = ImageUpload::store($_FILES['image'], self::imageType($slide['placement']), $slide['placement'] === 'side' ? 'home-side-banner' : 'home-slide');
            if (isset($upload['error'])) {
                self::back($formUrl, ['image' => $upload['error']]);
            }
            $data['image_path'] = $upload['path'];
            $report = ' Image optimised: ' . ImageUpload::summary($upload['report']) . '.';
        }

        Slide::update((int) $slide['id'], $data);
        if (isset($data['image_path'])) {
            ImageUpload::delete($slide['image_path']); // only after the database points at the new file
        }
        flash('success', 'Saved.' . $report);
        redirect('admin/slides');
    }

    /** POST /admin/slides/move/{id}  (direction = up | down) */
    public function move(string $id): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/slides');
        $slide = self::findOrBack($id);
        Slide::move((int) $slide['id'], ($_POST['direction'] ?? '') === 'up' ? -1 : 1);
        redirect('admin/slides');
    }

    /** POST /admin/slides/toggle/{id} */
    public function toggle(string $id): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/slides');
        $slide = self::findOrBack($id);
        Slide::update((int) $slide['id'], ['is_active' => $slide['is_active'] ? 0 : 1]);
        flash('success', $slide['is_active'] ? 'Hidden from the homepage.' : 'Shown on the homepage.');
        redirect('admin/slides');
    }

    /** POST /admin/slides/delete/{id} */
    public function destroy(string $id): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/slides');
        $slide = self::findOrBack($id);
        Slide::delete((int) $slide['id']);
        ImageUpload::delete($slide['image_path']);
        flash('success', $slide['placement'] === 'side' ? 'Side banner deleted.' : 'Slide deleted.');
        redirect('admin/slides');
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    public static function imageType(string $placement): string
    {
        return $placement === 'side' ? 'side_banner' : 'slide';
    }

    /** @return array{0: array, 1: array<string, string>} */
    private static function validate(): array
    {
        $errors = [];
        $title = $_POST['title'] ?? '';
        $title = is_string($title) && mb_check_encoding($title, 'UTF-8') ? trim((string) preg_replace('/\s+/u', ' ', $title)) : null;
        if ($title === null || mb_strlen($title) > Slide::TITLE_MAX || preg_match('/[\x00-\x1F\x7F<>]/', (string) $title)) {
            $errors['title'] = sprintf('Up to %d characters, no < or >.', Slide::TITLE_MAX);
        }
        $link = Slide::cleanLink($_POST['link_url'] ?? '');
        if ($link === null) {
            $errors['link_url'] = 'Use a page on this site like /shop?sale=1 or /category/fashion, or a full https:// address.';
        }
        return [[
            'title'     => $title === '' ? null : $title,
            'link_url'  => $link === '' ? null : $link,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ], $errors];
    }

    private static function back(string $url, array $errors): never
    {
        with_input([
            'title'     => is_string($_POST['title'] ?? null) ? mb_substr($_POST['title'], 0, 300) : '',
            'link_url'  => is_string($_POST['link_url'] ?? null) ? mb_substr($_POST['link_url'], 0, 300) : '',
            'is_active' => !empty($_POST['is_active']) ? '1' : '0',
            '_submitted' => '1',
        ], $errors);
        flash(ImageUpload::hasFile($_FILES['image'] ?? null) ? 'warning' : 'error',
            ImageUpload::hasFile($_FILES['image'] ?? null) ? 'Please fix the errors below and select the image again.' : 'Please fix the errors below.');
        redirect($url);
    }

    private static function findOrBack(string $id): array
    {
        $slide = ($slideId = self::routeId($id)) ? Slide::find($slideId) : null;
        if ($slide === null) {
            flash('error', 'That slide no longer exists.');
            redirect('admin/slides');
        }
        return $slide;
    }
}
