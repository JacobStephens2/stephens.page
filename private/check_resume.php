#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resume check. Projects a document root through the resume projections module and prints one line
 * per invariant. Exits non-zero if any fails.
 *
 * Usage: php private/check_resume.php [DOCUMENT_ROOT]
 * The default document root is the repository this file lives in. Run it on the droplet: another
 * host's fonts and Chrome can paginate differently, so only the droplet's page count is authoritative.
 */

require __DIR__ . '/resume_projections.php';

$root = $argv[1] ?? dirname(__DIR__);
$failures = 0;

function report(bool $ok, string $line): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo str_replace("\n", ' ', $line) . "\n";
}

/**
 * Whitespace-separated words. Wrapping punctuation is not part of the word
 * (`(source` and `evaluation):` are `source` and `evaluation`); internal marks
 * such as `@`, `.`, and `-` stay. A separator made only of punctuation is not a word.
 */
function wordTokens(string $text): array
{
    $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) {
        return [];
    }
    $words = [];
    foreach ($tokens as $token) {
        $word = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token);
        if (is_string($word) && $word !== '' && preg_match('/\p{L}|\p{N}/u', $word)) {
            $words[] = $word;
        }
    }

    return $words;
}

function foldWords(array $words): array
{
    return array_map(static fn (string $word): string => mb_strtolower($word, 'UTF-8'), $words);
}

/** The first needle that does not occur, in order, in the haystack. Null when every needle occurs. */
function firstMissingWord(array $haystack, array $needles): ?string
{
    $at = 0;
    $count = count($needles);
    foreach ($haystack as $word) {
        if ($at < $count && $word === $needles[$at]) {
            $at++;
        }
    }

    return $at === $count ? null : $needles[$at];
}

/** The projection's skips: buttons, download links, aria-hidden elements, and inline SVG. */
function projectionSkips(DOMElement $element): bool
{
    return $element->nodeName === 'button'
        || $element->nodeName === 'svg'
        || ($element->nodeName === 'a' && $element->hasAttribute('download'))
        || strtolower($element->getAttribute('aria-hidden')) === 'true';
}

/** Printable words of a node. Each element boundary is a word boundary, so text is not glued across elements. */
function pageWords(DOMNode $node): array
{
    if ($node instanceof DOMText) {
        return wordTokens($node->data);
    }
    if (!$node instanceof DOMElement || projectionSkips($node)) {
        return [];
    }
    $words = [];
    foreach ($node->childNodes as $child) {
        array_push($words, ...pageWords($child));
    }

    return $words;
}

/** Readable words of a Markdown projection: link text kept, destinations and markers dropped. */
function markdownWords(string $markdown): array
{
    $text = $markdown;
    $previous = null;
    while ($previous !== $text) {
        $previous = $text;
        $replaced = preg_replace('/\[([^\[\]]+)\]\([^()]*\)/', '$1', $text);
        $text = $replaced === null ? $text : $replaced;
    }
    $text = preg_replace('/^#{1,6}[ \t]*/m', '', $text) ?? $text;
    $text = str_replace(['**', '*', '`'], '', $text);

    return wordTokens($text);
}

function loadPage(string $root): ?DOMDocument
{
    $path = $root . '/resume/index.html';
    if (!is_file($path)) {
        return null;
    }
    $html = file_get_contents($path);
    if ($html === false) {
        return null;
    }
    $page = new DOMDocument();
    $page->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return $page;
}

function isRelativeUrl(string $url): bool
{
    if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '?')) {
        return true;
    }
    if (str_starts_with($url, '/') || str_starts_with($url, '\\') || str_starts_with($url, '//')) {
        return false;
    }

    return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) !== 1;
}

