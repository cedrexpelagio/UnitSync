/**
 * UnitSync Shared UI Utilities
 * Replaces browser alerts with custom modals and toasts
 * Follows UI_DESIGN.md section 4 & 5
 */

// Toast notification helper
function showToast(message, type = 'info', duration = 4000) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'alert');

    // Choose SVG icon based on type
    let iconSvg = '';
    if (type === 'success') {
        iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    } else if (type === 'error') {
        iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--error)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
    } else if (type === 'warning') {
        iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
    } else {
        iconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--info)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
    }

    toast.innerHTML = `
        <div style="flex-shrink:0;">${iconSvg}</div>
        <div style="flex:1;">${message}</div>
    `;

    container.appendChild(toast);

    // Trigger enter animation
    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    // Auto dismiss
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => {
            toast.remove();
        });
    }, duration);
}

// Confirmation modal helper (returns a Promise<boolean>)
function showConfirm(options = {}) {
    const title = options.title || 'Confirm Action';
    const message = options.message || 'Are you sure you want to proceed?';
    const confirmText = options.confirmText || 'Confirm';
    const cancelText = options.cancelText || 'Cancel';
    const isDanger = options.isDanger || false;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        overlay.innerHTML = `
            <div class="modal-card">
                <div class="modal-header">
                    <h3>${title}</h3>
                </div>
                <div class="modal-body">
                    ${message}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" id="modal-cancel-btn">${cancelText}</button>
                    <button type="button" class="btn ${isDanger ? 'btn-danger' : 'btn-primary'} btn-sm" id="modal-confirm-btn">${confirmText}</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        // Open modal with transition
        requestAnimationFrame(() => {
            overlay.classList.add('open');
        });

        const cancelBtn = overlay.querySelector('#modal-cancel-btn');
        const confirmBtn = overlay.querySelector('#modal-confirm-btn');

        function cleanup(result) {
            overlay.classList.remove('open');
            document.removeEventListener('keydown', handleKey);
            setTimeout(() => {
                overlay.remove();
                resolve(result);
            }, 200);
        }

        function handleKey(e) {
            if (e.key === 'Escape') {
                cleanup(false);
            }
        }

        cancelBtn.addEventListener('click', () => cleanup(false));
        confirmBtn.addEventListener('click', () => cleanup(true));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) cleanup(false);
        });
        document.addEventListener('keydown', handleKey);

        confirmBtn.focus();
    });
}

// Prompt modal with textarea helper (for rejection / deactivation reasons)
function showPromptModal(options = {}) {
    const title = options.title || 'Reason Required';
    const message = options.message || 'Please provide details:';
    const placeholder = options.placeholder || 'Enter reason here...';
    const confirmText = options.confirmText || 'Submit';
    const isDanger = options.isDanger || false;

    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        overlay.innerHTML = `
            <div class="modal-card">
                <div class="modal-header">
                    <h3>${title}</h3>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 12px;">${message}</p>
                    <textarea id="modal-prompt-input" class="form-control" rows="3" placeholder="${placeholder}" required autofocus></textarea>
                    <span id="modal-prompt-error" class="field-error" style="display:none;">This field is required.</span>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" id="modal-prompt-cancel">Cancel</button>
                    <button type="button" class="btn ${isDanger ? 'btn-danger' : 'btn-primary'} btn-sm" id="modal-prompt-confirm">${confirmText}</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        requestAnimationFrame(() => {
            overlay.classList.add('open');
        });

        const input = overlay.querySelector('#modal-prompt-input');
        const errSpan = overlay.querySelector('#modal-prompt-error');
        const cancelBtn = overlay.querySelector('#modal-prompt-cancel');
        const confirmBtn = overlay.querySelector('#modal-prompt-confirm');

        function cleanup(result) {
            overlay.classList.remove('open');
            document.removeEventListener('keydown', handleKey);
            setTimeout(() => {
                overlay.remove();
                resolve(result);
            }, 200);
        }

        function handleKey(e) {
            if (e.key === 'Escape') {
                cleanup(null);
            }
        }

        cancelBtn.addEventListener('click', () => cleanup(null));
        confirmBtn.addEventListener('click', () => {
            const val = input.value.trim();
            if (!val) {
                errSpan.style.display = 'block';
                input.classList.add('is-invalid');
                input.focus();
                return;
            }
            cleanup(val);
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) cleanup(null);
        });
        document.addEventListener('keydown', handleKey);

        input.focus();
    });
}

// Global initialization
document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile navigation drawer toggle
    const mobileToggle = document.getElementById('mobile-nav-toggle');
    const sidebar = document.getElementById('app-sidebar');

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('drawer-open');
        });

        document.addEventListener('click', (e) => {
            if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                sidebar.classList.remove('drawer-open');
            }
        });
    }

    // 2. Sign out confirmation modal
    const signoutBtn = document.getElementById('signout-btn');
    const signoutForm = document.getElementById('signout-form');
    if (signoutBtn && signoutForm) {
        signoutBtn.addEventListener('click', async () => {
            const confirmed = await showConfirm({
                title: 'Sign Out Confirmation',
                message: 'Are you sure you want to sign out of UnitSync?',
                confirmText: 'Sign out',
                cancelText: 'Cancel'
            });
            if (confirmed) {
                signoutForm.submit();
            }
        });
    }

    // 3. Convert session flash banners to toasts (Progressive Enhancement)
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach((msgEl) => {
        let type = 'info';
        if (msgEl.classList.contains('flash-success')) type = 'success';
        else if (msgEl.classList.contains('flash-error')) type = 'error';
        else if (msgEl.classList.contains('flash-warning')) type = 'warning';

        showToast(msgEl.textContent, type);
    });

    // 4. Password visibility toggle (Eye icon)
    initPasswordToggles();
});

function initPasswordToggles() {
    document.querySelectorAll('.password-toggle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const input = document.getElementById(targetId);
            if (!input) return;

            const eyeOpen = btn.querySelector('.eye-open');
            const eyeClosed = btn.querySelector('.eye-closed');

            if (input.type === 'password') {
                input.type = 'text';
                if (eyeOpen) eyeOpen.style.display = 'none';
                if (eyeClosed) eyeClosed.style.display = 'inline';
                btn.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                if (eyeOpen) eyeOpen.style.display = 'inline';
                if (eyeClosed) eyeClosed.style.display = 'none';
                btn.setAttribute('aria-label', 'Show password');
            }
        });
    });
}
