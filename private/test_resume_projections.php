<?php

// Tests for the resume projections module and the resume check, run against fixture document roots.
// Run on the droplet with: php private/test_resume_projections.php
// Chrome is never mocked: the PDF tests render for real, and one waits out the 20 s render limit.

declare(strict_types=1);

require __DIR__ . '/resume_projections.php';

$failures = 0;

function test(string $name, callable $body): void
{
    global $failures;
    try {
        $body();
        echo "✓ {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "✗ {$name}\n    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
    }
}

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . var_export($expected, true) . "\ngot " . var_export($actual, true));
    }
}

/** A fresh document root holding the given files (path => contents). */
function fixtureRoot(array $files = []): string
{
    $root = sys_get_temp_dir() . '/resume-fixture-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    foreach ($files as $path => $contents) {
        if (!is_dir(dirname("{$root}/{$path}"))) {
            mkdir(dirname("{$root}/{$path}"), 0700, true);
        }
        file_put_contents("{$root}/{$path}", $contents);
    }
    return $root;
}

test('Markdown projection maps the page\'s <main> and skips its controls and decoration', function () {
    $root = fixtureRoot(['resume/index.html' => <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Fixture</title></head>
        <body>
          <p>Outside main.</p>
          <main>
            <header>
              <h1>Ada Lovelace</h1>
              <div class="actions">
                <button>Download PDF</button>
                <a href="cv.pdf" download="cv.pdf">Download PDF</a>
              </div>
            </header>
            <p class="contact">
              <a href="mailto:ada@example.com"><span aria-hidden="true">✉</span>ada@example.com</a> |
              <a href="https://example.com/">
                <svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>example.com
              </a>
              <br>
              London
            </p>
            <section>
              <h2>Work</h2>
              <h3>Analyst</h3>
              <h4>Analytical Engine</h4>
              <p>Wrote <strong>the first</strong> program in <em>Note G</em>, computing <code>B7</code>.</p>
              <ul>
                <li>Translated Menabrea</li>
                <li>Added <a href="https://example.com/notes">notes</a> three times as long</li>
              </ul>
              <ol>
                <li>Notes A to F</li>
                <li>Note G</li>
              </ol>
              <div>
                <table><tr><td>Unknown elements keep their text</td></tr></table>
              </div>
            </section>
          </main>
          <p>Also outside main.</p>
        </body>
        </html>
        HTML]);

    // Lines of the expected Markdown; the two trailing spaces after the contact links are its hard line break.
    expectSame(implode("\n", [
        '# Ada Lovelace',
        '',
        '[ada@example.com](mailto:ada@example.com) | [example.com](https://example.com/)  ',
        'London',
        '',
        '## Work',
        '',
        '### Analyst',
        '',
        '#### Analytical Engine',
        '',
        'Wrote **the first** program in *Note G*, computing `B7`.',
        '',
        '- Translated Menabrea',
        '- Added [notes](https://example.com/notes) three times as long',
        '',
        '1. Notes A to F',
        '2. Note G',
        '',
        'Unknown elements keep their text',
        '',
    ]), ResumeProjections::markdown($root));
});

test('Markdown projection refuses a root with no resume page', function () {
    $root = fixtureRoot();
    try {
        ResumeProjections::markdown($root);
    } catch (ResumeSourceMissing $e) {
        expectSame(true, str_contains($e->getMessage(), 'resume page not found'));
        return;
    }
    throw new RuntimeException('expected ResumeSourceMissing');
});

test('PDF projection refuses a root with no resume page before starting Chrome', function () {
    $root = fixtureRoot();
    $started = hrtime(true);
    try {
        ResumeProjections::pdf($root);
    } catch (ResumeSourceMissing $e) {
        $elapsed = (hrtime(true) - $started) / 1e9;
        if ($elapsed >= 1) {
            throw new RuntimeException("refused too slowly ({$elapsed}s); Chrome may have started");
        }
        expectSame(true, str_contains($e->getMessage(), 'resume page not found'));
        return;
    }
    throw new RuntimeException('expected ResumeSourceMissing');
});

