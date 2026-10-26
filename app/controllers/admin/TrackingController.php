<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Setting;

/**
 * Admin -> Marketing & tracking: Facebook (Meta) Pixel ID and Google Tag Manager ID.
 * Only IDs are accepted (not pasted code), so nothing can inject scripts into the store.
 */
final class TrackingController
{
    use GuardsPosts;

    /** GET /admin/tracking */
    public function edit(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/tracking', [
            'title'  => 'Marketing & tracking',
            'admin'  => $admin,
            'values' => ['fb_pixel_id' => Setting::get('fb_pixel_id'), 'gtm_id' => Setting::get('gtm_id')],
            'errors' => errors(),
        ], 'admin');
    }

    /** POST /admin/tracking */
    public function update(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/tracking');

        $errors = [];
        $pixel = is_string($_POST['fb_pixel_id'] ?? null) ? preg_replace('/\s+/', '', $_POST['fb_pixel_id']) : '';
        $gtm = is_string($_POST['gtm_id'] ?? null) ? strtoupper(preg_replace('/\s+/', '', $_POST['gtm_id'])) : '';
        // People often paste the whole snippet: pick the ID out of it
        if ($pixel !== '' && !preg_match('/^\d{8,20}$/', $pixel) && preg_match("/fbq\\(\\s*['\"]init['\"]\\s*,\\s*['\"](\\d{8,20})['\"]/", (string) $_POST['fb_pixel_id'], $m)) {
            $pixel = $m[1];
        }
        if ($gtm !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm) && preg_match('/GTM-[A-Z0-9]{4,12}/i', (string) $_POST['gtm_id'], $m)) {
            $gtm = strtoupper($m[0]);
        }
        if ($pixel !== '' && !preg_match('/^\d{8,20}$/', $pixel)) {
            $errors['fb_pixel_id'] = 'The Pixel ID is a number of 15-16 digits (Meta Events Manager -> Data sources -> your Pixel).';
        }
        if ($gtm !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm)) {
            $errors['gtm_id'] = 'The container ID looks like GTM-XXXXXXX (Google Tag Manager -> top bar).';
        }
        if ($errors) {
            with_input(['_submitted' => '1', 'fb_pixel_id' => mb_substr((string) ($_POST['fb_pixel_id'] ?? ''), 0, 100), 'gtm_id' => mb_substr((string) ($_POST['gtm_id'] ?? ''), 0, 100)], $errors);
            flash('error', 'Please fix the highlighted fields.');
            redirect('admin/tracking');
        }
        Setting::set('fb_pixel_id', $pixel);
        Setting::set('gtm_id', $gtm);
        flash('success', 'Tracking saved.' . ($pixel || $gtm ? ' Open the store and check with Meta Pixel Helper / Tag Assistant.' : ' Tracking is off.'));
        redirect('admin/tracking');
    }
}
