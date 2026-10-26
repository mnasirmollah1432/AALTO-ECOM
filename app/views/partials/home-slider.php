<?php
/**
 * Homepage slider + side banner (Admin -> Home slider).
 * Works as a swipeable strip without JavaScript; app.js (modules.slider)
 * adds autoplay, dots and arrows.
 *
 * @var array{slides: array, side: ?array} $banners
 */
use App\Core\ImageUpload;
use App\Models\Slide;

$slides = $banners['slides'];
$side = $banners['side'];
$slideSpec = ImageUpload::spec('slide');
$sideSpec = ImageUpload::spec('side_banner');
$multi = count($slides) > 1;
$link = static function (?string $url, string $inner, string $label): string {
    if ($url === null || $url === '') {
        return $inner;
    }
    $external = Slide::isExternal($url);
    return '<a href="' . e($external ? $url : url(ltrim($url, '/'))) . '" aria-label="' . e($label) . '"'
        . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $inner . '</a>';
};
?>
<section class="home-banners" aria-label="Featured offers">
    <h1 class="visually-hidden"><?= e((string) config('app.name')) ?> - Everyday style, thoughtfully priced</h1>
    <div class="container">
        <div class="home-banners__grid<?= $side ? ' home-banners__grid--side' : '' ?>">
            <div class="slider<?= $multi ? ' slider--multi' : '' ?>" data-slider <?= $multi ? 'data-autoplay="5000"' : '' ?> role="region" aria-roledescription="carousel" aria-label="Offers">
                <ul class="slider__track" data-slider-track>
                    <?php foreach ($slides as $i => $s):
                        $alt = (string) ($s['title'] ?? '');
                        $img = responsive_img($s['image_path'], $alt, $side ? '(min-width: 1400px) 880px, (min-width: 992px) 66vw, 100vw' : '(min-width: 1400px) 1320px, 100vw', [
                            'width' => $slideSpec['width'], 'height' => $slideSpec['height'],
                            'loading' => $i === 0 ? 'eager' : 'lazy', 'fetchpriority' => $i === 0 ? 'high' : null,
                        ], true);
                    ?>
                        <li class="slider__slide" data-slide aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= count($slides) ?>">
                            <?= $link($s['link_url'], $img, $alt !== '' ? $alt : 'Offer ' . ($i + 1)) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($multi): ?>
                    <button type="button" class="slider__arrow slider__arrow--prev" data-slider-prev aria-label="Previous slide"><?= icon('chevron-left') ?></button>
                    <button type="button" class="slider__arrow slider__arrow--next" data-slider-next aria-label="Next slide"><?= icon('chevron-right') ?></button>
                    <div class="slider__dots js-only" data-slider-dots></div>
                <?php endif; ?>
            </div>
            <?php if ($side):
                $alt = (string) ($side['title'] ?? '');
                $img = responsive_img($side['image_path'], $alt, '440px', ['width' => $sideSpec['width'], 'height' => $sideSpec['height']], true);
            ?>
                <div class="side-banner"><?= $link($side['link_url'], $img, $alt !== '' ? $alt : 'Offer') ?></div>
            <?php endif; ?>
        </div>
    </div>
</section>
