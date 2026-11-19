<?php

/**
 * One text node outside links and inline code, as the editor counts a
 * phrase's matches, and whether a link may go there: only in a paragraph's
 * prose, never in a heading or a code block.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Document;

final readonly class TextRun
{
    public function __construct(
        public string $text,
        public bool $linkable,
    ) {}
}
