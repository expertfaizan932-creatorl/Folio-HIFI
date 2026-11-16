/* ==========================================================================
   Folio - app shell interactions
   Theme toggle, responsive sidebar, button ripple, count-up numbers,
   reveal-on-scroll, live 12h clock, toasts. No dependencies.
   ========================================================================== */
(function () {
    'use strict';

    var root = document.documentElement;
    var KEY = 'wf-theme';
    var SIDE = 'wf-side-collapsed';

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* ------------------------------------------------------------ theme ---- */
    function applyTheme(t) {
        root.setAttribute('data-theme', t);
        $$('[data-theme-toggle]').forEach(function (b) {
            var dark = t === 'dark';
            b.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
            b.title = dark ? 'Switch to light theme' : 'Switch to dark theme';
        });
    }
    try {
        var saved = localStorage.getItem(KEY);
        if (!saved) {
            saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }
        applyTheme(saved);
    } catch (e) { applyTheme('dark'); }

    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('[data-theme-toggle]');
        if (!t) return;
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem(KEY, next); } catch (err) { }
    });

    /* ---------------------------------------------------------- sidebar ---- */
    function setSide(open) {
        document.body.classList.toggle('side-open', open);
    }
    function setCollapsed(on) {
        document.body.classList.toggle('side-collapsed', on);
        try { localStorage.setItem(SIDE, on ? '1' : '0'); } catch (e) { }
    }
    try {
        if (localStorage.getItem(SIDE) === '1' && window.innerWidth > 991) setCollapsed(true);
    } catch (e) { }

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-side-toggle]')) {
            if (window.innerWidth <= 991) setSide(!document.body.classList.contains('side-open'));
            else setCollapsed(!document.body.classList.contains('side-collapsed'));
            return;
        }
        if (e.target.closest && e.target.closest('[data-side-close]')) { setSide(false); return; }
        // tapping a nav link on a phone closes the drawer
        if (window.innerWidth <= 991 && e.target.closest && e.target.closest('.wf-link')) setSide(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setSide(false);
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 991) setSide(false); });

    /* ----------------------------------------------------------- ripple ---- */
    document.addEventListener('pointerdown', function (e) {
        var btn = e.target.closest && e.target.closest('.btn');
        if (!btn || btn.disabled) return;
        var r = btn.getBoundingClientRect();
        var size = Math.max(r.width, r.height);
        var span = document.createElement('span');
        span.className = 'wf-ripple';
        span.style.width = span.style.height = size + 'px';
        span.style.left = (e.clientX - r.left - size / 2) + 'px';
        span.style.top = (e.clientY - r.top - size / 2) + 'px';
        if (getComputedStyle(btn).position === 'static') btn.style.position = 'relative';
        btn.appendChild(span);
        setTimeout(function () { span.remove(); }, 620);
    });

    /* --------------------------------------------------------- count up ---- */
    function countUp(el) {
        var raw = (el.textContent || '').trim();
        if (!/^[\d][\d,.\s]*$/.test(raw)) return;             // only plain numbers
        var target = parseFloat(raw.replace(/[,\s]/g, ''));
        if (!isFinite(target) || target > 1e9) return;
        var decimals = (raw.split('.')[1] || '').replace(/\D/g, '').length;
        var dur = 700, t0 = performance.now();
        el.style.fontVariantNumeric = 'tabular-nums';
        function step(now) {
            var p = Math.min(1, (now - t0) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            var v = target * eased;
            el.textContent = (decimals ? v.toFixed(decimals) : Math.round(v).toLocaleString('en-US'));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }
    function runCounters(scope) {
        $$('.stat-num, [data-count]', scope || document).forEach(function (el) {
            if (el.dataset.counted) return;
            el.dataset.counted = '1';
            countUp(el);
        });
    }

    /* ----------------------------------------------------------- reveal ---- */
    var revealables = $$('.wf-reveal');
    if (revealables.length) {
        if (!('IntersectionObserver' in window)) {
            revealables.forEach(function (el) { el.classList.add('wf-in'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    var el = en.target;
                    var sibs = Array.prototype.indexOf.call(el.parentNode.children, el);
                    el.style.setProperty('--d', Math.min(sibs, 10) * 55 + 'ms');
                    el.classList.add('wf-in');
                    io.unobserve(el);
                    runCounters(el);
                });
            }, { rootMargin: '0px 0px -6% 0px', threshold: .06 });
            revealables.forEach(function (el) { io.observe(el); });
        }
    }
    runCounters();

    /* ------------------------------------------------------ live clock ---- */
    /* Ticks from the server's clock, not the browser's. The page ships the
       server's own unix stamp in data-wf-now; the difference between that and
       the browser clock at load time is the offset we carry forward. Every
       timestamp in the app is server time, so a header clock that silently sat
       in another timezone made the top bar disagree with the log below it. */
    var clock = $('#wf-clock-time');
    if (clock) {
        var serverNow = parseInt(clock.getAttribute('data-wf-now'), 10);
        var base = performance.now();
        var start = isNaN(serverNow) ? Math.floor(Date.now() / 1000) : serverNow;
        var paint = function () {
            /* performance.now() rather than Date.now() for the elapsed part: it
               is monotonic, so correcting the system clock mid-session cannot
               make the header jump or stall. */
            var d = new Date((start + (performance.now() - base) / 1000) * 1000);
            clock.textContent = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
            var dc = $('#wf-clock-date');
            if (dc) dc.textContent = d.toLocaleDateString('en-US', { weekday: 'short', day: '2-digit', month: 'short' });
        };
        paint();
        setInterval(paint, 1000);
    }

    /* ----------------------------------------------------------- toasts ---- */
    window.wfToast = function (msg, type) {
        var box = $('.wf-toasts');
        if (!box) {
            box = document.createElement('div');
            box.className = 'wf-toasts';
            document.body.appendChild(box);
        }
        var icons = { ok: 'bi-check-circle-fill', err: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        var colors = { ok: 'var(--wf-success)', err: 'var(--wf-danger)', info: 'var(--wf-info)' };
        var t = document.createElement('div');
        t.className = 'wf-toast';
        t.innerHTML = '<i class="bi ' + (icons[type] || icons.info) + '" style="color:' + (colors[type] || colors.info) + '"></i><span></span>';
        t.querySelector('span').textContent = msg;
        box.appendChild(t);
        setTimeout(function () {
            t.classList.add('out');
            setTimeout(function () { t.remove(); }, 300);
        }, 3200);
    };

    /* ------------------------------ flash messages from ?msg= / ?error= ---- */
    try {
        var q = new URLSearchParams(location.search);
        if (q.get('msg')) window.wfToast(q.get('msg'), 'ok');
        if (q.get('error')) window.wfToast(q.get('error'), 'err');
    } catch (e) { }

    /* --------------------------- shared password generator (wiring) ---- */
    /* Readable on purpose: the admin has to write it down and hand it to the
       employee, and it is never stored anywhere in plain text. */
    window.WorkfolioGeneratePassword = function (len) {
        var n = len || 12;
        var lower = 'abcdefghijkmnopqrstuvwxyz';
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var digits = '23456789';
        var all = lower + upper + digits;
        function pick(set) { return set[Math.floor(Math.random() * set.length)]; }
        var out = pick(lower) + pick(upper) + pick(digits);
        while (out.length < n) out += pick(all);
        return out;
    };

    var genNew = document.getElementById('wfGenPass');
    var newPass = document.getElementById('wfNewPass');
    if (genNew && newPass) {
        genNew.addEventListener('click', function () {
            newPass.value = window.WorkfolioGeneratePassword(12);
            newPass.focus();
        });
    }
})();
