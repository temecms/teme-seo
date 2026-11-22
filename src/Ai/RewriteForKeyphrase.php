<?php

/**
 * Rewrite: asks the site's AI to rewrite the draft's introduction, its
 * headings, or a few of its paragraphs around a keyphrase, and offers the
 * result as a whole document the person compares word by word before using
 * it. Blocks holding links or other marks are never sent to be rewritten,
 * so no link is lost.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Ai;

use RuntimeException;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Jobs\ItemPayload;
use Teme\Seo\Jobs\SuggestLinks;
use Teme\Seo\Panels\KeyphrasePanel;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\SuggestionStore;

final readonly class RewriteForKeyphrase implements JobHandler
{
    /** The prefix of the suggestions this job makes. */
    public const string PREFIX = 'rewrite-';

    /** The AI task this job asks for. */
    public const string TASK = 'keyphrase-rewrite';

    /** The most blocks the AI may rewrite in one go. */
    private const int MOST_BLOCKS = 12;

    public function __construct(
        private SiteAi $ai,
        private Indexer $indexer,
        private SuggestionStore $suggestions,
    ) {}

    public function handle(JobContext $context): void
    {
        $itemId = ItemPayload::itemId($context);
        $keyphrase = mb_substr(ItemPayload::value($context, 'keyphrase'), 0, 80);
        $part = ItemPayload::value($context, 'part');

        if ($keyphrase === '' || ! in_array($part, ['intro', 'headings', 'spread'], true)) {
            throw new RuntimeException(__('Give a keyphrase, and choose what to rewrite.'));
        }

        $person = $context->personId();
        $context->progress(0, 2, __('Reading the draft'));
        $snapshot = $this->indexer->read($itemId, $person);
        $document = $snapshot->body;
        $blocks = self::blocks($document, $part);

        if ($blocks === []) {
            $context->progress(2, 2, __('There is nothing plain to rewrite there: no such block, or each one holds a link.'));

            return;
        }

        $context->progress(1, 2, __('Asking the site’s AI'));
        $answer = $this->ai->ask(new AiAsk(
            self::TASK,
            self::instructions($part),
            [
                'keyphrase' => $keyphrase,
                'title' => $snapshot->item->title,
                'blocks' => implode("\n", array_map(fn (int $index, string $text): string => $index.': '.$text, array_keys($blocks), $blocks)),
            ],
            [
                'type' => 'object',
                'properties' => [
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['index' => ['type' => 'integer'], 'text' => ['type' => 'string']],
                            'required' => ['index', 'text'],
                        ],
                    ],
                ],
                'required' => ['blocks'],
            ],
            8000,
            itemId: $itemId,
        ), $person);

        $changed = 0;
        $content = is_array($document['content'] ?? null) ? $document['content'] : [];

        foreach (is_array($answer->data['blocks'] ?? null) ? $answer->data['blocks'] : [] as $block) {
            $index = is_array($block) && is_int($block['index'] ?? null) ? $block['index'] : -1;
            $text = is_array($block) && is_string($block['text'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $block['text'])) : '';

            if (! isset($blocks[$index]) || $text === '' || $text === $blocks[$index] || mb_strlen($text) > 5000 || $changed >= self::MOST_BLOCKS) {
                continue;
            }

            $node = is_array($content[$index] ?? null) ? $content[$index] : [];
            $content[$index] = [...$node, 'content' => [['type' => 'text', 'text' => $text]]];
            $changed++;
        }

        if ($changed === 0) {
            $context->progress(2, 2, __('The AI suggested no change.'));

            return;
        }

        $label = match ($part) {
            'intro' => __('The introduction, for “:keyphrase”', ['keyphrase' => $keyphrase]),
            'headings' => __('The headings, for “:keyphrase”', ['keyphrase' => $keyphrase]),
            default => __('A few paragraphs, for “:keyphrase”', ['keyphrase' => $keyphrase]),
        };

        $this->suggestions->replace($itemId, KeyphrasePanel::ID, self::PREFIX.$part, [new Suggestion(
            self::PREFIX.$part.'-'.base_convert((string) time(), 10, 36),
            SuggestLinks::shorten($label, 120),
            __('Blocks rewritten: :count. Links and formatting elsewhere stay as they are.', ['count' => $changed]),
            SuggestedChange::body([...$document, 'content' => $content]),
        )], $snapshot->revisionId);

        $context->progress(2, 2, __('A rewrite is ready to compare.'));
    }

    /**
     * The blocks the AI may rewrite, by their place among the document's
     * top-level blocks: plain text only, with no link or other mark.
     *
     * @param  array<string, mixed>  $document
     *
     * @return array<int, string>
     */
    public static function blocks(array $document, string $part): array
    {
        $blocks = [];

        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $index => $node) {
            if (! is_array($node) || ! is_array($node['content'] ?? null) || $node['content'] === []) {
                continue;
            }

            $type = $node['type'] ?? null;
            $text = self::plain($node);

            if ($part === 'intro' && $type === 'paragraph') {
                return $text === null || $text === '' ? [] : [(int) $index => $text];
            }

            $wanted = ($part === 'headings' && $type === 'heading') || ($part === 'spread' && $type === 'paragraph' && count($blocks) < 30);

            if ($wanted && $text !== null && $text !== '') {
                $blocks[(int) $index] = $text;
            }
        }

        return $blocks;
    }

    /**
     * A block's text when it holds only unmarked text, or null.
     *
     * @param  array<mixed>  $node
     */
    private static function plain(array $node): ?string
    {
        $text = '';

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'text' || ! empty($child['marks'])) {
                return null;
            }

            $text .= is_string($child['text'] ?? null) ? $child['text'] : '';
        }

        return trim($text);
    }

    /**
     * What the AI is asked to do for each part.
     */
    private static function instructions(string $part): string
    {
        $common = 'You edit an article on a website so that it serves readers searching for the keyphrase given as an input. '
            .'Keep the author\'s voice, facts and language. Write plain text only, with no Markdown or HTML. '
            .'The blocks input lists blocks as "index: text". Answer with the blocks you change, each with its index and its new text; leave out blocks you keep as they are. ';

        return $common.match ($part) {
            'intro' => 'Rewrite the one block, the introduction, so that it uses the keyphrase once, early and naturally, in no more words than it has now.',
            'headings' => 'Rewrite the headings so that one or two of them use the keyphrase or a close form of it naturally. Keep each heading short.',
            default => 'Choose at most three paragraphs where the keyphrase fits naturally and rewrite only those, using it once each, so it is spread through the article without being repeated in every paragraph.',
        };
    }
}
