<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Homepage slider images and the side banner (table `home_slides`).
 * placement "slider" = rotating banner; "side" = banner beside it on large screens.
 */
final class Slide
{
    public const PLACEMENTS = ['slider' => 'Slider', 'side' => 'Side banner'];
    public const TITLE_MAX = 120;
    public const LINK_MAX = 255;
    public const MAX_SLIDES = 12;

    /** What the homepage shows: active slides in order and the first active side banner. */
    public static function forHome(): array
    {
        try {
            $rows = db_all('SELECT id, placement, image_path, title, link_url FROM home_slides WHERE is_active = 1 ORDER BY sort_order, id');
        } catch (\Throwable) {
            return ['slides' => [], 'side' => null]; // table missing (not yet migrated)
        }
        $slides = array_values(array_filter($rows, static fn ($r) => $r['placement'] === 'slider'));
        $side = array_values(array_filter($rows, static fn ($r) => $r['placement'] === 'side'))[0] ?? null;
        return ['slides' => array_slice($slides, 0, self::MAX_SLIDES), 'side' => $side];
    }

    public static function all(): array
    {
        return db_all("SELECT * FROM home_slides ORDER BY placement = 'side', sort_order, id");
    }

    public static function find(int $id): ?array
    {
        return db_one('SELECT * FROM home_slides WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_slides WHERE placement = ?', [$data['placement']]);
        return db_insert('home_slides', self::only($data));
    }

    public static function update(int $id, array $data): void
    {
        db_update('home_slides', self::only($data), ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        db_query('DELETE FROM home_slides WHERE id = ?', [$id]);
    }

    /** Swap a slide with its neighbour (-1 = earlier, 1 = later) within its placement. */
    public static function move(int $id, int $direction): void
    {
        $slide = self::find($id);
        if ($slide === null) {
            return;
        }
        $rows = db_all('SELECT id FROM home_slides WHERE placement = ? ORDER BY sort_order, id', [$slide['placement']]);
        $ids = array_map('intval', array_column($rows, 'id'));
        $at = array_search($id, $ids, true);
        $to = $at + $direction;
        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        db_transaction(static function () use ($ids): void {
            foreach ($ids as $i => $slideId) {
                db_update('home_slides', ['sort_order' => $i + 1], ['id' => $slideId]);
            }
        });
    }

    /**
     * A link for a slide: a path on this site ("/shop?sale=1") or an http(s)
     * address. Returns the cleaned link, '' for none, or null when invalid.
     */
    public static function cleanLink(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $link = trim($value);
        if ($link === '') {
            return '';
        }
        if (strlen($link) > self::LINK_MAX || preg_match('/[\s<>"\'\\\\\x00-\x1F\x7F]/', $link)) {
            return null;
        }
        if (str_starts_with($link, '/')) {
            return str_starts_with($link, '//') ? null : $link; // "//host" would leave the site
        }
        $parts = parse_url($link);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host']) ? $link : null;
    }

    /** Is the link to another website (opens in a new tab)? */
    public static function isExternal(string $link): bool
    {
        return !str_starts_with($link, '/');
    }

    private static function only(array $data): array
    {
        return array_intersect_key($data, array_flip(['placement', 'image_path', 'title', 'link_url', 'sort_order', 'is_active']));
    }
}
