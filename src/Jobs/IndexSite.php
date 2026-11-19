<?php

/**
 * Reads the site into Teme SEO's index, as the person the run acts for.
 * The hourly pass reads only the items a change marked and the drafts it
 * hasn't read. Reading every item again walks every published item, and
 * repairs what changed while Teme SEO was off.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Jobs;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;

final readonly class IndexSite implements JobHandler
{
    /** Items read in one run at most; the next pass reads the rest. */
    private const int MOST = 2000;

    public function __construct(
        private ContentReader $content,
        private Indexer $indexer,
        private ItemIndex $index,
    ) {}

    public function handle(JobContext $context): void
    {
        $person = $context->personId();
        $everything = ($context->payload()['action'] ?? null) === 'read-links';
        $wanted = [];

        // The first pass after Teme SEO is enabled has nothing to go on but the whole site.
        if ($everything || ! $this->index->hasPublished()) {
            $this->walkPublished($person);
        }

        $after = null;

        do {
            $queue = $this->content->queue(new QueueQuery(after: $after, limit: 200), $person);

            foreach ($queue->items as $queued) {
                if ($everything || ! $this->index->isRead($queued->item->id)) {
                    $this->index->remember($queued->item);
                    $wanted[$queued->item->id] = true;
                }
            }

            $after = $queue->next;
        } while ($after !== null && count($wanted) < self::MOST);

        foreach ($this->index->staleIds(self::MOST) as $itemId) {
            $wanted[$itemId] = true;
        }

        $ids = array_map(strval(...), array_slice(array_keys($wanted), 0, self::MOST));
        $total = count($ids);

        foreach ($ids as $done => $itemId) {
            if ($done % 25 === 0) {
                $context->progress($done, $total, __('Reading items'));
            }

            try {
                $this->indexer->read($itemId, $person);
            } catch (ReadRefused) {
                // An item this person may not edit waits for someone who may.
            }
        }

        $context->progress($total, $total, __('Items read: :count.', ['count' => $total]));
    }

    /**
     * Marks every published item to be read again, and settles the items
     * the index still holds as published that the site no longer does:
     * read again when they still exist, forgotten when they don't.
     */
    private function walkPublished(string $person): void
    {
        $seen = [];
        $after = null;

        do {
            $page = $this->content->published(new ItemQuery(after: $after, limit: 200));

            foreach ($page->items as $item) {
                $this->index->remember($item);
                $seen[$item->id] = true;
            }

            $after = $page->next;
        } while ($after !== null);

        foreach ($this->index->publishedIds() as $itemId) {
            if (isset($seen[$itemId])) {
                continue;
            }

            try {
                $this->indexer->read($itemId, $person);
            } catch (ReadRefused) {
                // The person who enabled Teme SEO may edit every item: one they can't read is gone.
                $this->index->forget($itemId);
            }
        }
    }
}
