<?php
require __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/config/PdfReportService.php';

// Redirige la sortie Output('I', ...) vers Output('F', test.pdf) ou capture stdout
ob_start();
$pdfService = new PdfReportService($db);
$pdfService->generate(25);
$content = ob_get_clean();

file_put_contents('/tmp/test_export.pdf', $content);
if (filesize('/tmp/test_export.pdf') > 100) {
    echo "SUCCESS: PDF generated, size: " . filesize('/tmp/test_export.pdf') . " bytes.\n";
    // Check if it's a valid PDF 
    $head = substr($content, 0, 5);
    if ($head === '%PDF-') {
        echo "VALID PDF SIGNATURE: $head\n";
    } else {
        echo "INVALID SIGNATURE! It returned HTML or Error.\n";
    }
} else {
    echo "EMPTY OR FAILED.\n";
}
