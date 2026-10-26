<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure, standardised image upload. Every image slot follows its standard in
 * app/config/images.php (dimensions, ratio, size target, quality).
 *
 * Validation (server side - the browser preview is only a convenience):
 * - PHP upload status, size limit (images.max_upload_kb), not empty.
 * - Extension must be jpg/jpeg/png/webp AND match the type detected from the
 *   file content (finfo + getimagesize). SVG, GIF, PHP, renamed executables and
 *   corrupted files are refused.
 * - Pixel limit (server memory), and a minimum size after cropping so images
 *   never look blurred where they are shown.
 *
 * Processing (PHP GD):
 * 1. Decode and apply the camera's EXIF rotation.
 * 2. Centre-crop to the slot's aspect ratio ('cover'), or keep the whole image
 *    ('contain', logos). Images are never stretched.
 * 3. Scale down to the standard size (never up).
 * 4. Encode WebP, lowering quality step by step until the size target is met,
 *    but never below the slot's minimum quality.
 * 5. Save under a safe generated name (e.g. product-linen-shirt-main-3f9a2c1d.webp);
 *    the original file name is never used. Re-encoding drops all metadata and
 *    anything hidden in the file; only the processed image is stored.
 */
final class ImageUpload
{
    /** Detected MIME type => allowed client extensions */
    private const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp'],
    ];

    /** WebP quality steps tried between the slot's start and minimum quality. */
    private const QUALITY_STEP = 4;

    /**
     * Paths the app stores (and is allowed to delete). Old random names
     * (32 hex) and standard names (slug-like stem + 8 hex) are both valid.
     */
    private const STORED_PATH = '#^uploads/[a-z0-9-]+/(?:[a-f0-9]{32}|[a-z0-9]+(?:-[a-z0-9]+)*-[a-f0-9]{8})\.(webp|jpg|png)$#';

    /** The standard for an image slot (see app/config/images.php). */
    public static function spec(string $type): array
    {
        $spec = config("images.types.{$type}");
        if (!is_array($spec)) {
            throw new \InvalidArgumentException("Unknown image type: {$type}");
        }
        [$rw, $rh] = array_map('intval', explode(':', (string) $spec['ratio']));

        return $spec + [
            'type'        => $type,
            'ratio_value' => $rw / $rh,
            'ratio_label' => self::ratioLabel($rw, $rh),
            'max_bytes'   => self::maxBytes(),
            'tolerance'   => (float) config('images.ratio_tolerance', 0.02),
        ];
    }

    /** "4:5", "1:1", or "2.74:1" for awkward ratios like 1920:700. */
    public static function ratioLabel(int $w, int $h): string
    {
        [$a, $b] = [$w, $h];
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        [$rw, $rh] = [intdiv($w, $a), intdiv($h, $a)];
        return $rw <= 20 && $rh <= 20 ? "{$rw}:{$rh}" : rtrim(rtrim(number_format($w / $h, 2), '0'), '.') . ':1';
    }

    public static function hasFile(?array $file): bool
    {
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('images.max_upload_kb', 8192)) * 1024;
    }

    /**
     * Turn a multi-file field (name="images[]" or name="replace_images[ID]")
     * into single-file arrays keyed like the field, skipping empty slots.
     *
     * @return array<int|string, array>
     */
    public static function normalizeMultiple(?array $field): array
    {
        if (!is_array($field) || !isset($field['name']) || !is_array($field['name'])) {
            return [];
        }
        $files = [];
        foreach (array_keys($field['name']) as $i) {
            $file = [
                'name'     => $field['name'][$i] ?? '',
                'type'     => $field['type'][$i] ?? '',
                'tmp_name' => $field['tmp_name'][$i] ?? '',
                'error'    => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $field['size'][$i] ?? 0,
            ];
            if (self::hasFile($file)) {
                $files[$i] = $file;
            }
        }
        return $files;
    }

    /** Path of the small variant ("-sm") when one exists, else the path itself. */
    public static function thumbPath(?string $path): ?string
    {
        if ($path === null || !preg_match(self::STORED_PATH, $path)) {
            return $path;
        }
        $thumb = preg_replace('/\.(webp|jpg|png)$/', '-sm.$1', $path);
        return is_file(PUBLIC_PATH . '/' . $thumb) ? $thumb : $path;
    }

    /**
     * Dimensions, size and format of a stored image, for the admin.
     *
     * @return array{width: int, height: int, bytes: int, format: string, ratio: string}|null
     */
    public static function info(?string $path): ?array
    {
        if ($path === null || !preg_match(self::STORED_PATH, $path)) {
            return null;
        }
        $file = PUBLIC_PATH . '/' . $path;
        $size = is_file($file) ? @getimagesize($file) : false;
        if (!$size) {
            return null;
        }
        return [
            'width'  => (int) $size[0],
            'height' => (int) $size[1],
            'bytes'  => (int) filesize($file),
            'format' => strtoupper(str_replace(['image/', 'jpeg'], ['', 'jpg'], (string) $size['mime'])),
            'ratio'  => self::ratioLabel((int) $size[0], (int) $size[1]),
        ];
    }

    /**
     * Validate, process and store an uploaded image for an image slot.
     *
     * @param string $type  image slot from app/config/images.php, e.g. 'product'
     * @param string $stem  file name stem, e.g. 'product-linen-shirt-main'
     * @return array{path: string, report: array}|array{error: string}
     */
    public static function store(array $file, string $type, string $stem): array
    {
        $spec = self::spec($type);
        $maxLabel = self::formatBytes($spec['max_bytes']);

        // 1. PHP upload status
        switch ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['error' => "The image is too large. Maximum upload size is {$maxLabel}."];
            case UPLOAD_ERR_PARTIAL:
                return ['error' => 'The image was only partly uploaded. Please try again.'];
            case UPLOAD_ERR_NO_FILE:
                return ['error' => 'No image was uploaded.'];
            default:
                log_message('error', 'Upload failed with PHP error code ' . (int) $file['error']);
                return ['error' => 'The image could not be uploaded. Please try again.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['error' => 'The image could not be uploaded. Please try again.'];
        }

        // 2. File size
        $bytes = (int) filesize($tmp);
        if ($bytes <= 0) {
            return ['error' => 'The image file is empty.'];
        }
        if ($bytes > $spec['max_bytes']) {
            return ['error' => sprintf('The image is too large (%s). Maximum upload size is %s.', self::formatBytes($bytes), $maxLabel)];
        }

        // 3. Extension of the original name must be an allowed image type
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, array_merge(...array_values(self::TYPES)), true)) {
            return ['error' => 'Only JPG, PNG or WebP images are allowed.'];
        }

        // 4. Real type from the file content, and it must match the extension
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::TYPES[$mime])) {
            return ['error' => 'Only JPG, PNG or WebP images are allowed.'];
        }
        if (!in_array($ext, self::TYPES[$mime], true)) {
            return ['error' => 'The file extension does not match the image type.'];
        }
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) {
            return ['error' => 'The file is not a valid image.'];
        }
        if ($info[0] * $info[1] > (int) config('images.max_pixels', 16_000_000)) {
            return ['error' => sprintf('The image is %s × %s px, too large to process. Please use an image under 16 megapixels (%d × %d px is recommended).',
                number_format($info[0]), number_format($info[1]), $spec['width'], $spec['height'])];
        }

        // 5. Decode (a broken or disguised file fails here)
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
        };
        if (!$image instanceof \GdImage) {
            return ['error' => 'The image could not be read. It may be damaged or animated.'];
        }
        if ($mime === 'image/jpeg') {
            $image = self::fixOrientation($image, $tmp);
        }
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        // 6. Crop box (centre, target ratio) and output size
        [$cx, $cy, $cw, $ch] = $spec['fit'] === 'cover'
            ? self::centreCrop($srcW, $srcH, $spec['ratio_value'])
            : [0, 0, $srcW, $srcH];

        if ($cw < $spec['min_width'] || $ch < $spec['min_height']) {
            imagedestroy($image);
            $after = $cw === $srcW && $ch === $srcH ? '' : sprintf(' (%d × %d px after cropping to %s)', $cw, $ch, $spec['ratio_label']);
            return ['error' => sprintf('The image is too small: %d × %d px%s. %s must be at least %d × %d px; %d × %d px is recommended.',
                $srcW, $srcH, $after, $spec['label'], $spec['min_width'], $spec['min_height'], $spec['width'], $spec['height'])];
        }

        $scale = min(1, $spec['width'] / $cw, $spec['height'] / $ch); // never upscale
        $outW = max(1, (int) round($cw * $scale));
        $outH = max(1, (int) round($ch * $scale));

        $canvas = self::resample($image, $cx, $cy, $cw, $ch, $outW, $outH);
        imagedestroy($image);

        // 7. Encode: WebP (JPEG/PNG only where the server has no WebP support), size target
        [$format, $outExt] = function_exists('imagewebp')
            ? ['webp', 'webp']
            : ($mime === 'image/png' ? ['png', 'png'] : ['jpeg', 'jpg']);
        [$data, $quality] = self::encodeToTarget($canvas, $format, $spec['quality'][0], $spec['quality'][1], $spec['target_kb'] * 1024);

        // 8. Safe generated name: <stem>-<8 random hex>.<ext> (new URL for every upload)
        $dir = PUBLIC_PATH . '/uploads/' . $spec['folder'];
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            log_message('error', "Upload folder could not be created: {$dir}");
            return ['error' => 'The image could not be saved. Please contact the site administrator.'];
        }
        $base = self::safeStem($stem) . '-' . bin2hex(random_bytes(4));
        $relative = 'uploads/' . $spec['folder'] . '/' . $base . '.' . $outExt;
        $saved = $data !== '' && file_put_contents(PUBLIC_PATH . '/' . $relative, $data, LOCK_EX) !== false;

        // 9. Small copy for listings (srcset), same ratio and quality
        if ($saved && $spec['thumb'] && $outW > $spec['thumb'][0]) {
            $thumb = self::resample($canvas, 0, 0, $outW, $outH, $spec['thumb'][0], $spec['thumb'][1]);
            $saved = file_put_contents(PUBLIC_PATH . '/uploads/' . $spec['folder'] . '/' . $base . '-sm.' . $outExt,
                self::encode($thumb, $format, $quality), LOCK_EX) !== false;
            imagedestroy($thumb);
        }
        imagedestroy($canvas);

        if (!$saved) {
            self::delete($relative);
            log_message('error', "Image could not be written: {$relative}");
            return ['error' => 'The image could not be saved. Please try again.'];
        }

        $cropped = abs(($srcW / $srcH) / $spec['ratio_value'] - 1) > $spec['tolerance'];
        return [
            'path'   => $relative,
            'report' => [
                'label'          => $spec['label'],
                'original_bytes' => $bytes,
                'original_size'  => "{$srcW} × {$srcH}",
                'bytes'          => strlen($data),
                'size'           => "{$outW} × {$outH}",
                'quality'        => $quality,
                'format'         => strtoupper($outExt),
                'cropped'        => $spec['fit'] === 'cover' && $cropped,
                'ratio'          => $spec['ratio_label'],
                'target_kb'      => $spec['target_kb'],
                'target_met'     => strlen($data) <= $spec['target_kb'] * 1024,
            ],
        ];
    }

    /** One line for the admin: "Product main image 1.2 MB → 78 KB (800 × 1000, centre-cropped to 4:5)". */
    public static function summary(array $report): string
    {
        return sprintf('%s %s → %s (%s%s%s)',
            $report['label'],
            self::formatBytes($report['original_bytes']),
            self::formatBytes($report['bytes']),
            $report['size'],
            $report['cropped'] ? ', centre-cropped to ' . $report['ratio'] : '',
            $report['target_met'] ? '' : ', kept above the ' . $report['target_kb'] . ' KB target to preserve quality'
        );
    }

    /** Delete a previously stored upload (and its small variant). Anything else is ignored. */
    public static function delete(?string $path): void
    {
        if ($path === null || !preg_match(self::STORED_PATH, $path)) {
            return;
        }
        foreach ([$path, preg_replace('/\.(webp|jpg|png)$/', '-sm.$1', $path)] as $p) {
            $file = PUBLIC_PATH . '/' . $p;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Largest box with the given ratio, centred in the source: [x, y, width, height]. */
    private static function centreCrop(int $w, int $h, float $ratio): array
    {
        if ($w / $h > $ratio) {                     // too wide: trim left and right
            $cw = max(1, (int) round($h * $ratio));
            return [intdiv($w - $cw, 2), 0, $cw, $h];
        }
        $ch = max(1, (int) round($w / $ratio));     // too tall: trim top and bottom
        return [0, intdiv($h - $ch, 2), $w, $ch];
    }

    /** Copy a region into a new true-colour canvas of the given size, keeping transparency. */
    private static function resample(\GdImage $src, int $x, int $y, int $w, int $h, int $outW, int $outH): \GdImage
    {
        $canvas = imagecreatetruecolor($outW, $outH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $src, 0, 0, $x, $y, $outW, $outH, $w, $h);
        return $canvas;
    }

    /**
     * Encode at the start quality; while the result is above the target, try
     * lower qualities down to the minimum. Returns [bytes, quality used].
     */
    private static function encodeToTarget(\GdImage $image, string $format, int $start, int $min, int $targetBytes): array
    {
        $data = self::encode($image, $format, $start);
        $quality = $start;
        if ($format === 'png') {
            return [$data, $quality]; // lossless: quality does not apply
        }
        for ($q = $start - self::QUALITY_STEP; strlen($data) > $targetBytes && $q >= $min; $q -= self::QUALITY_STEP) {
            [$data, $quality] = [self::encode($image, $format, $q), $q];
        }
        if (strlen($data) > $targetBytes && $quality !== $min) {
            [$data, $quality] = [self::encode($image, $format, $min), $min];
        }
        return [$data, $quality];
    }

    private static function encode(\GdImage $image, string $format, int $quality): string
    {
        ob_start();
        $ok = match ($format) {
            'webp' => imagewebp($image, null, $quality),
            'jpeg' => imagejpeg($image, null, $quality),
            'png'  => imagepng($image, null, 9),
        };
        $data = (string) ob_get_clean();
        return $ok ? $data : '';
    }

    /** Lowercase letters, digits and single hyphens; at most 80 characters. */
    private static function safeStem(string $stem): string
    {
        $stem = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($stem)), '-');
        $stem = rtrim(substr($stem, 0, 80), '-');
        return $stem !== '' ? $stem : 'image';
    }

    /** Apply the camera's EXIF rotation, since re-encoding drops the EXIF tag. */
    private static function fixOrientation(\GdImage $image, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }
}
