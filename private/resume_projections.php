<?php

declare(strict_types=1);

/**
 * Resume projections: the Markdown and the PDF, computed on request from the resume source
 * (resume/index.html and its stylesheet) under a document root, and never committed.
 *
 * The document root is the deployed tree for the endpoints, the working tree for the resume check,
 * and a fixture tree in a test. Callers use only markdown() and pdf().
 */
final class ResumeSourceMissing extends RuntimeException
{
}

/** A Chrome render that did not produce a PDF. Carries Chrome's exit code and output. */
final class ResumeRenderFailed extends RuntimeException
{
    public function __construct(private readonly int $exitCode, private readonly string $chromeOutput)
    {
        parent::__construct('chrome exited ' . $exitCode . ': ' . $chromeOutput);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function chromeOutput(): string
    {
        return $this->chromeOutput;
    }
}

final class ResumeProjections
{
    /** Wall-clock limit for one Chrome render. The process group is killed when it elapses. */
    private const RENDER_LIMIT_SECONDS = 20;
    /** Elements whose children the Markdown walk visits as blocks. */
    private const CONTAINERS = ['main', 'header', 'footer', 'section', 'article', 'aside', 'nav', 'div'];

    /** The Markdown projection of the document root's resume page: a walk of its <main>. */
    public static function markdown(string $documentRoot): string
    {
        $main = self::main($documentRoot);

        return implode("\n\n", self::blocks($main)) . "\n";
    }

