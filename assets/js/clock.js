/* Live clock — renders current time in the configured timezone.
   Element with id="liveClock" gets its HTML replaced every second. */
(function () {
  'use strict';
  const el = document.getElementById('liveClock');
  if (!el) return;
  const tz = el.dataset.tz || 'UTC';

  function render() {
    const now = new Date();
    let timeStr, dateStr;
    try {
      timeStr = new Intl.DateTimeFormat([], { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: tz, hour12: false }).format(now);
      dateStr = new Intl.DateTimeFormat([], { weekday: 'short', month: 'short', day: 'numeric', timeZone: tz }).format(now);
    } catch (e) {
      timeStr = now.toLocaleTimeString();
      dateStr = now.toLocaleDateString();
    }
    el.innerHTML =
      '<div class="time">' + timeStr + '</div>' +
      '<div class="date">' + dateStr + ' · ' + tz + '</div>';
  }
  render();
  setInterval(render, 1000);
})();
