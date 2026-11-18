<?php

declare(strict_types=1);

namespace Teme\Seo\Ai;

use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Document\Article;
use Teme\Seo\Jobs\ItemPayload;
use Teme\Seo\Jobs\SuggestLinks;
use Teme\Seo\Links\LinkSuggester;
use Teme\Seo\Panels\LinksPanel;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SuggestionStore;

/**
 * Ask AI for links: sends the draft's words and a shortlist of the site's
 * published titles to the site's AI connection, and offers each link it
 * picks whose phrase is really in the draft and whose item is on the list.
 */
final readonly class AskAiForLinks implements JobHandler
{
    /** The prefix of the suggestions this job makes. */
    public const string PREFIX = 'ai-link-';

    /** The AI task this job asks for. */
    public const string TASK = 'link-ideas';

    /** The most titles the AI is shown. */
    private const int SHORTLIST = 150;

    /** The most characters of the draft sent. */
    private const int MOST_TEXT = 40_000;

    public function __construct(
        private SiteAi $ai,
        private Indexer $indexer,
        private ItemIndex $index,
        private SuggestionStore $suggestions,
        private LinkSuggester $suggester,
    ) {}

    public function handle(JobContext $context): void
    {
        $itemId = ItemPayload::itemId($context);
        $person = $context->personId();
        $context->progress(0, 2, __('Reading the draft'));

        $snapshot = $this->indexer->read($itemId, $person);
        $article = Article::read($snapshot->body);
        $skip = array_fill_keys($this->index->linkedFrom($itemId), true);

        foreach (array_keys($this->suggestions->settledKeys($itemId, LinksPanel::ID)) as $key) {
            $skip[substr($key, strrpos($key, '-') + 1)] = true;
        }

        $shortlist = Shortlist::of($article->text, array_values(array_filter(
            $this->index->linkTargets($itemId, $snapshot->item->language),
            fn (array $target): bool => ! isset($skip[$target['id']]),
        )), self::SHORTLIST);

        if ($shortlist === [] || $article->words === 0) {
            $this->suggestions->replace($itemId, LinksPanel::ID, self::PREFIX, []);
            $context->progress(2, 2, __('No published item to link to yet.'));

            return;
        }

        $context->progress(1, 2, __('Asking the site’s AI'));
        $answer = $this->ai->ask(new AiAsk(
            self::TASK,
            'You suggest internal links for an article on a website. The article and a list of the site\'s published items are given as inputs. '
            .'Pick up to 8 items the article\'s readers would want to follow. For each, give a phrase copied exactly from the article, 2 to 6 words, '
            .'that a link to the item would sit on naturally, the item\'s id exactly as listed, and one short sentence saying why. '
            .'Never link the same item twice, never invent an id, and prefer items marked cornerstone.',
            [
                'article' => mb_substr($article->text, 0, self::MOST_TEXT),
                'items' => implode("\n", array_map(fn (array $target): string => $target['id'].' | '.$target['title'].($target['cornerstone'] ? ' | cornerstone' : ''), $shortlist)),
            ],
            [
                'type' => 'object',
                'properties' => [
                    'links' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['phrase' => ['type' => 'string'], 'id' => ['type' => 'string'], 'why' => ['type' => 'string']],
                            'required' => ['phrase', 'id', 'why'],
                        ],
                    ],
                ],
                'required' => ['links'],
            ],
            2000,
            itemId: $itemId,
        ), $person);

        $listed = array_column($shortlist, null, 'id');
        $made = [];

        foreach (is_array($answer->data['links'] ?? null) ? $answer->data['links'] : [] as $link) {
            $target = is_array($link) && is_string($link['id'] ?? null) ? ($listed[$link['id']] ?? null) : null;
            $place = $target !== null && is_string($link['phrase'] ?? null) ? $this->suggester->locate($article, $link['phrase']) : null;

            if ($target === null || $place === null || isset($made[$target['id']]) || count($made) >= 8) {
                continue;
            }

            $why = is_string($link['why'] ?? null) ? trim($link['why']) : '';
            $made[$target['id']] = new Suggestion(
                self::PREFIX.$target['id'],
                SuggestLinks::shorten(__('Link “:phrase”', ['phrase' => $place['phrase']]), 120),
                SuggestLinks::shorten(trim(__('To “:title”', ['title' => $target['title']]).' '.$why), 500),
                SuggestedChange::link($place['phrase'], $target['id'], $place['occurrence']),
            );
        }

        $this->suggestions->replace($itemId, LinksPanel::ID, self::PREFIX, array_values($made));
        $context->progress(2, 2, $made === [] ? __('The AI found no link worth making.') : __('Links found: :count.', ['count' => count($made)]));
    }
}
