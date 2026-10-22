<?php

declare(strict_types=1);

require dirname(__DIR__) . '/private/resume_projections.php';

// The Markdown at /resume/jacob-stephens-resume.md is rewritten here. Query parameters are ignored.

$root = dirname(__DIR__);

try {
    $markdown = ResumeProjections::markdown($root);
} catch (Throwable $e) {
    error_log('resume markdown failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo "Resume source is missing.\n";
    exit;
}

header('Content-Type: text/markdown; charset=UTF-8');
header('Content-Length: ' . (string) strlen($markdown));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $markdown;
