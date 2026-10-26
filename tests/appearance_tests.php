<?php
echo "== Appearance: announcement bar and menu line\n";
$home = req('/')['body'];
t(str_contains($home, '<div class="announcement" style="background: #16130f; color: #ffffff">') && str_contains($home, 'Delivery all over Bangladesh'), 'default bar: dark background, white text, automatic text');
t(str_contains($home, 'style="--am-header-line: #fc5d07"'), 'menu line uses the brand orange by default');
t(req('/admin/appearance', null, __DIR__ . '/h_anon.txt')['location'] === B . '/admin/login', 'signed out: /admin/appearance -> login'); @unlink(__DIR__ . '/h_anon.txt');
$ap = req('/admin/appearance', null, $ADMIN)['body'];
t(str_contains($ap, 'name="announce_enabled"') && str_contains($ap, 'name="announce_bg"') && str_contains($ap, 'name="announce_color"') && str_contains($ap, 'data-preview-bar'), 'appearance form with switch, colours and preview');
$look = ['announce_enabled' => '1', 'announce_bg' => '#FC5D07', 'announce_color' => 'fff', 'announce_text' => 'Eid offer: 10% off everything', 'header_line' => '#16130f'];
foreach ([['announce_bg', 'red;background:url(x)'], ['announce_color', '#12345g'], ['header_line', 'javascript:1'], ['announce_text', '<b>hi</b>'], ['announce_color', '#fb5e08']] as [$k, $bad]) {
    req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), $k => $bad] + $look, $ADMIN);
    t(val("SELECT COUNT(*) FROM settings WHERE setting_key='$k'") === 0, "$k \"$bad\" rejected");
}
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN)] + $look, $ADMIN);
$home = req('/')['body'];
t(str_contains($home, 'style="background: #fc5d07; color: #ffffff"') && str_contains($home, 'Eid offer: 10% off everything') && !str_contains($home, 'Delivery all over Bangladesh'), 'saved colours (#FC5D07 -> #fc5d07, fff -> #ffffff) and own text shown');
t(str_contains($home, 'style="--am-header-line: #16130f"'), 'menu line colour saved');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'announce_enabled' => ''] + $look, $ADMIN);
t(!str_contains(req('/')['body'], 'class="announcement"'), 'bar hidden when switched off');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'reset' => '1'] + $look, $ADMIN);
t(str_contains(req('/')['body'], '<div class="announcement" style="background: #16130f; color: #ffffff">'), 'reset to defaults');
req('/admin/appearance', ['_csrf' => 'forged', 'announce_enabled' => ''] + $look, $ADMIN);
t(str_contains(req('/')['body'], 'class="announcement"'), 'forged CSRF: nothing changed');


echo "== Appearance: home page promo boxes\n";
$pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'promo!_%' ESCAPE '!'");
$home = req('/')['body'];
t(str_contains($home, 'Limited-time deals') && str_contains($home, 'Shop the sale') && str_contains($home, 'promo--delivery'), 'default: both boxes with automatic text');
$promo = ['announce_enabled' => '1', 'announce_bg' => '#16130f', 'announce_color' => '#ffffff', 'announce_text' => '', 'header_line' => '#fc5d07',
    'promo_sale_enabled' => '1', 'promo_sale_eyebrow' => 'Eid special', 'promo_sale_title' => 'Panjabi from 950 taka', 'promo_sale_text' => 'Only this week.',
    'promo_sale_button' => 'See panjabi', 'promo_sale_link' => '/category/fashion', 'promo_delivery_enabled' => '1'];
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'promo_sale_link' => 'javascript:alert(1)'] + $promo, $ADMIN);
t(val("SELECT COUNT(*) FROM settings WHERE setting_key='promo_sale_title'") === 0, 'javascript: button link rejected');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'promo_sale_title' => '<script>x</script>'] + $promo, $ADMIN);
t(val("SELECT COUNT(*) FROM settings WHERE setting_key='promo_sale_title'") === 0, 'title with < > rejected');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN)] + $promo, $ADMIN);
$home = req('/')['body'];
t(str_contains($home, 'Eid special') && str_contains($home, 'Panjabi from 950 taka') && str_contains($home, 'Only this week.') && !str_contains($home, 'Limited-time deals'), 'own deals text shown');
t(str_contains($home, 'href="' . B . '/category/fashion" class="btn btn-light">See panjabi</a>'), 'own button label and link');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'promo_sale_enabled' => ''] + $promo, $ADMIN);
$home = req('/')['body'];
t(!str_contains($home, 'promo--sale') && str_contains($home, 'promo-grid promo-grid--single') && str_contains($home, 'promo--delivery'), 'deals box hidden; delivery box fills the row');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'promo_sale_enabled' => '', 'promo_delivery_enabled' => ''] + $promo, $ADMIN);
t(!str_contains(req('/')['body'], 'promo-title'), 'both hidden: offers section removed');
req('/admin/appearance', ['_csrf' => tok('/admin/appearance', $ADMIN), 'promo_sale_eyebrow' => '', 'promo_sale_title' => '', 'promo_sale_text' => '', 'promo_sale_button' => '', 'promo_sale_link' => '', 'promo_delivery_enabled' => '1'] + $promo, $ADMIN);
t(str_contains(req('/')['body'], 'Limited-time deals'), 'emptied fields go back to the automatic text');
