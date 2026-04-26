(function () {
  const overlay = document.getElementById('adminLoadingOverlay');
  const main = document.querySelector('.admin-main');
  if (!overlay) return;

  let visible = false;

  function show() {
    if (visible) return;
    visible = true;
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
    if (main) main.setAttribute('aria-busy', 'true');
  }

  function hide() {
    visible = false;
    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
    if (main) main.removeAttribute('aria-busy');
  }

  function shouldSkipForm(form) {
    if (!form) return true;
    if (form.dataset.noLoader === 'true') return true;
    const target = (form.getAttribute('target') || '').trim();
    return target !== '' && target !== '_self';
  }

  function shouldShowForLink(link, event) {
    if (!link || event.defaultPrevented) return false;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    if (link.dataset.noLoader === 'true' || link.hasAttribute('download')) return false;

    const target = (link.getAttribute('target') || '').trim();
    if (target && target !== '_self') return false;

    const rawHref = link.getAttribute('href') || '';
    if (!rawHref || rawHref.startsWith('#') || rawHref.startsWith('javascript:') || rawHref.startsWith('mailto:') || rawHref.startsWith('tel:')) {
      return false;
    }

    let url;
    try {
      url = new URL(rawHref, window.location.href);
    } catch (_) {
      return false;
    }

    if (url.origin !== window.location.origin) return false;
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;

    // CSV exports stream a download without leaving the page; showing the
    // overlay there would leave it stuck over the admin area.
    if (url.searchParams.has('export')) return false;

    return true;
  }

  window.adminLoader = { show, hide };

  document.addEventListener('submit', function (event) {
    const form = event.target;
    window.setTimeout(function () {
      if (!event.defaultPrevented && !shouldSkipForm(form)) show();
    }, 0);
  });

  document.addEventListener('click', function (event) {
    const link = event.target.closest && event.target.closest('a[href]');
    if (!shouldShowForLink(link, event)) return;
    window.setTimeout(function () {
      if (!event.defaultPrevented) show();
    }, 0);
  });

  const nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if (!shouldSkipForm(this)) show();
    return nativeSubmit.apply(this, arguments);
  };

  window.addEventListener('pageshow', hide);
  window.addEventListener('pagehide', hide);
})();
