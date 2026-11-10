/*
 * WorkFolio PWA bootstrap.
 *
 * Registers the service worker and, on the app pages admins actually use,
 * offers an "Install app" button so the portal can be pinned to the desktop and
 * taskbar as a standalone window (that window IS the WebView - Chromium's, via
 * Edge/Chrome "Install this site as an app"). No EXE to distribute, and updates
 * arrive from the server.
 *
 * Loaded with defer, so the DOM is ready when it runs.
 */
(function () {
    'use strict';

    var SW_URL = '/sw.js';

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(SW_URL, { scope: '/' })
                .catch(function (err) {
                    // A failed registration must never break the page.
                    if (window.console) console.warn('WorkFolio SW registration failed:', err);
                });
        });
    }

    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        // Stops Chrome from showing its own mini-infobar so we can offer a
        // proper button in the app's own UI instead.
        event.preventDefault();
        deferredPrompt = event;
        revealInstallButton();
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        var button = document.getElementById('pwa-install');
        if (button) button.remove();
    });

    function revealInstallButton() {
        var button = document.getElementById('pwa-install');
        if (!button || button.dataset.shown === '1') return;

        button.hidden = false;
        button.dataset.shown = '1';

        button.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function () {
                deferredPrompt = null;
                button.remove();
            });
        });
    }

    // Browsers without install support (Firefox, Safari, iOS) get nothing to
    // show, which is correct - the app still works, just in a browser tab.
})();
