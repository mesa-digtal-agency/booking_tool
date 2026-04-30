/* Customer manage-booking page: view + cancel + reschedule. */
(function () {
  'use strict';
  const tokenMeta = document.querySelector('meta[name="booking-token"]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const token = tokenMeta ? tokenMeta.content : '';
  if (!token) return;
  const CURRENCY_SYMBOL = document.body.dataset.currencySymbol || '$';
  const BUSINESS_TODAY = document.body.dataset.businessToday || '';

  const bookingBox = document.getElementById('bookingBox');
  const actions = document.getElementById('actions');
  const rescheduleBtn = document.getElementById('rescheduleBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const rescheduleUI = document.getElementById('rescheduleUI');
  const newDate = document.getElementById('newDate');
  const newSlots = document.getElementById('newSlots');
  const newSlotsEmpty = document.getElementById('newSlotsEmpty');
  const feedback = document.getElementById('feedback');
  let current = null;
  if (!bookingBox || !actions || !rescheduleBtn || !cancelBtn || !rescheduleUI || !newDate || !newSlots || !newSlotsEmpty || !feedback) return;

  async function api(path, opts = {}) {
    const headers = Object.assign({ 'Accept': 'application/json', 'X-CSRF-Token': csrf }, opts.headers || {});
    if (opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(path, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }
  const TIME_FORMAT = document.body.dataset.timeFormat || '24h';
  function fmtTime(hhmm) {
    if (!hhmm) return '';
    const m = /^(\d{1,2}):(\d{2})/.exec(hhmm);
    if (!m) return hhmm;
    let h = +m[1], i = m[2];
    if (TIME_FORMAT === '12h') {
      const suf = h >= 12 ? 'PM' : 'AM';
      h = h % 12; if (h === 0) h = 12;
      return h + ':' + i + ' ' + suf;
    }
    return String(m[1]).padStart(2, '0') + ':' + i;
  }
  function fmtDateHuman(d) {
    return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday:'long', month:'short', day:'numeric', year:'numeric' });
  }
  function fmtMoney(n) { return CURRENCY_SYMBOL + Number(n).toFixed(2); }

  function renderBooking(b, canReschedule) {
    const statusColor = {
      confirmed: 'bg-blue-100 text-blue-700',
      pending: 'bg-amber-100 text-amber-700',
      cancelled: 'bg-red-100 text-red-700',
      completed: 'bg-green-100 text-green-700',
      no_show: 'bg-violet-100 text-violet-700',
    }[b.status] || 'bg-neutral-100';
    bookingBox.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-xs text-neutral-500">Booking #${b.id}</div>
          <div class="font-semibold text-lg mt-1">${escapeHtml(b.service_name)}</div>
          <div class="text-sm text-neutral-600 mt-1">with ${escapeHtml(b.staff_name)}</div>
          <div class="text-sm mt-3">${fmtDateHuman(b.booking_date)}</div>
          <div class="text-sm">${fmtTime(b.start_time)} – ${fmtTime(b.end_time)}</div>
          <div class="text-sm mt-3">Total: <strong>${fmtMoney(b.price)}</strong></div>
          <div class="text-xs text-neutral-500 mt-4">${escapeHtml(b.customer_name)} · ${escapeHtml(b.customer_email)} · ${escapeHtml(b.customer_phone)}</div>
          ${b.notes ? `<div class="text-xs text-neutral-500 mt-2">Note: ${escapeHtml(b.notes)}</div>` : ''}
        </div>
        <span class="text-xs px-2 py-1 rounded-full ${statusColor}">${b.status}</span>
      </div>`;

    const canAct = b.status === 'confirmed' || b.status === 'pending';
    actions.classList.toggle('hidden', !canAct);
    cancelBtn.classList.toggle('hidden', !canAct);
    rescheduleBtn.classList.toggle('hidden', !canAct || !canReschedule);
    const oldNote = actions.querySelector('[data-reschedule-note]');
    if (oldNote) oldNote.remove();

    if (canAct && !canReschedule) {
      // Add a note explaining why reschedule is disabled
      const note = document.createElement('div');
      note.dataset.rescheduleNote = 'true';
      note.className = 'text-xs text-neutral-500 mt-2';
      note.textContent = 'Rescheduling is only available more than 24 hours in advance. You can still cancel.';
      actions.appendChild(note);
    }
  }

  async function load() {
    try {
      const d = await api('/api/booking.php?token=' + encodeURIComponent(token));
      current = d.booking;
      renderBooking(d.booking, d.can_reschedule);
    } catch (e) {
      bookingBox.innerHTML = `<div class="text-red-600">Could not load booking: ${escapeHtml(e.message)}</div>`;
    }
  }

  cancelBtn.addEventListener('click', async () => {
    const ok = await window.confirmDialog('Are you sure you want to cancel this booking?', { danger: true, okLabel: 'Yes, cancel it', cancelLabel: 'Keep booking' });
    if (!ok) return;
    feedback.innerHTML = '';
    try {
      await api('/api/booking-action.php', { method: 'POST', body: { token, action: 'cancel' } });
      feedback.innerHTML = '<div class="text-emerald-700">Your booking has been cancelled. A confirmation email has been sent.</div>';
      load();
    } catch (e) {
      feedback.innerHTML = `<div class="text-red-600">${escapeHtml(e.message)}</div>`;
    }
  });

    rescheduleBtn.addEventListener('click', () => {
    rescheduleUI.classList.remove('hidden');
    const today = BUSINESS_TODAY ? new Date(BUSINESS_TODAY + 'T00:00:00') : new Date();
    today.setDate(today.getDate() + 1);
    const iso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    newDate.min = iso;
    newDate.value = iso;
    loadNewSlots();
  });

  newDate.addEventListener('change', loadNewSlots);

  async function loadNewSlots() {
    if (!current) return;
    newSlots.innerHTML = '<div class="col-span-full text-sm text-neutral-400">Loading…</div>';
    newSlotsEmpty.classList.add('hidden');
    try {
      const { slots } = await api(`/api/availability.php?staff_id=${current.staff_id}&service_id=${current.service_id}&date=${newDate.value}`);
      newSlots.innerHTML = '';
      if (!slots.length) { newSlotsEmpty.classList.remove('hidden'); return; }
      slots.forEach(t => {
        const b = document.createElement('button');
        b.className = 'py-2 rounded-lg border border-neutral-200 text-sm hover:border-primary hover:text-primary transition';
        b.textContent = fmtTime(t);
        b.addEventListener('click', () => confirmReschedule(t));
        newSlots.appendChild(b);
      });
    } catch (e) {
      newSlots.innerHTML = `<div class="col-span-full text-red-600 text-sm">${escapeHtml(e.message)}</div>`;
    }
  }

  async function confirmReschedule(time) {
    feedback.innerHTML = '';
    const ok = await window.confirmDialog(`Confirm new time: ${newDate.value} at ${fmtTime(time)}?`, { okLabel: 'Reschedule', cancelLabel: 'Cancel' });
    if (!ok) return;
    try {
      await api('/api/booking-action.php', { method: 'POST', body: { token, action: 'reschedule', new_date: newDate.value, new_time: time } });
      feedback.innerHTML = '<div class="text-emerald-700">Your booking has been rescheduled. A confirmation email has been sent.</div>';
      rescheduleUI.classList.add('hidden');
      load();
    } catch (e) {
      feedback.innerHTML = `<div class="text-red-600">${escapeHtml(e.message)}</div>`;
    }
  }

  load();
})();
