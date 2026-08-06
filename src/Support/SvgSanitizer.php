<?php

declare(strict_types=1);

namespace Lumera\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list SVG sanitiser.
 *
 * SVG is an XML document that a browser will happily execute, so an uploaded
 * logo is a stored-XSS vector unless it is rewritten. This class parses the
 * file and rebuilds it from an explicit allow-list of elements and attributes:
 * anything not named here is dropped, including every scripting construct,
 * every event handler and every external reference.
 *
 * Documents containing a DOCTYPE are rejected outright rather than sanitised,
 * which removes entity-expansion and XXE from consideration entirely.
 */
final class SvgSanitizer
{
    /** Elements that may appear in the output. */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath',
        'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'marker',
        'filter', 'fegaussianblur', 'feoffset', 'feblend', 'feflood',
        'fecomposite', 'femerge', 'femergenode', 'fecolormatrix', 'fedropshadow',
        'use',
    ];

    /** Attributes that may appear in the output. */
    private const ALLOWED_ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'd', 'points',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'width', 'height', 'viewbox', 'preserveaspectratio',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'stroke-miterlimit', 'opacity', 'color',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'letter-spacing', 'word-spacing', 'dominant-baseline', 'alignment-baseline',
        'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'fx', 'fy',
        'clip-path', 'clip-rule', 'mask', 'filter',
        'patternunits', 'patterncontentunits', 'markerwidth', 'markerheight',
        'refx', 'refy', 'orient', 'markerunits',
        'stddeviation', 'in', 'in2', 'result', 'mode', 'type', 'values',
        'dx', 'dy', 'flood-color', 'flood-opacity',
        'xmlns', 'xmlns:xlink', 'version', 'role', 'aria-label', 'aria-hidden',
        // href is allowed but is additionally restricted to same-document
        // fragments (#id) below.
        'href', 'xlink:href',
    ];

    /**
     * @return array{ok: bool, svg?: string, error?: string}
     */
    public function sanitize(string $source): array
    {
        $source = trim($source);

        if ($source === '') {
            return ['ok' => false, 'error' => 'The file is empty.'];
        }

        // Reject rather than try to sanitise anything that can pull in entities.
        if (preg_match('/<!DOCTYPE/i', $source) === 1 || preg_match('/<!ENTITY/i', $source) === 1) {
            return ['ok' => false, 'error' => 'The SVG contains a DOCTYPE or entity declaration and was rejected.'];
        }

        if (preg_match('/<\?php/i', $source) === 1) {
            return ['ok' => false, 'error' => 'The file contains embedded code.'];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $loaded = $document->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $document->documentElement === null) {
            return ['ok' => false, 'error' => 'The file is not valid SVG.'];
        }

        if (strtolower($document->documentElement->nodeName) !== 'svg') {
            return ['ok' => false, 'error' => 'The root element must be <svg>.'];
        }

        // The root <svg> carries attributes too — onload lives here most often.
        $this->scrubAttributes($document->documentElement);
        $this->scrub($document->documentElement);

        $output = $document->saveXML($document->documentElement);

        if ($output === false || $output === '') {
            return ['ok' => false, 'error' => 'The SVG could not be rewritten safely.'];
        }

        return ['ok' => true, 'svg' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $output];
    }

    /** Recursively removes disallowed nodes and attributes. */
    private function scrub(DOMNode $node): void
    {
        // Walk a static copy: the live NodeList shifts as children are removed.
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (!in_array(strtolower($child->nodeName), self::ALLOWED_ELEMENTS, true)) {
                    $node->removeChild($child);
                    continue;
                }

                $this->scrubAttributes($child);
                $this->scrub($child);
                continue;
            }

            // Comments and processing instructions carry no rendering value and
            // are a classic smuggling spot.
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private function scrubAttributes(DOMElement $element): void
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof DOMAttr) {
                continue;
            }

            $name  = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            // Every event handler, in one rule.
            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            if (!in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

            if (str_contains($normalized, 'javascript:') || str_contains($normalized, 'data:text/html')) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            // Links may only target this same document.
            if ($name === 'href' || $name === 'xlink:href') {
                if (!str_starts_with(trim($value), '#')) {
                    $element->removeAttributeNode($attribute);
                }
                continue;
            }

            // Inline CSS may not fetch or evaluate anything.
            if ($name === 'style') {
                if (
                    str_contains($normalized, 'expression(')
                    || str_contains($normalized, '@import')
                    || preg_match('/url\((?!#)/', $normalized) === 1
                ) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
    }
}
