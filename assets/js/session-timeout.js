/**
 * assets/js/session-timeout.js
 * ────────────────────────────────────────────────────────────────────────
 * Client-side inactivity session timeout — mirrors the server-side
 * SESSION_TIMEOUT of 120 seconds (2 minutes).
 *
 * Behaviour:
 *  • Tracks mouse, keyboard, touch and scroll events as "activity".
 *  • At T-30 s (90 s of idle) shows a countdown warning modal.
 *  • At T=0 (120 s of idle) logs the user out automatically.
 *  • Any activity resets the timer AND sends a server-side ping.
 * ────────────────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    var TIMEOUT_SECONDS = 120;   // must match PHP SESSION_TIMEOUT
    var WARN_BEFORE     = 30;    // show warning this many seconds before expiry
    var PING_INTERVAL   = 45000; // ping server every 45 s while user is active (ms)

    var idleSeconds    = 0;
    var warningVisible = false;
    var pingTimer      = null;
    var countdownTimer = null;

    // ── Detect root path for redirect / ping ────────────────────────────
    function getRootPrefix() {
        // e.g. if at /admin/dashboard.php  → prefix is '../'
        var path = window.location.pathname;
        var depth = (path.match(/\//g) || []).length - 1; // exclude leading /
        var prefix = '';
        for (var i = 0; i < depth; i++) { prefix += '../'; }
        return prefix;
    }

    var ROOT = getRootPrefix();

    // ── Build the warning modal once ────────────────────────────────────
    function buildModal() {
        if (document.getElementById('_sessionTimeoutModal')) return;

        var overlay = document.createElement('div');
        overlay.id = '_sessionTimeoutModal';
        overlay.style.cssText = [
            'position:fixed','top:0','left:0','width:100%','height:100%',
            'background:rgba(0,0,0,0.65)','z-index:999999',
            'display:none','align-items:center','justify-content:center',
            'font-family:Poppins,sans-serif',
            'animation:_stFadeIn .3s ease'
        ].join(';');

        overlay.innerHTML = [
            '<div style="background:#1E293B;border-radius:20px;padding:40px 36px;',
                'max-width:420px;width:100%;text-align:center;border:1px solid rgba(255,255,255,.1);',
                'box-shadow:0 25px 60px rgba(0,0,0,.5);">',
                '<div style="font-size:54px;margin-bottom:16px;">⏳</div>',
                '<h2 style="color:#F1F5F9;font-size:22px;font-weight:700;margin-bottom:8px;">',
                    'Session Expiring Soon</h2>',
                '<p style="color:#94A3B8;font-size:14px;line-height:1.6;margin-bottom:6px;">',
                    'You have been inactive for a while.</p>',
                '<p style="color:#94A3B8;font-size:14px;">',
                    'Auto-logout in <strong id="_stCountdown" style="color:#EF4444;font-size:18px;">30</strong> seconds.</p>',
                '<div style="margin-top:28px;display:flex;gap:12px;justify-content:center;">',
                    '<button id="_stKeepAlive" style="',
                        'padding:12px 28px;border-radius:12px;border:none;',
                        'background:#2F80ED;color:#fff;font-family:Poppins,sans-serif;',
                        'font-size:15px;font-weight:600;cursor:pointer;transition:all .2s;">',
                        '✅ Stay Logged In</button>',
                    '<button id="_stLogoutNow" style="',
                        'padding:12px 28px;border-radius:12px;',
                        'border:1px solid rgba(255,255,255,.15);',
                        'background:transparent;color:#CBD5E1;font-family:Poppins,sans-serif;',
                        'font-size:15px;font-weight:500;cursor:pointer;transition:all .2s;">',
                        'Logout Now</button>',
                '</div>',
            '</div>'
        ].join('');

        // Inject keyframe
        var style = document.createElement('style');
        style.textContent = '@keyframes _stFadeIn{from{opacity:0}to{opacity:1}}';
        document.head.appendChild(style);
        document.body.appendChild(overlay);

        document.getElementById('_stKeepAlive').addEventListener('click', keepAlive);
        document.getElementById('_stLogoutNow').addEventListener('click', doLogout);
    }

    // ── Show / hide modal ────────────────────────────────────────────────
    function showWarning(secondsLeft) {
        var modal = document.getElementById('_sessionTimeoutModal');
        if (!modal) return;
        modal.style.display = 'flex';
        warningVisible = true;
        updateCountdown(secondsLeft);
    }

    function hideWarning() {
        var modal = document.getElementById('_sessionTimeoutModal');
        if (modal) modal.style.display = 'none';
        warningVisible = false;
    }

    function updateCountdown(s) {
        var el = document.getElementById('_stCountdown');
        if (el) el.textContent = Math.max(0, s);
    }

    // ── Core timer logic ─────────────────────────────────────────────────
    function tick() {
        idleSeconds++;

        var remaining = TIMEOUT_SECONDS - idleSeconds;

        if (remaining <= 0) {
            // Time's up
            doLogout();
            return;
        }

        if (remaining <= WARN_BEFORE) {
            showWarning(remaining);
        }
    }

    function resetTimer() {
        idleSeconds = 0;
        if (warningVisible) hideWarning();
    }

    // ── Keep-alive: reset locally + ping server ──────────────────────────
    function keepAlive() {
        resetTimer();
        pingServer();
    }

    function pingServer() {
        fetch(ROOT + 'api/session-ping.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.alive) {
                doLogout();
            }
        })
        .catch(function () { /* network error, let server-side handle it */ });
    }

    function doLogout() {
        // Clear intervals so we don't fire again
        clearInterval(countdownTimer);
        clearInterval(pingTimer);
        window.location.href = ROOT + 'logout.php?timeout=1';
    }

    // ── Activity event listeners ─────────────────────────────────────────
    var events = ['mousemove','mousedown','keydown','touchstart','scroll','click'];
    var activityDebounce = null;

    function onActivity() {
        // Debounce rapid events
        if (activityDebounce) return;
        activityDebounce = setTimeout(function () {
            activityDebounce = null;
        }, 500);
        resetTimer();
    }

    events.forEach(function (e) {
        document.addEventListener(e, onActivity, { passive: true });
    });

    // ── Initialise ───────────────────────────────────────────────────────
    function init() {
        buildModal();

        // Tick every second
        countdownTimer = setInterval(tick, 1000);

        // Ping server periodically while user is active
        pingTimer = setInterval(function () {
            if (idleSeconds < TIMEOUT_SECONDS - WARN_BEFORE) {
                pingServer();
            }
        }, PING_INTERVAL);
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