function resolveAgainstResume(string $root, string $url): string
{
    $url = preg_replace('/[?#].*$/', '', $url) ?? $url;
    $parts = [];
    foreach (explode('/', $root . '/resume/' . $url) as $part) {
        if ($part === '' || $part === '.') {
            if ($parts === []) {
                $parts[] = '';
            }
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

// 1. The Markdown carries every word of the page's printable <main> text, in order.
$page = loadPage($root);
$markdown = null;
$markdownError = null;
try {
    $markdown = ResumeProjections::markdown($root);
} catch (Throwable $e) {
    $markdownError = $e->getMessage();
}

if ($markdown === null || $page === null) {
    report(false, 'markdown words: fail (' . ($markdownError ?? 'resume page not found') . ')');
} else {
    $main = $page->getElementsByTagName('main')->item(0);
    if (!$main instanceof DOMElement) {
        report(false, 'markdown words: fail (resume page has no <main>)');
    } else {
        $missing = firstMissingWord(markdownWords($markdown), pageWords($main));
        report($missing === null, $missing === null
            ? 'markdown words: pass'
            : 'markdown words: fail (missing "' . $missing . '")');
    }
}

// 2. The PDF renders and fits the page budget of two. 3. Its text carries every Markdown word.
$pdfWords = null;
try {
    $pdf = ResumeProjections::pdf($root);
    $tmp = tempnam(sys_get_temp_dir(), 'resume-check-');
    if ($tmp === false) {
        throw new RuntimeException('could not allocate a temporary pdf');
    }
    file_put_contents($tmp, $pdf);
    $info = shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>&1');
    $pages = null;
    if (is_string($info) && preg_match('/^Pages:\s+(\d+)/m', $info, $match)) {
        $pages = (int) $match[1];
    }
    $text = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    unlink($tmp);
    if (is_string($text)) {
        // pdftotext -layout breaks a hyphenated word at the line end ("on-" / "call").
        // That is the line break, not content the print CSS hid.
        $text = preg_replace("/-\n\s*/", '-', $text) ?? $text;
    }
    if ($pages === null) {
        report(false, 'pdf pages: fail (pdfinfo did not report a page count)');
    } else {
        report($pages <= 2, 'pdf pages: ' . $pages . ($pages <= 2 ? '' : ' fail'));
    }
    $pdfWords = is_string($text) ? foldWords(wordTokens($text)) : null;
} catch (ResumeSourceMissing $e) {
    report(false, 'pdf pages: fail (' . $e->getMessage() . ')');
} catch (Throwable $e) {
    report(false, 'pdf pages: fail (' . $e->getMessage() . ')');
}

if ($pdfWords === null || $markdown === null) {
    report(false, 'pdf text: fail (no pdf)');
} else {
    $missing = firstMissingWord($pdfWords, foldWords(markdownWords($markdown)));
    report($missing === null, $missing === null
        ? 'pdf text: pass'
        : 'pdf text: fail (missing "' . $missing . '")');
}

// 4. The page's download links point only at the two projection URLs, and both URLs are linked.
$projectionUrls = ['jacob-stephens-resume.md', 'Jacob-Stephens-Resume.pdf'];
if ($page === null) {
    report(false, 'download links: fail (resume page not found)');
} else {
    $hrefs = [];
    foreach ($page->getElementsByTagName('a') as $anchor) {
        if ($anchor instanceof DOMElement && $anchor->hasAttribute('download')) {
            $hrefs[] = $anchor->getAttribute('href');
        }
    }
    $only = $hrefs !== [] && array_diff($hrefs, $projectionUrls) === [];
    $both = array_diff($projectionUrls, $hrefs) === [];
    if ($only && $both) {
        report(true, 'download links: pass');
    } else {
        report(false, 'download links: fail (found ' . implode(', ', $hrefs) . ')');
    }
}

// 5. Every stylesheet and image the page loads, and every url() in those stylesheets, is relative.
if ($page === null) {
    report(false, 'relative inputs: fail (resume page not found)');
} else {
    $offenders = [];
    $stylesheets = [];
    foreach ($page->getElementsByTagName('link') as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }
        $rel = strtolower($link->getAttribute('rel'));
        $href = $link->getAttribute('href');
        $isStylesheet = str_contains($rel, 'stylesheet');
        $isIcon = str_contains($rel, 'icon');
        if (!$isStylesheet && !$isIcon) {
            continue;
        }
        if (!isRelativeUrl($href)) {
            $offenders[] = $href;
        } elseif ($isStylesheet) {
            $stylesheets[] = $href;
        }
    }
    foreach ($page->getElementsByTagName('img') as $img) {
        if ($img instanceof DOMElement && !isRelativeUrl($img->getAttribute('src'))) {
            $offenders[] = $img->getAttribute('src');
        }
    }
    foreach ($stylesheets as $href) {
        $path = resolveAgainstResume($root, $href);
        $css = is_file($path) ? file_get_contents($path) : false;
        if ($css === false) {
            $offenders[] = $href . ' (unreadable)';
            continue;
        }
        if (preg_match_all('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $matches)) {
            foreach ($matches[2] as $url) {
                $url = trim($url);
                if (!isRelativeUrl($url)) {
                    $offenders[] = $url;
                }
            }
        }
    }
    report($offenders === [], $offenders === []
        ? 'relative inputs: pass'
        : 'relative inputs: fail (' . implode(', ', $offenders) . ')');
}

exit($failures > 0 ? 1 : 0);
