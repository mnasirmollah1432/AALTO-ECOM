<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Contact details, social links and the chat button (Admin -> Contact & social).
 * Stored in `settings` with the "contact_" / "chat_" prefixes; the storefront
 * Contact page, footer and the floating chat button read them from here.
 */
final class Contact
{
    /** key => [label, kind]. kind decides the validation and the link that is built. */
    public const FIELDS = [
        'contact_phone'     => ['Phone (call)', 'phone'],
        'contact_whatsapp'  => ['WhatsApp number', 'phone'],
        'contact_imo'       => ['imo number', 'phone'],
        'contact_messenger' => ['Messenger (Facebook page username or m.me link)', 'messenger'],
        'contact_email'     => ['Email', 'email'],
        'contact_facebook'  => ['Facebook page link', 'url'],
        'contact_instagram' => ['Instagram link', 'url'],
        'contact_youtube'   => ['YouTube link', 'url'],
        'contact_tiktok'    => ['TikTok link', 'url'],
        'contact_address'   => ['Address', 'text'],
        'contact_hours'     => ['Opening hours', 'text'],
        'contact_map'       => ['Google Maps link', 'url'],
    ];

    /** Allowed hosts per social link (the link must be on that site). */
    private const HOSTS = [
        'contact_facebook'  => ['facebook.com', 'fb.com', 'fb.me'],
        'contact_instagram' => ['instagram.com'],
        'contact_youtube'   => ['youtube.com', 'youtu.be'],
        'contact_tiktok'    => ['tiktok.com'],
        'contact_map'       => ['google.com', 'maps.google.com', 'goo.gl', 'maps.app.goo.gl', 'g.co'],
    ];

    public const TEXT_MAX = 300;
    public const GREETING_MAX = 200;

