/**
 * Mautic Ecommerce for WooCommerce: loads a Mautic focus item for anonymous visitors once they
 * viewed enough different products in the last 24 hours. Settings in window.mauticEcommerceFocus
 * (see FocusPrompt.php). Nothing is stored or loaded without consent, with Do Not Track or Global
 * Privacy Control, or when the "known shopper" marker cookie is set.
 */
(function () {
    'use strict';

    var config = window.mauticEcommerceFocus;
    var STORE = 'mauticEcommerceViews';
    var WINDOW = 24 * 60 * 60 * 1000;
    var loaded = false;

    if (!config || !config.src) {
        return;
    }

    function cookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setMarker() {
        document.cookie = config.marker + '=1; max-age=' + (365 * 24 * 60 * 60) + '; path=' + config.path
            + (config.domain ? '; domain=' + config.domain : '') + '; SameSite=Lax'
            + ('https:' === location.protocol ? '; Secure' : '');
    }

    function optedOut() {
        return '1' === navigator.doNotTrack || '1' === window.doNotTrack || true === navigator.globalPrivacyControl;
    }

    function hasConsent() {
        if ('always' === config.consent) {
            return true;
        }

        return 'function' === typeof window.wp_has_consent && true === window.wp_has_consent('marketing');
    }

    function readViews() {
        try {
            var views = JSON.parse(window.localStorage.getItem(STORE) || '{}');
            return views && 'object' === typeof views ? views : {};
        } catch (e) {
            return {};
        }
    }

    function writeViews(views) {
        try {
            window.localStorage.setItem(STORE, JSON.stringify(views));
        } catch (e) {
            // Storage blocked: the visitor is simply never counted.
        }
    }

    function currentProductId() {
        var body = document.body;
        if (!body || !body.classList.contains('single-product')) {
            return null;
        }

        var match = body.className.match(/(?:^|\s)postid-(\d+)(?:\s|$)/);
        return match ? match[1] : null;
    }

    function run() {
        if (loaded || optedOut()) {
            return;
        }

        if ('1' === cookie(config.marker)) {
            return;
        }

        // Mautic's own cookie: -1 once the visitor converted (with "Stop after conversion").
        if ('-1' === cookie('mautic_focus_' + config.focusId)) {
            setMarker();
            return;
        }

        if (!hasConsent()) {
            return;
        }

        var now = Date.now();
        var views = readViews();
        var fresh = {};
        Object.keys(views).forEach(function (id) {
            if ('number' === typeof views[id] && now - views[id] < WINDOW) {
                fresh[id] = views[id];
            }
        });

        var productId = currentProductId();
        if (null !== productId) {
            fresh[productId] = now;
        }
        writeViews(fresh);

        if (Object.keys(fresh).length < config.views) {
            return;
        }

        loaded = true;
        var script = document.createElement('script');
        script.src = config.src;
        script.async = true;
        document.body.appendChild(script);
    }

    // Consent banners often answer after the page loaded.
    document.addEventListener('wp_listen_for_consent_change', run);

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