test('PDF projection renders a resume page to a PDF', function () {
    $root = resumeFixture('<h1>Ada Lovelace</h1><p>Note G.</p>');
    $pdf = ResumeProjections::pdf($root);
    expectSame(true, str_starts_with($pdf, '%PDF-'));
    expectSame(1, pdfPageCount($pdf));
});

test('PDF projection renders a resume page that references a remote script', function () {
    $root = resumeFixture('<h1>Ada Lovelace</h1><p>Note G.</p>');
    $path = $root . '/resume/index.html';
    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException('fixture page missing');
    }
    file_put_contents($path, str_replace(
        '</body>',
        '<script defer src="https://analytics.example/script.js"></script></body>',
        $html,
    ));
    $started = hrtime(true);
    $pdf = ResumeProjections::pdf($root);
    $elapsed = (hrtime(true) - $started) / 1e9;
    if ($elapsed >= 15) {
        throw new RuntimeException('remote script stalled the render for ' . round($elapsed, 2) . 's');
    }
    expectSame(true, str_starts_with($pdf, '%PDF-'));
    expectSame(1, pdfPageCount($pdf));
});

test('resume check passes a one-page resume whose links and assets are relative', function () {
    $root = resumeFixture('<h1>Ada Lovelace</h1><p>Wrote the first program.</p>');
    $result = runCheck($root);
    expectSame(0, $result['code']);
    expectSame(true, in_array('markdown words: pass', $result['lines'], true));
    expectSame(true, in_array('pdf pages: 1', $result['lines'], true));
    expectSame(true, in_array('pdf text: pass', $result['lines'], true));
    expectSame(true, in_array('download links: pass', $result['lines'], true));
    expectSame(true, in_array('relative inputs: pass', $result['lines'], true));
});

test('resume check fails when a print rule hides a section', function () {
    $root = resumeFixture(
        '<h1>Ada Lovelace</h1><section class="projects"><h2>Selected Projects</h2><p>Hidden project alpha</p></section>',
        '<style>@media print { section.projects { display: none !important; } }</style>',
    );
    $result = runCheck($root);
    expectSame(1, $result['code']);
    $pdfText = lineStarting($result['lines'], 'pdf text:');
    if (!str_starts_with($pdfText, 'pdf text: fail') || !str_contains($pdfText, 'selected')) {
        throw new RuntimeException('expected the hidden section to fail pdf text, got ' . $pdfText);
    }
});

test('resume check fails a resume padded past the two-page budget', function () {
    $paragraphs = '';
    for ($i = 0; $i < 90; $i++) {
        $paragraphs .= '<p>Padding sentence ' . $i . ' pushes the resume projection past the page budget of two pages.</p>';
    }
    $root = resumeFixture(
        '<h1>Ada Lovelace</h1>' . $paragraphs,
        '<style>@page { size: letter; margin: 0.5in; } p { font-size: 14pt; line-height: 1.4; margin: 0 0 10pt; }</style>',
    );
    $result = runCheck($root);
    expectSame(1, $result['code']);
    $pages = lineStarting($result['lines'], 'pdf pages:');
    if (!preg_match('/pdf pages: (\d+) fail/', $pages, $match) || (int) $match[1] < 3) {
        throw new RuntimeException('expected a page count past the budget, got ' . $pages);
    }
});

test('resume check fails a download link that is not a projection URL', function () {
    $root = resumeFixture('<h1>Ada Lovelace</h1><p>Note G.</p>', '', 'https://example.com/other.pdf');
    $result = runCheck($root);
    expectSame(1, $result['code']);
    expectSame(true, str_starts_with(lineStarting($result['lines'], 'download links:'), 'download links: fail'));
});

test('resume check fails a stylesheet that is not relative', function () {
    $root = resumeFixture('<h1>Ada Lovelace</h1><p>Note G.</p>', '', null, 'https://example.com/resume.css');
    $result = runCheck($root);
    expectSame(1, $result['code']);
    expectSame(true, str_starts_with(lineStarting($result['lines'], 'relative inputs:'), 'relative inputs: fail'));
});

