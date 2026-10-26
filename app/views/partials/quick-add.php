<?php
/**
 * Quick add popup: choose the size/colour of a product from its card (home, shop,
 * category, wishlist) and add it to the cart or order it now. app.js fills it from
 * the card's data-options; the form posts to /cart/add like the product page.
 */
?>
<div class="modal fade quick-add" id="quickAdd" tabindex="-1" aria-labelledby="quickAddTitle" aria-hidden="true" data-quick-add-modal>
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('cart/add')) ?>" class="modal-content" data-quick-add-form>
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="" data-qa-id>
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="quickAddTitle">Choose options</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="quick-add__product">
                    <img src="<?= e(asset('images/placeholder-product.svg')) ?>" alt="" width="72" height="90" loading="lazy" decoding="async" data-qa-img>
                    <div>
                        <p class="quick-add__name" data-qa-name></p>
                        <p class="quick-add__price" data-qa-price></p>
                    </div>
                </div>
                <fieldset class="option-group" data-qa-group="size" hidden>
                    <legend class="option-group__label">Size<span class="option-group__chosen" data-qa-chosen></span></legend>
                    <div class="option-chips" data-qa-chips></div>
                </fieldset>
                <fieldset class="option-group" data-qa-group="colour" hidden>
                    <legend class="option-group__label">Colour<span class="option-group__chosen" data-qa-chosen></span></legend>
                    <div class="option-chips" data-qa-chips></div>
                </fieldset>
                <p class="option-status small mt-3 mb-0" data-qa-status aria-live="polite"></p>
            </div>
            <div class="modal-footer quick-add__actions">
                <button type="submit" class="btn btn-outline-primary" data-qa-submit><?= icon('bag', 'me-1') ?>Add to Cart</button>
                <button type="submit" name="buy_now" value="1" class="btn btn-primary" data-qa-submit><?= icon('bag-check', 'me-1') ?>Order Now</button>
            </div>
        </form>
    </div>
</div>
