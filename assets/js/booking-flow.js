/* Customer multi-step booking flow (vanilla JS).
   Stages: 1=service, 2=staff, 3=date/time, 4=details, 5=review, success. */

(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  const state = {
    step: 1,
    service: null,
    staff: null,     // null = "any"
    date: null,
    time: null,
    details: { customer_name: '', customer_email: '', customer_phone: '', notes: '' },
  };

  // ------------------------------------------------------------------
  // Helpers
  async function api(path, opts = {}) {
    const headers = Object.assign({
      'Accept': 'application/json',
      'X-CSRF-Token': csrf,
    }, opts.headers || {});
    if (opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    const res = await fetch(path, Object.assign({}, opts, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.error || 'Request failed');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function fmtMoney(n) {
    return '$' + Number(n).toFixed(2);
  }
  function fmtMinutes(m) {
    const h = Math.floor(m / 60); const mm = m % 60;
    if (h && mm) return `${h}h ${mm}m`;
    if (h) return `${h}h`;
    return `${mm}m`;
  }
  function fmtDateHuman(d) {
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
  }
  function todayISO() {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
  }
  function shiftDate(iso, days) {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  }

  // ------------------------------------------------------------------
  // Step navigation
  function showStep(n) {
    state.step = n;
    document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
    const sel = n === 'success' ? '[data-panel="success"]' : `[data-panel="${n}"]`;
    document.querySelector(sel).classList.remove('hidden');
    document.querySelectorAll('#steps .step').forEach((el) => {
      const s = Number(el.dataset.step);
      if (typeof n === 'number' && s <= n) {
        el.classList.add('border-primary', 'text-primary', 'font-medium');
        el.classList.remove('border-neutral-200');
      } else {
        el.classList.remove('border-primary', 'text-primary', 'font-medium');
        el.classList.add('border-neutral-200');
      }
    });
    updateCart();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  document.querySelectorAll('[data-back]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (state.step === 'success') return;
      showStep(Math.max(1, state.step - 1));
    });
  });

  // ------------------------------------------------------------------
  // Step 1: Services
  async function loadServices() {
    const el = document.getElementById('servicesList');
    el.innerHTML = '<div class="text-neutral-400 text-sm">Loading services…</div>';
    try {
      const { services } = await api('/api/services.php');
      if (!services.length) { el.innerHTML = '<div class="text-neutral-500">No services are available yet.</div>'; return; }
      el.innerHTML = '';
      // group by category
      const groups = {};
      services.forEach(s => { (groups[s.category || 'Services'] = groups[s.category || 'Services'] || []).push(s); });
      Object.keys(groups).forEach(cat => {
        const h = document.createElement('h3');
        h.className = 'text-xs uppercase tracking-wide text-neutral-400 mt-4 mb-1';
        h.textContent = cat;
        el.appendChild(h);
        groups[cat].forEach(s => {
          const card = document.createElement('button');
          card.type = 'button';
          card.className = 'w-full text-left bg-white border border-neutral-200 rounded-xl p-4 flex items-center justify-between hover:border-primary transition';
          card.innerHTML = `
            <div>
              <div class="font-medium">${escapeHtml(s.name)}</div>
              <div class="text-xs text-neutral-500">${fmtMinutes(s.duration_minutes)}${s.description ? ' · ' + escapeHtml(s.description) : ''}</div>
            </div>
            <div class="text-sm font-semibold">${fmtMoney(s.price)}</div>`;
          card.addEventListener('click', () => {
            state.service = s;
            state.staff = null; state.date = null; state.time = null;
            loadStaff();
            showStep(2);
          });
          el.appendChild(card);
        });
      });
    } catch (e) {
      el.innerHTML = `<div class="text-red-600 text-sm">Could not load services.</div>`;
    }
  }

  // Step 2: Professionals
  async function loadStaff() {
    const el = document.getElementById('staffList');
    el.innerHTML = '<div class="text-neutral-400 text-sm col-span-full">Loading…</div>';
    try {
      const { staff } = await api(`/api/staff.php?service_id=${state.service.id}`);
      el.innerHTML = '';
      // "Any" card
      const anyBtn = pickStaffCard({ id: 'any', name: 'Any professional', avatar: null, subtitle: 'Maximum availability' });
      el.appendChild(anyBtn);
      staff.forEach(s => el.appendChild(pickStaffCard(s)));
      if (!staff.length) {
        el.innerHTML += '<div class="col-span-full text-sm text-neutral-500">No professionals currently perform this service.</div>';
      }
    } catch (e) {
      el.innerHTML = `<div class="text-red-600 text-sm col-span-full">Could not load professionals.</div>`;
    }
  }

  function pickStaffCard(s) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bg-white border border-neutral-200 rounded-xl p-4 text-center hover:border-primary transition';
    const av = s.avatar
      ? `<img src="/assets/avatars/${escapeHtml(s.avatar)}" class="w-16 h-16 rounded-full mx-auto object-cover">`
      : `<div class="w-16 h-16 rounded-full mx-auto flex items-center justify-center bg-neutral-100 text-neutral-500 text-xl font-semibold">${initials(s.name)}</div>`;
    btn.innerHTML = `
      ${av}
      <div class="font-medium mt-2">${escapeHtml(s.name)}</div>
      ${s.subtitle ? `<div class="text-xs text-neutral-500">${escapeHtml(s.subtitle)}</div>` : ''}`;
    btn.addEventListener('click', () => {
      state.staff = (s.id === 'any') ? null : s;
      state.date = todayISO();
      document.getElementById('dateInput').value = state.date;
      document.getElementById('dateInput').min = todayISO();
      loadSlots();
      showStep(3);
    });
    return btn;
  }

  // Step 3: Date + slots
  document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'dateInput') {
      state.date = e.target.value;
      loadSlots();
    }
  });
  document.getElementById('prevDay').addEventListener('click', () => {
    const d = shiftDate(state.date, -1);
    if (d < todayISO()) return;
    state.date = d;
    document.getElementById('dateInput').value = d;
    loadSlots();
  });
  document.getElementById('nextDay').addEventListener('click', () => {
    state.date = shiftDate(state.date, 1);
    document.getElementById('dateInput').value = state.date;
    loadSlots();
  });

  async function loadSlots() {
    const slotsEl = document.getElementById('slotsList');
    const emptyEl = document.getElementById('slotsEmpty');
    slotsEl.innerHTML = '<div class="col-span-full text-sm text-neutral-400">Loading…</div>';
    emptyEl.classList.add('hidden');
    const staffParam = state.staff ? state.staff.id : 'any';
    try {
      const { slots } = await api(`/api/availability.php?staff_id=${staffParam}&service_id=${state.service.id}&date=${state.date}`);
      slotsEl.innerHTML = '';
      if (!slots.length) { emptyEl.classList.remove('hidden'); return; }
      slots.forEach(t => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'py-2 rounded-lg border border-neutral-200 text-sm hover:border-primary hover:text-primary transition';
        b.textContent = t;
        b.addEventListener('click', () => {
          state.time = t;
          showStep(4);
        });
        slotsEl.appendChild(b);
      });
    } catch (e) {
      slotsEl.innerHTML = `<div class="col-span-full text-red-600 text-sm">Could not load times.</div>`;
    }
  }

  // Step 4: Details
  document.getElementById('detailsForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    state.details = {
      customer_name: fd.get('customer_name').trim(),
      customer_email: fd.get('customer_email').trim(),
      customer_phone: fd.get('customer_phone').trim(),
      notes: (fd.get('notes') || '').trim(),
    };
    renderReview();
    showStep(5);
  });

  function renderReview() {
    const r = document.getElementById('reviewBox');
    r.innerHTML = `
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Service</div><div class="font-medium">${escapeHtml(state.service.name)}</div></div>
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Professional</div><div class="font-medium">${state.staff ? escapeHtml(state.staff.name) : 'Any available'}</div></div>
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Date</div><div class="font-medium">${fmtDateHuman(state.date)}</div></div>
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Time</div><div class="font-medium">${state.time} (${fmtMinutes(state.service.duration_minutes)})</div></div>
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Name</div><div class="font-medium">${escapeHtml(state.details.customer_name)}</div></div>
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Contact</div><div class="font-medium text-right">${escapeHtml(state.details.customer_email)}<br>${escapeHtml(state.details.customer_phone)}</div></div>
      <hr class="my-2">
      <div class="flex items-center justify-between"><div class="text-neutral-500 text-sm">Total</div><div class="font-semibold text-lg">${fmtMoney(state.service.price)}</div></div>`;
  }

  // Step 5: Confirm
  document.getElementById('confirmBtn').addEventListener('click', async () => {
    const btn = document.getElementById('confirmBtn');
    const err = document.getElementById('confirmError');
    btn.disabled = true; btn.textContent = 'Booking…'; err.classList.add('hidden');
    try {
      const payload = {
        service_id: state.service.id,
        staff_id: state.staff ? state.staff.id : 'any',
        date: state.date,
        time: state.time,
        ...state.details,
      };
      const { booking, management_url } = await api('/api/bookings.php', { method: 'POST', body: payload });
      document.getElementById('successDetails').innerHTML = `
        <div class="flex items-center justify-between"><div class="text-neutral-500">Service</div><div>${escapeHtml(booking.service_name)}</div></div>
        <div class="flex items-center justify-between"><div class="text-neutral-500">With</div><div>${escapeHtml(booking.staff_name)}</div></div>
        <div class="flex items-center justify-between"><div class="text-neutral-500">When</div><div>${fmtDateHuman(booking.booking_date)} at ${booking.start_time}</div></div>
        <div class="flex items-center justify-between"><div class="text-neutral-500">Total</div><div>${fmtMoney(booking.price)}</div></div>
        <div class="mt-3 text-xs text-neutral-500 break-all">Manage your booking: <a href="${management_url}" class="underline">${management_url}</a></div>`;
      document.getElementById('cart').classList.add('hidden');
      showStep('success');
    } catch (e) {
      err.textContent = e.message || 'Something went wrong. Please try again.';
      err.classList.remove('hidden');
    } finally {
      btn.disabled = false; btn.textContent = 'Confirm booking';
    }
  });

  // ------------------------------------------------------------------
  // Cart summary
  function updateCart() {
    const cart = document.getElementById('cart');
    const sum = document.getElementById('cartSummary');
    const btn = document.getElementById('cartContinue');
    if (state.step === 1 || state.step === 'success' || !state.service) {
      cart.classList.add('hidden'); return;
    }
    const parts = [state.service.name + ' · ' + fmtMoney(state.service.price)];
    if (state.time) parts.push(fmtDateHuman(state.date) + ' ' + state.time);
    sum.textContent = parts.join(' — ');
    cart.classList.remove('hidden');
    // Continue button is only meaningful when we've chosen a time but haven't entered details yet.
    btn.classList.toggle('hidden', state.step !== 3 || !state.time);
    btn.onclick = () => showStep(4);
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
  }
  function initials(name) {
    return (name || '?').split(/\s+/).map(x => x[0]).join('').slice(0, 2).toUpperCase();
  }

  // Kick off.
  loadServices();
})();
