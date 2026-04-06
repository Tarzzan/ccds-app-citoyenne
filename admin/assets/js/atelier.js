/**
 * Atelier Design — onglets + dropdowns
 * Chargé globalement via layout.php <head defer>
 */

(function () {
  'use strict';

  /* ── IDs des sections pilotées par les onglets ── */
  var SECTION_IDS = [
    'visual-admin-branding',
    'visual-admin-slots',
    'visual-admin-badges',
    'visual-admin-scenes',
    'visual-admin-nav',
    'visual-admin-inventory'
  ];

  /* ── 1. Basculer un onglet ── */
  function switchTab(tab) {
    if (!tab) return;
    var container = tab.closest('.atelier-tabs');
    if (!container) return;
    var target = tab.getAttribute('data-target');
    if (!target) return;

    // --- mise à jour visuelle des onglets ---
    var allTabs = container.querySelectorAll('.atelier-tab');
    for (var i = 0; i < allTabs.length; i++) {
      var t = allTabs[i];
      if (t === tab) {
        t.classList.add('active');
        t.style.borderColor = 'var(--primary)';
        t.style.background  = 'color-mix(in srgb, var(--primary) 4%, white)';
        t.style.boxShadow   = '0 8px 24px color-mix(in srgb, var(--primary) 12%, transparent)';
      } else {
        t.classList.remove('active');
        t.style.borderColor = 'transparent';
        t.style.background  = 'white';
        t.style.boxShadow   = '0 4px 12px rgba(0,0,0,0.05)';
      }
    }

    // --- afficher/masquer les sections ---
    for (var s = 0; s < SECTION_IDS.length; s++) {
      var id = SECTION_IDS[s];
      var nodes = document.querySelectorAll('#' + id);
      for (var n = 0; n < nodes.length; n++) {
        // On prend le dernier noeud de cet ID (le frais après swap AJAX)
        if (id === target && n === nodes.length - 1) {
          nodes[n].style.display = '';
        } else {
          nodes[n].style.display = 'none';
        }
      }
    }
  }

  /* ── 2. Initialiser les dropdowns visuels ── */
  function initDropdowns() {
    var triggers = document.querySelectorAll('.visual-select-trigger');
    for (var i = 0; i < triggers.length; i++) {
      (function (trigger) {
        trigger.onclick = function (e) {
          e.stopPropagation();
          var select = trigger.closest('.visual-select');
          if (!select) return;
          var wasOpen = select.classList.contains('is-open');
          closeAllDropdowns();
          if (!wasOpen) select.classList.add('is-open');
        };
      })(triggers[i]);
    }

    var options = document.querySelectorAll('.visual-option');
    for (var j = 0; j < options.length; j++) {
      (function (opt) {
        opt.onclick = function (e) {
          e.stopPropagation();
          var select = opt.closest('.visual-select');
          if (!select) return;
          var input = select.querySelector('.visual-select-input');
          if (input) input.value = opt.getAttribute('data-val') || '';
          var img = select.querySelector('.trigger-img');
          if (img) img.src = opt.getAttribute('data-url') || '';
          var label = select.querySelector('.trigger-label');
          if (label) label.textContent = opt.getAttribute('data-label') || '';
          var siblings = select.querySelectorAll('.visual-option');
          for (var k = 0; k < siblings.length; k++) siblings[k].classList.remove('selected');
          opt.classList.add('selected');
          select.classList.remove('is-open');
        };
      })(options[j]);
    }
  }

  function closeAllDropdowns() {
    var all = document.querySelectorAll('.visual-select.is-open');
    for (var i = 0; i < all.length; i++) all[i].classList.remove('is-open');
  }

  /* ── 3. Init complète de l'atelier ── */
  function initAtelier() {
    var containers = document.querySelectorAll('.atelier-tabs');
    if (!containers.length) return;
    var container = containers[containers.length - 1];

    // Choisir l'onglet initial : hash de l'URL ou premier onglet
    var hash = window.location.hash.replace('#', '');
    var initialTab = null;
    if (hash) {
      initialTab = container.querySelector('.atelier-tab[data-target="' + hash + '"]');
    }
    if (!initialTab) {
      initialTab = container.querySelector('.atelier-tab');
    }

    // Activer le premier onglet
    switchTab(initialTab);

    // Initialiser les dropdowns
    initDropdowns();
  }

  /* ── Exposer sur window pour onclick inline ── */
  window.atelierSwitchTab   = switchTab;
  window.atelierInitAtelier = initAtelier;

  /* ── Hover delegué ── */
  document.addEventListener('mouseover', function (e) {
    var tab = e.target.closest ? e.target.closest('.atelier-tab') : null;
    if (tab && !tab.classList.contains('active')) {
      tab.style.transform = 'translateY(-4px)';
      tab.style.boxShadow = '0 12px 28px rgba(0,0,0,0.08)';
    }
  });
  document.addEventListener('mouseout', function (e) {
    var tab = e.target.closest ? e.target.closest('.atelier-tab') : null;
    if (tab && !tab.classList.contains('active')) {
      tab.style.transform = '';
      tab.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
    }
  });

  /* Fermer les dropdowns au clic global */
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.visual-select')) {
      closeAllDropdowns();
    }
  });

  /* Init au DOMContentLoaded (premier chargement) */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAtelier);
  } else {
    // DOM déjà prêt
    initAtelier();
  }
})();
