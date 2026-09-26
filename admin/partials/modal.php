<?php
// admin/partials/modal.php
// Universal Minimalist Modal System for GlobalSCM
// Replaces all native "localhost says" browser alert() and confirm() dialogs with modern, high-aesthetic modals.
?>
<!-- ===== GLOBAL SYSTEM MODAL ===== -->
<div id="systemModalBackdrop" class="system-modal-backdrop" style="display: none;" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="system-modal-dialog" id="systemModalDialog">
        <div class="system-modal-body">
            <div class="system-modal-icon-wrapper" id="systemModalIconWrapper">
                <i id="systemModalIcon" class="fas fa-question"></i>
            </div>
            <div class="system-modal-content">
                <h3 id="systemModalTitle" class="system-modal-title">Confirmation</h3>
                <p id="systemModalMessage" class="system-modal-message">Are you sure you want to proceed with this action?</p>
            </div>
        </div>
        <div class="system-modal-actions" id="systemModalActions">
            <button type="button" class="modal-btn modal-btn-cancel" id="systemModalCancelBtn">Cancel</button>
            <button type="button" class="modal-btn modal-btn-confirm" id="systemModalConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<style>
/* ====================================================
   UNIVERSAL MINIMALIST MODAL & DIALOG SYSTEM (TAILWIND COMPATIBLE)
   ==================================================== */
.system-modal-backdrop {
    position: fixed !important;
    inset: 0 !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(15, 23, 42, 0.45) !important;
    backdrop-filter: blur(5px) !important;
    -webkit-backdrop-filter: blur(5px) !important;
    z-index: 999999 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px !important;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s ease-out !important;
    box-sizing: border-box !important;
}

.system-modal-backdrop.active {
    opacity: 1 !important;
    pointer-events: auto !important;
}

