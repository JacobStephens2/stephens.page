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

if ($failures > 0) {
    echo "\n{$failures} test(s) failed.\n";
    exit(1);
}
