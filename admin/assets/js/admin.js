/**
 * Ma Commune Back-Office — JavaScript principal
 */

document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  const closeSidebar = () => {
    body.classList.remove('nav-open');
    if (sidebarToggle) {
      sidebarToggle.setAttribute('aria-expanded', 'false');
    }
  };

  const openSidebar = () => {
    body.classList.add('nav-open');
    if (sidebarToggle) {
      sidebarToggle.setAttribute('aria-expanded', 'true');
    }
  };

  if (sidebarToggle && sidebarOverlay) {
    sidebarToggle.addEventListener('click', () => {
      if (body.classList.contains('nav-open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    sidebarOverlay.addEventListener('click', closeSidebar);

    document.querySelectorAll('.nav-item').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
          closeSidebar();
        }
      });
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 1024) {
        closeSidebar();
      }
    });
  }

  // --- Auto-dismiss des alertes après 4 secondes ---
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .4s';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 400);
    }, 4000);
  });

  // --- Confirmation avant suppression ---
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      const msg = el.dataset.confirm || 'Êtes-vous sûr de vouloir effectuer cette action ?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // --- Marquer le lien actif dans la sidebar ---
  const currentPath = window.location.search;
  document.querySelectorAll('.nav-item').forEach(link => {
    if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href').split('?')[1])) {
      link.classList.add('active');
    }
  });

  // --- Prévisualisation d'image dans les formulaires ---
  document.querySelectorAll('input[type="file"][data-preview]').forEach(input => {
    input.addEventListener('change', () => {
      const previewId = input.dataset.preview;
      const preview = document.getElementById(previewId);
      if (preview && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
      }
    });
  });

  // --- Sélectionner/désélectionner tout dans les tableaux ---
  const selectAll = document.getElementById('select-all');
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = selectAll.checked;
      });
    });
  }

  // --- Fermeture des alertes manuellement ---
  document.querySelectorAll('.alert-close').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.closest('.alert').remove();
    });
  });

});