test('resume check fails a remote url() in an inline style', function () {
    $root = resumeFixture(
        '<h1>Ada Lovelace</h1><p>Note G.</p>',
        '<style>@media print { body { background: url(https://example.com/a.png); } }</style>',
    );
    $result = runCheck($root);
    expectSame(1, $result['code']);
    expectSame(true, str_contains(lineStarting($result['lines'], 'relative inputs:'), 'https://example.com/a.png'));
});

test('resume check fails an image srcset that is not relative', function () {
    $root = resumeFixture(
        '<h1>Ada Lovelace</h1><p>Note G.</p><img alt="" src="pic.png" srcset="https://example.com/a.png 2x">',
    );
    $result = runCheck($root);
    expectSame(1, $result['code']);
    expectSame(true, str_contains(lineStarting($result['lines'], 'relative inputs:'), 'https://example.com/a.png'));
});

test('PDF projection kills Chrome after 20 seconds and reports its exit code', function () {
    $root = fixtureRoot([
        'resume/index.html' => '<!DOCTYPE html><html><head><link rel="stylesheet" href="../block.css"></head><body><main><h1>Hang</h1></main></body></html>',
    ]);
    if (!posix_mkfifo($root . '/block.css', 0600)) {
        throw new RuntimeException('could not create a blocking stylesheet');
    }
    $started = hrtime(true);
    try {
        ResumeProjections::pdf($root);
    } catch (ResumeRenderFailed $e) {
        $elapsed = (hrtime(true) - $started) / 1e9;
        if ($elapsed < 19 || $elapsed > 30) {
            throw new RuntimeException("killed after {$elapsed}s, exit {$e->exitCode()}: {$e->chromeOutput()}");
        }
        if ($e->exitCode() === 0) {
            throw new RuntimeException('expected a non-zero Chrome exit code');
        }
        expectSame(true, str_contains($e->getMessage(), 'chrome exited ' . $e->exitCode()));
        return;
    }
    throw new RuntimeException('expected ResumeRenderFailed');
});

/** A fixture resume page with both projection links and a relative stylesheet. */
function resumeFixture(string $main, string $head = '', ?string $pdfHref = null, ?string $stylesheetHref = null): string
{
    $pdfHref ??= 'Jacob-Stephens-Resume.pdf';
    $stylesheetHref ??= './files/styles.css';
    return fixtureRoot([
        'resume/files/styles.css' => '',
        'resume/index.html' => <<<HTML
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="utf-8"><title>Fixture</title>
            <link rel="stylesheet" href="{$stylesheetHref}">
            {$head}
            </head><body>
            <a href="{$pdfHref}" download="Jacob-Stephens-Resume.pdf">Download PDF</a>
            <a href="jacob-stephens-resume.md" download="jacob-stephens-resume.md">Download Markdown</a>
            <main>{$main}</main>
            </body></html>
            HTML,
    ]);
}

function pdfPageCount(string $pdf): int
{
    $tmp = tempnam(sys_get_temp_dir(), 'resume-test-');
    if ($tmp === false) {
        throw new RuntimeException('could not allocate a temporary pdf');
    }
    file_put_contents($tmp, $pdf);
    $info = shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    if (!is_string($info) || !preg_match('/^Pages:\s+(\d+)/m', $info, $match)) {
        throw new RuntimeException('pdfinfo did not report a page count: ' . $info);
    }

    return (int) $match[1];
}

/** @return array{code: int, lines: list<string>} */
function runCheck(string $root): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/check_resume.php') . ' ' . escapeshellarg($root);
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);

    return ['code' => $code, 'lines' => $lines];
}

function lineStarting(array $lines, string $prefix): string
{
    foreach ($lines as $line) {
        if (str_starts_with($line, $prefix)) {
            return $line;
        }
    }
    throw new RuntimeException('no line starting ' . $prefix . ' in ' . implode(' | ', $lines));
}

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}