    /**
     * The PDF projection of the document root's resume page.
     * Refuses a missing page before starting Chrome. Accepts a render only when Chrome exits 0
     * and the output starts with %PDF-.
     */
    public static function pdf(string $documentRoot): string
    {
        $page = self::pagePath($documentRoot);
        if (!is_file($page)) {
            throw new ResumeSourceMissing('resume page not found: ' . $page);
        }

        $profile = sys_get_temp_dir() . '/resume-chrome-' . bin2hex(random_bytes(6));
        $pdfPath = tempnam(sys_get_temp_dir(), 'resume-pdf-');
        $stdoutPath = tempnam(sys_get_temp_dir(), 'resume-chrome-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'resume-chrome-err-');
        if ($pdfPath === false || $stdoutPath === false || $stderrPath === false || !mkdir($profile, 0700)) {
            throw new ResumeRenderFailed(-1, 'could not allocate a temporary Chrome profile');
        }

        try {
            // Print a shadow of the page. Chrome 142 treats the "~NOTFOUND" token as a
            // hostname, and virtual time does not advance while an https subresource is
            // pending, so a remote script runs past the 20s kill. Remote script and
            // stylesheet URLs are pointed at a missing relative file. Relative URLs stay,
            // so a stylesheet that never arrives still blocks until the kill.
            $page = self::shadowPage($documentRoot, $profile . '/doc');
            $result = self::runChrome($page, $profile, $pdfPath, $stdoutPath, $stderrPath);
            $bytes = is_file($pdfPath) ? (string) file_get_contents($pdfPath) : '';
            if ($result['exitCode'] !== 0 || !str_starts_with($bytes, '%PDF-')) {
                throw new ResumeRenderFailed($result['exitCode'], $result['output']);
            }

            return $bytes;
        } finally {
            self::removeTree($profile);
            foreach ([$pdfPath, $stdoutPath, $stderrPath] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private static function pagePath(string $documentRoot): string
    {
        return $documentRoot . '/resume/index.html';
    }

    private static function main(string $documentRoot): DOMElement
    {
        $path = self::pagePath($documentRoot);
        if (!is_file($path)) {
            throw new ResumeSourceMissing('resume page not found: ' . $path);
        }
        $html = file_get_contents($path);
        if ($html === false) {
            throw new ResumeSourceMissing('resume page not readable: ' . $path);
        }

        $page = new DOMDocument();
        // The XML declaration makes libxml read the page as UTF-8 whatever its <meta> says.
        $page->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $main = $page->getElementsByTagName('main')->item(0);
        if (!$main instanceof DOMElement) {
            throw new ResumeSourceMissing('resume page has no <main>: ' . $path);
        }

        return $main;
    }

    /** @return array{exitCode: int, output: string} */
    private static function runChrome(string $pagePath, string $profile, string $pdfPath, string $stdoutPath, string $stderrPath): array
    {
        $command = [
            'google-chrome',
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--no-pdf-header-footer',
            '--virtual-time-budget=5000',
            '--run-all-compositor-stages-before-draw',
            '--host-resolver-rules=MAP * ~NOTFOUND',
            '--user-data-dir=' . $profile,
            '--print-to-pdf=' . $pdfPath,
            self::fileUrl($pagePath),
        ];
        $proc = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            return ['exitCode' => -1, 'output' => 'could not start chrome'];
        }

        $started = hrtime(true);
        $killed = false;
        $exit = -1;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['signaled'] ? 128 + (int) $status['termsig'] : (int) $status['exitcode'];
                break;
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            if (!$killed && $elapsed >= self::RENDER_LIMIT_SECONDS) {
                $pid = (int) $status['pid'];
                @posix_kill(-$pid, SIGKILL);
                @posix_kill($pid, SIGKILL);
                exec('pkill -KILL -P ' . $pid . ' >/dev/null 2>&1');
                $killed = true;
            }
            if ($killed && $elapsed >= self::RENDER_LIMIT_SECONDS + 5) {
                break;
            }
            usleep(50000);
        }
        proc_close($proc);

        $output = substr((string) file_get_contents($stdoutPath) . (string) file_get_contents($stderrPath), 0, 4000);

        return ['exitCode' => $exit, 'output' => $output];
    }

    /**
     * A shadow of the document root whose resume page is the one Chrome prints.
     * Every other entry is a symlink, so relative stylesheets, images, and a
     * blocking fixture resolve to the original files.
     */
    private static function shadowPage(string $documentRoot, string $dest): string
    {
        if (!mkdir($dest, 0700, true) && !is_dir($dest)) {
            throw new ResumeRenderFailed(-1, 'could not allocate a render tree');
        }
        self::symlinkChildren($documentRoot, $dest, ['resume']);
        $resumeDest = $dest . '/resume';
        if (!mkdir($resumeDest, 0700) && !is_dir($resumeDest)) {
            throw new ResumeRenderFailed(-1, 'could not allocate a render tree');
        }
        self::symlinkChildren($documentRoot . '/resume', $resumeDest, ['index.html']);
        $html = file_get_contents($documentRoot . '/resume/index.html');
        if ($html === false) {
            throw new ResumeSourceMissing('resume page not readable: ' . $documentRoot . '/resume/index.html');
        }
        $written = file_put_contents($resumeDest . '/index.html', self::neutralizeNetworkSubresources($html));
        if ($written === false) {
            throw new ResumeRenderFailed(-1, 'could not write the render page');
        }

        return $resumeDest . '/index.html';
    }

    /** @param list<string> $skip */
    private static function symlinkChildren(string $source, string $dest, array $skip): void
    {
        $items = scandir($source);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $skip, true)) {
                continue;
            }
            @symlink($source . '/' . $item, $dest . '/' . $item);
        }
    }

    /** Point remote script and fetched-link URLs at a missing relative file so the load fails on disk. */
    private static function neutralizeNetworkSubresources(string $html): string
    {
        $rewritten = preg_replace_callback('/<(script|link)\b[^>]*>/i', static function (array $match): string {
            $tag = $match[0];
            $isLink = strtolower($match[1]) === 'link';
            if ($isLink && !self::linkFetches($tag)) {
                return $tag;
            }
            $attr = $isLink ? 'href' : 'src';
            $fallback = $isLink ? './__resume_blocked__.css' : './__resume_blocked__.js';
            $updated = preg_replace_callback(
                '/\s' . $attr . '\s*=\s*(["\'])([^"\']*)\1/i',
                static function (array $attribute) use ($attr, $fallback): string {
                    $url = html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5);
                    if (!self::isNetworkUrl($url)) {
                        return $attribute[0];
                    }

                    return ' ' . $attr . '="' . $fallback . '"';
                },
                $tag,
            );

            return $updated ?? $tag;
        }, $html);

