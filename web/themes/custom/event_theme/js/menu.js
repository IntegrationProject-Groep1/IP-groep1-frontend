/**
 * menu.js — Mobile hamburger menu toggle.
 * Pure vanilla JS, no dependencies.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var toggle   = document.getElementById('menu-toggle');
    var menu     = document.getElementById('mobile-menu');
    var iconOpen  = document.getElementById('icon-open');
    var iconClose = document.getElementById('icon-close');

    if (!toggle || !menu) {
      return;
    }

    toggle.addEventListener('click', function () {
      var isOpen = !menu.classList.contains('hidden');

      if (isOpen) {
        menu.classList.add('hidden');
        iconOpen.classList.remove('hidden');
        iconClose.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        menu.classList.remove('hidden');
        iconOpen.classList.add('hidden');
        iconClose.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
      }
    });

    // Close when clicking outside the header.
    document.addEventListener('click', function (e) {
      var header = toggle.closest('header');
      if (header && !header.contains(e.target)) {
        menu.classList.add('hidden');
        iconOpen.classList.remove('hidden');
        iconClose.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    // Close on Escape key.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.classList.contains('hidden')) {
        menu.classList.add('hidden');
        iconOpen.classList.remove('hidden');
        iconClose.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });
  });
}());
