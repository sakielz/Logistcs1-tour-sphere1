/* ==========================================================================
   Tour-Sphere · Tour Operations Extension Modules – shared UI behaviour
   ========================================================================== */
(function () {
    'use strict';

    // ---------- Tabs (persisted in URL hash) ----------
    function activateTab(group, name) {
        var tabs = document.querySelectorAll('[data-ts-tab-group="' + group + '"] .ts-tab[data-tab]');
        var found = false;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('active', on);
            if (on) found = true;
        });
        if (!found) return false;
        document.querySelectorAll('.ts-panel[data-panel-group="' + group + '"]').forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-panel') === name);
        });
        return true;
    }

    function initTabs() {
        document.querySelectorAll('[data-ts-tab-group]').forEach(function (bar) {
            var group = bar.getAttribute('data-ts-tab-group');
            bar.querySelectorAll('.ts-tab[data-tab]').forEach(function (tab) {
                tab.addEventListener('click', function (e) {
                    e.preventDefault();
                    var name = tab.getAttribute('data-tab');
                    activateTab(group, name);
                    if (history.replaceState) history.replaceState(null, '', '#' + name);
                });
            });
            var hash = (location.hash || '').replace('#', '');
            if (!hash || !activateTab(group, hash)) {
                var first = bar.querySelector('.ts-tab[data-tab]');
                if (first) activateTab(group, first.getAttribute('data-tab'));
            }
        });
    }

    // ---------- Modals ----------
    function openModal(id, data) {
        var m = document.getElementById(id);
        if (!m) return;
        var form = m.querySelector('form');
        if (form && data !== undefined) {
            if (form.getAttribute('data-ts-reset') !== 'false') form.reset();
            Object.keys(data || {}).forEach(function (key) {
                var fields = form.querySelectorAll('[name="' + key + '"]');
                fields.forEach(function (field) {
                    var val = data[key] === null || data[key] === undefined ? '' : data[key];
                    if (field.type === 'checkbox') {
                        field.checked = String(val) === '1' || val === true;
                    } else if (field.type === 'radio') {
                        field.checked = String(field.value) === String(val);
                    } else {
                        field.value = String(val).length >= 10 && field.type === 'date' ? String(val).substring(0, 10) : val;
                    }
                });
            });
            var titleEl = m.querySelector('[data-ts-title]');
            if (titleEl && data && data.__title) titleEl.textContent = data.__title;
        }
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        var firstInput = m.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
        if (firstInput) setTimeout(function () { firstInput.focus(); }, 60);
        m.dispatchEvent(new CustomEvent('ts:open', { detail: data || {} }));
    }

    function closeModal(m) {
        if (!m) return;
        m.classList.remove('open');
        if (!document.querySelector('.ts-modal-backdrop.open')) document.body.style.overflow = '';
    }

    window.tsOpenModal = openModal;
    window.tsCloseModal = function (id) { closeModal(document.getElementById(id)); };

    function initModals() {
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-ts-open]');
            if (opener) {
                e.preventDefault();
                var payload;
                var raw = opener.getAttribute('data-ts-payload');
                if (raw) {
                    try { payload = JSON.parse(raw); } catch (err) { payload = {}; }
                } else {
                    payload = {};
                }
                openModal(opener.getAttribute('data-ts-open'), payload);
                return;
            }
            var closer = e.target.closest('[data-ts-close]');
            if (closer) {
                e.preventDefault();
                closeModal(closer.closest('.ts-modal-backdrop'));
                return;
            }
            if (e.target.classList && e.target.classList.contains('ts-modal-backdrop')) {
                closeModal(e.target);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.ts-modal-backdrop.open').forEach(closeModal);
            }
        });
        var auto = document.querySelector('.ts-modal-backdrop[data-ts-autoopen="1"]');
        if (auto) openModal(auto.id);
    }

    // ---------- Client-side table search ----------
    function initSearch() {
        document.querySelectorAll('[data-ts-search]').forEach(function (input) {
            var target = document.getElementById(input.getAttribute('data-ts-search'));
            if (!target) return;
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase();
                target.querySelectorAll('tbody tr').forEach(function (row) {
                    if (row.querySelector('.ts-empty')) return;
                    row.style.display = !q || row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                });
            });
        });
    }

    // ---------- Animated progress bars ----------
    function initProgress() {
        document.querySelectorAll('.ts-progress > span[data-w]').forEach(function (bar) {
            requestAnimationFrame(function () { bar.style.width = Math.max(0, Math.min(100, parseFloat(bar.getAttribute('data-w')) || 0)) + '%'; });
        });
    }

    // ---------- Auto-dismiss flash ----------
    function initFlash() {
        document.querySelectorAll('.ts-alert[data-autohide]').forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s ease, transform .4s ease';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-6px)';
                setTimeout(function () { el.remove(); }, 420);
            }, 6000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initModals();
        initSearch();
        initProgress();
        initFlash();
    });
})();