    /** All saved values ('' when not set). Phone/email fall back to .env STORE_PHONE / STORE_EMAIL. */
    public static function values(): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $values[$key] = Setting::get($key);
        }
        if ($values['contact_phone'] === '') {
            $values['contact_phone'] = (string) config('app.store.phone', '');
        }
        if ($values['contact_email'] === '' && !str_ends_with((string) config('app.store.email', ''), '@example.com')) {
            $values['contact_email'] = (string) config('app.store.email', '');
        }
        return $values;
    }

    /**
     * Ready-to-show channels in display order, only those that are set:
     * [key, label, value, href, icon, external].
     */
    public static function channels(): array
    {
        $v = self::values();
        $list = [];
        $add = static function (string $key, string $label, string $value, ?string $href, string $icon, bool $external = true) use (&$list): void {
            if ($value !== '') {
                $list[] = compact('key', 'label', 'value', 'href', 'icon', 'external');
            }
        };
        $add('whatsapp', 'WhatsApp', $v['contact_whatsapp'], self::whatsappUrl($v['contact_whatsapp']), 'whatsapp');
        $add('messenger', 'Messenger', self::messengerName($v['contact_messenger']), self::messengerUrl($v['contact_messenger']), 'messenger');
        $add('imo', 'imo', $v['contact_imo'], self::telUrl($v['contact_imo']), 'chat-fill', false);
        $add('phone', 'Call us', $v['contact_phone'], self::telUrl($v['contact_phone']), 'telephone', false);
        $add('email', 'Email', $v['contact_email'], $v['contact_email'] !== '' ? 'mailto:' . $v['contact_email'] : null, 'envelope', false);
        $add('facebook', 'Facebook', self::shortUrl($v['contact_facebook']), $v['contact_facebook'] ?: null, 'facebook');
        $add('instagram', 'Instagram', self::shortUrl($v['contact_instagram']), $v['contact_instagram'] ?: null, 'instagram');
        $add('youtube', 'YouTube', self::shortUrl($v['contact_youtube']), $v['contact_youtube'] ?: null, 'youtube');
        $add('tiktok', 'TikTok', self::shortUrl($v['contact_tiktok']), $v['contact_tiktok'] ?: null, 'tiktok');
        return $list;
    }

    /** Floating chat button: enabled channels [key, label, href] (empty = hidden). */
    public static function chat(): array
    {
        if (Setting::get('chat_enabled') !== '1') {
            return [];
        }
        $v = self::values();
        $greeting = Setting::get('chat_greeting');
        $out = [];
        if (Setting::get('chat_whatsapp', '1') === '1' && ($url = self::whatsappUrl($v['contact_whatsapp'], $greeting)) !== null) {
            $out[] = ['key' => 'whatsapp', 'label' => 'WhatsApp', 'href' => $url, 'icon' => 'whatsapp'];
        }
        if (Setting::get('chat_messenger', '1') === '1' && ($url = self::messengerUrl($v['contact_messenger'])) !== null) {
            $out[] = ['key' => 'messenger', 'label' => 'Messenger', 'href' => $url, 'icon' => 'messenger'];
        }
        return $out;
    }

    /* --------------------------------------------------------
     | Links
     * ------------------------------------------------------ */

    /** "01712-345678" -> "8801712345678" (international digits for wa.me), or null. */
    public static function internationalDigits(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (preg_match('/^01[3-9]\d{8}$/', $digits)) {
            return '88' . $digits;
        }
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    public static function whatsappUrl(string $phone, string $message = ''): ?string
    {
        $digits = $phone !== '' ? self::internationalDigits($phone) : null;
        if ($digits === null) {
            return null;
        }
        return 'https://wa.me/' . $digits . ($message !== '' ? '?text=' . rawurlencode($message) : '');
    }

    public static function telUrl(string $phone): ?string
    {
        $tel = (string) preg_replace('/[^\d+]/', '', $phone);
        return $tel !== '' ? 'tel:' . $tel : null;
    }

    /** Messenger username from "aaltomart", "m.me/aaltomart" or a facebook.com/aaltomart link. */
    public static function messengerName(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^(?:https?://)?(?:www\.)?(?:m\.me|(?:m\.|web\.)?facebook\.com|fb\.com)/([A-Za-z0-9.\-]{2,80})/?(?:\?.*)?$#i', $value, $m)) {
            return $m[1];
        }
        return preg_match('/^[A-Za-z0-9.\-]{2,80}$/', $value) ? $value : '';
    }

    public static function messengerUrl(string $value): ?string
    {
        $name = self::messengerName($value);
        return $name !== '' ? 'https://m.me/' . rawurlencode($name) : null;
    }

    /** "https://www.facebook.com/aaltomart/" -> "facebook.com/aaltomart" for display. */
    public static function shortUrl(string $url): string
    {
        return rtrim((string) preg_replace('#^https?://(www\.)?#i', '', $url), '/');
    }

    /* --------------------------------------------------------
     | Admin validation
     * ------------------------------------------------------ */

    /**
     * Validate the admin form. Returns [values to save, errors].
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $save = [];
        $errors = [];
        foreach (self::FIELDS as $key => [$label, $kind]) {
            $raw = $input[$key] ?? '';
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
                $errors[$key] = 'This field contains invalid characters.';
                continue;
            }
            $value = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $raw)));
            if ($value === '') {
                $save[$key] = '';
                continue;
            }
            switch ($kind) {
                case 'phone':
                    if (!preg_match('/^\+?[\d\s\-()]{6,25}$/', $value) || self::internationalDigits($value) === null) {
                        $errors[$key] = sprintf('%s: enter a phone number like 01712-345678 or +8801712345678.', $label);
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 150) {
                        $errors[$key] = 'Enter a valid email address.';
                    }
                    break;
                case 'messenger':
                    if (self::messengerName($value) === '') {
                        $errors[$key] = 'Enter your Facebook page username (e.g. aaltomart) or its m.me / facebook.com link.';
                    }
                    break;
                case 'url':
                    $host = strtolower((string) parse_url($value, PHP_URL_HOST));
                    $okHost = $host !== '' && array_filter(self::HOSTS[$key], static fn ($h) => $host === $h || str_ends_with($host, '.' . $h));
                    if (!preg_match('#^https?://#i', $value) || mb_strlen($value) > 255 || preg_match('/[\s<>"\']/', $value) || !$okHost) {
                        $errors[$key] = sprintf('%s: paste the full https:// link (%s).', $label, implode(', ', self::HOSTS[$key]));
                    }
                    break;
                default: // text
                    if (mb_strlen($value) > self::TEXT_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F<>]/', $value)) {
                        $errors[$key] = sprintf('%s: up to %d characters, no < or >.', $label, self::TEXT_MAX);
                    }
            }
            $save[$key] = $value;
        }

        // Chat button
        $save['chat_enabled'] = !empty($input['chat_enabled']) ? '1' : '0';
        $save['chat_whatsapp'] = !empty($input['chat_whatsapp']) ? '1' : '0';
        $save['chat_messenger'] = !empty($input['chat_messenger']) ? '1' : '0';
        $greeting = is_string($input['chat_greeting'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['chat_greeting'])) : '';
        if (mb_strlen($greeting) > self::GREETING_MAX || !mb_check_encoding($greeting, 'UTF-8')) {
            $errors['chat_greeting'] = sprintf('The WhatsApp message can be up to %d characters.', self::GREETING_MAX);
        }
        $save['chat_greeting'] = $greeting;
        if ($save['chat_enabled'] === '1' && !isset($errors['contact_whatsapp']) && !isset($errors['contact_messenger'])) {
            $wa = $save['chat_whatsapp'] === '1' && ($save['contact_whatsapp'] ?? '') !== '';
            $ms = $save['chat_messenger'] === '1' && ($save['contact_messenger'] ?? '') !== '';
            if (!$wa && !$ms) {
                $errors['chat_enabled'] = 'To show the chat button, fill in the WhatsApp number or Messenger and tick it below.';
            }
        }

        return [$save, $errors];
    }
}
