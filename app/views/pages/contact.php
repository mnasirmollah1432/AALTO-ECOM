<?php
/**
 * Contact us: channels from Admin -> Contact & social.
 *
 * @var array $values    all contact_* settings
 * @var array $channels  key, label, value, href, icon, external (only the ones that are set)
 */
$actions = [
    'whatsapp'  => 'Chat on WhatsApp',
    'messenger' => 'Message us',
    'imo'       => 'Call on imo',
    'phone'     => 'Call now',
    'email'     => 'Send an email',
    'facebook'  => 'Visit page',
    'instagram' => 'Follow us',
    'youtube'   => 'Watch videos',
    'tiktok'    => 'Follow us',
];
$hasVisit = $values['contact_address'] !== '' || $values['contact_hours'] !== '';
?>
<div class="page-head">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Contact us</li>
            </ol>
        </nav>
        <h1 class="page-head__title">Contact us</h1>
        <p class="page-head__count mb-0">Questions about a product or your order? Message us on your favourite app.</p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$channels && !$hasVisit): ?>
        <div class="empty-state my-4">
            <?= icon('chat-dots-fill') ?>
            <h2 class="h5 mt-2">Contact details are coming soon</h2>
            <p class="text-muted mb-0">Please check back shortly.</p>
        </div>
    <?php else: ?>
        <ul class="contact-grid list-unstyled" aria-label="Ways to contact us">
            <?php foreach ($channels as $c): ?>
                <li class="contact-card contact-card--<?= e($c['key']) ?>">
                    <span class="contact-card__icon" aria-hidden="true"><?= icon($c['icon']) ?></span>
                    <span class="contact-card__body">
                        <span class="contact-card__label"><?= e($c['label']) ?></span>
                        <span class="contact-card__value"><?= e($c['value']) ?></span>
                    </span>
                    <span class="contact-card__actions">
                        <?php if ($c['href']): ?>
                            <a href="<?= e($c['href']) ?>" class="contact-card__btn" data-contact="<?= e($c['key']) ?>"
                               <?= $c['external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($actions[$c['key']] ?? 'Open') ?></a>
                        <?php endif; ?>
                        <?php if (in_array($c['key'], ['imo', 'whatsapp', 'phone'], true)): ?>
                            <button type="button" class="contact-card__copy js-only" data-copy="<?= e($c['value']) ?>" aria-label="Copy <?= e($c['label']) ?> number">
                                <?= icon('copy') ?><span data-copy-label>Copy</span>
                            </button>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($hasVisit): ?>
            <section class="contact-visit" aria-labelledby="visit-title">
                <h2 class="contact-visit__title" id="visit-title">Visit or reach us</h2>
                <dl class="contact-visit__list">
                    <?php if ($values['contact_address'] !== ''): ?>
                        <div><dt><?= icon('geo-alt') ?> Address</dt><dd><?= nl2br(e($values['contact_address'])) ?>
                            <?php if ($values['contact_map'] !== ''): ?>
                                <a href="<?= e($values['contact_map']) ?>" target="_blank" rel="noopener noreferrer" class="d-inline-block mt-1">Open in Google Maps</a>
                            <?php endif; ?></dd></div>
                    <?php endif; ?>
                    <?php if ($values['contact_hours'] !== ''): ?>
                        <div><dt><?= icon('clock') ?> Opening hours</dt><dd><?= nl2br(e($values['contact_hours'])) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>

        <p class="small text-muted mt-4 mb-0"><?= icon('cash-coin', 'me-1') ?>We confirm every order by phone before delivery. Pay with cash when it arrives.</p>
    <?php endif; ?>
</div>
