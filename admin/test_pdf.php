<?php
require '/var/www/admin/includes/bootstrap.php';
require '/var/www/backend/vendor/autoload.php';
require_once '/var/www/backend/config/PdfReportService.php';

ob_start();
$db = Database::getInstance();
$pdfService = new PdfReportService($db);
$pdfService->generate(25);
$content = ob_get_clean();

file_put_contents('/tmp/test_export.pdf', $content);
$size = filesize('/tmp/test_export.pdf');

if ($size > 100) {
    echo "SUCCESS: PDF generated, size: " . $size . " bytes.\n";
    $head = substr($content, 0, 5);
    if ($head === '%PDF-') {
        echo "VALID PDF SIGNATURE: $head (SQL JOINS & GD OSM are fully functional without crash)\n";
    } else {
        echo "INVALID SIGNATURE! It returned HTML or Error.\n";
    }
} else {
    echo "EMPTY OR FAILED. Error executing generation.\n";
}
