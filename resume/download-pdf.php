<?php

declare(strict_types=1);

require dirname(__DIR__) . '/private/resume_projections.php';

// The PDF at /resume/Jacob-Stephens-Resume.pdf is rewritten here. Query parameters are ignored.

$root = dirname(__DIR__);

try {
    $pdf = ResumeProjections::pdf($root);
} catch (ResumeSourceMissing $e) {
    error_log('resume pdf source missing: ' . $e->getMessage());
    failResumePdf(500, "Resume source is missing.\n");
} catch (Throwable $e) {
    error_log('resume pdf render failed: ' . $e->getMessage());
    failResumePdf(503, "Resume PDF render failed.\n");
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Jacob-Stephens-Resume.pdf"');
header('Content-Length: ' . (string) strlen($pdf));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdf;

function failResumePdf(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $body;
    exit;
}
