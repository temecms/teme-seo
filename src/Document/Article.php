<?php

declare(strict_types=1);

namespace Teme\Seo\Document;

/**
 * What Teme SEO reads from an item's Tiptap document: its words, its text
 * in the order the editor holds it, the items it links to by ID, its
 * headings and paragraphs, and the images that have no alt text.
 */
final readonly class Article
{
    /** An internal link's address, as the editor stores it: the item's ID, never its address. */
    private const string INTERNAL_LINK = '/^teme:content\/([0-9a-hjkmnp-tv-z]{26})(?:#.*)?$/D';

    /** Nodes whose text counts for nothing a reader reads as prose. */
    private const array NOT_PROSE = ['codeBlock'];

    /**
     * @param  list<TextRun>  $runs  every text node outside links and inline code, in document order
     * @param  array<string, int>  $links  how many times the document links to each item, by its ID
     * @param  list<string>  $headings  each heading's text
     * @param  list<string>  $paragraphs  each top-level paragraph's text
     */
    private function __construct(
        public string $text,
        public int $words,
        public array $runs,
        public array $links,
        public array $headings,
        public array $paragraphs,
        public int $imagesMissingAlt,
    ) {}

    /**
     * Reads a Tiptap document; anything that isn't one reads as empty.
     *
     * @param  array<mixed>  $document
     */
    public static function read(array $document): self
    {
        $state = ['prose' => [], 'runs' => [], 'links' => [], 'headings' => [], 'paragraphs' => [], 'missingAlt' => 0];

        foreach (self::children($document) as $block) {
            $text = self::walk($block, $state, '', false);

            if (($block['type'] ?? null) === 'paragraph' && trim($text) !== '') {
                $state['paragraphs'][] = trim($text);
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $state['prose'])));

        return new self(
            $text,
            $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []),
            $state['runs'],
            $state['links'],
            $state['headings'],
            $state['paragraphs'],
            $state['missingAlt'],
        );
    }

    /**
     * Walks one node, gathering into $state, and returns the text it holds.
     *
     * @param  array<mixed>  $node
     * @param  array{prose: list<string>, runs: list<TextRun>, links: array<string, int>, headings: list<string>, paragraphs: list<string>, missingAlt: int}  $state
     */
    private static function walk(array $node, array &$state, string $block, bool $inCode): string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';

        if ($type === 'text') {
            return self::text($node, $state, $block, $inCode);
        }

        if ($type === 'hardBreak') {
            return ' ';
        }

        if ($type === 'image') {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

            if (trim((string) (is_scalar($attrs['alt'] ?? null) ? $attrs['alt'] : '')) === '' && ($attrs['decorative'] ?? false) !== true) {
                $state['missingAlt']++;
            }
        }

        $inCode = $inCode || in_array($type, self::NOT_PROSE, true);
        $block = in_array($type, ['paragraph', 'heading'], true) ? $type : $block;
        $text = '';

        foreach (self::children($node) as $child) {
            $text .= self::walk($child, $state, $block, $inCode);
        }

        if ($type === 'heading' && trim($text) !== '') {
            $state['headings'][] = trim($text);
        }

        if (in_array($type, ['paragraph', 'heading', 'listItem', 'tableCell', 'tableHeader', 'blockquote'], true)) {
            $state['prose'][] = ' ';
        }

        return $text;
    }

    /**
     * One text node: its words, the link it carries, and a run when it lies
     * outside links and inline code, as the editor counts a phrase's matches.
     *
     * @param  array<mixed>  $node
     * @param  array{prose: list<string>, runs: list<TextRun>, links: array<string, int>, headings: list<string>, paragraphs: list<string>, missingAlt: int}  $state
     */
    private static function text(array $node, array &$state, string $block, bool $inCode): string
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';
        $linked = false;
        $code = false;

        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            $markType = is_array($mark) ? ($mark['type'] ?? null) : null;

            if ($markType === 'code') {
                $code = true;
            }

            if ($markType === 'link') {
                $linked = true;
                $href = is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : '';

                if (preg_match(self::INTERNAL_LINK, $href, $match) === 1) {
                    $state['links'][$match[1]] = ($state['links'][$match[1]] ?? 0) + 1;
                }
            }
        }

        if (! $inCode) {
            $state['prose'][] = $text;
        }

        if (! $linked && ! $code) {
            $state['runs'][] = new TextRun($text, $block === 'paragraph' && ! $inCode);
        }

        return $text;
    }

    /**
     * A node's children that are nodes.
     *
     * @param  array<mixed>  $node
     *
     * @return list<array<mixed>>
     */
    private static function children(array $node): array
    {
        $children = [];

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
