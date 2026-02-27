/**
 * CCDS Back-Office — JavaScript principal
 */

document.addEventListener('DOMContentLoaded', () => {

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
