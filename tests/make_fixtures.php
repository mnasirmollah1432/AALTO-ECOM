<?php
// Generates upload test fixtures into ./fixtures
$dir = __DIR__ . '/fixtures';
@mkdir($dir);

// valid JPG 2000x1500 (should be scaled to 1200 wide)
$im = imagecreatetruecolor(2000, 1500);
imagefilledrectangle($im, 0, 0, 2000, 1500, imagecolorallocate($im, 15, 118, 110));
imagefilledellipse($im, 1000, 750, 900, 900, imagecolorallocate($im, 245, 158, 11));
imagejpeg($im, "$dir/valid.jpg", 85);
copy("$dir/valid.jpg", "$dir/valid-upper.JPEG");

// valid PNG with transparency
$im = imagecreatetruecolor(400, 300);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledellipse($im, 200, 150, 200, 200, imagecolorallocate($im, 67, 56, 202));
imagepng($im, "$dir/valid.png");

// valid WebP
$im = imagecreatetruecolor(600, 600);
imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, 220, 38, 38));
imagewebp($im, "$dir/valid.webp", 80);

// PNG content with a .jpg name (extension/type mismatch)
copy("$dir/valid.png", "$dir/png-named.jpg");

// Text file pretending to be a JPG
file_put_contents("$dir/fake.jpg", "<?php echo 'pwned'; ?>\nnot an image");

// Polyglot: valid PNG with PHP code appended
file_put_contents("$dir/polyglot.png", file_get_contents("$dir/valid.png") . "<?php system(\$_GET['c']); ?>");
// Same polyglot but with a .php name
copy("$dir/polyglot.png", "$dir/shell.php");
// Double extension
copy("$dir/polyglot.png", "$dir/shell.php.png");

// GIF (not allowed)
$im = imagecreate(50, 50);
imagecolorallocate($im, 1, 2, 3);
imagegif($im, "$dir/image.gif");

// SVG (not allowed; could carry script)
file_put_contents("$dir/image.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

// Over 2 MB: noisy JPG at max quality
$im = imagecreatetruecolor(2600, 2600);
for ($y = 0; $y < 2600; $y += 2) {
    for ($x = 0; $x < 2600; $x += 2) {
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
    }
}
imagejpeg($im, "$dir/big.jpg", 100);

// Tiny file, huge dimensions (decompression-bomb style): 6000x4000 = 24 MP
$im = imagecreate(6000, 4000);
imagecolorallocate($im, 255, 255, 255);
imagepng($im, "$dir/huge-dimensions.png", 9);

// Truncated / corrupt JPG (valid header, broken body)
file_put_contents("$dir/corrupt.jpg", substr(file_get_contents("$dir/valid.jpg"), 0, 600));

foreach (glob("$dir/*") as $f) {
    printf("%-22s %9s bytes  %s\n", basename($f), number_format(filesize($f)), (new finfo(FILEINFO_MIME_TYPE))->file($f));
}
