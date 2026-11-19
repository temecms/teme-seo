<?php

/**
 * Find links: reads the item's draft as the person who asked, and suggests
 * links from its phrases to the site's published items, cornerstone items
 * first, leaving out items it already links to and links the person
 * discarded before.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Jobs;

use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Ai\AskAiForLinks;
use Teme\Seo\Document\Article;
use Teme\Seo\Links\LinkSuggester;
use Teme\Seo\Panels\LinksPanel;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SuggestionStore;

final readonly class SuggestLinks implements JobHandler
{
    /** The prefix of the suggestions this job makes. */
    public const string PREFIX = 'link-';

    /** The most links it suggests at once. */
    private const int MOST = 10;

    public function __construct(
        private Indexer $indexer,
        private ItemIndex $index,
        private SuggestionStore $suggestions,
        private LinkSuggester $suggester,
    ) {}

    public function handle(JobContext $context): void
    {
        $itemId = ItemPayload::itemId($context);
        $context->progress(0, 2, __('Reading the draft'));

        $snapshot = $this->indexer->read($itemId, $context->personId());
        $skip = $this->index->linkedFrom($itemId);

        foreach (array_keys($this->suggestions->settledKeys($itemId, LinksPanel::ID)) as $key) {
            if (str_starts_with($key, self::PREFIX) || str_starts_with($key, AskAiForLinks::PREFIX)) {
                $skip[] = substr($key, strrpos($key, '-') + 1);
            }
        }

        $context->progress(1, 2, __('Looking for phrases to link'));
        $found = $this->suggester->suggest(Article::read($snapshot->body), $this->index->linkTargets($itemId, $snapshot->item->language), $skip, self::MOST);
        $made = [];

        foreach ($found as $link) {
            $made[] = new Suggestion(
                self::PREFIX.$link['target'],
                self::shorten(__('Link “:phrase”', ['phrase' => $link['phrase']]), 120),
                self::shorten($link['cornerstone'] ? __('To “:title”, cornerstone content', ['title' => $link['title']]) : __('To “:title”', ['title' => $link['title']]), 500),
                SuggestedChange::link($link['phrase'], $link['target'], $link['occurrence']),
            );
        }

        $this->suggestions->replace($itemId, LinksPanel::ID, self::PREFIX, $made);
        $context->progress(2, 2, $made === [] ? __('No new links found.') : __('Links found: :count.', ['count' => count($made)]));
    }

    /**
     * The text cut to $most characters, with an ellipsis when cut.
     */
    public static function shorten(string $text, int $most): string
    {
        return mb_strlen($text) <= $most ? $text : rtrim(mb_substr($text, 0, $most - 1)).'…';
    }
}
