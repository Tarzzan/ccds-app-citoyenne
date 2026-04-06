<?php
/**
 * Bouton d'export PDF — à inclure dans incident_detail.php
 * Usage : <?php include __DIR__ . '/../includes/pdf_export_button.php'; ?>
 * Requiert $inc['id'] dans le contexte.
 */
?>
<a href="/admin/?page=incident_detail&id=<?= (int)$inc['id'] ?>&export=pdf&t=<?= time() ?>"
   target="_blank"
   class="btn btn-danger btn-sm pdf-export-btn">
    Exporter en PDF
</a>
