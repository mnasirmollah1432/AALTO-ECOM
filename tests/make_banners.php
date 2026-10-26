<?php
// Test banners for the home slider (not real content).
$out = __DIR__ . '/fixtures/banners';
@mkdir($out, 0777, true);
$font = 'C:/Windows/Fonts/arialbd.ttf';
$make = function (string $file, int $w, int $h, array $from, array $to, string $title, string $sub) use ($font, $out) {
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x++) {
        $t = $x / $w;
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, $x, 0, $x, $h, $c);
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 90);
    imagefilledellipse($im, (int) ($w * 0.82), (int) ($h * 0.5), (int) ($h * 0.9), (int) ($h * 0.9), $soft);
    imagettftext($im, (int) ($h * 0.12), 0, (int) ($w * 0.07), (int) ($h * 0.45), $white, $font, $title);
    imagettftext($im, (int) ($h * 0.055), 0, (int) ($w * 0.07), (int) ($h * 0.62), $white, $font, $sub);
    imagepng($im, "$out/$file");
};
$make('slide1.png', 1500, 600, [252, 93, 7], [224, 78, 0], 'TEST SLIDE 1', 'Eid collection - up to 30% off');
$make('slide2.png', 1500, 600, [22, 19, 15], [80, 60, 40], 'TEST SLIDE 2', 'New arrivals every week');
$make('slide3.png', 1500, 600, [11, 87, 208], [30, 160, 120], 'TEST SLIDE 3', 'Cash on delivery all over Bangladesh');
$make('side.png', 750, 600, [192, 71, 5], [252, 93, 7], 'SIDE', 'Test banner');
echo "ok\n";
