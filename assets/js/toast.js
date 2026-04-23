/* Global toast + confirm helpers.
   Usage:
     toast('Booking saved', { type: 'success' })
     toast('Something went wrong', { type: 'error' })
     await confirmDialog('Delete this booking?', { danger: true })
*/
(function () {
  'use strict';

  function root() {
    let r = document.getElementById('toastRoot');
    if (!r) {
      r = document.createElement('div');
      r.id = 'toastRoot';
      document.body.appendChild(r);
    }
    return r;
  }

  const ICONS = {
    info:    '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    warn:    '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    error:   '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
  };

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }

  window.toast = function (message, opts) {
    opts = opts || {};
    const type = opts.type || 'info';
    const timeout = opts.timeout == null ? 4500 : opts.timeout;
    const r = root();
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML =
      (ICONS[type] || ICONS.info) +
      '<div class="toast-body">' + esc(message) + '</div>' +
      '<button class="toast-close" aria-label="Dismiss">&times;</button>';
    const remove = () => {
      el.classList.add('leaving');
      setTimeout(() => el.remove(), 200);
    };
    el.querySelector('.toast-close').addEventListener('click', remove);
    r.appendChild(el);
    if (timeout) setTimeout(remove, timeout);
    return { close: remove };
  };

  /**
   * Promise-based confirm dialog. Returns true/false.
   * Replaces browser confirm() for a consistent look.
   */
  window.confirmDialog = function (message, opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop';
      backdrop.innerHTML =
        '<div class="modal" role="dialog" aria-modal="true">' +
          '<div style="font-size:15px;line-height:1.45;color:#111827;margin-bottom:16px;">' + esc(message).replace(/\n/g, '<br>') + '</div>' +
          '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
            '<button type="button" data-act="cancel" style="padding:8px 14px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;font-size:14px;cursor:pointer;">' + esc(opts.cancelLabel || 'Cancel') + '</button>' +
            '<button type="button" data-act="ok" style="padding:8px 14px;border-radius:8px;border:0;color:#fff;font-size:14px;cursor:pointer;background:' + (opts.danger ? '#ef4444' : (opts.primary || 'var(--primary-color, #7c3aed)')) + ';">' + esc(opts.okLabel || 'Confirm') + '</button>' +
          '</div>' +
        '</div>';
      const cleanup = (val) => { backdrop.remove(); resolve(val); };
      backdrop.addEventListener('click', (ev) => { if (ev.target === backdrop) cleanup(false); });
      backdrop.querySelector('[data-act="cancel"]').addEventListener('click', () => cleanup(false));
      backdrop.querySelector('[data-act="ok"]').addEventListener('click', () => cleanup(true));
      document.addEventListener('keydown', function onKey(ev) {
        if (ev.key === 'Escape') { cleanup(false); document.removeEventListener('keydown', onKey); }
        if (ev.key === 'Enter')  { cleanup(true);  document.removeEventListener('keydown', onKey); }
      });
      document.body.appendChild(backdrop);
    });
  };
})();
