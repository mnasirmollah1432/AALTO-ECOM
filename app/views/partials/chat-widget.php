<?php
/**
 * Floating chat button (bottom right): WhatsApp / Messenger from
 * Admin -> Contact & social. Hidden when switched off or nothing is set.
 * Uses <details>, so it opens without JavaScript.
 */
$chat = App\Models\Contact::chat();
if (!$chat) {
    return;
}
?>
<details class="chat-widget" data-chat-widget>
    <summary class="chat-widget__toggle" aria-label="Chat with us">
        <span class="chat-widget__open"><?= icon('chat-dots-fill') ?></span>
        <span class="chat-widget__close"><?= icon('x') ?></span>
    </summary>
    <div class="chat-widget__panel">
        <p class="chat-widget__title">Chat with us</p>
        <?php foreach ($chat as $c): ?>
            <a href="<?= e($c['href']) ?>" data-contact="<?= e($c['key']) ?>" class="chat-widget__link chat-widget__link--<?= e($c['key']) ?>" target="_blank" rel="noopener noreferrer">
                <?= icon($c['icon']) ?><span><?= e($c['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</details>
