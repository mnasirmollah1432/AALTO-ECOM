/**
 * Aalto Mart - Facebook Pixel and Google Tag Manager (Admin -> Marketing & tracking).
 * Reads its settings and the page's e-commerce events from
 * <script type="application/json" id="am-tracking">, so the pages need no inline script.
 */
(function () {
    'use strict';
    var el = document.getElementById('am-tracking');
    if (!el) return;
    var cfg;
    try { cfg = JSON.parse(el.textContent); } catch (e) { return; }

    if (cfg.gtm) {
        window.dataLayer = window.dataLayer || [];
        // Page details first, so GTM triggers/variables can use them (pageType, cartItems, ...)
        if (cfg.page) window.dataLayer.push(cfg.page);
        window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
        var g = document.createElement('script');
        g.async = true;
        g.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(cfg.gtm);
        document.head.appendChild(g);
    }

    /*
     * Extra details sent with every Pixel event (as WordPress pixel plugins do):
     * page, where the visitor came from on this visit, landing page and time.
     */
    var common = (function () {
        var get = function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } };
        var set = function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* private mode */ } };
        if (!get('am_source')) {
            var ref = '';
            try { ref = document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) { ref = ''; }
            set('am_source', ref && ref !== location.hostname ? ref : 'direct');
            set('am_landing', location.pathname);
        }
        var now = new Date();
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return {
            page_title: document.title,
            event_url: location.hostname + location.pathname, // no query string
            page_type: cfg.page ? cfg.page.pageType : undefined,
            traffic_source: get('am_source') || 'direct',
            landing_page: get('am_landing') || location.pathname,
            user_role: 'guest',
            event_day: days[now.getDay()],
            event_month: months[now.getMonth()],
            event_hour: now.getHours() + '-' + (now.getHours() + 1)
        };
    })();
    var withCommon = function (data) {
        var out = {}, k;
        for (k in common) if (common[k] !== undefined) out[k] = common[k];
        for (k in data || {}) out[k] = data[k];
        return out;
    };

    if (cfg.pixel) {
        /* Meta Pixel base code */
        !function (f, b, e, v, n, t, s) {
            if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
            if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
            t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        // Advanced Matching: hashed customer details (Purchase page only), else a plain init
        if (cfg.user) window.fbq('init', cfg.pixel, cfg.user);
        else window.fbq('init', cfg.pixel);
        window.fbq('track', 'PageView', withCommon({}));
    }

    var send = function (ev) {
        if (cfg.gtm) {
            window.dataLayer.push({ ecommerce: null }); // clear the previous ecommerce object (GA4 recommendation)
            var push = { event: ev.ga, ecommerce: ev.ecommerce };
            for (var k in ev.gaData || {}) push[k] = ev.gaData[k];
            window.dataLayer.push(push);
        }
        if (cfg.pixel && window.fbq) {
            var method = ev.fbCustom ? 'trackCustom' : 'track'; // ViewCategory, ViewCart, RemoveFromCart, ... are custom
            if (ev.eventId) window.fbq(method, ev.fb, withCommon(ev.fbData), { eventID: ev.eventId });
            else window.fbq(method, ev.fb, withCommon(ev.fbData));
        }
    };
    (cfg.events || []).forEach(send);

    // ViewCart / view_cart when the side cart opens (once per page)
    if (cfg.cartView) {
        document.addEventListener('shown.bs.offcanvas', function (e) {
            if (!cfg.cartView || !e.target || e.target.id !== 'cartDrawer') return;
            send(cfg.cartView);
            cfg.cartView = null;
        });
    }

    // Contact / contact_click: WhatsApp, Messenger, imo, phone and email links (chat button, contact page, footer);
    // social_click (GTM only) for the Facebook / Instagram / YouTube / TikTok links
    var contactKeys = ['whatsapp', 'messenger', 'imo', 'phone', 'email'];
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        var key = a.getAttribute('data-contact') || '';
        if (key && contactKeys.indexOf(key) === -1) {
            if (cfg.gtm) window.dataLayer.push({ event: 'social_click', social_network: key });
            return;
        }
        var method = key ? key
            : /^tel:/i.test(href) ? 'phone'
            : /^mailto:/i.test(href) ? 'email'
            : /(wa\.me|whatsapp\.com|^whatsapp:)/i.test(href) ? 'whatsapp'
            : /(m\.me|messenger\.com|^fb-messenger:)/i.test(href) ? 'messenger'
            : /(imo\.im|^imo:)/i.test(href) ? 'imo'
            : '';
        if (!method) return;
        if (cfg.gtm) window.dataLayer.push({ event: 'contact_click', contact_method: method });
        if (cfg.pixel && window.fbq) window.fbq('track', 'Contact', withCommon({ content_name: method }));
    });
})();
