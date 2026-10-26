<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Admin -> Appearance: the announcement bar above the header (show/hide,
 * colours, optional own text) and the line under the top menu.
 * Stored in `settings`; colours are strict #rrggbb values.
 */
final class Appearance
{
    public const DEFAULTS = [
        'announce_enabled' => '1',
        'announce_bg'      => '#16130f',
        'announce_color'   => '#ffffff',
        'announce_text'    => '',
        'header_line'      => '#fc5d07',
        // Homepage promo boxes: '' = automatic text (see pages/home.php)
        'promo_sale_enabled'     => '1',
        'promo_sale_eyebrow'     => '',
        'promo_sale_title'       => '',
        'promo_sale_text'        => '',
        'promo_sale_button'      => '',
        'promo_sale_link'        => '',
        'promo_delivery_enabled' => '1',
        'promo_delivery_eyebrow' => '',
        'promo_delivery_title'   => '',
        'promo_delivery_text'    => '',
        'promo_delivery_button'  => '',
        'promo_delivery_link'    => '',
    ];
    public const TEXT_MAX = 160;

    /** Promo box field => [label, max length]. */
    public const PROMO_FIELDS = [
        'eyebrow' => ['Small heading', 40],
        'title'   => ['Title', 80],
        'text'    => ['Text', 160],
        'button'  => ['Button label', 30],
        'link'    => ['Button link', 255],
    ];

    public static function get(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = Setting::get($key, $default);
            if (in_array($key, ['announce_bg', 'announce_color', 'header_line'], true) && !self::isColour($value)) {
                $value = $default;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function isColour(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/', $value);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $save = ['announce_enabled' => !empty($input['announce_enabled']) ? '1' : '0'];
        $errors = [];
        foreach (['announce_bg' => 'Background colour', 'announce_color' => 'Text colour', 'header_line' => 'Menu line colour'] as $key => $label) {
            $value = is_string($input[$key] ?? null) ? strtolower(trim($input[$key])) : '';
            if (preg_match('/^#?([0-9a-f]{3})$/', $value, $m)) { // #abc -> #aabbcc
                $value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
            } elseif (preg_match('/^[0-9a-f]{6}$/', $value)) {
                $value = '#' . $value;
            }
            if (!self::isColour($value)) {
                $errors[$key] = sprintf('%s: pick a colour or type it like #16130f.', $label);
            }
            $save[$key] = $value;
        }
        $text = is_string($input['announce_text'] ?? null) && mb_check_encoding($input['announce_text'], 'UTF-8')
            ? trim((string) preg_replace('/\s+/u', ' ', $input['announce_text'])) : '';
        if (mb_strlen($text) > self::TEXT_MAX || preg_match('/[<>\x00-\x1F\x7F]/', $text)) {
            $errors['announce_text'] = sprintf('Up to %d characters, no < or >.', self::TEXT_MAX);
        }
        $save['announce_text'] = $text;
        if (!isset($errors['announce_bg']) && !isset($errors['announce_color']) && self::contrast($save['announce_bg'], $save['announce_color']) < 3) {
            $errors['announce_color'] = 'The text colour is too close to the background - visitors could not read it. Choose a lighter or darker colour.';
        }

        // Homepage promo boxes
        foreach (['sale', 'delivery'] as $box) {
            $save["promo_{$box}_enabled"] = !empty($input["promo_{$box}_enabled"]) ? '1' : '0';
            foreach (self::PROMO_FIELDS as $field => [$label, $max]) {
                $key = "promo_{$box}_{$field}";
                $raw = $input[$key] ?? '';
                $value = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
                if ($field === 'link') {
                    $link = Slide::cleanLink($value);
                    if ($link === null) {
                        $errors[$key] = 'Button link: a page on this site like /shop?sale=1, or a full https:// address.';
                    }
                    $save[$key] = $link ?? '';
                    continue;
                }
                if (mb_strlen($value) > $max || preg_match('/[<>\x00-\x1F\x7F]/', $value)) {
                    $errors[$key] = sprintf('%s: up to %d characters, no < or >.', $label, $max);
                }
                $save[$key] = $value;
            }
        }
        return [$save, $errors];
    }

    /** WCAG contrast ratio of two #rrggbb colours (1 - 21). */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static function ($v) {
                $v = hexdec($v) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$l1, $l2] = [$lum($a), $lum($b)];
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
