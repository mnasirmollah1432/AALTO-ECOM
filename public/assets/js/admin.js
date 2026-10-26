/**
 * Aalto Mart - admin panel scripts (Vanilla JS).
 * Same module pattern as app.js: each module has init() and
 * targets elements through data-* attributes. Everything here is a
 * convenience - the server validates and protects every action.
 */
(function () {
    'use strict';

    const modules = {};
    const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const IMAGE_EXT = /\.(jpe?g|png|webp)$/i;

    /** Mirrors PHP slugify(): "Café & Co" -> "cafe-co" */
    function slugify(text, max = 140) {
        return text
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, max)
            .replace(/-+$/, '');
    }

    function formatBytes(bytes) {
        return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1).replace(/\.0$/, '')} MB` : `${Math.round(bytes / 1024)} KB`;
    }

    /* Show / hide password: <button data-toggle-password="inputId"> */
    modules.passwordToggle = {
        init() {
            document.querySelectorAll('[data-toggle-password]').forEach((button) => {
                const input = document.getElementById(button.dataset.togglePassword);
                if (!input) return;
                button.addEventListener('click', () => {
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    button.setAttribute('aria-pressed', String(show));
                    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                    input.focus();
                });
            });
        },
    };

    /* Sign-in form: block submit while required fields are empty */
    modules.authForm = {
        init() {
            const form = document.querySelector('[data-auth-form]');
            if (!form) return;

            form.addEventListener('submit', (event) => {
                let firstInvalid = null;
                form.querySelectorAll('input[required]').forEach((input) => {
                    const empty = input.value.trim() === '';
                    input.classList.toggle('is-invalid', empty);
                    if (empty && !firstInvalid) firstInvalid = input;
                });
                if (firstInvalid) {
                    event.preventDefault();
                    firstInvalid.focus();
                }
            });

            form.querySelectorAll('input').forEach((input) => {
                input.addEventListener('input', () => input.classList.remove('is-invalid'));
            });
        },
    };

    /*
     * <form data-confirm="Question?" data-confirm-button="Delete">
     * Asks in a Bootstrap modal before submitting. Without JS the form
     * simply submits (still CSRF-protected on the server).
     */
    modules.confirmForms = {
        modal: null,
        pending: null,

        init() {
            if (!document.querySelector('form[data-confirm]') || !window.bootstrap) return;

            const el = document.createElement('div');
            el.className = 'modal fade';
            el.tabIndex = -1;
            el.setAttribute('aria-labelledby', 'confirmTitle');
            el.setAttribute('aria-hidden', 'true');
            el.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0 pb-0">
                            <h2 class="modal-title h5" id="confirmTitle">Are you sure?</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body"><p class="mb-0" data-confirm-message></p></div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" data-confirm-ok>Confirm</button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(el);
            this.modal = new bootstrap.Modal(el);

            el.querySelector('[data-confirm-ok]').addEventListener('click', () => {
                const form = this.pending;
                this.pending = null;
                this.modal.hide();
                if (form) {
                    form.dataset.confirmed = '1';
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });

            // Capture phase: runs before other submit handlers (e.g. busy state)
            document.addEventListener('submit', (event) => {
                const form = event.target.closest('form[data-confirm]');
                if (!form || form.dataset.confirmed === '1') return;
                // data-confirm-when="value": only ask when the form's status field has that value
                if (form.dataset.confirmWhen && form.elements.status?.value !== form.dataset.confirmWhen) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                this.pending = form;
                el.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
                el.querySelector('[data-confirm-ok]').textContent = form.dataset.confirmButton || 'Confirm';
                this.modal.show();
            }, true);
        },
    };

    /* Disable the submit button while a form posts (no double submits) */
    modules.busyForms = {
        init() {
            document.addEventListener('submit', (event) => {
                if (event.defaultPrevented) return;
                const button = event.target.querySelector('[data-submit]');
                if (!button) return;
                // Defer so the button's value is still sent with this submit
                setTimeout(() => {
                    button.disabled = true;
                    button.querySelector('[data-submit-spinner]')?.classList.remove('d-none');
                }, 0);
            });
        },
    };

    /* Slug placeholder follows the name while the slug field is blank */
    modules.slugPreview = {
        init() {
            const source = document.querySelector('[data-slug-source]');
            const target = document.querySelector('[data-slug-target]');
            if (!source || !target) return;

            const update = () => {
                target.placeholder = slugify(source.value) || 'generated-from-name';
            };
            source.addEventListener('input', update);
            target.addEventListener('blur', () => {
                if (target.value.trim() !== '') target.value = slugify(target.value);
            });
            update();
        },
    };

    /* Live character counter: <textarea data-char-count="counterId"> */
    modules.charCount = {
        init() {
            document.querySelectorAll('[data-char-count]').forEach((field) => {
                const counter = document.getElementById(field.dataset.charCount);
                if (!counter) return;
                const max = parseInt(counter.dataset.max, 10) || field.maxLength;
                const update = () => {
                    const length = field.value.length;
                    counter.textContent = `${length} / ${max}`;
                    counter.classList.toggle('text-danger', length > max);
                };
                field.addEventListener('input', update);
                update();
            });
        },
    };

    /*
     * Image upload fields. Each file input carries its slot's standard as
     * data-spec (from app/config/images.php). Before uploading, the admin sees
     * the image in the target frame (object-fit: cover shows the centre crop),
     * its dimensions, ratio, size and format, and whether it passes. The server
     * repeats every check and does the real crop / resize / WebP conversion.
     */
    const imageCheck = {
        ratioLabel(w, h) {
            const gcd = (a, b) => (b ? gcd(b, a % b) : a);
            const g = gcd(w, h);
            const [a, b] = [w / g, h / g];
            return a <= 20 && b <= 20 ? `${a}:${b}` : `${(w / h).toFixed(2).replace(/\.?0+$/, '')}:1`;
        },

        load(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = url;
            });
        },

        /** Check one file: {ok, url, width, height, lines: [[kind, text]]}, kind = ok|warn|info|error */
        async analyse(file, spec) {
            const r = { file, ok: false, url: null, width: 0, height: 0, lines: [] };
            const fail = (text) => { r.lines.push(['error', text]); return r; };

            if (!IMAGE_TYPES.includes(file.type) || !IMAGE_EXT.test(file.name)) {
                return fail('Only JPG, PNG or WebP images are allowed.');
            }
            if (file.size > spec.maxBytes) {
                return fail(`The file is too large (${formatBytes(file.size)}). Maximum upload size is ${formatBytes(spec.maxBytes)}.`);
            }
            r.url = URL.createObjectURL(file);
            let img;
            try {
                img = await this.load(r.url);
            } catch (e) {
                return fail('This file could not be read as an image.');
            }
            const [w, h] = [img.naturalWidth, img.naturalHeight];
            Object.assign(r, { width: w, height: h });
            if (w * h > spec.maxPixels) {
                return fail(`Too large to process (${w} × ${h} px). Use an image under 16 megapixels.`);
            }

            // Same centre crop as the server
            let [cw, ch] = [w, h];
            if (spec.fit === 'cover') {
                if (w / h > spec.ratio) cw = Math.round(h * spec.ratio); else ch = Math.round(w / spec.ratio);
            }
            if (cw < spec.minWidth || ch < spec.minHeight) {
                const after = cw !== w || ch !== h ? ` (${cw} × ${ch} px after cropping to ${spec.ratioLabel})` : '';
                return fail(`Image is too small${after}. Minimum is ${spec.minWidth} × ${spec.minHeight} px; ${spec.width} × ${spec.height} px is recommended.`);
            }

            const offRatio = spec.fit === 'cover' && Math.abs((w / h) / spec.ratio - 1) > spec.tolerance;
            r.lines.push(offRatio
                ? ['warn', `Image is not ${spec.ratioLabel} ratio. It will be centre-cropped to ${spec.ratioLabel}; the preview shows the result.`]
                : ['ok', 'Valid image']);
            if (cw > spec.width) {
                r.lines.push(['info', `Will be resized to ${spec.width} × ${spec.height} px.`]);
            } else if (cw < spec.width) {
                r.lines.push(['info', `Smaller than the recommended ${spec.width} × ${spec.height} px; kept at ${cw} × ${ch} px (never enlarged).`]);
            }
            r.lines.push(file.size > spec.targetKb * 1024
                ? ['info', `Size is above the ${spec.targetKb} KB target; it will be converted to WebP and compressed.`]
                : ['info', `Within the ${spec.targetKb} KB target; it will be saved as WebP.`]);
            r.ok = true;
            return r;
        },

        /** Facts + status lines into target (text only: file names never become HTML). */
        render(target, r, compact = false) {
            target.replaceChildren();
            if (r.width) {
                const facts = document.createElement('dl');
                facts.className = 'image-check__facts';
                const add = (label, value) => {
                    const row = document.createElement('div');
                    const dt = document.createElement('dt');
                    const dd = document.createElement('dd');
                    dt.textContent = label;
                    dd.textContent = value;
                    row.append(dt, dd);
                    facts.appendChild(row);
                };
                add('Dimensions', `${r.width} × ${r.height}`);
                if (!compact) add('Ratio', this.ratioLabel(r.width, r.height));
                add('Size', formatBytes(r.file.size));
                add('Format', (r.file.type.split('/')[1] || '').toUpperCase().replace('JPEG', 'JPG'));
                target.appendChild(facts);
            }
            const list = document.createElement('ul');
            list.className = 'image-check__lines';
            const icons = { ok: '✓', warn: '⚠', info: '•', error: '✕' };
            for (const [kind, text] of (compact ? r.lines.filter(([k]) => k !== 'info') : r.lines)) {
                const li = document.createElement('li');
                li.className = `is-${kind}`;
                li.textContent = `${icons[kind]} ${text}`;
                list.appendChild(li);
            }
            target.appendChild(list);
        },
    };

    modules.imageFields = {
        spec(input) {
            try {
                return JSON.parse(input.dataset.spec || '');
            } catch (e) {
                return null;
            }
        },

        init() {
            document.querySelectorAll('[data-image-field]').forEach((field) => this.single(field));
            this.multiple();
            this.replace();
            document.querySelectorAll('[data-remove-gallery]').forEach((box) => {
                box.addEventListener('change', () => {
                    box.closest('[data-gallery-item]')?.classList.toggle('is-removed', box.checked);
                });
            });
        },

        /* One image (category image, product main image) */
        single(field) {
            const input = field.querySelector('[data-image-input]');
            const box = field.querySelector('[data-image-preview]');
            const status = field.querySelector('[data-image-status]');
            const meta = field.querySelector('[data-current-meta]');
            const remove = field.querySelector('[data-remove-image]') || document.querySelector('[data-remove-image]');
            const spec = input && this.spec(input);
            if (!input || !box || !status || !spec) return;

            const original = box.innerHTML;
            let url = null;

            input.addEventListener('change', async () => {
                if (url) URL.revokeObjectURL(url);
                url = null;
                box.innerHTML = original;
                status.replaceChildren();
                meta?.classList.remove('d-none');
                input.classList.remove('is-invalid');

                const file = input.files[0];
                if (!file) return;
                const r = await imageCheck.analyse(file, spec);
                imageCheck.render(status, r);
                if (!r.ok) {
                    if (r.url) URL.revokeObjectURL(r.url);
                    input.value = '';               // an unusable file is never submitted
                    input.classList.add('is-invalid');
                    return;
                }
                url = r.url;
                const img = document.createElement('img');
                img.src = url;
                img.alt = 'Preview of the selected image';
                box.replaceChildren(img);
                meta?.classList.add('d-none');
                if (remove) remove.checked = false;
                box.classList.remove('is-removed');
            });

            remove?.addEventListener('change', () => box.classList.toggle('is-removed', remove.checked));
            if (remove?.checked) box.classList.add('is-removed');
        },

        /*
         * New gallery images. A plain file input REPLACES its selection each time
         * it is used, so picking images one by one would keep only the last one.
         * Here every pick is ADDED to a pending list (kept in the input through
         * DataTransfer), each pending image has a remove button, and an unusable
         * file is skipped with the reason instead of clearing the whole list.
         */
        multiple() {
            const input = document.querySelector('[data-multi-image-input]');
            const box = document.querySelector('[data-multi-preview]');
            const spec = input && this.spec(input);
            if (!input || !box || !spec) return;

            const maxFiles = parseInt(input.dataset.maxFiles, 10) || 0;
            const help = document.getElementById('images-help');
            let canKeep = true; // browsers without DataTransfer: normal replace behaviour
            try { new DataTransfer(); } catch (e) { canKeep = false; }
            let pending = [];   // [{file, url, result}]

            const message = (text) => {
                let feedback = input.parentElement.querySelector('[data-multi-error]');
                if (!feedback) {
                    feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback d-block';
                    feedback.dataset.multiError = '';
                    input.insertAdjacentElement('afterend', feedback);
                }
                feedback.textContent = text;
                input.classList.toggle('is-invalid', text !== '');
            };
            const same = (a, b) => a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;

            const sync = () => {
                if (canKeep) {
                    const dt = new DataTransfer();
                    pending.forEach((p) => dt.items.add(p.file));
                    input.files = dt.files;
                }
                if (help && maxFiles) {
                    const left = maxFiles - pending.length;
                    help.textContent = pending.length
                        ? `${pending.length} selected · ${left} more can be added. Pick more files to add them.`
                        : `You can add ${maxFiles} more. Select several at once, or add them one by one.`;
                }
            };

            const render = () => {
                box.replaceChildren();
                pending.forEach((p, i) => {
                    const item = document.createElement('div');
                    item.className = 'gallery-item';
                    const img = document.createElement('img');
                    img.src = p.url;
                    img.alt = `New image ${p.file.name}`;
                    img.style.aspectRatio = `${spec.width} / ${spec.height}`;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'gallery-item__remove';
                    remove.setAttribute('aria-label', `Remove ${p.file.name}`);
                    remove.textContent = '×';
                    remove.addEventListener('click', () => {
                        URL.revokeObjectURL(p.url);
                        pending.splice(i, 1);
                        sync();
                        render();
                        message('');
                    });
                    const st = document.createElement('div');
                    st.className = 'image-check image-check--compact';
                    imageCheck.render(st, p.result, true);
                    item.append(img, remove, st);
                    box.appendChild(item);
                });
            };

            input.addEventListener('change', async () => {
                message('');
                const picked = Array.from(input.files);
                if (!canKeep) {             // old browser: the new pick replaces the list
                    pending.forEach((p) => URL.revokeObjectURL(p.url));
                    pending = [];
                }
                const problems = [];
                for (const file of picked) {
                    if (pending.some((p) => same(p.file, file))) continue;          // already in the list
                    if (maxFiles && pending.length >= maxFiles) {
                        problems.push(`"${file.name}": the limit of ${maxFiles} image${maxFiles === 1 ? '' : 's'} is reached.`);
                        continue;
                    }
                    const r = await imageCheck.analyse(file, spec);
                    if (!r.ok) {
                        if (r.url) URL.revokeObjectURL(r.url);
                        const reason = (r.lines.find(([kind]) => kind === 'error') || [, 'cannot be used.'])[1];
                        problems.push(`"${file.name}" was not added: ${reason}`);
                        continue;
                    }
                    pending.push({ file, url: r.url, result: r });
                }
                sync();
                render();
                if (problems.length) message(problems.join(' '));
            });

            sync();
        },

        /* "Replace" on an existing gallery image */
        replace() {
            document.querySelectorAll('[data-replace-input]').forEach((input) => {
                const item = input.closest('[data-gallery-item]');
                const img = item?.querySelector('[data-gallery-img]');
                const status = item?.querySelector('[data-image-status]');
                const spec = this.spec(input);
                if (!item || !img || !status || !spec) return;
                const originalSrc = img.src;
                let url = null;

                input.addEventListener('change', async () => {
                    if (url) URL.revokeObjectURL(url);
                    url = null;
                    img.src = originalSrc;
                    item.classList.remove('is-replaced');
                    status.replaceChildren();

                    const file = input.files[0];
                    if (!file) return;
                    const r = await imageCheck.analyse(file, spec);
                    imageCheck.render(status, r, true);
                    if (!r.ok) {
                        if (r.url) URL.revokeObjectURL(r.url);
                        input.value = '';
                        return;
                    }
                    url = r.url;
                    img.src = url;
                    item.classList.add('is-replaced');
                    const del = item.querySelector('[data-remove-gallery]');
                    if (del) {
                        del.checked = false;
                        item.classList.remove('is-removed');
                    }
                });
            });
        },
    };

    /* Small toast for background actions */
    function toast(message, ok = true) {
        const area = document.querySelector('[data-toast-area]');
        if (!area || !window.bootstrap) return;
        const el = document.createElement('div');
        el.className = `toast align-items-center border-0 ${ok ? 'text-bg-dark' : 'text-bg-danger'}`;
        el.setAttribute('role', ok ? 'status' : 'alert');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>'
            + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        el.querySelector('.toast-body').textContent = message;
        area.appendChild(el);
        el.addEventListener('hidden.bs.toast', () => el.remove());
        new bootstrap.Toast(el, { delay: 3000 }).show();
    }

    /*
     * Product list switches (Active / Featured / New).
     * Posts in the background and updates the button; if anything goes
     * wrong the normal form submit (full page) still works.
     */
    modules.flagToggles = {
        init() {
            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('form[data-flag-toggle]');
                if (!form || !window.fetch) return;
                event.preventDefault();

                const button = form.querySelector('button');
                button.disabled = true;
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        toast(data.message || 'Could not update the product.', false);
                        return;
                    }
                    button.classList.toggle('is-on', data.value);
                    button.setAttribute('aria-pressed', String(data.value));
                    button.title = button.title.replace(/: (on|off) —/, `: ${data.value ? 'on' : 'off'} —`);
                    if (data.flag === 'active') {
                        form.closest('tr')?.classList.toggle('is-inactive', !data.value);
                    }
                    toast(data.message);
                } catch (error) {
                    form.submit(); // fall back to a normal request
                } finally {
                    button.disabled = false;
                }
            });
        },
    };

    /*
     * Pricing preview. Amounts are parsed into integer cents - the same
     * rules as the server (Money::parse) - so no floating point is used.
     */
    modules.salePreview = {
        toCents(text) {
            const m = String(text).replace(/[,\s]/g, '').match(/^(\d{1,7})(?:\.(\d{1,2}))?$/);
            return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] || '0').padEnd(2, '0'), 10) : null;
        },
        format(cents) {
            const whole = String(Math.trunc(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return `${whole}.${String(cents % 100).padStart(2, '0')}`;
        },
        init() {
            const regular = document.querySelector('[data-price="regular"]');
            const sale = document.querySelector('[data-price="sale"]');
            const out = document.querySelector('[data-sale-preview]');
            if (!regular || !sale || !out) return;
            const initial = out.textContent;

            const update = () => {
                const r = this.toCents(regular.value);
                const s = sale.value.trim() === '' ? 0 : this.toCents(sale.value);
                out.classList.remove('text-danger', 'text-success');
                if (s === null) {
                    out.textContent = 'Enter a number like 999 or 999.50.';
                    out.classList.add('text-danger');
                } else if (s === 0 || r === null) {
                    out.textContent = initial;
                } else if (s >= r) {
                    out.textContent = 'Must be lower than the regular price.';
                    out.classList.add('text-danger');
                } else {
                    const pct = Math.floor(((r - s) * 200 + r) / (r * 2)); // integer, rounded half up
                    out.textContent = `Customers pay ${this.format(s)} (${pct}% off).`;
                    out.classList.add('text-success');
                }
            };
            regular.addEventListener('input', update);
            sale.addEventListener('input', update);
            update();
        },
    };

    /*
     * Size & colour options on the product form: on/off panels, colour rows,
     * and the stock table rebuilt live from the entered sizes/colours.
     * Input names use the hex of the UTF-8 name (like PHP bin2hex), so typed
     * stock stays with its size/colour when the lists change.
     */
    modules.productOptions = {
        key(value) {
            if (value === '') return '_';
            return Array.from(new TextEncoder().encode(value), (b) => b.toString(16).padStart(2, '0')).join('');
        },
        clean(value) {
            return value.replace(/\s+/g, ' ').trim();
        },
        unique(list) {
            const seen = new Set();
            return list.filter((v) => {
                const k = v.toLowerCase();
                if (v === '' || seen.has(k)) return false;
                seen.add(k);
                return true;
            });
        },
        init() {
            const root = document.querySelector('[data-product-options]');
            if (!root) return;
            const sizesToggle = root.querySelector('[data-option-toggle="sizes"]');
            const coloursToggle = root.querySelector('[data-option-toggle="colours"]');
            const sizesInput = root.querySelector('[data-sizes-input]');
            const colourList = root.querySelector('[data-colour-list]');
            const template = root.querySelector('[data-colour-template]');
            const gridWrap = root.querySelector('[data-stock-grid-wrap]');
            const grid = root.querySelector('[data-stock-grid]');
            const totalOut = root.querySelector('[data-stock-total]');
            const single = document.querySelector('[data-stock-single]');
            const singleHelp = document.querySelector('[data-stock-help]');
            const singleHelpText = singleHelp ? singleHelp.textContent : '';
            // The single stock field shows the options' total (read-only) while options are on
            let ownStock = single ? single.value : '0';
            let usingOptions = false;
            const max = parseInt(root.dataset.stockMax, 10) || 1000000;

            // Values typed so far, by input name (survive rebuilds)
            const values = new Map();
            grid.querySelectorAll('[data-stock-cell]').forEach((input) => values.set(input.name, input.value));

            const sizes = () => this.unique(sizesInput.value.split(',').map((s) => this.clean(s)));
            const colours = () => this.unique(Array.from(colourList.querySelectorAll('[data-colour-name]'), (i) => this.clean(i.value)));

            const cell = (size, colour, label) => {
                const input = document.createElement('input');
                Object.assign(input, { type: 'number', min: '0', max: String(max), step: '1', className: 'form-control form-control-sm stock-grid__input' });
                input.inputMode = 'numeric';
                input.name = `variant_stock[${this.key(size)}][${this.key(colour)}]`;
                input.value = values.has(input.name) ? values.get(input.name) : '0';
                input.setAttribute('aria-label', `Stock for ${label}`);
                input.dataset.stockCell = '';
                const td = document.createElement('td');
                td.append(input);
                return td;
            };
            const th = (text, scope) => {
                const el = document.createElement('th');
                el.scope = scope;
                el.textContent = text;
                return el;
            };

            const updateTotal = () => {
                let total = 0;
                grid.querySelectorAll('[data-stock-cell]').forEach((input) => {
                    const n = parseInt(input.value, 10);
                    if (n > 0) total += n;
                });
                totalOut.textContent = total.toLocaleString('en-US');
                if (single && usingOptions) single.value = String(total);
            };

            const render = () => {
                const s = sizesToggle.checked ? sizes() : [];
                const c = coloursToggle.checked ? colours() : [];
                root.querySelector('[data-option-panel="sizes"]').hidden = !sizesToggle.checked;
                root.querySelector('[data-option-panel="colours"]').hidden = !coloursToggle.checked;

                const usesOptions = sizesToggle.checked || coloursToggle.checked;
                if (single) {
                    if (usesOptions && !usingOptions) ownStock = single.value;
                    if (!usesOptions && usingOptions) single.value = ownStock;
                    usingOptions = usesOptions;
                    single.readOnly = usesOptions;
                    single.classList.toggle('bg-body-tertiary', usesOptions);
                    if (usesOptions) {
                        single.classList.remove('is-invalid');
                        single.value = '0'; // replaced by the total below
                    }
                    if (singleHelp) singleHelp.textContent = usesOptions ? 'Total of the sizes/colours below (set the stock of each there).' : singleHelpText;
                }

                grid.replaceChildren();
                gridWrap.hidden = s.length === 0 && c.length === 0;
                if (gridWrap.hidden) return;

                const head = document.createElement('thead');
                const headRow = head.insertRow();
                const body = document.createElement('tbody');
                if (s.length && c.length) {
                    headRow.append(th('Size \\ Colour', 'col'), ...c.map((name) => th(name, 'col')));
                    s.forEach((size) => {
                        const row = body.insertRow();
                        row.append(th(size, 'row'), ...c.map((colour) => cell(size, colour, `${size} / ${colour}`)));
                    });
                } else {
                    const isSize = s.length > 0;
                    headRow.append(th(isSize ? 'Size' : 'Colour', 'col'), th('Stock', 'col'));
                    (isSize ? s : c).forEach((v) => {
                        const row = body.insertRow();
                        row.append(th(v, 'row'), isSize ? cell(v, '', v) : cell('', v, v));
                    });
                }
                grid.append(head, body);
                updateTotal();
            };

            grid.addEventListener('input', (e) => {
                if (e.target.matches('[data-stock-cell]')) {
                    values.set(e.target.name, e.target.value);
                    updateTotal();
                }
            });
            sizesToggle.addEventListener('change', render);
            coloursToggle.addEventListener('change', render);
            sizesInput.addEventListener('input', render);
            colourList.addEventListener('input', (e) => {
                if (e.target.matches('[data-colour-name]')) render();
            });
            root.querySelectorAll('[data-size-preset]').forEach((btn) => btn.addEventListener('click', () => {
                sizesInput.value = btn.dataset.sizePreset;
                render();
                sizesInput.focus();
            }));
            root.querySelector('[data-colour-add]').addEventListener('click', () => {
                colourList.append(template.content.cloneNode(true));
                colourList.lastElementChild.querySelector('[data-colour-name]').focus();
            });
            colourList.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-colour-remove]');
                if (!btn) return;
                const row = btn.closest('[data-colour-row]');
                if (colourList.querySelectorAll('[data-colour-row]').length > 1) {
                    row.remove();
                } else {
                    row.querySelector('[data-colour-name]').value = '';
                }
                render();
            });
            render();
        },
    };

    /* <select data-autosubmit> saves as soon as it changes (its Save button is hidden with JS) */
    modules.autoSubmit = {
        init() {
            document.documentElement.classList.add('js');
            document.addEventListener('change', (event) => {
                const field = event.target.closest('[data-autosubmit]');
                if (field?.form) field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
            });
        },
    };

    /* <input data-table-filter="#table"> hides rows whose data-filter-text doesn't match */
    modules.tableFilter = {
        init() {
            document.querySelectorAll('[data-table-filter]').forEach((input) => {
                const table = document.querySelector(input.dataset.tableFilter);
                if (!table) return;
                input.addEventListener('input', () => {
                    const q = input.value.trim().toLowerCase();
                    table.querySelectorAll('tbody tr[data-filter-text]').forEach((row) => {
                        row.hidden = q !== '' && !row.dataset.filterText.includes(q);
                    });
                });
            });
        },
    };

    /* Appearance: colour picker + hex text stay in sync; live preview of the bar and menu line */
    modules.appearance = {
        init() {
            const form = document.querySelector('[data-appearance-form]');
            if (!form) return;
            const hex = (value) => {
                let v = value.trim().toLowerCase().replace(/^#?/, '#');
                if (/^#[0-9a-f]{3}$/.test(v)) v = '#' + v.slice(1).split('').map((c) => c + c).join('');
                return /^#[0-9a-f]{6}$/.test(v) ? v : null;
            };
            const bar = form.querySelector('[data-preview-bar]');
            const header = form.querySelector('[data-preview-header]');
            const text = form.querySelector('[data-preview-text]');
            const note = form.querySelector('[data-preview-hidden-note]');
            const field = (name) => form.querySelector(`[name="${name}"]`);
            const update = () => {
                const bg = hex(field('announce_bg').value);
                const fg = hex(field('announce_color').value);
                const line = hex(field('header_line').value);
                if (bg) bar.style.background = bg;
                if (fg) bar.style.color = fg;
                if (line) header.style.borderBottomColor = line;
                const t = field('announce_text');
                text.textContent = t.value.trim() || t.dataset.autoText;
                bar.hidden = !field('announce_enabled').checked;
                note.hidden = !bar.hidden;
            };
            form.querySelectorAll('[data-colour-field]').forEach((group) => {
                const picker = group.querySelector('[data-colour-picker]');
                const input = group.querySelector('[data-colour-text]');
                picker.addEventListener('input', () => { input.value = picker.value; input.classList.remove('is-invalid'); update(); });
                input.addEventListener('input', () => { const v = hex(input.value); if (v) picker.value = v; update(); });
            });
            form.addEventListener('input', update);
            form.addEventListener('change', update);
        },
    };

    /* <button data-copy-target="#id"> copies that field; <button data-confirm-click="Question?"> asks first */
    modules.copyAndConfirm = {
        init() {
            document.addEventListener('click', async (event) => {
                const copy = event.target.closest('[data-copy-target]');
                if (copy) {
                    const field = document.querySelector(copy.dataset.copyTarget);
                    if (!field) return;
                    try { await navigator.clipboard.writeText(field.value); } catch (e) { field.select(); document.execCommand('copy'); }
                    const label = copy.textContent;
                    copy.textContent = 'Copied';
                    setTimeout(() => { copy.textContent = label; }, 1500);
                    return;
                }
                const ask = event.target.closest('[data-confirm-click]');
                if (ask && !window.confirm(ask.dataset.confirmClick)) event.preventDefault();
            });
            // Order list: "select all" and the bulk courier button state
            const all = document.querySelector('[data-select-all]');
            const boxes = () => Array.from(document.querySelectorAll('[data-select-row]'));
            const bulk = document.querySelector('[data-bulk-button]');
            const sync = () => {
                const n = boxes().filter((b) => b.checked).length;
                if (bulk) { bulk.disabled = n === 0; bulk.querySelector('[data-bulk-count]').textContent = n ? ` (${n})` : ''; }
                if (all) { all.checked = n > 0 && n === boxes().length; all.indeterminate = n > 0 && n < boxes().length; }
            };
            all?.addEventListener('change', () => { boxes().forEach((b) => { b.checked = all.checked; }); sync(); });
            document.addEventListener('change', (e) => { if (e.target.matches('[data-select-row]')) sync(); });
            if (bulk) sync();
        },
    };

    /* Bootstrap tooltips for [data-bs-toggle="tooltip"] */
    modules.tooltips = {
        init() {
            if (!window.bootstrap) return;
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
        },
    };

    function boot() {
        Object.values(modules).forEach((module) => {
            try {
                module.init();
            } catch (error) {
                console.error('[AaltoMart admin] module failed to start', error);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