.system-modal-dialog {
    background: var(--card, #ffffff) !important;
    color: var(--text, #1E293B) !important;
    border: 1px solid var(--border, #E2E8F0) !important;
    border-radius: 16px !important;
    width: 100% !important;
    max-width: 440px !important;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
    overflow: hidden !important;
    transform: scale(0.96) translateY(6px);
    transition: transform 0.15s cubic-bezier(0.16, 1, 0.3, 1) !important;
    box-sizing: border-box !important;
}

.system-modal-backdrop.active .system-modal-dialog {
    transform: scale(1) translateY(0) !important;
}

.system-modal-body {
    padding: 24px 24px 20px !important;
    display: flex !important;
    gap: 16px !important;
    align-items: flex-start !important;
}

.system-modal-icon-wrapper {
    width: 44px !important;
    height: 44px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    font-size: 18px !important;
    transition: all 0.15s ease !important;
}

/* Modal Themes */
.system-modal-icon-wrapper.danger {
    background: rgba(239, 68, 68, 0.1) !important;
    color: #EF4444 !important;
    border: 1px solid rgba(239, 68, 68, 0.2) !important;
}
.system-modal-icon-wrapper.warning {
    background: rgba(245, 158, 11, 0.1) !important;
    color: #F59E0B !important;
    border: 1px solid rgba(245, 158, 11, 0.22) !important;
}
.system-modal-icon-wrapper.success {
    background: rgba(16, 185, 129, 0.1) !important;
    color: #10B981 !important;
    border: 1px solid rgba(16, 185, 129, 0.2) !important;
}
.system-modal-icon-wrapper.primary,
.system-modal-icon-wrapper.info {
    background: rgba(47, 128, 237, 0.1) !important;
    color: #2F80ED !important;
    border: 1px solid rgba(47, 128, 237, 0.2) !important;
}

.system-modal-content {
    flex: 1 !important;
    min-width: 0 !important;
}

.system-modal-title {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: var(--text, #0F172A) !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.2px !important;
}

.system-modal-message {
    font-size: 13.5px !important;
    color: var(--secondary-text, #64748B) !important;
    margin: 0 !important;
    line-height: 1.5 !important;
    word-break: break-word !important;
}

.system-modal-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    padding: 14px 20px !important;
    background: rgba(0, 0, 0, 0.02) !important;
    border-top: 1px solid var(--border, #E2E8F0) !important;
}

[data-theme="dark"] .system-modal-actions {
    background: rgba(255, 255, 255, 0.02) !important;
}

.modal-btn {
    font-family: inherit !important;
    font-size: 13px !important;
    font-weight: 500 !important;
    padding: 8px 16px !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-height: 36px !important;
    box-sizing: border-box !important;
}

.modal-btn-cancel {
    background: var(--bg, #F8FAFC) !important;
    color: var(--secondary-text, #64748B) !important;
    border: 1px solid var(--border, #E2E8F0) !important;
}
.modal-btn-cancel:hover {
    background: var(--card, #ffffff) !important;
    color: var(--text, #0F172A) !important;
    border-color: #CBD5E1 !important;
}

.modal-btn-confirm {
    background: #2F80ED !important;
    color: #ffffff !important;
    border: 1px solid #2F80ED !important;
}
.modal-btn-confirm:hover {
    background: #2563EB !important;
    border-color: #2563EB !important;
}

.modal-btn-confirm.danger {
    background: #EF4444 !important;
    border-color: #EF4444 !important;
}
.modal-btn-confirm.danger:hover {
    background: #DC2626 !important;
    border-color: #DC2626 !important;
}

.modal-btn-confirm.warning {
    background: #F59E0B !important;
    border-color: #F59E0B !important;
}
.modal-btn-confirm.warning:hover {
    background: #D97706 !important;
    border-color: #D97706 !important;
}

.modal-btn-confirm.success {
    background: #10B981 !important;
    border-color: #10B981 !important;
}
.modal-btn-confirm.success:hover {
    background: #059669 !important;
    border-color: #059669 !important;
}
</style>

<script>
// ====================================================
// UNIVERSAL MODAL CONTROLLER (Zero "localhost says")
// ====================================================
(function() {
    var modalBackdrop = null;
    var modalDialog = null;
    var modalTitle = null;
    var modalMessage = null;
    var modalIconWrapper = null;
    var modalIcon = null;
    var modalCancelBtn = null;
    var modalConfirmBtn = null;
    var onConfirmCallback = null;
    var onCancelCallback = null;

    function initModalElements() {
        modalBackdrop = document.getElementById('systemModalBackdrop');
        modalDialog = document.getElementById('systemModalDialog');
        modalTitle = document.getElementById('systemModalTitle');
        modalMessage = document.getElementById('systemModalMessage');
        modalIconWrapper = document.getElementById('systemModalIconWrapper');
        modalIcon = document.getElementById('systemModalIcon');
        modalCancelBtn = document.getElementById('systemModalCancelBtn');
        modalConfirmBtn = document.getElementById('systemModalConfirmBtn');

        if (modalCancelBtn) {
            modalCancelBtn.onclick = function() {
                closeModal();
                if (typeof onCancelCallback === 'function') onCancelCallback();
            };
        }

        if (modalConfirmBtn) {
            modalConfirmBtn.onclick = function() {
                var cb = onConfirmCallback;
                closeModal();
                if (typeof cb === 'function') cb();
            };
        }

        if (modalBackdrop) {
            modalBackdrop.onclick = function(e) {
                if (e.target === modalBackdrop) {
                    closeModal();
                    if (typeof onCancelCallback === 'function') onCancelCallback();
                }
            };
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalBackdrop && modalBackdrop.classList.contains('active')) {
                closeModal();
                if (typeof onCancelCallback === 'function') onCancelCallback();
            }
        });
    }

    function closeModal() {
        if (!modalBackdrop) return;
        modalBackdrop.classList.remove('active');
        setTimeout(function() {
            if (!modalBackdrop.classList.contains('active')) {
                modalBackdrop.style.display = 'none';
            }
        }, 160);
    }

    // Expose Global Confirmation Modal
    window.showConfirmModal = function(options) {
        if (!modalBackdrop) initModalElements();
        options = options || {};

        var title = options.title || 'Confirmation';
        var message = options.message || 'Are you sure you want to proceed?';
        var type = options.type || 'primary'; // 'danger', 'warning', 'success', 'primary'
        var confirmText = options.confirmText || 'Confirm';
        var cancelText = options.cancelText || 'Cancel';
        onConfirmCallback = options.onConfirm || null;
        onCancelCallback = options.onCancel || null;

        modalTitle.textContent = title;
        modalMessage.innerHTML = message;
        modalCancelBtn.style.display = 'inline-flex';
        modalCancelBtn.textContent = cancelText;

        modalConfirmBtn.textContent = confirmText;
        modalConfirmBtn.className = 'modal-btn modal-btn-confirm ' + type;

        // Icon configuration
        modalIconWrapper.className = 'system-modal-icon-wrapper ' + type;
        var iconClass = 'fa-question';
        if (type === 'danger') iconClass = 'fa-trash-can';
        else if (type === 'warning') iconClass = 'fa-triangle-exclamation';
        else if (type === 'success') iconClass = 'fa-check';
        else if (type === 'primary') iconClass = 'fa-circle-info';
        modalIcon.className = 'fas ' + iconClass;

        modalBackdrop.style.display = 'flex';
        // Force reflow
        void modalBackdrop.offsetWidth;
        modalBackdrop.classList.add('active');
        modalConfirmBtn.focus();
    };

    // Expose Global Alert Modal (Replaces browser alert())
    window.showAlertModal = function(options) {
        if (!modalBackdrop) initModalElements();
        if (typeof options === 'string') {
            options = { message: options };
        }
        options = options || {};

        var title = options.title || 'Notice';
        var message = options.message || '';
        var type = options.type || 'primary';
        var buttonText = options.buttonText || 'OK';
        var onOk = options.onOk || null;

        modalTitle.textContent = title;
        modalMessage.innerHTML = message;
        modalCancelBtn.style.display = 'none'; // Alert has only OK button

        modalConfirmBtn.textContent = buttonText;
        modalConfirmBtn.className = 'modal-btn modal-btn-confirm ' + type;
        onConfirmCallback = onOk;

        modalIconWrapper.className = 'system-modal-icon-wrapper ' + type;
        var iconClass = type === 'danger' ? 'fa-circle-xmark' : (type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info');
        modalIcon.className = 'fas ' + iconClass;

        modalBackdrop.style.display = 'flex';
        void modalBackdrop.offsetWidth;
        modalBackdrop.classList.add('active');
        modalConfirmBtn.focus();
    };

    // ====================================================
    // OVERRIDE BROWSER NATIVE alert()
    // ====================================================
    window.alert = function(msg) {
        window.showAlertModal({
            title: 'Notice',
            message: String(msg),
            type: 'primary'
        });
    };

    // ====================================================
    // GLOBAL INTERCEPTOR FOR onclick="return confirm(...)"
    // Eliminates "localhost says" across all links and buttons!
    // ====================================================
    document.addEventListener('DOMContentLoaded', function() {
        initModalElements();

        document.addEventListener('click', function(e) {
            var target = e.target.closest('a, button, [onclick]');
            if (!target) return;

            var onclickAttr = target.getAttribute('onclick');
            if (onclickAttr && /confirm\s*\(/i.test(onclickAttr)) {
                // Intercept execution
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                // Extract message inside confirm('...')
                var match = onclickAttr.match(/confirm\s*\(\s*['"`]([\s\S]*?)['"`]\s*\)/i);
                var rawMsg = match ? match[1] : 'Are you sure you want to perform this action?';
                var cleanMsg = rawMsg.replace(/^[⚠️!]+\s*/, '');

                // Deduce type and context
                var isDelete = /delete|permanently|remove/i.test(rawMsg) || /action=delete/i.test(target.href || '');
                var isArchive = /archive/i.test(rawMsg) || /action=archive/i.test(target.href || '');
                var isRestore = /restore/i.test(rawMsg) || /action=restore/i.test(target.href || '');
                var isLogout = /logout/i.test(rawMsg) || /logout/i.test(target.href || '');
                var isConvert = /convert|po/i.test(rawMsg);

                var type = isDelete ? 'danger' : (isArchive || isLogout ? 'warning' : (isRestore ? 'success' : (isConvert ? 'primary' : 'warning')));
                var title = isDelete ? 'Confirm Permanent Delete' : 
                           (isArchive ? 'Confirm Archive' : 
                           (isRestore ? 'Confirm Restore' : 
                           (isLogout ? 'Confirm Logout' : 
                           (isConvert ? 'Confirm Conversion' : 'Confirmation'))));
                var confirmText = isDelete ? 'Yes, Delete' : 
                                 (isArchive ? 'Yes, Archive' : 
                                 (isRestore ? 'Yes, Restore' : 
                                 (isLogout ? 'Yes, Logout' : 
                                 (isConvert ? 'Yes, Convert' : 'Confirm'))));

                window.showConfirmModal({
                    title: title,
                    message: cleanMsg,
                    type: type,
                    confirmText: confirmText,
                    onConfirm: function() {
                        if (target.tagName === 'A' && target.href && !target.href.startsWith('javascript:')) {
                            window.location.href = target.href;
                        } else if (target.type === 'submit' && target.form) {
                            target.form.submit();
                        } else {
                            // Execute remaining onclick logic without the confirm guard
                            var strippedOnclick = onclickAttr.replace(/return\s+confirm\s*\(.*?\);?/gi, '');
                            if (strippedOnclick.trim()) {
                                new Function(strippedOnclick).call(target);
                            }
                        }
                    }
                });
            }
        }, true); // Use capture phase so we intercept before inline onclick executes!
    });
})();
</script>
