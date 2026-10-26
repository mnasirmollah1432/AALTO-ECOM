<?php
/**
 * Admin -> Contact & social.
 *
 * @var array $values saved settings
 * @var array $errors
 */
use App\Models\Contact;

$hasOld = old('_submitted') === '1';
$v = static fn (string $key): string => $hasOld ? old($key) : (string) ($values[$key] ?? '');
$on = static fn (string $key): bool => $hasOld ? old($key) === '1' : ($values[$key] ?? '0') === '1';
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$err = static fn (string $f): string => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';

$groups = [
    'Chat & phone' => [
        'contact_whatsapp'  => ['bi-whatsapp', '01712-345678', 'Used for the WhatsApp button (wa.me link).'],
        'contact_messenger' => ['bi-messenger', 'aaltomart', 'Your Facebook page username, or its m.me / facebook.com link.'],
        'contact_imo'       => ['bi-chat-fill', '01712-345678', 'Shown with a "copy number" button (imo has no web link).'],
        'contact_phone'     => ['bi-telephone', '+880 1712-345678', 'Tap-to-call number.'],
        'contact_email'     => ['bi-envelope', 'hello@aaltomart.com', ''],
    ],
    'Social media' => [
        'contact_facebook'  => ['bi-facebook', 'https://facebook.com/aaltomart', ''],
        'contact_instagram' => ['bi-instagram', 'https://instagram.com/aaltomart', ''],
        'contact_youtube'   => ['bi-youtube', 'https://youtube.com/@aaltomart', ''],
        'contact_tiktok'    => ['bi-tiktok', 'https://tiktok.com/@aaltomart', ''],
    ],
    'Address' => [
        'contact_address'   => ['bi-geo-alt', "House 4, Road 2, Dhanmondi, Dhaka", ''],
        'contact_hours'     => ['bi-clock', 'Saturday - Thursday, 10am - 8pm', ''],
        'contact_map'       => ['bi-map', 'https://maps.app.goo.gl/...', 'Optional: "Open in Google Maps" link under the address.'],
    ],
];
?>
<p class="text-muted mb-3">
    Shown on the <a href="<?= e(url('contact')) ?>" target="_blank" rel="noopener">Contact us</a> page (only the filled-in ones), as icons in the footer, and in the chat button.
    Leave a field empty to hide it.
</p>

<form method="post" action="<?= e(url('admin/contact')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 g-lg-4">
        <div class="col-12 col-xl-8">
            <?php foreach ($groups as $heading => $fields): ?>
                <div class="card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <h2 class="h6 fw-semibold mb-3"><?= e($heading) ?></h2>
                        <div class="row g-3">
                            <?php foreach ($fields as $key => [$ico, $placeholder, $help]): $label = Contact::FIELDS[$key][0]; ?>
                                <div class="col-12 <?= $key === 'contact_address' ? '' : 'col-md-6' ?>">
                                    <label for="<?= $key ?>" class="form-label"><i class="bi <?= $ico ?> me-1" aria-hidden="true"></i><?= e($label) ?></label>
                                    <?php if ($key === 'contact_address'): ?>
                                        <textarea id="<?= $key ?>" name="<?= $key ?>" rows="2" maxlength="300" class="form-control<?= $invalid($key) ?>" placeholder="<?= e($placeholder) ?>"><?= e($v($key)) ?></textarea>
                                    <?php else: ?>
                                        <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($v($key)) ?>" maxlength="255" class="form-control<?= $invalid($key) ?>"
                                               placeholder="<?= e($placeholder) ?>" autocomplete="off" <?= in_array(Contact::FIELDS[$key][1], ['url', 'email', 'messenger'], true) ? 'autocapitalize="none" spellcheck="false"' : '' ?>>
                                    <?php endif; ?>
                                    <?= $err($key) ?>
                                    <?php if ($help !== ''): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-1">Chat button</h2>
                    <p class="small text-muted mb-3">A round button in the bottom-right corner of every store page that opens WhatsApp or Messenger.</p>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input<?= $invalid('chat_enabled') ?>" type="checkbox" role="switch" id="chat_enabled" name="chat_enabled" value="1" <?= $on('chat_enabled') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="chat_enabled">Show the chat button</label>
                        <?= $err('chat_enabled') ?>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="chat_whatsapp" name="chat_whatsapp" value="1" <?= $on('chat_whatsapp') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chat_whatsapp"><i class="bi bi-whatsapp me-1 text-success" aria-hidden="true"></i>WhatsApp</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="chat_messenger" name="chat_messenger" value="1" <?= $on('chat_messenger') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chat_messenger"><i class="bi bi-messenger me-1 text-primary" aria-hidden="true"></i>Messenger</label>
                    </div>
                    <label for="chat_greeting" class="form-label small">First WhatsApp message <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="chat_greeting" name="chat_greeting" value="<?= e($v('chat_greeting')) ?>" maxlength="200" class="form-control<?= $invalid('chat_greeting') ?>"
                           placeholder="Hi, I have a question about a product">
                    <?= $err('chat_greeting') ?>
                    <div class="form-text">Typed in for the customer when WhatsApp opens.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save contact details</button>
    </div>
</form>
