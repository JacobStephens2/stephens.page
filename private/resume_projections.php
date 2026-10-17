<?php

declare(strict_types=1);

/**
 * Resume projections: the Markdown and the PDF, computed on request from the resume source
 * (resume/index.html and its stylesheet) under a document root, and never committed.
 *
 * The document root is the deployed tree for the endpoints, the working tree for the resume check,
 * and a fixture tree in a test.
 */
final class ResumeProjections
{
    /** Elements whose children the Markdown walk visits as blocks. */
    private const CONTAINERS = ['main', 'header', 'footer', 'section', 'article', 'aside', 'nav', 'div'];

    /** The Markdown projection of the document root's resume page: a walk of its <main>. */
    public static function markdown(string $documentRoot): string
    {
        $page = new DOMDocument();
        // The XML declaration makes libxml read the page as UTF-8 whatever its <meta> says.
        $page->loadHTML(
            '<?xml encoding="UTF-8">' . file_get_contents($documentRoot . '/resume/index.html'),
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        $main = $page->getElementsByTagName('main')->item(0);

        return implode("\n\n", self::blocks($main)) . "\n";
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
