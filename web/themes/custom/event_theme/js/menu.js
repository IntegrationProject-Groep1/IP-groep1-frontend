/**
 * menu.js — Shift Festival 2026 navigation: mobile menu + account dropdown.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // ── Landing page hamburger (landing-menu-toggle) ─────────────────────────
    var landingToggle     = document.getElementById('landing-menu-toggle');
    var landingMenu       = document.getElementById('landing-mobile-menu');
    var landingIconOpen   = document.getElementById('landing-icon-open');
    var landingIconClose  = document.getElementById('landing-icon-close');

    if (landingToggle && landingMenu) {
      landingToggle.addEventListener('click', function () {
        var isOpen = landingMenu.classList.contains('is-open');
        if (isOpen) {
          landingMenu.classList.remove('is-open');
          landingMenu.setAttribute('aria-hidden', 'true');
          if (landingIconOpen)  landingIconOpen.style.display  = '';
          if (landingIconClose) landingIconClose.style.display = 'none';
          landingToggle.setAttribute('aria-expanded', 'false');
        } else {
          landingMenu.classList.add('is-open');
          landingMenu.setAttribute('aria-hidden', 'false');
          if (landingIconOpen)  landingIconOpen.style.display  = 'none';
          if (landingIconClose) landingIconClose.style.display = '';
          landingToggle.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && landingMenu.classList.contains('is-open')) {
          landingMenu.classList.remove('is-open');
          landingMenu.setAttribute('aria-hidden', 'true');
          if (landingIconOpen)  landingIconOpen.style.display  = '';
          if (landingIconClose) landingIconClose.style.display = 'none';
          landingToggle.setAttribute('aria-expanded', 'false');
          landingToggle.focus();
        }
      });

      // Close when a menu link is clicked (smooth scroll / navigation).
      landingMenu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
          landingMenu.classList.remove('is-open');
          landingMenu.setAttribute('aria-hidden', 'true');
          if (landingIconOpen)  landingIconOpen.style.display  = '';
          if (landingIconClose) landingIconClose.style.display = 'none';
          landingToggle.setAttribute('aria-expanded', 'false');
        });
      });
    }

    // ── Shift Festival mobile hamburger (sf-menu-toggle) ────────────────────
    var sfToggle    = document.getElementById('sf-menu-toggle');
    var sfMenu      = document.getElementById('sf-mobile-menu');
    var sfIconOpen  = document.getElementById('sf-icon-open');
    var sfIconClose = document.getElementById('sf-icon-close');

    if (sfToggle && sfMenu) {
      sfToggle.addEventListener('click', function () {
        var isOpen = sfMenu.classList.contains('is-open');
        if (isOpen) {
          sfMenu.classList.remove('is-open');
          if (sfIconOpen)  sfIconOpen.style.display  = '';
          if (sfIconClose) sfIconClose.style.display = 'none';
          sfToggle.setAttribute('aria-expanded', 'false');
        } else {
          sfMenu.classList.add('is-open');
          if (sfIconOpen)  sfIconOpen.style.display  = 'none';
          if (sfIconClose) sfIconClose.style.display = '';
          sfToggle.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!sfToggle.contains(e.target) && !sfMenu.contains(e.target)) {
          sfMenu.classList.remove('is-open');
          if (sfIconOpen)  sfIconOpen.style.display  = '';
          if (sfIconClose) sfIconClose.style.display = 'none';
          sfToggle.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sfMenu.classList.contains('is-open')) {
          sfMenu.classList.remove('is-open');
          if (sfIconOpen)  sfIconOpen.style.display  = '';
          if (sfIconClose) sfIconClose.style.display = 'none';
          sfToggle.setAttribute('aria-expanded', 'false');
          sfToggle.focus();
        }
      });
    }

    // ── Shift Festival account dropdown (sf-account-toggle) ─────────────────
    var sfAccountToggle   = document.getElementById('sf-account-toggle');
    var sfAccountDropdown = document.getElementById('sf-account-dropdown');
    var sfAccountChevron  = document.getElementById('sf-account-chevron');

    if (sfAccountToggle && sfAccountDropdown) {
      function closeSfDropdown() {
        sfAccountDropdown.style.display = 'none';
        if (sfAccountChevron) sfAccountChevron.style.transform = '';
        sfAccountToggle.setAttribute('aria-expanded', 'false');
      }

      sfAccountToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = sfAccountDropdown.style.display !== 'none';
        if (isOpen) {
          closeSfDropdown();
        } else {
          sfAccountDropdown.style.display = 'block';
          if (sfAccountChevron) sfAccountChevron.style.transform = 'rotate(180deg)';
          sfAccountToggle.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        var wrapper = document.getElementById('sf-account-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
          closeSfDropdown();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSfDropdown();
      });
    }

    // ── Legacy fallback (old menu-toggle / account-menu-toggle IDs) ─────────
    var toggle    = document.getElementById('menu-toggle');
    var menu      = document.getElementById('mobile-menu');
    var iconOpen  = document.getElementById('icon-open');
    var iconClose = document.getElementById('icon-close');

    if (toggle && menu) {
      toggle.addEventListener('click', function () {
        var isOpen = !menu.classList.contains('hidden');
        menu.classList.toggle('hidden', isOpen);
        if (iconOpen)  iconOpen.classList.toggle('hidden', !isOpen);
        if (iconClose) iconClose.classList.toggle('hidden', isOpen);
        toggle.setAttribute('aria-expanded', String(!isOpen));
      });
    }

    var accountToggle   = document.getElementById('account-menu-toggle');
    var accountDropdown = document.getElementById('account-dropdown');
    var accountChevron  = document.getElementById('account-chevron');

    if (accountToggle && accountDropdown) {
      function closeDropdown() {
        accountDropdown.classList.add('hidden');
        if (accountChevron) accountChevron.classList.remove('rotate-180');
        accountToggle.setAttribute('aria-expanded', 'false');
      }

      accountToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = !accountDropdown.classList.contains('hidden');
        if (isOpen) {
          closeDropdown();
        } else {
          accountDropdown.classList.remove('hidden');
          if (accountChevron) accountChevron.classList.add('rotate-180');
          accountToggle.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        var wrapper = document.getElementById('account-menu-wrapper');
        if (wrapper && !wrapper.contains(e.target)) closeDropdown();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDropdown();
      });
    }

  });
}());
