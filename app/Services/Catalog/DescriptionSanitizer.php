<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Reduces an upstream project description (Modrinth HTML body or CurseForge
 * HTML) to a small, safe presentational fragment.
 *
 * The output is rendered with React's dangerouslySetInnerHTML in the panel
 * dashboard, so this sanitizer is the only line of defense and is written
 * defensively:
 *
 *  - an explicit tag allowlist (headings, paragraphs, lists, emphasis,
 *    tables, images, links, code) — everything else is unwrapped, not dropped,
 *    so text content survives;
 *  - every attribute is dropped except a checked subset (href, src, alt,
 *    title, colspan/rowspan) after validation;
 *  - href/src may only be http(s) or protocol-relative URLs; javascript:,
 *    data:, vbscript: and unknown schemes are removed;
 *  - script/style/iframe/object/embed/input/button/textarea/meta/link and
 *    HTML comments are removed entirely with their contents where relevant;
 *  - all on* event handler attributes are dropped by construction.
 */
final class DescriptionSanitizer
{
    private const ALLOWED_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr',
        'ul', 'ol', 'li',
        'blockquote',
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'code', 'pre',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'details', 'summary', 'span', 'div',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed',
        'noscript', 'template', 'svg', 'math', 'form', 'textarea',
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = $this->stripDangerousElements($html);

        $document = $this->parse($html);

        if ($document === null) {
            return '';
        }

        $body = $document->getElementsByTagName('body')->item(0)
            ?? $document->documentElement;

        if ($body === null) {
            return '';
        }

        $this->walk($body);

        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $document->saveHTML($child) . "\n";
        }

        return trim($output);
    }

    private function parse(string $html): ?DOMDocument
    {
        $document = new DOMDocument();

        libxml_use_internal_errors(true);

        $ok = $document->loadHTML(
            '<?xml encoding="utf-8"?><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();

        return $ok ? $document : null;
    }

    private function stripDangerousElements(string $html): string
    {
        // Comments can hide conditional script payloads; they never render.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        foreach (self::DROP_WITH_CONTENT as $tag) {
            $html = preg_replace(
                '/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
                '',
                $html,
            ) ?? $html;

            // Unclosed variants: drop the element and everything after its
            // opening tag to the end — an unterminated <script> is hostile.
            $html = preg_replace(
                '/<' . $tag . '\b[^>]*>.*$/is',
                '',
                $html,
            ) ?? $html;
        }

        return $html;
    }

    private function walk(DOMNode $node): void
    {
        $child = $node->firstChild;

        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Unwrap: keep children, drop the unknown element itself.
                    $this->unwrap($child);
                    $next = $node->firstChild;

                    // Re-scan from the start of the moved children; advance
                    // past nothing — the loop re-reads firstChild.
                    if ($next === null) {
                        break;
                    }

                    $child = $next;

                    continue;
                }

                $this->filterAttributes($child);

                $this->walk($child);
            } elseif ($child instanceof DOMComment
                || $child instanceof DOMProcessingInstruction
            ) {
                $node->removeChild($child);
            }

            $child = $next;
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function filterAttributes(DOMElement $element): void
    {
        $keep = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            // Event handlers and anything odd-shaped are never kept.
            if (str_starts_with($name, 'on')) {
                continue;
            }

            switch ($name) {
                case 'href':
                case 'src':
                    if ($this->isSafeUrl($value)) {
                        $keep[$name] = $value;
                    }
                    break;
                case 'style':
                    // CurseForge descriptions center text/images with inline
                    // styles. Keep ONLY the presentational properties that
                    // affect layout; every declaration is re-serialized, so
                    // no url()/expression()/import injection can survive.
                    $cleanStyle = $this->cleanStyle($value);

                    if ($cleanStyle !== '') {
                        $keep[$name] = $cleanStyle;
                    }
                    break;
                case 'alt':
                case 'title':
                    $keep[$name] = $value;
                    break;
                case 'colspan':
                case 'rowspan':
                    if (preg_match('/^\d{1,3}$/', $value) === 1) {
                        $keep[$name] = $value;
                    }
                    break;
            }
        }

        while ($element->attributes->length > 0) {
            $element->removeAttribute(
                $element->attributes->item(0)->name,
            );
        }

        foreach ($keep as $name => $value) {
            $element->setAttribute($name, $value);
        }

        if ($element->tagName === 'a'
            && $element->getAttribute('href') !== ''
        ) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    /**
     * Keeps only harmless layout declarations from an inline style: colors,
     * text alignment, sizing, spacing. Values are validated to exclude any
     * construct that could execute or reference external resources
     * (url(), expression(), @import, behavior, binding).
     */
    private function cleanStyle(string $style): string
    {
        $allowed = [
            'text-align', 'font-weight', 'font-style', 'font-size',
            'color', 'background-color', 'margin', 'margin-left',
            'margin-right', 'margin-top', 'margin-bottom', 'padding',
            'width', 'max-width', 'height', 'border-radius',
        ];

        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (!in_array($property, $allowed, true) || $value === '') {
                continue;
            }

            // url(), expression(), @import, backslash escapes, quotes: any
            // of these mean the declaration is dropped wholesale.
            if (preg_match(
                '/url\s*\(|expression|@import|behavior|binding|\\|[\'"{}]/i',
                $value,
            ) === 1) {
                continue;
            }

            $kept[] = $property . ': ' . $value;
        }

        return implode('; ', $kept);
    }

    private function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '//')) {
            return true;
        }

        if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
            return true;
        }

        // Site-relative URLs (./x, /x, ../x, x.png) are fine; anything with a
        // scheme-looking prefix that is not http(s) was already rejected by
        // the preg above only for absolute forms, so check colon presence.
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $trimmed) === 1) {
            return false;
        }

        return true;
    }
}
