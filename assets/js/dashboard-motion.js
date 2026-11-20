/* ============================================================
   dashboard-motion.js — the dashboard "comes alive" on load
   Numbers count up from zero, columns rise, the donut sweeps
   and progress bars fill. Everything respects
   prefers-reduced-motion.
   ============================================================ */
(function () {
  'use strict';

  if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  const DURATION = 1250;

  function animateNumber(element) {
    if (element.dataset.motionDone === '1') return;

    const original = (element.textContent || '').trim();
    const match = original.match(/^([^0-9\-]*)(-?[0-9][0-9,]*(?:\.[0-9]+)?)([\s\S]*)$/);

    if (!match) return;

    const prefix   = match[1];
    const number   = match[2];
    const suffix   = match[3];
    const target   = parseFloat(number.replace(/,/g, ''));

    if (!isFinite(target)) return;

    const decimals = (number.split('.')[1] || '').length;
    const formatter = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    });

    const started = performance.now();

    function frame(now) {
      const progress = Math.min(1, (now - started) / DURATION);
      const eased    = 1 - Math.pow(1 - progress, 3);

      element.textContent = prefix + formatter.format(target * eased) + suffix;

      if (progress < 1) {
        window.requestAnimationFrame(frame);
      } else {
        element.textContent = prefix + formatter.format(target) + suffix;
        element.dataset.motionDone = '1';
      }
    }

    window.requestAnimationFrame(frame);
  }

  function start() {
    const numbers = document.querySelectorAll('.k-value, .chart-total, .chart-donut-total');

    numbers.forEach(function (element, index) {
      window.setTimeout(function () {
        animateNumber(element);
      }, 120 + index * 45);
    });

    document.querySelectorAll('.progress-thin > span').forEach(function (bar, index) {
      const target = bar.style.width || (bar.offsetWidth + 'px');

      bar.style.width = '0%';

      window.setTimeout(function () {
        window.requestAnimationFrame(function () {
          bar.style.width = target;
        });
      }, 260 + index * 90);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
