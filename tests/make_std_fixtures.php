<?php
// Photo-like fixtures for the image-standard tests (gradient + fabric-like texture + soft shapes).
$dir = __DIR__ . '/fixtures/std';
@mkdir($dir, 0777, true);
mt_srand(42);

function photo(int $w, int $h, bool $alpha = false): GdImage {
    // draw small then upscale = smooth like a real photo, then add fine grain
    $sw = max(8, intdiv($w, 8)); $sh = max(8, intdiv($h, 8));
    $s = imagecreatetruecolor($sw, $sh);
    for ($y = 0; $y < $sh; $y++) for ($x = 0; $x < $sw; $x++) {
        $r = (int) (190 + 40 * sin($x / 9) - $y / $sh * 60); $g = (int) (150 + 30 * cos($y / 7)); $b = (int) (120 + 50 * sin(($x + $y) / 13));
        imagesetpixel($s, $x, $y, imagecolorallocate($s, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))));
    }
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) { imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true); }
    imagecopyresampled($im, $s, 0, 0, 0, 0, $w, $h, $sw, $sh);
    // a "garment" shape in the middle
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h * 2, 3), imagecolorallocatealpha($im, 40, 60, 110, $alpha ? 0 : 30));
    // fine texture
    for ($i = 0; $i < $w * $h / 40; $i++) { $c = mt_rand(0, 30); imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocatealpha($im, $c, $c, $c, 90)); }
    return $im;
}
$make = [
    'photo-2000x1500.jpg'  => [2000, 1500, 'jpg'],   // landscape: wrong ratio for every slot
    'photo-1200x1500.jpg'  => [1200, 1500, 'jpg'],   // 4:5, larger than recommended
    'photo-800x1000.webp'  => [800, 1000, 'webp'],   // exactly the product standard
    'photo-1000x1000.png'  => [1000, 1000, 'png'],   // 1:1 PNG
    'photo-320x320.webp'   => [320, 320, 'webp'],    // exactly the category standard
    'photo-600x750.jpg'    => [600, 750, 'jpg'],     // 4:5, smaller than recommended but above minimum
    'small-300x375.jpg'    => [300, 375, 'jpg'],     // below product minimum 400x500
    'small-120x120.png'    => [120, 120, 'png'],     // below category minimum 160x160
    'phone-4032x3024.jpg'  => [4032, 3024, 'jpg'],   // 12 MP phone photo (landscape)
    'transparent-800x1000.png' => [800, 1000, 'png-alpha'],
];
foreach ($make as $name => [$w, $h, $fmt]) {
    $im = photo($w, $h, $fmt === 'png-alpha');
    $path = "$dir/$name";
    match ($fmt) { 'jpg' => imagejpeg($im, $path, 92), 'webp' => imagewebp($im, $path, 92), default => imagepng($im, $path, 6) };
    printf("%-26s %5d x %-5d %8s\n", $name, $w, $h, number_format(filesize($path)));
}
// Renamed executables / fakes
file_put_contents("$dir/evil.php.jpg", "<?php system(\$_GET['c']); ?>");
file_put_contents("$dir/windows-exe.jpg", "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\x00", 200));
file_put_contents("$dir/script.png", "<script>alert(1)</script>");
echo "fakes written\n";
