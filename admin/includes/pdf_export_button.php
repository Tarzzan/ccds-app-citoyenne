<?php
/**
 * Bouton d'export PDF — à inclure dans incident_detail.php
 * Usage : <?php include __DIR__ . '/../includes/pdf_export_button.php'; ?>
 * Requiert $inc['id'] dans le contexte.
 */
?>
<a href="/api/incidents/<?= (int)$inc['id'] ?>/report"
   target="_blank"
   style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#ef4444;color:#fff;border-radius:8px;text-decoration:none;font-size:.875rem;font-weight:500;">
    📄 Exporter en PDF
</a>
