/* Country-code phone input.
   Renders a <select> of dial codes beside a number input, and
   combines them into a hidden input with the target `name`.
   Usage:
     <div data-phone-input data-name="customer_phone" data-default-cc="+1"></div>
   The combined value (e.g. "+1 555-1234") is submitted under `name`.
*/
(function () {
  'use strict';

  // Common country list — ISO2, flag-ish label, E.164 dial code, digit range.
  const COUNTRIES = [
    { cc: '+1',   name: 'US / CA',         iso: 'US', min: 10, max: 10 },
    { cc: '+44',  name: 'United Kingdom',  iso: 'GB', min: 9,  max: 11 },
    { cc: '+971', name: 'UAE',             iso: 'AE', min: 8,  max: 9  },
    { cc: '+966', name: 'Saudi Arabia',    iso: 'SA', min: 9,  max: 9  },
    { cc: '+20',  name: 'Egypt',           iso: 'EG', min: 9,  max: 10 },
    { cc: '+962', name: 'Jordan',          iso: 'JO', min: 8,  max: 9  },
    { cc: '+961', name: 'Lebanon',         iso: 'LB', min: 7,  max: 8  },
    { cc: '+973', name: 'Bahrain',         iso: 'BH', min: 8,  max: 8  },
    { cc: '+974', name: 'Qatar',           iso: 'QA', min: 8,  max: 8  },
    { cc: '+965', name: 'Kuwait',          iso: 'KW', min: 8,  max: 8  },
    { cc: '+968', name: 'Oman',            iso: 'OM', min: 8,  max: 8  },
    { cc: '+90',  name: 'Turkey',          iso: 'TR', min: 10, max: 10 },
    { cc: '+49',  name: 'Germany',         iso: 'DE', min: 6,  max: 13 },
    { cc: '+33',  name: 'France',          iso: 'FR', min: 9,  max: 9  },
    { cc: '+34',  name: 'Spain',           iso: 'ES', min: 9,  max: 9  },
    { cc: '+39',  name: 'Italy',           iso: 'IT', min: 9,  max: 11 },
    { cc: '+31',  name: 'Netherlands',     iso: 'NL', min: 9,  max: 9  },
    { cc: '+41',  name: 'Switzerland',     iso: 'CH', min: 9,  max: 9  },
    { cc: '+46',  name: 'Sweden',          iso: 'SE', min: 7,  max: 10 },
    { cc: '+47',  name: 'Norway',          iso: 'NO', min: 8,  max: 8  },
    { cc: '+45',  name: 'Denmark',         iso: 'DK', min: 8,  max: 8  },
    { cc: '+353', name: 'Ireland',         iso: 'IE', min: 7,  max: 11 },
    { cc: '+351', name: 'Portugal',        iso: 'PT', min: 9,  max: 9  },
    { cc: '+91',  name: 'India',           iso: 'IN', min: 10, max: 10 },
    { cc: '+92',  name: 'Pakistan',        iso: 'PK', min: 10, max: 10 },
    { cc: '+86',  name: 'China',           iso: 'CN', min: 11, max: 11 },
    { cc: '+81',  name: 'Japan',           iso: 'JP', min: 9,  max: 11 },
    { cc: '+82',  name: 'South Korea',     iso: 'KR', min: 9,  max: 11 },
    { cc: '+61',  name: 'Australia',       iso: 'AU', min: 9,  max: 9  },
    { cc: '+64',  name: 'New Zealand',     iso: 'NZ', min: 8,  max: 10 },
    { cc: '+27',  name: 'South Africa',    iso: 'ZA', min: 9,  max: 9  },
    { cc: '+234', name: 'Nigeria',         iso: 'NG', min: 10, max: 10 },
    { cc: '+254', name: 'Kenya',           iso: 'KE', min: 9,  max: 10 },
    { cc: '+55',  name: 'Brazil',          iso: 'BR', min: 10, max: 11 },
    { cc: '+52',  name: 'Mexico',          iso: 'MX', min: 10, max: 10 },
    { cc: '+54',  name: 'Argentina',       iso: 'AR', min: 10, max: 11 },
  ];

  function build(container) {
    const name        = container.dataset.name || 'phone';
    const defaultCc   = container.dataset.defaultCc || '+1';
    const initial     = container.dataset.value || '';
    const required    = container.hasAttribute('data-required');
    const inputClass  = container.dataset.inputClass || 'w-full px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20';

    // Try to parse an existing value like "+1 555-1234"
    let startCc = defaultCc;
    let startNum = '';
    if (initial) {
      const m = initial.trim().match(/^(\+\d{1,4})\s*(.*)$/);
      if (m && COUNTRIES.find(c => c.cc === m[1])) {
        startCc = m[1]; startNum = m[2];
      } else {
        startNum = initial.trim();
      }
    }

    const wrap = document.createElement('div');
    // Compact grid: fixed-width select, input takes the rest. min-w-0 allows
    // the input to shrink inside narrow columns (e.g. md:grid-cols-2).
    wrap.className = 'flex gap-2 items-stretch';
    wrap.innerHTML =
      '<select class="w-[84px] px-2 py-2 rounded-lg border border-neutral-200 text-sm bg-white flex-shrink-0" data-cc>' +
      // Collapsed option shows just the dial code (e.g. "+971"); expanded option
      // shows the country + code for disambiguation.
      COUNTRIES.map(c => `<option value="${c.cc}" ${c.cc === startCc ? 'selected' : ''} data-short="${c.cc}" title="${c.name} ${c.cc}">${c.iso} ${c.cc}</option>`).join('') +
      '</select>' +
      `<input type="tel" inputmode="tel" autocomplete="tel-national" class="${inputClass} flex-1 min-w-0" data-num ${required ? 'required' : ''} value="${escapeAttr(startNum)}" placeholder="Phone number">` +
      `<input type="hidden" name="${escapeAttr(name)}" data-combined>`;

    container.appendChild(wrap);

    const sel  = wrap.querySelector('[data-cc]');
    const num  = wrap.querySelector('[data-num]');
    const out  = wrap.querySelector('[data-combined]');
    // Append the error message to the CONTAINER, not the flex wrap, so it
    // sits on its own line directly below the select+input row.
    const err  = document.createElement('div');
    err.className = 'text-xs text-red-600 mt-1 hidden';
    container.appendChild(err);

    function recompute() {
      const digits = (num.value || '').replace(/\D/g, '');
      out.value = digits ? (sel.value + ' ' + num.value.trim()) : '';
      validate();
    }
    function validate() {
      const digits = (num.value || '').replace(/\D/g, '');
      const spec = COUNTRIES.find(c => c.cc === sel.value);
      if (!digits) {
        num.setCustomValidity(required ? 'Please enter a phone number.' : '');
        err.classList.add('hidden');
        return;
      }
      if (spec && (digits.length < spec.min || digits.length > spec.max)) {
        const msg = `Expected ${spec.min === spec.max ? spec.min : spec.min + '–' + spec.max} digits for ${spec.iso} (${spec.cc}).`;
        num.setCustomValidity(msg);
        err.textContent = msg;
        err.classList.remove('hidden');
      } else {
        num.setCustomValidity('');
        err.classList.add('hidden');
      }
    }
    sel.addEventListener('change', recompute);
    num.addEventListener('input', recompute);
    recompute();
  }

  function escapeAttr(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }

  function init() {
    document.querySelectorAll('[data-phone-input]').forEach(build);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.PhoneInput = { COUNTRIES };
})();
