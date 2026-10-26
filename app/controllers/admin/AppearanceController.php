<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Appearance;
use App\Models\Setting;

/**
 * Admin -> Appearance: announcement bar (show/hide, colours, text) and the
 * line under the top menu.
 */
final class AppearanceController
{
    use GuardsPosts;

    /** GET /admin/appearance */
    public function edit(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/appearance', [
            'title'  => 'Appearance',
            'admin'  => $admin,
            'values' => Appearance::get(),
            'errors' => errors(),
        ], 'admin');
    }

    /** POST /admin/appearance */
    public function update(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/appearance');

        [$save, $errors] = Appearance::validate($_POST);
        if ($errors) {
            $keep = ['_submitted' => '1'];
            foreach (array_keys(Appearance::DEFAULTS) as $key) {
                $keep[$key] = str_ends_with($key, '_enabled')
                    ? $save[$key]
                    : (is_string($_POST[$key] ?? null) ? mb_substr($_POST[$key], 0, 300) : '');
            }
            with_input($keep, $errors);
            flash('error', 'Please fix the highlighted fields.');
            redirect('admin/appearance');
        }
        if (($_POST['reset'] ?? '') === '1') {
            // Only the colours go back to the defaults; texts and switches stay as saved
            foreach (['announce_bg', 'announce_color', 'header_line'] as $key) {
                $save[$key] = Appearance::DEFAULTS[$key];
            }
        }

        db_transaction(static function () use ($save): void {
            foreach ($save as $key => $value) {
                Setting::set($key, $value);
            }
        });
        flash('success', ($_POST['reset'] ?? '') === '1' ? 'Colours reset to the defaults.' : 'Appearance saved.');
        redirect('admin/appearance');
    }
}
