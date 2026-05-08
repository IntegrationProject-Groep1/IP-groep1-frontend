/**
 * menu.js — Mobile hamburger menu + account dropdown toggle.
 * Pure vanilla JS, no dependencies.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // ── Hamburger menu ───────────────────────────────────────────────────────
    var toggle    = document.getElementById('menu-toggle');
    var menu      = document.getElementById('mobile-menu');
    var iconOpen  = document.getElementById('icon-open');
    var iconClose = document.getElementById('icon-close');

    if (toggle && menu) {
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

      document.addEventListener('click', function (e) {
        var header = toggle.closest('header');
        if (header && !header.contains(e.target)) {
          menu.classList.add('hidden');
          iconOpen.classList.remove('hidden');
          iconClose.classList.add('hidden');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.classList.contains('hidden')) {
          menu.classList.add('hidden');
          iconOpen.classList.remove('hidden');
          iconClose.classList.add('hidden');
          toggle.setAttribute('aria-expanded', 'false');
          toggle.focus();
        }
      });
    }

    // ── Account dropdown ─────────────────────────────────────────────────────
    var accountToggle   = document.getElementById('account-menu-toggle');
    var accountDropdown = document.getElementById('account-dropdown');
    var accountChevron  = document.getElementById('account-chevron');

    if (accountToggle && accountDropdown) {
      function closeDropdown() {
        accountDropdown.classList.add('hidden');
        if (accountChevron) { accountChevron.classList.remove('rotate-180'); }
        accountToggle.setAttribute('aria-expanded', 'false');
      }

      accountToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = !accountDropdown.classList.contains('hidden');
        if (isOpen) {
          closeDropdown();
        } else {
          accountDropdown.classList.remove('hidden');
          if (accountChevron) { accountChevron.classList.add('rotate-180'); }
          accountToggle.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        var wrapper = document.getElementById('account-menu-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
          closeDropdown();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeDropdown();
        }
      });
    }

  });
}());