        return $rewritten ?? $html;
    }

    private static function linkFetches(string $tag): bool
    {
        if (!preg_match('/\brel\s*=\s*(["\']?)([^"\'>\s]+)\1/i', $tag, $rel)) {
            return false;
        }
        $value = strtolower($rel[2]);

        return str_contains($value, 'stylesheet')
            || str_contains($value, 'icon')
            || str_contains($value, 'preload')
            || str_contains($value, 'manifest');
    }

    private static function isNetworkUrl(string $url): bool
    {
        return str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
    }

    private static function fileUrl(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /** @return list<string> the Markdown blocks of a container's children, in order */
    private static function blocks(DOMNode $container): array
    {
        $blocks = [];
        // Inline content that sits in the container outside any block of its own.
        $loose = '';
        foreach ($container->childNodes as $node) {
            $own = $node instanceof DOMElement && !self::isSkipped($node) ? self::ownBlocks($node) : null;
            if ($own === null) {
                $loose .= self::inline($node);
                continue;
            }
            $blocks[] = self::lines($loose);
            $loose = '';
            array_push($blocks, ...$own);
        }
        $blocks[] = self::lines($loose);

        return array_values(array_filter($blocks, fn (string $block): bool => $block !== ''));
    }

    /** @return list<string>|null the blocks an element makes, or null when its text stays in the block around it */
    private static function ownBlocks(DOMElement $element): ?array
    {
        $name = $element->nodeName;

        return match (true) {
            in_array($name, self::CONTAINERS, true) => self::blocks($element),
            in_array($name, ['h1', 'h2', 'h3', 'h4'], true) => [self::heading((int) $name[1], $element)],
            $name === 'p' => [self::lines(self::inlineChildren($element))],
            $name === 'ul', $name === 'ol' => [self::listItems($element)],
            default => null,
        };
    }

    private static function heading(int $level, DOMElement $heading): string
    {
        $text = str_replace("  \n", ' ', self::lines(self::inlineChildren($heading)));

        return $text === '' ? '' : str_repeat('#', $level) . ' ' . $text;
    }

    private static function listItems(DOMElement $list): string
    {
        $items = [];
        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || self::isSkipped($item)) {
                continue;
            }
            $text = self::lines(self::inlineChildren($item));
            if ($text === '') {
                continue;
            }
            $marker = $list->nodeName === 'ol' ? (count($items) + 1) . '. ' : '- ';
            $items[] = $marker . str_replace("\n", "\n" . str_repeat(' ', strlen($marker)), $text);
        }

        return implode("\n", $items);
    }

    private static function inlineChildren(DOMNode $parent): string
    {
        $text = '';
        foreach ($parent->childNodes as $child) {
            $text .= self::inline($child);
        }

        return $text;
    }

    /** Inline Markdown for one node; a "\n" stands for a <br> until lines() turns it into a hard line break. */
    private static function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return preg_replace('/[ \t\n\f\r]+/', ' ', $node->data);
        }
        if (!$node instanceof DOMElement || self::isSkipped($node)) {
            return '';
        }
        $inner = self::inlineChildren($node);

        return match ($node->nodeName) {
            'br' => "\n",
            'a' => $node->hasAttribute('href') ? self::wrap($inner, '[', '](' . $node->getAttribute('href') . ')') : $inner,
            'strong' => self::wrap($inner, '**', '**'),
            'em' => self::wrap($inner, '*', '*'),
            'code' => self::wrap($inner, '`', '`'),
            default => $inner,
        };
    }

    /** Wraps inline text in Markdown delimiters, keeping its edge whitespace outside them. */
    private static function wrap(string $inner, string $open, string $close): string
    {
        $text = trim($inner, " \n");
        if ($text === '') {
            return $inner;
        }
        $lead = substr($inner, 0, strlen($inner) - strlen(ltrim($inner, " \n")));
        $trail = substr($inner, strlen(rtrim($inner, " \n")));

        return $lead . $open . $text . $close . $trail;
    }

    /** Collapses a block's inline text and writes each <br> as a Markdown hard line break. */
    private static function lines(string $inline): string
    {
        $text = preg_replace('/ *\n */', "\n", preg_replace('/ {2,}/', ' ', $inline));

        return str_replace("\n", "  \n", trim($text, " \n"));
    }

    /** Controls and decoration that the Markdown leaves out. */
    private static function isSkipped(DOMElement $element): bool
    {
        return $element->nodeName === 'button'
            || $element->nodeName === 'svg'
            || ($element->nodeName === 'a' && $element->hasAttribute('download'))
            || strtolower($element->getAttribute('aria-hidden')) === 'true';
    }
}
