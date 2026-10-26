/**
 * Aalto Mart - storefront scripts (Vanilla JS, no build step).
 *
 * Kept deliberately small: every feature below is an enhancement and
 * the page works without JavaScript (forms submit, links navigate).
 * No API calls are made from the storefront.
 *
 * Add a feature as a module with init(); modules find their elements
 * through data-* attributes, so they are safe to run on every page.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    const modules = {};

    /* Header shadow once the page is scrolled */
    modules.stickyHeader = {
        init() {
            const header = document.querySelector('[data-sticky-header]');
            if (!header) return;
            const update = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
            window.addEventListener('scroll', update, { passive: true });
            update();
        },
    };

    /* Back-to-top link: only visible after scrolling (plain #main link without JS) */
    modules.backToTop = {
        init() {
            const link = document.querySelector('[data-back-to-top]');
            if (!link) return;
            const update = () => link.classList.toggle('is-visible', window.scrollY > 600);
            window.addEventListener('scroll', update, { passive: true });
            link.addEventListener('click', (event) => {
                event.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                document.getElementById('main')?.focus({ preventScroll: true });
            });
            update();
        },
    };

    /* GET forms (search, filters, sort) leave out empty fields: /shop?sale=1, not /shop?q=&sale=1 */
    modules.cleanGetForms = {
        init() {
            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (form.method !== 'get') return;
                form.querySelectorAll('input[name], select[name]').forEach((field) => {
                    if (field.value.trim() === '' && field.type !== 'checkbox') field.disabled = true;
                });
                // Re-enable if the page is restored from the back/forward cache
                window.addEventListener('pageshow', () => form.querySelectorAll('[disabled]').forEach((f) => { f.disabled = false; }), { once: true });
            });
        },
    };

    /* <form data-confirm="Question?"> asks before submitting (e.g. Clear cart) */
    modules.confirmForms = {
        init() {
            document.addEventListener('submit', (event) => {
                const form = event.target.closest('form[data-confirm]');
                if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
            }, true);
        },
    };

    /* Checkout: stop double submits (the server also refuses an empty cart) */
    modules.placeOrder = {
        init() {
            const form = document.querySelector('[data-checkout-form]');
            const button = form?.querySelector('[data-place-order]');
            if (!button) return;
            form.addEventListener('submit', () => {
                // defer so the click itself still submits the form
                setTimeout(() => {
                    button.disabled = true;
                    button.querySelector('[data-spinner]')?.classList.remove('d-none');
                    const label = button.querySelector('[data-label]');
                    if (label) label.textContent = 'Placing your order…';
                }, 0);
            });
        },
    };

    /*
     * Money for display, from integer cents (same format as PHP Money::format).
     * Amounts come from the server; the server recalculates everything on submit.
     */
    function formatCents(cents, symbol) {
        const whole = String(Math.trunc(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return `${symbol}${whole}.${String(cents % 100).padStart(2, '0')}`;
    }
    /** "70.00" -> 7000 (DECIMAL strings from data attributes; no floating point) */
    function toCents(text) {
        const m = String(text).match(/^(\d+)(?:\.(\d{1,2}))?$/);
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] || '0').padEnd(2, '0'), 10) : 0;
    }

    /*
     * Checkout: the thana list shows only the chosen district's thanas, the
     * delivery charge and total follow the district (zone charge), and
     * "Your Address: ..." previews the full address.
     */
    modules.checkoutLocation = {
        init() {
            const form = document.querySelector('[data-checkout-form]');
            const district = form?.querySelector('[data-district]');
            const thana = form?.querySelector('[data-thana]');
            if (!district || !thana) return;
            const address = form.querySelector('[data-address]');
            const preview = form.querySelector('[data-address-preview]');
            const previewText = form.querySelector('[data-address-text]');
            const shown = form.querySelector('[data-delivery-shown]');
            const subtotal = parseInt(form.dataset.subtotal, 10) || 0;
            const freeFrom = parseInt(form.dataset.freeFrom, 10) || 0;
            const symbol = form.dataset.currency || '';

            // Thanas per district, taken from the server-rendered optgroups
            const thanas = new Map();
            thana.querySelectorAll('optgroup').forEach((group) => {
                thanas.set(group.dataset.districtId, Array.from(group.children, (o) => [o.value, o.textContent]));
            });
            const placeholder = thana.querySelector('option[value=""]');
            const selectedThana = thana.value;

            const fillThanas = (keep) => {
                const list = thanas.get(district.value) || [];
                const options = list.map(([value, text]) => new Option(text, value, false, value === keep));
                placeholder.textContent = district.value ? 'Select thana' : 'Select district first';
                thana.replaceChildren(placeholder, ...options);
                thana.disabled = !district.value;
                if (!options.some((o) => o.selected)) thana.value = '';
            };

            const updateTotals = () => {
                const option = district.selectedOptions[0];
                const known = Boolean(district.value);
                const charge = known ? toCents(option.dataset.charge) : 0;
                const delivery = known ? (freeFrom > 0 && subtotal >= freeFrom ? 0 : charge) : null;
                const out = form.querySelector('[data-delivery-out]');
                out.textContent = delivery === null ? 'Select district' : (delivery === 0 ? 'Free' : formatCents(delivery, symbol));
                out.className = delivery === 0 ? 'text-success fw-semibold' : (delivery === null ? 'text-muted' : '');
                form.querySelector('[data-zone-out]').textContent = known ? option.dataset.zone : '';
                const total = formatCents(subtotal + (delivery || 0), symbol);
                document.querySelectorAll('[data-total-out]').forEach((el) => { el.textContent = total; });
                shown.value = delivery === null ? '' : `${Math.trunc(delivery / 100)}.${String(delivery % 100).padStart(2, '0')}`;
            };

            const updatePreview = () => {
                const parts = [
                    address.value.split('\n').map((l) => l.trim()).filter(Boolean).join(', '),
                    thana.value ? thana.selectedOptions[0].textContent : '',
                    district.value ? district.selectedOptions[0].textContent : '',
                ].filter(Boolean);
                preview.hidden = parts.length === 0;
                previewText.textContent = parts.join(', ');
            };

            district.addEventListener('change', () => {
                fillThanas('');
                updateTotals();
                updatePreview();
                thana.classList.remove('is-invalid');
            });
            thana.addEventListener('change', updatePreview);
            address.addEventListener('input', updatePreview);

            fillThanas(selectedThana);
            updateTotals();
            updatePreview();
        },
    };

    /*
     * Product page: [Add to Cart] [Order Now]. Once the product (or the chosen
     * size/colour) is in the cart, "Add to Cart" becomes a  - N +  stepper that
     * changes the cart quantity. Sizes/colours: options with no stock for the
     * other chosen option get a cross and can't be picked. The server checks
     * everything again.
     */
    modules.buyForm = {
        init() {
            const form = document.querySelector('[data-buy-form]');
            if (!form) return;
            const price = parseInt(form.dataset.price, 10) || 0;
            const symbol = form.dataset.currency || '';
            const orderBtns = Array.from(document.querySelectorAll('[data-order-now]'));
            const status = form.querySelector('[data-option-status]');
            const variants = form.dataset.variants ? JSON.parse(form.dataset.variants) : null;
            if (!variants) return; // plain products are fully rendered by the server

            // Total: what is already in the cart for the chosen option, else one
            const setTotal = (v) => {
                const text = formatCents(price * Math.max(1, v ? v.in_cart : 1), symbol);
                document.querySelectorAll('[data-line-total]').forEach((el) => { el.textContent = text; });
            };

            const group = (name) => form.querySelector(`[data-option-group="${name}"]`);
            const radios = (name) => Array.from(form.querySelectorAll(`input[name="${name}"]`));
            const chosen = (name) => (radios(name).find((r) => r.checked) || {}).value;
            const hasSize = Boolean(group('size'));
            const hasColour = Boolean(group('colour'));
            const find = (size, colour) => variants.find((v) => v.size === (size ?? '') && v.colour === (colour ?? ''));
            const stockOf = (size, colour) => variants
                .filter((v) => (size === undefined || v.size === size) && (colour === undefined || v.colour === colour))
                .reduce((sum, v) => sum + v.stock, 0);

            const refresh = () => {
                const size = hasSize ? chosen('size') : '';
                // Cross out sizes sold out in every colour, and colours sold out in the chosen size
                const mark = (name, available) => radios(name).forEach((r) => {
                    const out = !available(r.value);
                    r.disabled = out;
                    if (out && r.checked) r.checked = false;
                    const label = form.querySelector(`label[for="${r.id}"]`);
                    label.classList.toggle('is-out', out);
                    label.title = out ? 'Out of stock' : '';
                });
                if (hasSize) mark('size', (s) => stockOf(s, undefined) > 0);
                if (hasColour) mark('colour', (c) => stockOf(hasSize && size ? size : undefined, c) > 0);
                ['size', 'colour'].forEach((name) => {
                    const out = group(name)?.querySelector('[data-option-chosen]');
                    if (out) out.textContent = chosen(name) ? `: ${chosen(name)}` : '';
                });

                const s = hasSize ? chosen('size') : '';
                const c = hasColour ? chosen('colour') : '';
                const complete = (!hasSize || s) && (!hasColour || c);
                const v = complete ? find(s, c) : null;
                const soldOut = complete && (!v || v.stock <= 0);
                if (!complete) {
                    status.textContent = `Please choose ${[hasSize && !s ? 'a size' : '', hasColour && !c ? 'a colour' : ''].filter(Boolean).join(' and ')}.`;
                    status.className = 'option-status small mb-3 text-muted';
                } else if (soldOut) {
                    status.textContent = 'This option is out of stock.';
                    status.className = 'option-status small mb-3 text-danger';
                } else if (v.in_cart > 0) {
                    status.textContent = `${v.in_cart} of this option ${v.in_cart === 1 ? 'is' : 'are'} in your cart.`;
                    status.className = 'option-status small mb-3 text-success';
                } else {
                    status.textContent = v.stock <= 5 ? `Only ${v.stock} left in this option.` : 'In stock.';
                    status.className = `option-status small mb-3 ${v.stock <= 5 ? 'text-warning-emphasis' : 'text-success'}`;
                }
                orderBtns.forEach((b) => { b.disabled = soldOut; }); // a missing choice is asked for by the browser
                setTotal(complete && !soldOut ? v : null);
            };
            form.addEventListener('change', (e) => {
                if (e.target.name === 'size' || e.target.name === 'colour') refresh();
            });
            refresh();
        },
    };

    /*
     * Product image zoom: magnify under the pointer (mouse/trackpad) and a
     * full-screen viewer (click the image or the zoom button) with previous/
     * next and tap-to-zoom; phones can also pinch there.
     */
    modules.imageZoom = {
        init() {
            const gallery = document.querySelector('[data-gallery]');
            const frame = gallery?.querySelector('[data-zoom]');
            const main = gallery?.querySelector('[data-gallery-main]');
            const dialog = document.querySelector('[data-lightbox]');
            if (!frame || !main) return;

            // Hover zoom on devices with a precise pointer
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                frame.classList.add('is-zoomable');
                frame.addEventListener('mousemove', (e) => {
                    const r = frame.getBoundingClientRect();
                    main.style.transformOrigin = `${((e.clientX - r.left) / r.width) * 100}% ${((e.clientY - r.top) / r.height) * 100}%`;
                    frame.classList.add('is-zooming');
                });
                frame.addEventListener('mouseleave', () => frame.classList.remove('is-zooming'));
            }

            if (!dialog || typeof dialog.showModal !== 'function') return;
            const images = JSON.parse(gallery.dataset.images || '[]');
            const img = dialog.querySelector('[data-lightbox-img]');
            const stage = dialog.querySelector('[data-lightbox-stage]');
            const count = dialog.querySelector('[data-lightbox-count]');
            let index = 0;

            const show = (i) => {
                index = (i + images.length) % images.length;
                stage.classList.remove('is-zoomed');
                img.src = images[index];
                count.textContent = images.length > 1 ? `${index + 1} / ${images.length} · tap to zoom` : 'Tap to zoom';
            };
            const open = () => {
                // Start on the image shown in the gallery
                const current = main.currentSrc || main.src;
                const at = images.findIndex((u) => current.endsWith(u) || current.includes(u.replace(/\.webp$/, '')));
                show(at >= 0 ? at : 0);
                dialog.showModal();
            };

            frame.addEventListener('click', (e) => {
                if (e.target.closest('a')) return;
                open();
            });
            dialog.addEventListener('click', (e) => {
                const step = e.target.closest('[data-lightbox-step]');
                if (step) return show(index + parseInt(step.dataset.lightboxStep, 10));
                if (e.target.closest('[data-lightbox-close]') || e.target === dialog) return dialog.close();
                if (e.target === img) {
                    // Zoom in where tapped, or back out
                    const r = img.getBoundingClientRect();
                    const x = (e.clientX - r.left) / r.width;
                    const y = (e.clientY - r.top) / r.height;
                    const zoomed = stage.classList.toggle('is-zoomed');
                    if (zoomed) {
                        stage.scrollLeft = x * stage.scrollWidth - stage.clientWidth / 2;
                        stage.scrollTop = y * stage.scrollHeight - stage.clientHeight / 2;
                    }
                }
            });
            dialog.addEventListener('keydown', (e) => {
                if (images.length > 1 && e.key === 'ArrowRight') show(index + 1);
                if (images.length > 1 && e.key === 'ArrowLeft') show(index - 1);
            });
            // Swipe left/right between images (when not zoomed)
            let startX = null;
            stage.addEventListener('touchstart', (e) => { startX = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
            stage.addEventListener('touchend', (e) => {
                if (startX === null || stage.classList.contains('is-zoomed') || images.length < 2) return;
                const dx = e.changedTouches[0].clientX - startX;
                if (Math.abs(dx) > 50) show(index + (dx < 0 ? 1 : -1));
                startX = null;
            });
        },
    };

    /*
     * Home slider: the strip already swipes (CSS scroll-snap). This adds
     * dots, arrows and autoplay, which pauses on hover, focus, a hidden tab
     * and for visitors who prefer reduced motion.
     */
    modules.slider = {
        init() {
            document.querySelectorAll('[data-slider]').forEach((slider) => {
                const track = slider.querySelector('[data-slider-track]');
                const slides = Array.from(track.children);
                if (slides.length < 2) return;
                const dotsBox = slider.querySelector('[data-slider-dots]');
                let index = 0;
                let timer = null;

                const dots = slides.map((_, i) => {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'slider__dot';
                    dot.setAttribute('aria-label', `Show slide ${i + 1}`);
                    dot.addEventListener('click', () => { go(i); restart(); });
                    dotsBox?.append(dot);
                    return dot;
                });
                const mark = (i) => {
                    index = i;
                    dots.forEach((d, n) => {
                        d.classList.toggle('is-active', n === i);
                        d.setAttribute('aria-current', n === i ? 'true' : 'false');
                    });
                };
                const go = (i) => {
                    const n = (i + slides.length) % slides.length;
                    track.scrollTo({ left: n * track.clientWidth });
                    mark(n);
                };
                // Follow manual swipes
                let settle;
                track.addEventListener('scroll', () => {
                    clearTimeout(settle);
                    settle = setTimeout(() => mark(Math.round(track.scrollLeft / track.clientWidth)), 80);
                }, { passive: true });
                slider.querySelector('[data-slider-prev]')?.addEventListener('click', () => { go(index - 1); restart(); });
                slider.querySelector('[data-slider-next]')?.addEventListener('click', () => { go(index + 1); restart(); });

                const delay = parseInt(slider.dataset.autoplay, 10) || 0;
                const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const stop = () => { clearInterval(timer); timer = null; };
                const start = () => { if (delay && !calm && !timer && !document.hidden) timer = setInterval(() => go(index + 1), delay); };
                const restart = () => { stop(); start(); };
                slider.addEventListener('mouseenter', stop);
                slider.addEventListener('mouseleave', start);
                slider.addEventListener('focusin', stop);
                slider.addEventListener('focusout', start);
                track.addEventListener('touchstart', stop, { passive: true });
                document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
                window.addEventListener('resize', () => track.scrollTo({ left: index * track.clientWidth, behavior: 'instant' }));
                mark(0);
                start();
            });
        },
    };

    /* Contact page: copy a number (e.g. for imo) - [data-copy] */
    modules.copy = {
        init() {
            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-copy]');
                if (!button || !navigator.clipboard) return;
                const label = button.querySelector('[data-copy-label]');
                try {
                    await navigator.clipboard.writeText(button.dataset.copy);
                    if (label) { label.textContent = 'Copied'; setTimeout(() => { label.textContent = 'Copy'; }, 1800); }
                } catch (e) { /* clipboard blocked: the number is on screen */ }
            });
        },
    };

    /* Chat button: close when clicking elsewhere or pressing Escape */
    modules.chatWidget = {
        init() {
            const widget = document.querySelector('[data-chat-widget]');
            if (!widget) return;
            document.addEventListener('click', (e) => { if (widget.open && !widget.contains(e.target)) widget.open = false; });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && widget.open) { widget.open = false; widget.querySelector('summary').focus(); } });
        },
    };

    /* Side cart: opens by itself after an item was added or changed (data-open="1") */
    modules.cartDrawer = {
        init() {
            const drawer = document.querySelector('[data-cart-drawer]');
            if (!drawer || drawer.dataset.open !== '1' || !window.bootstrap) return;
            window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
        },
    };

    /*
     * Quick add: "Add To Cart" on a card of a product with sizes/colours opens a
     * popup to choose them (sold-out combinations crossed out), then posts to /cart/add.
     */
    modules.quickAdd = {
        init() {
            const modalEl = document.querySelector('[data-quick-add-modal]');
            if (!modalEl || !window.bootstrap) return;
            const form = modalEl.querySelector('[data-quick-add-form]');
            const status = form.querySelector('[data-qa-status]');
            const submits = Array.from(form.querySelectorAll('[data-qa-submit]'));
            const group = (name) => form.querySelector(`[data-qa-group="${name}"]`);
            let opts = { sizes: [], colours: [], stock: {} };

            const radios = (name) => Array.from(form.querySelectorAll(`input[name="${name}"]`));
            const chosen = (name) => (radios(name).find((r) => r.checked) || {}).value;
            const stockOf = (size, colour) => Object.keys(opts.stock)
                .filter((k) => { const [s, c] = k.split('|'); return (size === undefined || s === size) && (colour === undefined || c === colour); })
                .reduce((sum, k) => sum + opts.stock[k], 0);

            const chips = (name, values) => {
                const box = group(name);
                const list = box.querySelector('[data-qa-chips]');
                list.replaceChildren();
                box.hidden = values.length === 0;
                values.forEach((v, i) => {
                    const input = document.createElement('input');
                    Object.assign(input, { type: 'radio', name, value: v.name, id: `qa-${name}-${i}`, className: 'option-chip__input', required: true });
                    const label = document.createElement('label');
                    label.htmlFor = input.id;
                    label.className = `option-chip${name === 'colour' ? ' option-chip--colour' : ''}`;
                    if (v.hex) {
                        const sw = document.createElement('span');
                        sw.className = 'option-chip__swatch';
                        sw.style.backgroundColor = v.hex;
                        label.append(sw);
                    }
                    label.append(v.name);
                    list.append(input, label);
                });
            };

            const refresh = () => {
                const hasSize = opts.sizes.length > 0;
                const hasColour = opts.colours.length > 0;
                const size = hasSize ? chosen('size') : '';
                const mark = (name, available) => radios(name).forEach((r) => {
                    const out = !available(r.value);
                    r.disabled = out;
                    if (out && r.checked) r.checked = false;
                    const label = form.querySelector(`label[for="${r.id}"]`);
                    label.classList.toggle('is-out', out);
                    label.title = out ? 'Out of stock' : '';
                });
                if (hasSize) mark('size', (s) => stockOf(s, undefined) > 0);
                if (hasColour) mark('colour', (c) => stockOf(hasSize && size ? size : undefined, c) > 0);
                // Only one choice left: pick it
                ['size', 'colour'].forEach((name) => {
                    const open = radios(name).filter((r) => !r.disabled);
                    if (open.length === 1 && !chosen(name)) open[0].checked = true;
                    const out = group(name).querySelector('[data-qa-chosen]');
                    out.textContent = chosen(name) ? `: ${chosen(name)}` : '';
                });
                const s = hasSize ? chosen('size') : '';
                const c = hasColour ? chosen('colour') : '';
                const complete = (!hasSize || s) && (!hasColour || c);
                const left = complete ? (opts.stock[`${s || ''}|${c || ''}`] || 0) : 0;
                if (!complete) {
                    status.textContent = `Please choose ${[hasSize && !s ? 'a size' : '', hasColour && !c ? 'a colour' : ''].filter(Boolean).join(' and ')}.`;
                    status.className = 'option-status small mt-3 mb-0 text-muted';
                } else if (left <= 0) {
                    status.textContent = 'This option is out of stock.';
                    status.className = 'option-status small mt-3 mb-0 text-danger';
                } else {
                    status.textContent = left <= 5 ? `Only ${left} left in this option.` : 'In stock.';
                    status.className = `option-status small mt-3 mb-0 ${left <= 5 ? 'text-warning-emphasis' : 'text-success'}`;
                }
                submits.forEach((b) => { b.disabled = complete && left <= 0; }); // a missing choice is asked for by the browser
            };
            form.addEventListener('change', (e) => { if (e.target.name === 'size' || e.target.name === 'colour') refresh(); });

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-quick-add]');
                if (!btn) return;
                try { opts = JSON.parse(btn.dataset.options); } catch (err) { return; } // fall back to the product page
                e.preventDefault();
                form.querySelector('[data-qa-id]').value = btn.dataset.productId;
                form.querySelector('[data-qa-name]').textContent = btn.dataset.name;
                form.querySelector('[data-qa-price]').textContent = btn.dataset.price;
                const img = form.querySelector('[data-qa-img]');
                img.src = btn.dataset.image;
                img.alt = btn.dataset.name;
                chips('size', opts.sizes.map((name) => ({ name })));
                chips('colour', opts.colours);
                refresh();
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        },
    };

    /* Order confirmation: print button */
    modules.print = {
        init() {
            document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
        },
    };

    /* Filters and sort apply as soon as they change ([data-autosubmit]) */
    modules.autoSubmit = {
        init() {
            document.addEventListener('change', (event) => {
                const field = event.target.closest('[data-autosubmit]');
                if (field?.form) field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
            });
        },
    };

    /* Quantity stepper: [data-qty] with +/- buttons around a number input */
    modules.quantity = {
        init() {
            document.querySelectorAll('[data-qty]').forEach((box) => {
                const input = box.querySelector('input');
                const min = parseInt(input.min, 10) || 1;
                // max is read each time: a chosen size/colour can change it
                const clamp = (value) => Math.min(parseInt(input.max, 10) || 99, Math.max(min, Number.isFinite(value) ? value : min));

                box.querySelectorAll('[data-qty-step]').forEach((button) => {
                    button.addEventListener('click', () => {
                        input.value = clamp((parseInt(input.value, 10) || min) + parseInt(button.dataset.qtyStep, 10));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
                input.addEventListener('change', () => {
                    input.value = clamp(parseInt(input.value, 10));
                });
            });
        },
    };

    /* Product gallery: thumbnails swap the main image (links to the full image without JS) */
    modules.gallery = {
        init() {
            const gallery = document.querySelector('[data-gallery]');
            const main = gallery?.querySelector('[data-gallery-main]');
            if (!main) return;

            gallery.addEventListener('click', (event) => {
                const thumb = event.target.closest('[data-gallery-thumb]');
                if (!thumb) return;
                event.preventDefault();

                if (thumb.dataset.srcset) {
                    main.srcset = thumb.dataset.srcset;
                } else {
                    main.removeAttribute('srcset');
                }
                main.src = thumb.dataset.full;

                gallery.querySelectorAll('[data-gallery-thumb]').forEach((t) => {
                    t.classList.toggle('is-active', t === thumb);
                    if (t === thumb) t.setAttribute('aria-current', 'true');
                    else t.removeAttribute('aria-current');
                });
            });
        },
    };

    function boot() {
        Object.values(modules).forEach((module) => {
            try {
                module.init();
            } catch (error) {
                console.error('[AaltoMart] module failed to start', error);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
