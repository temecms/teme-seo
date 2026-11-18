<?php

declare(strict_types=1);

namespace Teme\Seo\Document;

/**
 * One text node outside links and inline code, as the editor counts a
 * phrase's matches, and whether a link may go there: only in a paragraph's
 * prose, never in a heading or a code block.
 */
final readonly class TextRun
{
    public function __construct(
        public string $text,
        public bool $linkable,
    ) {}
}
