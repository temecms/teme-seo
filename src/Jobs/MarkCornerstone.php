<?php

/**
 * Cornerstone: marks or unmarks the item as cornerstone content, for a
 * person who may edit it, after reading it so the index knows it.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Jobs;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;

final readonly class MarkCornerstone implements JobHandler
{
    public function __construct(
        private Indexer $indexer,
        private ItemIndex $index,
    ) {}

    public function handle(JobContext $context): void
    {
        $itemId = ItemPayload::itemId($context);
        $this->indexer->read($itemId, $context->personId());
        $mark = ItemPayload::value($context, 'mark');
        // Change with no choice flips the mark; an explicit yes/no still works for older callers.
        $cornerstone = match ($mark) {
            'yes' => true,
            'no' => false,
            default => ! $this->index->isCornerstone($itemId),
        };
        $this->index->markCornerstone($itemId, $cornerstone);

        $context->progress(1, 1, $cornerstone ? __('Marked as cornerstone content.') : __('No longer cornerstone content.'));
    }
}
