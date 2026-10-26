<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Contact;
use App\Models\Setting;

/**
 * Admin -> Contact & social: phone, WhatsApp, imo, Messenger, email, social
 * links, address, and the floating chat button (stored in `settings`).
 */
final class ContactController
{
    use GuardsPosts;

    /** GET /admin/contact */
    public function edit(): string
    {
        $admin = require_admin();
        admin_headers();

        $values = [];
        foreach (array_keys(Contact::FIELDS) as $key) {
            $values[$key] = Setting::get($key);
        }
        foreach (['chat_enabled' => '0', 'chat_whatsapp' => '1', 'chat_messenger' => '1', 'chat_greeting' => ''] as $key => $default) {
            $values[$key] = Setting::get($key, $default);
        }

        return view('admin/contact', [
            'title'  => 'Contact & social',
            'admin'  => $admin,
            'values' => $values,
            'errors' => errors(),
        ], 'admin');
    }

    /** POST /admin/contact */
    public function update(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/contact');

        [$save, $errors] = Contact::validate($_POST);
        if ($errors) {
            $keep = ['_submitted' => '1'];
            foreach (array_merge(array_keys(Contact::FIELDS), ['chat_greeting']) as $key) {
                $keep[$key] = is_string($_POST[$key] ?? null) ? mb_substr($_POST[$key], 0, 500) : '';
            }
            foreach (['chat_enabled', 'chat_whatsapp', 'chat_messenger'] as $key) {
                $keep[$key] = !empty($_POST[$key]) ? '1' : '0';
            }
            with_input($keep, $errors);
            flash('error', 'Please fix the highlighted fields.');
            redirect('admin/contact');
        }

        db_transaction(static function () use ($save): void {
            foreach ($save as $key => $value) {
                Setting::set($key, $value);
            }
        });
        flash('success', 'Contact details saved.' . ($save['chat_enabled'] === '1' ? ' The chat button is on.' : ''));
        redirect('admin/contact');
    }
}
